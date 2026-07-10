<?php

namespace Tests\Feature;

use App\Models\Disk;
use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the GPT round-16 findings (YABS webhook hardening batch):
 * 1. Signed YABS URLs: expiry was already enforced (temporarySignedRoute +
 *    signed middleware — pinned here against regression to permanent URLs);
 *    replaying the same run within the window inserted duplicates. A run is
 *    now identified by (server, output timestamp) and re-submits no-op.
 * 2. A partial fio array (missing block sizes) passed validation but built
 *    a row missing NOT NULL columns — rollback and a generic 500. Partial
 *    runs now skip the disk-speed row and ingest the rest.
 * 3. No size/cardinality bounds: the webhook took arbitrarily large bodies
 *    and arrays; the paste route took an unbounded JSON string.
 * 4. default_server_os accepted any integer — a forged request persisted a
 *    dangling default the create-form silently failed to preselect.
 * 5. WHOIS enrichment had no timeout and assumed the exact upstream schema:
 *    a success-true response missing a field threw undefined-key.
 */
class GptRound16RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        $server_id = Str::random(8);
        (new Pricing)->insertPricing(1, $server_id, 'USD', 5.00, 1, '2027-01-01');
        Disk::insertDisk($server_id, 50, 'GB', 'SSD');

        return Server::create([
            'id' => $server_id, 'hostname' => 'gpt16.example.com', 'server_type' => 1,
            'os_id' => OS::create(['name' => 'OS ' . $server_id])->id,
            'provider_id' => Providers::create(['name' => 'P' . $server_id])->id,
            'location_id' => Locations::create(['name' => 'L' . $server_id])->id,
            'ram' => 4, 'ram_type' => 'GB', 'ram_as_mb' => 4096, 'disk' => 50, 'disk_type' => 'GB',
            'disk_as_gb' => 50, 'cpu' => 4, 'has_yabs' => 0, 'active' => 1, 'owned_since' => '2026-01-01',
        ]);
    }

    private function yabsPayload(): array
    {
        return [
            'version' => 'v2024-06-09', 'time' => '20260705-120000',
            'os' => ['distro' => 'Debian 13', 'kernel' => '6.1.0', 'uptime' => 432000],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'AMD EPYC', 'cores' => 4, 'freq' => '2299.998', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 4014080, 'swap' => 524288, 'disk' => 49283072],
            'fio' => [
                ['bs' => '4k', 'speed_rw' => 150000], ['bs' => '64k', 'speed_rw' => 500000],
                ['bs' => '512k', 'speed_rw' => 800000], ['bs' => '1m', 'speed_rw' => 900000],
            ],
        ];
    }

    private function signedUrl(Server $server, $expires = null): string
    {
        return URL::temporarySignedRoute('api.store-yabs', $expires ?? now()->addHours(12), ['server' => $server->id]);
    }

    public function test_expired_signed_url_is_rejected()
    {
        $server = $this->makeServer();

        $url = $this->signedUrl($server, now()->subMinute());
        $this->postJson($url, $this->yabsPayload())->assertStatus(403);
        $this->assertDatabaseCount('yabs', 0);
    }

    public function test_replayed_run_does_not_insert_a_duplicate()
    {
        $server = $this->makeServer();
        $url = $this->signedUrl($server);

        $this->postJson($url, $this->yabsPayload())->assertStatus(200);
        $this->assertDatabaseCount('yabs', 1);

        // Same signed URL, same payload — the replay must not add a row.
        $this->postJson($url, $this->yabsPayload())
            ->assertStatus(200)
            ->assertJsonPath('message', 'Duplicate YABS run; already recorded');
        $this->assertDatabaseCount('yabs', 1);

        // A genuinely new run (new output timestamp) still ingests.
        $this->postJson($url, array_merge($this->yabsPayload(), ['time' => '20260706-090000']))
            ->assertStatus(200);
        $this->assertDatabaseCount('yabs', 2);
    }

    public function test_partial_fio_skips_the_disk_row_instead_of_500ing()
    {
        $server = $this->makeServer();

        $payload = $this->yabsPayload();
        $payload['fio'] = [['bs' => '4k', 'speed_rw' => 150000]]; // interrupted run

        $this->postJson($this->signedUrl($server), $payload)->assertStatus(200);

        $this->assertDatabaseCount('yabs', 1);
        $this->assertDatabaseCount('disk_speed', 0);
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'has_yabs' => 1]);
    }

    public function test_oversized_and_overwide_payloads_are_bounded()
    {
        $server = $this->makeServer();
        $url = $this->signedUrl($server);

        // Body over 64KB → 413 before any parsing.
        $padded = array_merge($this->yabsPayload(), ['padding' => str_repeat('x', 70000)]);
        $this->postJson($url, $padded)->assertStatus(413);

        // fio cardinality over the bound → 422.
        $wide = $this->yabsPayload();
        $wide['fio'] = array_map(
            fn($i) => ['bs' => "b$i", 'speed_rw' => 1000],
            range(1, 9)
        );
        $this->postJson($url, $wide)->assertStatus(422);

        $this->assertDatabaseCount('yabs', 0);
    }

    public function test_paste_route_bounds_the_json_string()
    {
        $server = $this->makeServer();

        // VALID yabs JSON, padded past the cap: garbage would error via the
        // parse path on any version and pin nothing about the bound itself.
        $oversized = json_encode(array_merge($this->yabsPayload(), ['padding' => str_repeat('x', 70000)]));

        $this->actingAs(User::factory()->create())
            ->from(route('yabs.create'))
            ->post(route('yabs.store'), [
                'server_id' => $server->id,
                'yabs_json' => $oversized,
            ])->assertSessionHasErrors('yabs_json');

        $this->assertDatabaseCount('yabs', 0);
    }

    public function test_default_server_os_must_reference_a_real_os()
    {
        Settings::firstOrCreate(['id' => 1]);
        $os = OS::create(['name' => 'RealOS']);

        $payload = [
            'dark_mode' => 0, 'show_versions_footer' => 1, 'show_servers_public' => 0,
            'show_server_value_ip' => 0, 'show_server_value_hostname' => 1,
            'show_server_value_provider' => 1, 'show_server_value_location' => 1,
            'show_server_value_price' => 1, 'show_server_value_yabs' => 1,
            'default_currency' => 'USD', 'due_soon_amount' => 5, 'recently_added_amount' => 5,
            'dashboard_currency' => 'USD', 'sort_on' => 1, 'servers_index_cards' => 0,
            'default_per_page' => 25, 'prometheus_enabled' => 0, 'prometheus_check_interval' => 20,
        ];

        $this->actingAs(User::factory()->create())
            ->from(route('settings.index'))
            ->put(route('settings.update', 1), array_merge($payload, ['default_server_os' => 999999]))
            ->assertSessionHasErrors('default_server_os');

        $this->actingAs(User::factory()->create())
            ->put(route('settings.update', 1), array_merge($payload, ['default_server_os' => $os->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_old_favicon_is_replaced_only_across_a_successful_update()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1])->update(['favicon' => 'favicon.jpg']);
        $os = OS::create(['name' => 'FaviconOS']);
        \Illuminate\Support\Facades\Storage::fake('public_uploads');
        \Illuminate\Support\Facades\Storage::disk('public_uploads')->put('favicon.jpg', 'old-custom');

        $payload = [
            'dark_mode' => 0, 'show_versions_footer' => 1, 'show_servers_public' => 0,
            'show_server_value_ip' => 0, 'show_server_value_hostname' => 1,
            'show_server_value_provider' => 1, 'show_server_value_location' => 1,
            'show_server_value_price' => 1, 'show_server_value_yabs' => 1,
            'default_currency' => 'USD', 'default_server_os' => $os->id,
            'due_soon_amount' => 5, 'recently_added_amount' => 5,
            'dashboard_currency' => 'USD', 'sort_on' => 1, 'servers_index_cards' => 0,
            'default_per_page' => 25, 'prometheus_enabled' => 0, 'prometheus_check_interval' => 20,
            'favicon' => \Illuminate\Http\UploadedFile::fake()->image('icon.png', 32, 32),
        ];

        $this->actingAs($user)->put(route('settings.update', 1), $payload)
            ->assertSessionMissing('error');

        \Illuminate\Support\Facades\Storage::disk('public_uploads')->assertExists('favicon.png');
        \Illuminate\Support\Facades\Storage::disk('public_uploads')->assertMissing('favicon.jpg');
        $this->assertDatabaseHas('settings', ['id' => 1, 'favicon' => 'favicon.png']);
    }

    public function test_favicon_file_deletion_happens_after_the_settings_row_update()
    {
        // FS/DB boundary ordering: the old file must outlive the row update,
        // so a failed update never leaves settings referencing a deleted
        // file. Pinned structurally — an update() failure cannot be forced
        // through HTTP.
        $controller = file_get_contents(app_path('Http/Controllers/SettingsController.php'));

        $updatePos = strpos($controller, '$do_update = $settings->update([');
        $deletePos = strpos($controller, "Storage::disk('public_uploads')->delete(\$stale);");

        $this->assertNotFalse($updatePos);
        $this->assertNotFalse($deletePos);
        $this->assertGreaterThan($updatePos, $deletePos,
            'the stale favicon delete must run after the settings row update');
        $this->assertStringNotContainsString(
            "Storage::disk('public_uploads')->delete(\$settings->favicon)",
            $controller,
            'storeFavicon must not delete the previous favicon before the row update'
        );
    }

    public function test_whois_tolerates_a_sparse_success_response()
    {
        Http::fake(['ipwhois.app/*' => Http::response(['success' => true, 'continent' => 'Europe'], 200)]);

        $ip = IPs::create(['id' => 'gpt16ip1', 'service_id' => 'gpt16sv1',
            'address' => '203.0.113.7', 'is_ipv4' => 1, 'active' => 1]);

        // Pre-fix: undefined array key on the missing fields mid-request.
        $this->assertTrue(IPs::getUpdateIpInfo($ip));

        $ip->refresh();
        $this->assertSame('Europe', $ip->continent);
        $this->assertNull($ip->country);
        $this->assertNotNull($ip->fetched_at);
    }
}
