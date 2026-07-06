<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the round-19 review findings: import FK ordering,
 * pricing-update cache fan-out, delete cache/404 gaps, favicon upload
 * extension, settings sort cache keys, page-load timer.
 */
class Round19RegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        $this->user = User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        Providers::create(['name' => 'Test Provider']);
        Locations::create(['name' => 'Test Location']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function createServerWithPricing(string $id): Server
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        return Server::create([
            'id' => $id,
            'hostname' => "host-$id.example.com",
            'server_type' => 1,
            'os_id' => OS::first()->id,
            'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id,
            'ram' => 2048,
            'disk' => 50,
            'cpu' => 2,
        ]);
    }

    public function test_import_servers_command_satisfies_pricing_fk()
    {
        // servers.id has an FK to pricings.service_id, enforced immediately by
        // InnoDB but silently dropped by SQLite — inserting the server before
        // its pricing imported ZERO rows on production MySQL.
        $csv = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($csv,
            "COMPANY,LOCATION,HOSTNAME,RAM,VCPU,SSD DISK,HDD DISK,BANDWIDTH,PERIOD,COST,CURRENCY,Renews,Cancelled\n" .
            "TestCo,Test Location,importtest01.invalid,4 GB,2,80 GB,,10TB,1M,\$5.00,USD,12/01/26,\n");

        $this->artisan('import:servers', ['file' => $csv])->assertExitCode(0);
        unlink($csv);

        $server = Server::where('hostname', 'importtest01.invalid')->first();
        $this->assertNotNull($server, 'import:servers inserted no server (FK order regression)');
        $this->assertDatabaseHas('pricings', ['service_id' => $server->id, 'service_type' => 1]);
    }

    public function test_api_update_pricing_clears_owning_service_type_caches()
    {
        // PUT /api/pricing/{id} accepts any service's pricing row; it used to
        // clear only server caches, leaving e.g. domain list/show caches
        // serving the old price for up to a month.
        $pricing = Pricing::create([
            'service_id' => 'dom00001', 'service_type' => 4, 'currency' => 'USD',
            'price' => 10.00, 'term' => 4, 'as_usd' => 10.00, 'usd_per_month' => 0.83,
            'next_due_date' => now()->addYear()->format('Y-m-d'),
        ]);

        Cache::put('all_domains', 'sentinel', 600);
        Cache::put('all_active_domains', 'sentinel', 600);
        Cache::put('domain.dom00001', 'sentinel', 600);
        Cache::put('due_soon', 'sentinel', 600);

        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 12.00, 'currency' => 'USD', 'term' => 4,
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertFalse(Cache::has('all_domains'));
        $this->assertFalse(Cache::has('all_active_domains'));
        $this->assertFalse(Cache::has('domain.dom00001'));
        $this->assertFalse(Cache::has('due_soon'));
    }

    public function test_api_update_pricing_persists_active_flag()
    {
        // 'active' was validated then silently dropped from the update.
        $pricing = Pricing::create([
            'service_id' => 'srv00002', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 5.00, 'currency' => 'USD', 'term' => 1, 'active' => 0,
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('pricings', ['id' => $pricing->id, 'active' => 0]);
    }

    public function test_api_delete_missing_server_returns_404_not_500()
    {
        $this->deleteJson('/api/servers/zzzzzzz9', [], $this->apiHeaders())
            ->assertStatus(404);
    }

    public function test_web_server_destroy_clears_server_specific_cache()
    {
        // Web destroy only cleared the list caches; GET /api/servers/{id}
        // kept serving the deleted server from server.$id for up to a month.
        $server = $this->createServerWithPricing('webdstr1');
        Cache::put('server.webdstr1', 'sentinel', 600);

        $this->actingAs($this->user)
            ->delete(route('servers.destroy', $server))
            ->assertRedirect(route('servers.index'));

        $this->assertFalse(Cache::has('server.webdstr1'));
    }

    public function test_seedbox_destroy_deletes_assigned_ips()
    {
        $seedbox = SeedBoxes::create(['id' => 'seedbx01', 'title' => 'Test SB', 'active' => 1]);
        IPs::insertIP('seedbx01', '10.11.12.13');

        $this->actingAs($this->user)
            ->delete(route('seedboxes.destroy', $seedbox))
            ->assertRedirect(route('seedboxes.index'));

        $this->assertDatabaseMissing('ips', ['service_id' => 'seedbx01']);
    }

    public function test_favicon_upload_ignores_client_extension_and_settings_update_clears_sort_caches()
    {
        // The mimes rule validates content (and Laravel itself blocks *.php
        // names), but the stored filename used the client extension: a real
        // PNG named evil.html would land in the webroot as favicon.html — a
        // stored-XSS vector. The name must derive from the sniffed content.
        Storage::fake('public_uploads');
        Cache::put('all_active_shared', 'sentinel', 600);
        Cache::put('non_active_domains', 'sentinel', 600);

        // Real PNG bytes with a lying client name (Testing\File fakes derive
        // the mime from the name, so build a genuine UploadedFile instead).
        $path = tempnam(sys_get_temp_dir(), 'fav');
        imagepng(imagecreatetruecolor(16, 16), $path);
        $upload = new UploadedFile($path, 'evil.html', 'text/html', null, true);

        $this->actingAs($this->user)
            ->put(route('settings.update', 1), $this->validSettingsPayload() + [
                'favicon' => $upload,
            ])
            ->assertRedirect(route('settings.index'));

        Storage::disk('public_uploads')->assertExists('favicon.png');
        Storage::disk('public_uploads')->assertMissing('favicon.html');

        // sort_on affects the active/non-active list caches of every type,
        // not just servers — they must be cleared on settings save.
        $this->assertFalse(Cache::has('all_active_shared'));
        $this->assertFalse(Cache::has('non_active_domains'));
    }

    public function test_page_load_timer_reports_seconds()
    {
        $process = new Process();
        $process->startTimer();
        usleep(100000); // 0.1s
        $process->stopTimer();

        $elapsed = $process->getTimeTaken();
        // The old implementation multiplied by 100 (~10 for a 0.1s sleep).
        $this->assertGreaterThan(0.09, $elapsed);
        $this->assertLessThan(1.0, $elapsed);
    }

    private function validSettingsPayload(): array
    {
        return [
            'dark_mode' => 1,
            'show_versions_footer' => 1,
            'show_servers_public' => 0,
            'show_server_value_ip' => 1,
            'show_server_value_hostname' => 1,
            'show_server_value_provider' => 1,
            'show_server_value_location' => 1,
            'show_server_value_price' => 1,
            'show_server_value_yabs' => 1,
            'default_currency' => 'USD',
            'default_server_os' => 1,
            'due_soon_amount' => 5,
            'recently_added_amount' => 5,
            'dashboard_currency' => 'USD',
            'sort_on' => 1,
            'servers_index_cards' => 1,
            'default_per_page' => 25,
            'prometheus_enabled' => 0,
            'prometheus_check_interval' => 20,
        ];
    }
}
