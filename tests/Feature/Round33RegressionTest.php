<?php

namespace Tests\Feature;

use App\Models\Domains;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Settings;
use App\Models\Shared;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression for the round-33 review finding: legacy rows with dangling
 * provider/location ids (pre-guard databases, upstream migrations) must
 * not 500 the index/show/edit pages of ANY service type — round 25 only
 * covered servers.
 */
class Round33RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Providers::create(['name' => 'P']);
        Locations::create(['name' => 'L']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function makePricing(string $id, int $type): void
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => $type, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
    }

    public function test_dangling_provider_location_ids_do_not_500_any_service_pages()
    {
        // No FK guards these columns; DBs predating the destroy guards (or
        // migrated from upstream) can hold rows pointing at deleted rows.
        $this->makePricing('orphshr1', 2);
        Shared::create(['id' => 'orphshr1', 'main_domain' => 'orphan-shared.example.com', 'shared_type' => 'cPanel', 'provider_id' => 999, 'location_id' => 999]);

        $this->makePricing('orphres1', 3);
        Reseller::create(['id' => 'orphres1', 'main_domain' => 'orphan-reseller.example.com', 'reseller_type' => 'cPanel', 'provider_id' => 999, 'location_id' => 999]);

        $this->makePricing('orphsbx1', 6);
        SeedBoxes::create(['id' => 'orphsbx1', 'title' => 'Orphan SB', 'provider_id' => 999, 'location_id' => 999]);

        $this->makePricing('orphdom1', 4);
        Domains::create(['id' => 'orphdom1', 'domain' => 'orphandom', 'extension' => 'com', 'provider_id' => 999]);

        $routes = [
            route('shared.index'), route('shared.show', 'orphshr1'), route('shared.edit', 'orphshr1'),
            route('reseller.index'), route('reseller.show', 'orphres1'), route('reseller.edit', 'orphres1'),
            route('seedboxes.index'), route('seedboxes.show', 'orphsbx1'), route('seedboxes.edit', 'orphsbx1'),
            route('domains.index'), route('domains.show', 'orphdom1'), route('domains.edit', 'orphdom1'),
        ];

        foreach ($routes as $url) {
            $this->actingAs($this->user)->get($url)->assertOk();
        }
    }

    public function test_yabs_json_survives_server_with_dangling_relations()
    {
        $this->makePricing('orphyab1', 1);
        Server::create([
            'id' => 'orphyab1', 'hostname' => 'orphan-yabs.example.com', 'server_type' => 1,
            'os_id' => 999, 'provider_id' => 999, 'location_id' => 999,
            'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);
        \DB::table('yabs')->insert([
            'id' => 'orphyabs', 'server_id' => 'orphyab1', 'has_ipv6' => 0,
            'aes' => 1, 'vm' => 1, 'output_date' => '2026-06-01 00:00:00', 'cpu_cores' => 2,
            'cpu_freq' => 2400, 'cpu_model' => 'test', 'ram' => 4, 'ram_type' => 'GB',
            'ram_mb' => 4096, 'disk' => 50, 'disk_type' => 'GB', 'disk_gb' => 50,
            'gb5_single' => 100, 'gb5_multi' => 200, 'gb5_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->get(route('yabs.json', 'orphyabs'))->assertOk();
    }
}
