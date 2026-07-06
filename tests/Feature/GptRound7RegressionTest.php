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
 * Regressions for the 2026-07 GPT review (7th batch): EUR/GBP 1:1
 * fallback conversion, and API-supplied _as_ columns contradicting
 * their source fields.
 */
class GptRound7RegressionTest extends TestCase
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

    public function test_eur_is_rejected_when_no_rate_exists()
    {
        // The USD/EUR/GBP fallback list let EUR 10.00 be stored as as_usd
        // 10.00 whenever rates were unavailable — same 1:1 corruption, one
        // list over. With no rate data only USD is convertible.
        $this->actingAs($this->user)->post(route('servers.store'), [
            'hostname' => 'gpt7.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2048, 'ram_type' => 'MB',
            'disk' => [50], 'disk_type' => ['GB'], 'disk_media' => ['SSD'],
            'cpu' => 2, 'bandwidth' => 1000, 'ssh_port' => 22, 'was_promo' => 0,
            'currency' => 'EUR', 'price' => 10.00, 'payment_term' => 1,
        ])->assertSessionHasErrors('currency');

        $this->assertSame(0, Pricing::count());
        $this->assertContains('USD', Pricing::getCurrencyList());
        $this->assertNotContains('EUR', Pricing::getCurrencyList());
    }

    public function test_api_cannot_supply_contradictory_as_columns()
    {
        Pricing::create([
            'service_id' => 'ascols01', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'ascols01', 'hostname' => 'ascols.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId,
            'location_id' => $this->locationId, 'ram' => 2048, 'ram_type' => 'MB',
            'ram_as_mb' => 2048, 'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50, 'cpu' => 2,
        ]);

        // ram=8 GB with a contradictory ram_as_mb=1: the supplied value is
        // ignored and the column derived from the source fields.
        $this->putJson('/api/servers/ascols01', [
            'ram' => 8, 'ram_type' => 'GB', 'ram_as_mb' => 1,
            'disk' => 1, 'disk_type' => 'TB', 'disk_as_gb' => 1,
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('servers', [
            'id' => 'ascols01', 'ram_as_mb' => 8192, 'disk_as_gb' => 1024,
        ]);
    }

    public function test_api_store_no_longer_requires_derived_columns()
    {
        // ram_as_mb/disk_as_gb were required inputs that storeServer then
        // ignored and derived anyway; they're no longer part of the contract.
        $this->postJson('/api/servers', [
            'hostname' => 'noderive.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ssh_port' => 22, 'ram' => 4, 'ram_type' => 'GB',
            'disk' => 2, 'disk_type' => 'TB',
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0, 'active' => 1, 'show_public' => 0,
            'owned_since' => '2024-01-01', 'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('servers', [
            'hostname' => 'noderive.example.com', 'ram_as_mb' => 4096, 'disk_as_gb' => 2048,
        ]);
    }
}
