<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: Price/yr (USD) was rendered as usd_per_month * 12, but
 * usd_per_month is rounded to cents (decimal(10,2)), so the rounding error
 * was scaled back up — a 44.46/yr server displayed as 44.52
 * (round(44.46 / 12) = 3.71, * 12). The yearly figure must come from
 * as_usd and the term directly.
 */
class YearlyPriceRoundingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Settings::create(['id' => 1]);
    }

    protected function createAnnualServer(): Server
    {
        // Stored exactly as insertPricing writes an annual 44.46 USD service:
        // usd_per_month already carries the cents rounding (3.71, not 3.705).
        $pricing = new Pricing();
        $pricing->insertPricing(1, 'yrprice1', 'USD', 44.46, 4, now()->addYear()->format('Y-m-d'));

        return Server::create([
            'id' => 'yrprice1',
            'hostname' => 'yearly-price.test',
            'server_type' => 1,
            'os_id' => OS::create(['name' => 'Ubuntu 22.04'])->id,
            'provider_id' => Providers::create(['name' => 'Test Provider'])->id,
            'location_id' => Locations::create(['name' => 'Test Location'])->id,
            'ram' => 2048,
            'disk' => 50,
            'cpu' => 2,
        ]);
    }

    public function test_servers_index_shows_exact_yearly_price(): void
    {
        $this->createAnnualServer();

        $response = $this->actingAs($this->user)->get(route('servers.index'));

        $response->assertOk();
        $response->assertSee('$44.46');
        $response->assertDontSee('$44.52');
    }

    public function test_home_total_cost_yearly_uses_exact_yearly_price(): void
    {
        $this->createAnnualServer();

        $response = $this->actingAs($this->user)->get(route('/'));

        $response->assertOk();
        $response->assertSee('44.46');
        $response->assertDontSee('44.52');
    }
}
