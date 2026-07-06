<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Locations;
use App\Models\Note;
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
 * Regressions for the round-24 review findings: IP diff-sync, resource
 * route completeness, API duplicate-IP / pricing-update handling, IP
 * service integrity, char-column enum validation.
 */
class Round24RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        $this->user = User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        Providers::create(['name' => 'Test Provider']);
        Locations::create(['name' => 'Test Location']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
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
            'os_id' => OS::first()->id, 'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id, 'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);
    }

    public function test_ip_sync_diff_preserves_unchanged_rows_and_their_notes()
    {
        // Replace-on-any-difference wiped whois data and notes from IPs that
        // were NOT being edited; the sync must diff per-address.
        $this->makeServer('ipdiff01');
        IPs::insertIP('ipdiff01', '192.0.2.1');
        $keeper = IPs::insertIP('ipdiff01', '192.0.2.2');
        Note::create(['id' => Str::random(8), 'service_id' => $keeper->id, 'note' => 'keep me']);

        // Edit only the first address.
        IPs::syncForService('ipdiff01', ['192.0.2.99', '192.0.2.2']);

        $this->assertDatabaseHas('ips', ['id' => $keeper->id, 'address' => '192.0.2.2']);
        $this->assertDatabaseHas('notes', ['service_id' => $keeper->id]);
        $this->assertDatabaseMissing('ips', ['service_id' => 'ipdiff01', 'address' => '192.0.2.1']);
        $this->assertDatabaseHas('ips', ['service_id' => 'ipdiff01', 'address' => '192.0.2.99']);
    }

    public function test_unimplemented_resource_routes_are_not_500()
    {
        $ip = IPs::insertIP('routesrv', '192.0.2.30');

        foreach (["/IPs/{$ip->id}", "/IPs/{$ip->id}/edit", '/os/1', '/locations/1/edit', '/providers/1/edit', '/yabs/zzzzzzz1/edit'] as $url) {
            $status = $this->actingAs($this->user)->get($url)->status();
            $this->assertContains($status, [404, 405], "$url returned $status");
        }
    }

    public function test_api_server_store_rejects_duplicate_ips_with_400()
    {
        $this->postJson('/api/servers', [
            'hostname' => 'dup-api.example.com', 'server_type' => 1,
            'os_id' => 1, 'provider_id' => 1, 'location_id' => 1,
            'ssh_port' => 22, 'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'bandwidth' => 1000, 'was_promo' => 0,
            'active' => 1, 'show_public' => 0, 'owned_since' => '2024-01-01',
            'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
            'ip1' => '192.0.2.40', 'ip2' => '192.0.2.40',
        ], $this->apiHeaders())->assertStatus(400);

        $this->assertSame(0, Server::count());
        $this->assertSame(0, Pricing::count());
    }

    public function test_api_server_update_applies_pricing_fields()
    {
        // currency/price/payment_term were validated then silently discarded:
        // the API returned success while the price stayed unchanged.
        $server = $this->makeServer('apiprice');

        $this->putJson("/api/servers/{$server->id}", [
            'price' => 9.99, 'currency' => 'EUR', 'payment_term' => 4,
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('pricings', [
            'service_id' => 'apiprice', 'price' => 9.99, 'currency' => 'EUR', 'term' => 4,
        ]);
    }

    public function test_web_ip_store_validates_service_exists()
    {
        // A ghost service id created an orphan IP row; a >8-char id hit MySQL
        // strict truncation surfaced as the misleading 'already assigned'.
        $this->actingAs($this->user)->post(route('IPs.store'), [
            'address' => '192.0.2.50', 'ip_type' => 'ipv4', 'service_id' => 'zzzzzzz2',
        ])->assertSessionHasErrors('service_id');

        $this->actingAs($this->user)->post(route('IPs.store'), [
            'address' => '192.0.2.50', 'ip_type' => 'ipv4', 'service_id' => 'waytoolongid1',
        ])->assertSessionHasErrors('service_id');

        $this->assertSame(0, IPs::count());
    }

    public function test_server_store_validates_ram_and_disk_enums()
    {
        $payload = [
            'hostname' => 'enum-test.example.com', 'server_type' => 1,
            'os_id' => OS::first()->id, 'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id,
            'ram' => 2048, 'ram_type' => 'XX',
            'disk' => [50], 'disk_type' => ['GB'], 'disk_media' => ['FLOPPY'],
            'cpu' => 2, 'bandwidth' => 1000, 'ssh_port' => 22, 'was_promo' => 0,
            'currency' => 'USD', 'price' => 5.00, 'payment_term' => 1,
        ];

        $this->actingAs($this->user)->post(route('servers.store'), $payload)
            ->assertSessionHasErrors(['ram_type', 'disk_media.0']);

        $this->assertSame(0, Pricing::count());
    }
}
