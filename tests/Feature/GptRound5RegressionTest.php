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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 GPT review (5th batch): forged currency
 * codes converted 1:1 as USD, and negative prices feeding every total.
 */
class GptRound5RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private int $osId;
    private int $providerId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        $this->user = User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        $this->providerId = Providers::create(['name' => 'P'])->id;
        $this->locationId = Locations::create(['name' => 'L'])->id;
        $this->osId = OS::create(['name' => 'Ubuntu 22.04'])->id;
        Settings::create(['id' => 1]);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function webServerPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'gpt5.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2048, 'ram_type' => 'MB',
            'disk' => [50], 'disk_type' => ['GB'], 'disk_media' => ['SSD'],
            'cpu' => 2, 'bandwidth' => 1000, 'ssh_port' => 22, 'was_promo' => 0,
            'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ], $overrides);
    }

    private function makePricing(string $id): Pricing
    {
        return Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
    }

    public function test_forged_currency_codes_rejected_web_api_pricing_and_settings()
    {
        // 'ZZZ' passed size:3, then converted 1:1 as USD — corrupt totals.
        $this->actingAs($this->user)->post(route('servers.store'), $this->webServerPayload([
            'currency' => 'ZZZ',
        ]))->assertSessionHasErrors('currency');

        $this->postJson('/api/servers', [
            'hostname' => 'api5.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ssh_port' => 22, 'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0, 'active' => 1, 'show_public' => 0,
            'owned_since' => '2024-01-01', 'currency' => 'XXX', 'price' => 5.00, 'payment_term' => 1,
        ], $this->apiHeaders())->assertStatus(400);

        $pricing = $this->makePricing('cursrv01');
        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 5.00, 'currency' => 'ZZZ', 'term' => 1,
        ], $this->apiHeaders())->assertStatus(400);

        $this->assertSame(0, Server::count());
        $this->assertDatabaseHas('pricings', ['id' => $pricing->id, 'currency' => 'USD']);
    }

    public function test_settings_reject_forged_default_currency()
    {
        // Separate test: mixing API-guard and web-guard requests in one test
        // poisons the default guard (TokenGuard::viaRemember).
        $this->actingAs($this->user)->put(route('settings.update', 1), [
            'dark_mode' => 1, 'show_versions_footer' => 1, 'show_servers_public' => 0,
            'show_server_value_ip' => 1, 'show_server_value_hostname' => 1,
            'show_server_value_provider' => 1, 'show_server_value_location' => 1,
            'show_server_value_price' => 1, 'show_server_value_yabs' => 1,
            'default_currency' => 'ZZZ', 'default_server_os' => 1,
            'due_soon_amount' => 5, 'recently_added_amount' => 5,
            'dashboard_currency' => 'USD', 'sort_on' => 1,
            'servers_index_cards' => 1, 'default_per_page' => 25,
            'prometheus_enabled' => 0, 'prometheus_check_interval' => 20,
        ])->assertSessionHasErrors('default_currency');

        $this->assertDatabaseHas('settings', ['id' => 1, 'default_currency' => 'USD']);
    }

    public function test_negative_and_oversized_prices_rejected()
    {
        $this->actingAs($this->user)->post(route('servers.store'), $this->webServerPayload([
            'price' => -10,
        ]))->assertSessionHasErrors('price');

        $pricing = $this->makePricing('negsrv01');
        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => -10, 'currency' => 'USD', 'term' => 1,
        ], $this->apiHeaders())->assertStatus(400);

        // decimal(10,2) overflow guard (MySQL strict 500 otherwise).
        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 1e12, 'currency' => 'USD', 'term' => 1,
        ], $this->apiHeaders())->assertStatus(400);

        $this->assertDatabaseHas('pricings', ['id' => $pricing->id, 'price' => 5.00]);
        $this->assertSame(0, Server::count());
    }

    public function test_valid_non_usd_currency_and_zero_price_still_accepted()
    {
        // JPY isn't in the offline fallback rate list — validation must not
        // depend on the exchange-rate API being reachable. Zero price is
        // legitimate (free service).
        $this->actingAs($this->user)->post(route('servers.store'), $this->webServerPayload([
            'currency' => 'JPY', 'price' => 0,
        ]))->assertRedirect(route('servers.index'));

        $this->assertDatabaseHas('pricings', ['currency' => 'JPY', 'price' => 0.00]);
    }
}
