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
 * Regressions for the 2026-07 GPT review (3rd batch): mismatched disk
 * arrays and out-of-range payment terms.
 */
class GptRound3RegressionTest extends TestCase
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

    private function serverPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'gpt3.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2048, 'ram_type' => 'MB',
            'disk' => [50], 'disk_type' => ['GB'], 'disk_media' => ['SSD'],
            'cpu' => 2, 'bandwidth' => 1000, 'ssh_port' => 22, 'was_promo' => 0,
            'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ], $overrides);
    }

    public function test_mismatched_disk_arrays_are_rejected_before_any_write()
    {
        // Two disks but one media — the insert loop would 500 on the missing
        // index, after server/pricing/labels were written.
        $this->actingAs($this->user)->post(route('servers.store'), $this->serverPayload([
            'disk' => [50, 100], 'disk_type' => ['GB', 'GB'], 'disk_media' => ['SSD'],
        ]))->assertSessionHasErrors('disk');

        $this->assertSame(0, Pricing::count());
        $this->assertSame(0, Server::count());
    }

    public function test_out_of_range_payment_term_is_rejected_web_and_api()
    {
        $this->actingAs($this->user)->post(route('servers.store'), $this->serverPayload([
            'payment_term' => 99,
        ]))->assertSessionHasErrors('payment_term');

        $this->postJson('/api/servers', [
            'hostname' => 'api.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ssh_port' => 22, 'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0, 'active' => 1, 'show_public' => 0,
            'owned_since' => '2024-01-01', 'currency' => 'USD', 'price' => 5.00, 'payment_term' => 99,
        ], ['Authorization' => 'Bearer ' . $this->token])->assertStatus(400);

        $this->assertSame(0, Server::count());
    }

    public function test_unknown_term_does_not_advance_due_date_62_months()
    {
        // Defence in depth: a legacy row with an out-of-range term must not
        // have its due date advanced by the old 62-month default.
        $this->assertSame(0, (new Pricing())->termAsMonths(99));
    }

    public function test_valid_terms_still_accepted()
    {
        $this->actingAs($this->user)->post(route('servers.store'), $this->serverPayload([
            'payment_term' => 7,
        ]))->assertRedirect(route('servers.index'));

        $this->assertDatabaseHas('pricings', ['service_id' => Server::first()->id, 'term' => 7]);
    }
}
