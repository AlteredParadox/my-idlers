<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Providers;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 external code review findings:
 * API field mapping on server create, 404s for missing records,
 * shared-hosting db_limit persistence.
 */
class ApiRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        Providers::create(['name' => 'Test Provider']);
        Locations::create(['name' => 'Test Location']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_api_server_create_persists_ssh_active_and_explicit_show_public_zero()
    {
        $response = $this->postJson('/api/servers', [
            'hostname' => 'api-created.example.com',
            'server_type' => 1,
            'os_id' => \App\Models\OS::first()->id,
            'provider_id' => \App\Models\Providers::first()->id,
            'location_id' => \App\Models\Locations::first()->id,
            'ssh_port' => 2222,
            'ram' => 2048,
            'ram_type' => 'MB',
            'ram_as_mb' => 2048,
            'disk' => 50,
            'disk_type' => 'GB',
            'disk_as_gb' => 50,
            'cpu' => 2,
            'bandwidth' => 1000,
            'was_promo' => 0,
            'transferrable' => 0,
            'active' => 1,
            'show_public' => 0,
            'owned_since' => '2024-01-01',
            'currency' => 'USD',
            'price' => 5.00,
            'payment_term' => 1,
        ], $this->apiHeaders());

        $response->assertStatus(200);

        $this->assertDatabaseHas('servers', [
            'hostname' => 'api-created.example.com',
            'ssh' => 2222,
            'active' => 1,
            'show_public' => 0, // was saved as 1 pre-fix because isset() ignored the value
        ]);
    }

    public function test_api_server_create_without_hostname_is_rejected_and_leaves_no_orphans()
    {
        $this->postJson('/api/servers', [
            'server_type' => 1,
            'os_id' => \App\Models\OS::first()->id,
            'provider_id' => \App\Models\Providers::first()->id,
            'location_id' => \App\Models\Locations::first()->id,
            'ssh_port' => 22,
            'ram' => 2048,
            'ram_type' => 'MB',
            'ram_as_mb' => 2048,
            'disk' => 50,
            'disk_type' => 'GB',
            'disk_as_gb' => 50,
            'cpu' => 2,
            'bandwidth' => 1000,
            'was_promo' => 0,
            'active' => 1,
            'show_public' => 0,
            'owned_since' => '2024-01-01',
            'currency' => 'USD',
            'price' => 5.00,
            'payment_term' => 1,
        ], $this->apiHeaders())->assertStatus(400);

        // hostname used to be optional: pricing/IP rows were written before
        // Server::create failed on the non-null hostname column
        $this->assertSame(0, \App\Models\Pricing::count());
        $this->assertSame(0, \App\Models\Server::count());
    }

    public function test_currency_list_has_fallback_when_rates_unavailable()
    {
        // EXCHANGE_RATES_URL is blank in the test environment
        $currencies = \App\Models\Pricing::getCurrencyList();

        $this->assertNotEmpty($currencies);
        $this->assertContains('USD', $currencies);
    }

    public function test_api_update_pricing_missing_id_returns_404_not_500()
    {
        // A missing id used to make ->update() return 0 changed rows -> the
        // controller reported failure (500); it should be 404.
        $this->putJson('/api/pricing/99999', [
            'price' => 5.00, 'currency' => 'USD', 'term' => 1,
        ], $this->apiHeaders())->assertStatus(404);
    }

    public function test_api_update_pricing_with_unchanged_values_returns_200()
    {
        // MySQL-meaningful (the SQLite suite can't catch this): re-saving the
        // SAME values yields 0 CHANGED rows under MySQL, which the old code
        // reported as a 500. Must be 200.
        $pricing = \App\Models\Pricing::create([
            'service_id' => 'srv00001', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
        ]);

        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 5.00, 'currency' => 'USD', 'term' => 1,
        ], $this->apiHeaders())->assertStatus(200);
    }

    public function test_api_missing_records_return_404_not_500()
    {
        foreach (['servers', 'shared', 'reseller', 'seedbox', 'domains', 'misc', 'yabs'] as $resource) {
            $this->getJson("/api/{$resource}/nonexistent1", $this->apiHeaders())
                ->assertStatus(404);
        }
    }

    public function test_shared_hosting_create_persists_db_limit()
    {
        $this->actingAs(User::factory()->create())->post(route('shared.store'), [
            'domain' => 'shared.example.com',
            'shared_type' => 'cPanel',
            'provider_id' => \App\Models\Providers::first()->id,
            'location_id' => \App\Models\Locations::first()->id,
            'disk' => 10,
            'disk_type' => 'GB',
            'bandwidth' => 100,
            'domains' => 5,
            'sub_domains' => 10,
            'email' => 20,
            'ftp' => 5,
            'db' => 7,
            'was_promo' => 0,
            'currency' => 'USD',
            'price' => 3.00,
            'payment_term' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('shared_hosting', [
            'main_domain' => 'shared.example.com',
            'db_limit' => 7, // create used to write 'db__limit' and silently drop it
        ]);
    }
}
