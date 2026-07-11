<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Locations;
use App\Models\Note;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\Shared;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the round-25 review findings: shared/reseller IP
 * round-trip, dangling foreign ids, public IPv6 column, API disk and
 * active parity, BOM'd import headers.
 */
class Round25RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

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

    private function makePricing(string $id, int $type): Pricing
    {
        return Pricing::create([
            'service_id' => $id, 'service_type' => $type, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
    }

    private function makeServer(string $id): Server
    {
        $this->makePricing($id, 1);

        return Server::create([
            'id' => $id, 'hostname' => "host-$id.example.com", 'server_type' => 1,
            'os_id' => OS::first()->id, 'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id, 'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);
    }

    public function test_shared_update_preserves_ips_assigned_via_ips_page()
    {
        // The edit form only rendered ips[0] into dedicated_ip, so saving a
        // shared service diffed every other IP (and its notes) out of existence.
        $this->makePricing('shrdips1', 2);
        $shared = Shared::create(['id' => 'shrdips1', 'main_domain' => 'multi-ip.example.com', 'shared_type' => 'cPanel']);
        IPs::insertIP('shrdips1', '192.0.2.60');
        $extra = IPs::insertIP('shrdips1', '2001:db8::60');
        Note::create(['id' => Str::random(8), 'service_id' => $extra->id, 'note' => 'keep']);

        // Simulate the edit form round-trip: dedicated_ip = first, ip2 = extra.
        $this->actingAs($this->user)->put(route('shared.update', $shared), [
            'domain' => 'multi-ip.example.com',
            'shared_type' => 'cPanel',
            'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id,
            'price' => 5.00, 'currency' => 'USD', 'payment_term' => 1,
            'disk' => 50, 'domains' => 1, 'sub_domains' => 1, 'bandwidth' => 100,
            'email' => 1, 'ftp' => 1, 'db' => 1, 'was_promo' => 0,
            'dedicated_ip' => '192.0.2.60',
            'ip2' => '2001:db8::60',
        ])->assertRedirect(route('shared.index'));

        $this->assertDatabaseHas('ips', ['id' => $extra->id, 'address' => '2001:db8::60']);
        $this->assertDatabaseHas('notes', ['service_id' => $extra->id]);
    }

    public function test_dangling_foreign_ids_rejected_and_index_survives_legacy_orphans()
    {
        // API must reject ids that no FK guards.
        $this->postJson('/api/servers', [
            'hostname' => 'dangling.example.com', 'server_type' => 1,
            'os_id' => null, 'provider_id' => null, 'location_id' => null,
            'ssh_port' => 22, 'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0,
            'active' => 1, 'show_public' => 0, 'owned_since' => '2024-01-01',
            'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ], $this->apiHeaders())->assertStatus(400);
    }

    public function test_servers_index_survives_legacy_dangling_relations()
    {
        // Pre-existing orphans must not 500 the index.
        $this->makePricing('orphrel1', 1);
        Server::create([
            'id' => 'orphrel1', 'hostname' => 'orphan-rel.example.com', 'server_type' => 1,
            'os_id' => null, 'provider_id' => null, 'location_id' => null,
            'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);
        Cache::flush();

        $this->actingAs($this->user)->get(route('servers.index'))->assertOk();
    }

    public function test_public_page_shows_ipv6_addresses()
    {
        Settings::where('id', 1)->update(['show_servers_public' => 1, 'show_server_value_ip' => 1]);
        Cache::forget('settings');

        $server = $this->makeServer('pubipv61');
        $server->update(['show_public' => 1, 'active' => 1]);
        IPs::insertIP('pubipv61', '2001:db8::99');
        Cache::forget('public_server_data');

        // is_ipv6 doesn't exist as a column; the old check never matched.
        $this->get('/servers/public')->assertOk()->assertSee('2001:db8::99');
    }

    public function test_api_server_update_rewrites_disk_rows()
    {
        // The UI prefers server_disks sums; updating only servers.disk left
        // the old size showing forever.
        $server = $this->makeServer('apidisk1');
        \App\Models\Disk::insertDisk('apidisk1', 50, 'GB', 'SSD');

        $this->putJson('/api/servers/apidisk1', [
            'disk' => 500, 'disk_as_gb' => 500,
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('server_disks', ['server_id' => 'apidisk1', 'disk_size' => 500]);
        $this->assertSame(1, \App\Models\Disk::where('server_id', 'apidisk1')->count());
    }

    public function test_api_server_reactivation_fans_into_pricing_active()
    {
        $server = $this->makeServer('apiact01');
        Pricing::where('service_id', 'apiact01')->update(['active' => 0]);
        $server->update(['active' => 0]);

        $this->putJson('/api/servers/apiact01', ['active' => 1], $this->apiHeaders())
            ->assertStatus(200);

        $this->assertDatabaseHas('pricings', ['service_id' => 'apiact01', 'active' => 1]);
    }

    public function test_import_handles_bom_and_padded_headers()
    {
        $csv = tempnam(sys_get_temp_dir(), 'imp');
        // BOM before the first header + trailing space on another.
        file_put_contents($csv,
            "\xEF\xBB\xBFCOMPANY,LOCATION ,HOSTNAME,RAM,VCPU,SSD DISK,HDD DISK,BANDWIDTH,PERIOD,COST,CURRENCY,Renews,Cancelled\n" .
            "BomCo,Test Location,bomtest01.invalid,4 GB,2,80 GB,,10TB,1M,\$5.00,USD,12/01/26,\n");

        $this->artisan('import:servers', ['file' => $csv])->assertExitCode(0);
        unlink($csv);

        $this->assertDatabaseHas('servers', ['hostname' => 'bomtest01.invalid']);
    }
}
