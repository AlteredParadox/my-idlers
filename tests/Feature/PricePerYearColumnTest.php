<?php

namespace Tests\Feature;

use App\Models\Domains;
use App\Models\Pricing;
use App\Models\Server;
use App\Models\Shared;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The index tables show a standardized Price/yr (USD) column derived from
 * pricings.usd_per_month * 12 — comparable across currencies and terms.
 */
class PricePerYearColumnTest extends TestCase
{
    use RefreshDatabase;

    private function pricing(string $id, int $type, float $usd_per_month): void
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => $type, 'currency' => 'USD',
            'price' => $usd_per_month, 'term' => 1, 'as_usd' => $usd_per_month,
            'usd_per_month' => $usd_per_month,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
    }

    public function test_servers_index_shows_price_per_year()
    {
        $user = User::factory()->create();
        $this->pricing('ppysrv01', 1, 5.00);
        Server::create([
            'id' => 'ppysrv01', 'hostname' => 'ppy.example.com', 'server_type' => 1,
            'os_id' => 1, 'provider_id' => 1, 'location_id' => 1,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);

        $this->actingAs($user)->get('/servers')
            ->assertStatus(200)
            ->assertSee('Price/yr (USD)')
            ->assertSee('$60.00');
    }

    public function test_one_time_priced_services_show_dash_not_zero()
    {
        $user = User::factory()->create();
        // usd_per_month is deliberately 0 for one-time/lifetime terms — a
        // $200-lifetime box must not render (and sort) as a $0.00/yr service
        Pricing::create([
            'service_id' => 'ppyonce1', 'service_type' => 1, 'currency' => 'USD',
            'price' => 200.00, 'term' => 7, 'as_usd' => 200.00, 'usd_per_month' => 0,
            'next_due_date' => null,
        ]);
        Server::create([
            'id' => 'ppyonce1', 'hostname' => 'once.example.com', 'server_type' => 1,
            'os_id' => 1, 'provider_id' => 1, 'location_id' => 1,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);

        $this->actingAs($user)->get('/servers')
            ->assertStatus(200)
            ->assertDontSee('$0.00');
    }

    public function test_domains_and_shared_indexes_show_price_per_year()
    {
        $user = User::factory()->create();

        $this->pricing('ppydom01', 3, 1.25);
        Domains::create(['id' => 'ppydom01', 'domain' => 'ppy', 'extension' => 'com', 'active' => 1]);

        $this->pricing('ppyshr01', 2, 2.50);
        Shared::create(['id' => 'ppyshr01', 'main_domain' => 'ppy-shared.example.com', 'shared_type' => 'cPanel', 'active' => 1]);

        $this->actingAs($user)->get('/domains')
            ->assertStatus(200)->assertSee('Price/yr (USD)')->assertSee('$15.00');

        $this->actingAs($user)->get('/shared')
            ->assertStatus(200)->assertSee('Price/yr (USD)')->assertSee('$30.00');
    }
}
