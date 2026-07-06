<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\Shared;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 GPT review (6th batch): unrated ISO
 * currencies still converting 1:1, and unbounded capacity fields.
 */
class GptRound6RegressionTest extends TestCase
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

    private function webServerPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'gpt6.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2048, 'ram_type' => 'MB',
            'disk' => [50], 'disk_type' => ['GB'], 'disk_media' => ['SSD'],
            'cpu' => 2, 'bandwidth' => 1000, 'ssh_port' => 22, 'was_promo' => 0,
            'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ], $overrides);
    }

    public function test_valid_iso_but_unrated_currency_is_rejected()
    {
        // JPY is valid ISO but has no rate in this environment (no exchange
        // API configured): storing it would convert 1:1 as USD — the exact
        // corruption the currency rule exists to stop. Only USD is
        // convertible here (GPT round 7 dropped the EUR/GBP fallbacks).
        $this->actingAs($this->user)->post(route('servers.store'), $this->webServerPayload([
            'currency' => 'JPY',
        ]))->assertSessionHasErrors('currency');

        $this->assertSame(0, Pricing::count());
    }

    public function test_capacity_fields_reject_negative_and_absurd_values()
    {
        foreach ([
            ['ram', -2048], ['cpu', 0], ['cpu', 5000],
            ['bandwidth', -1], ['ssh_port', 0], ['ssh_port', 70000],
            ['disk', [-50]], ['link_speed', -10],
        ] as [$field, $value]) {
            $this->actingAs($this->user)->post(route('servers.store'), $this->webServerPayload([
                $field => $value,
            ]))->assertSessionHasErrors($field === 'disk' ? 'disk.0' : $field);
        }

        $this->assertSame(0, Server::count());
        $this->assertSame(0, Pricing::count());
    }

    public function test_api_capacity_fields_reject_out_of_range_values()
    {
        $base = [
            'hostname' => 'api6.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ssh_port' => 22, 'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0, 'active' => 1, 'show_public' => 0,
            'owned_since' => '2024-01-01', 'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ];
        $headers = ['Authorization' => 'Bearer ' . $this->token];

        // disk_as_gb is no longer accepted input (GPT round 7: always derived),
        // so the negative-disk case moved to the source field.
        foreach ([['ram', -1], ['disk', -50], ['cpu', 0], ['ssh_port', 99999], ['bandwidth', -5]] as [$field, $value]) {
            $this->postJson('/api/servers', array_merge($base, [$field => $value]), $headers)
                ->assertStatus(400);
        }

        $this->assertSame(0, Server::count());
    }

    public function test_shared_quotas_reject_negative_values()
    {
        $this->actingAs($this->user)->post(route('shared.store'), [
            'domain' => 'quota.example.com', 'shared_type' => 'cPanel',
            'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'price' => 5.00, 'currency' => 'USD', 'payment_term' => 1,
            'disk' => -50, 'domains' => -1, 'email' => -3,
        ])->assertSessionHasErrors(['disk', 'domains', 'email']);

        $this->assertSame(0, Shared::count());
    }

    public function test_sane_boundary_values_still_accepted()
    {
        // 1-core NAT box on port 65535 with zero bandwidth allowance.
        $this->actingAs($this->user)->post(route('servers.store'), $this->webServerPayload([
            'cpu' => 1, 'ssh_port' => 65535, 'bandwidth' => 0,
        ]))->assertRedirect(route('servers.index'));

        $this->assertDatabaseHas('servers', ['hostname' => 'gpt6.example.com', 'cpu' => 1, 'ssh' => 65535]);
    }
}
