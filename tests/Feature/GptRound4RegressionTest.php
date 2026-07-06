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
 * Regressions for the 2026-07 GPT review (4th batch): closed-set enum and
 * boolean fields must reject out-of-domain values — active=2 rows are
 * neither active nor inactive to the exact where('active', 1|0) queries,
 * and server_type=999 silently renders as SEMI-DEDI.
 */
class GptRound4RegressionTest extends TestCase
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

    private function apiServerPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'enum4.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ssh_port' => 22, 'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0, 'active' => 1, 'show_public' => 0,
            'owned_since' => '2024-01-01', 'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ], $overrides);
    }

    private function makeServer(string $id): Server
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        return Server::create([
            'id' => $id, 'hostname' => "host-$id.example.com", 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId,
            'location_id' => $this->locationId, 'ram' => 2048, 'disk' => 50, 'cpu' => 2, 'active' => 1,
        ]);
    }

    public function test_api_create_rejects_out_of_domain_booleans_and_type()
    {
        foreach ([['active', 2], ['show_public', 2], ['was_promo', 5], ['server_type', 999]] as [$field, $value]) {
            $this->postJson('/api/servers', $this->apiServerPayload([$field => $value]), $this->apiHeaders())
                ->assertStatus(400);
        }

        $this->assertSame(0, Server::count());
        $this->assertSame(0, Pricing::count());
    }

    public function test_api_update_rejects_out_of_domain_booleans_and_type()
    {
        $this->makeServer('enumupd1');

        foreach ([['active', 2], ['show_public', 2], ['transferrable', 3], ['server_type', 999]] as [$field, $value]) {
            $this->putJson('/api/servers/enumupd1', [$field => $value], $this->apiHeaders())
                ->assertStatus(400);
        }

        // The record's real state is untouched.
        $this->assertDatabaseHas('servers', ['id' => 'enumupd1', 'active' => 1, 'server_type' => 1]);
    }

    public function test_api_pricing_update_rejects_out_of_domain_active()
    {
        $pricing = Pricing::create([
            'service_id' => 'enumprc1', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 5.00, 'currency' => 'USD', 'term' => 1, 'active' => 2,
        ], $this->apiHeaders())->assertStatus(400);

        $this->assertDatabaseHas('pricings', ['id' => $pricing->id, 'active' => 1]);
    }

    public function test_web_store_rejects_bad_server_type_and_was_promo()
    {
        $this->actingAs($this->user)->post(route('servers.store'), [
            'hostname' => 'web4.example.com', 'server_type' => 999,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2048, 'ram_type' => 'MB',
            'disk' => [50], 'disk_type' => ['GB'], 'disk_media' => ['SSD'],
            'cpu' => 2, 'bandwidth' => 1000, 'ssh_port' => 22, 'was_promo' => 5,
            'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ])->assertSessionHasErrors(['server_type', 'was_promo']);

        $this->assertSame(0, Server::count());
    }

    public function test_valid_boundary_values_still_accepted()
    {
        // Type 7 (NAT) and the 0/1 booleans at both bounds.
        $this->postJson('/api/servers', $this->apiServerPayload([
            'server_type' => 7, 'was_promo' => 1, 'active' => 0, 'show_public' => 1, 'transferrable' => 0,
        ]), $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('servers', ['hostname' => 'enum4.example.com', 'server_type' => 7, 'active' => 0]);
    }
}
