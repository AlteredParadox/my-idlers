<?php

namespace Tests\Feature;

use App\Models\Disk;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 79: the round-78 as_usd overflow seal had a hole on partial
 * PUT /api/servers/{id}. PriceFitsStorableUsd only sees request data, but
 * applyPricingFields merges the missing half of (price, currency) from the
 * locked pricing row: a price-only PUT gave the rule no currency (silent
 * return) and a currency-only PUT never ran the rule at all (it hangs off
 * price) — the merged derivation then wrote unchecked: MySQL 1264 500,
 * SQLite silently out-of-spec. The merged pair is now re-checked at the
 * merge point, failing in the endpoint's native 400 shape and rolling the
 * whole transaction back.
 */
class Round79RegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        Settings::create(['id' => 1]);
        Cache::put('currency_rates', (object) ['USD' => 1.0, 'GBP' => 0.79], now()->addDay());
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function makeServer(string $id, string $currency, float $price): void
    {
        $as_usd = Pricing::usdEquivalent($price, $currency);
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => $currency,
            'price' => $price, 'term' => 1, 'as_usd' => $as_usd, 'usd_per_month' => $as_usd,
            'next_due_date' => now()->addMonth()->format('Y-m-d'), 'active' => 1,
        ]);
        Disk::insertDisk($id, 50, 'GB', 'SSD');
        Server::create([
            'id' => $id, 'hostname' => "host-$id.example.com", 'server_type' => 1,
            'os_id' => OS::create(['name' => "os-$id"])->id,
            'provider_id' => Providers::create(['name' => "p-$id"])->id,
            'location_id' => Locations::create(['name' => "l-$id"])->id,
            'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0, 'owned_since' => '2024-01-01',
            'ssh' => 22, 'active' => 1, 'show_public' => 0,
        ]);
    }

    public function test_price_only_partial_put_cannot_overflow_via_the_rows_currency()
    {
        $this->makeServer('r79gbp01', 'GBP', 5.00);

        $response = $this->withHeaders($this->apiHeaders())
            ->putJson('/api/servers/r79gbp01', ['price' => 99999999]);

        $response->assertStatus(400);
        $response->assertJsonPath('result', 'fail');
        $response->assertJsonPath('messages.price.0', 'The price exceeds the maximum storable USD equivalent.');

        $row = Pricing::where('service_id', 'r79gbp01')->first();
        $this->assertSame(5.00, (float) $row->price, 'the transaction must roll back wholesale');
    }

    public function test_currency_only_partial_put_cannot_overflow_via_the_rows_price()
    {
        // Priced at the cap in USD (valid); switching the currency alone
        // would push the merged derivation past the column ceiling.
        $this->makeServer('r79usd01', 'USD', 99999999);

        $response = $this->withHeaders($this->apiHeaders())
            ->putJson('/api/servers/r79usd01', ['currency' => 'GBP']);

        $response->assertStatus(400);
        $response->assertJsonPath('result', 'fail');

        $row = Pricing::where('service_id', 'r79usd01')->first();
        $this->assertSame('USD', $row->currency);
        $this->assertSame(99999999.0, (float) $row->as_usd);
    }

    public function test_fitting_partial_put_still_updates_the_merged_pricing()
    {
        $this->makeServer('r79gbp02', 'GBP', 5.00);

        $this->withHeaders($this->apiHeaders())
            ->putJson('/api/servers/r79gbp02', ['price' => 10.55])
            ->assertStatus(200);

        $row = Pricing::where('service_id', 'r79gbp02')->first();
        $this->assertSame(10.55, (float) $row->price);
        $this->assertSame('GBP', $row->currency);
        $this->assertSame(13.35, (float) $row->as_usd); // round(10.55 / 0.79, 2)
    }
}
