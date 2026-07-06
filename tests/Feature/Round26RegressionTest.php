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
use App\Models\Shared;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the round-26 review findings: validation ordering,
 * exists parity across types, API _as_ column derivation, orphan-safe
 * blades, yabs ordering, multi-disk preservation.
 */
class Round26RegressionTest extends TestCase
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
            'location_id' => Locations::first()->id, 'ram' => 2048, 'ram_type' => 'MB',
            'ram_as_mb' => 2048, 'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50, 'cpu' => 2,
        ]);
    }

    public function test_shared_update_with_invalid_extra_ip_persists_nothing()
    {
        // The ipN validation used to run AFTER $shared->update()/pricing/labels:
        // a bad IP left the DB changed with every cache forget skipped.
        $this->makePricing('shvalord', 2);
        $shared = Shared::create(['id' => 'shvalord', 'main_domain' => 'before.example.com', 'shared_type' => 'cPanel']);

        $this->actingAs($this->user)->put(route('shared.update', $shared), [
            'domain' => 'after.example.com',
            'shared_type' => 'cPanel',
            'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id,
            'price' => 99.00, 'currency' => 'USD', 'payment_term' => 1,
            'disk' => 50, 'domains' => 1, 'sub_domains' => 1, 'bandwidth' => 100,
            'email' => 1, 'ftp' => 1, 'db' => 1, 'was_promo' => 0,
            'ip2' => 'not-an-ip',
        ])->assertSessionHasErrors('ip2');

        $this->assertDatabaseHas('shared_hosting', ['id' => 'shvalord', 'main_domain' => 'before.example.com']);
        $this->assertDatabaseHas('pricings', ['service_id' => 'shvalord', 'price' => 5.00]);
    }

    public function test_dangling_provider_rejected_across_types()
    {
        // Round 25 added exists: to servers only; the same stale-form
        // scenario 500s the shared/reseller/seedbox/domain index pages.
        $this->actingAs($this->user)->post(route('shared.store'), [
            'domain' => 'dangling.example.com', 'shared_type' => 'cPanel',
            'provider_id' => 999, 'location_id' => 999,
            'price' => 5.00, 'currency' => 'USD', 'payment_term' => 1,
        ])->assertSessionHasErrors(['provider_id', 'location_id']);

        $this->actingAs($this->user)->post(route('domains.store'), [
            'domain' => 'dangling', 'extension' => 'com',
            'provider_id' => 999, 'payment_term' => 4,
            'price' => 10.00, 'currency' => 'USD',
        ])->assertSessionHasErrors('provider_id');
    }

    public function test_api_partial_ram_update_recomputes_ram_as_mb()
    {
        // ram_as_mb drives the index/public RAM display; a partial update
        // left it stale forever.
        $this->makeServer('ramupd01');

        $this->putJson('/api/servers/ramupd01', ['ram' => 8, 'ram_type' => 'GB'], $this->apiHeaders())
            ->assertStatus(200);

        $this->assertDatabaseHas('servers', ['id' => 'ramupd01', 'ram_as_mb' => 8192]);
    }

    public function test_api_disk_update_leaves_multi_disk_servers_intact()
    {
        // The parity rewrite collapsed multi-disk servers to one 'SSD' row.
        $this->makeServer('multidsk');
        Disk::insertDisk('multidsk', 500, 'GB', 'HDD');
        Disk::insertDisk('multidsk', 1, 'TB', 'HDD');

        $this->putJson('/api/servers/multidsk', ['disk' => 100], $this->apiHeaders())
            ->assertStatus(200);

        $this->assertSame(2, Disk::where('server_id', 'multidsk')->count());
        $this->assertSame(2, Disk::where('server_id', 'multidsk')->where('disk_media', 'HDD')->count());
        // servers.disk_as_gb still derived
        $this->assertDatabaseHas('servers', ['id' => 'multidsk', 'disk_as_gb' => 100]);
    }

    public function test_inactive_orphan_server_does_not_500_index_or_cards_or_show()
    {
        $this->makePricing('orphinac', 1);
        Server::create([
            'id' => 'orphinac', 'hostname' => 'orphan-inactive.example.com', 'server_type' => 1,
            'os_id' => 999, 'provider_id' => 999, 'location_id' => 999,
            'ram' => 2048, 'disk' => 50, 'cpu' => 2, 'active' => 0,
        ]);
        Cache::flush();

        $this->actingAs($this->user)->get(route('servers.index'))->assertOk();
        $this->actingAs($this->user)->get(route('servers.show', 'orphinac'))->assertOk();

        Settings::where('id', 1)->update(['servers_index_cards' => 1]);
        Cache::forget('settings');
        $this->actingAs($this->user)->get(route('servers.index'))->assertOk();
    }

    public function test_yabs_relation_returns_newest_first()
    {
        $server = $this->makeServer('yabsord1');

        foreach ([['old00001', '2025-01-01 00:00:00'], ['new00001', '2026-06-01 00:00:00']] as [$id, $date]) {
            DB::table('yabs')->insert([
                'id' => $id, 'server_id' => 'yabsord1', 'has_ipv6' => 0,
                'aes' => 1, 'vm' => 1, 'output_date' => $date, 'cpu_cores' => 2,
                'cpu_freq' => 2400, 'cpu_model' => 'test', 'ram' => 4, 'ram_type' => 'GB',
                'ram_mb' => 4096, 'disk' => 50, 'disk_type' => 'GB', 'disk_gb' => 50,
                'gb5_single' => 100, 'gb5_multi' => 200, 'gb5_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame('new00001', $server->fresh()->yabs()->first()->id);
    }
}
