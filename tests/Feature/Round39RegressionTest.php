<?php

namespace Tests\Feature;

use App\Models\IPs;
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
 * Review round 39 (post UI/API-parity feature block): findings verified
 * against MySQL where the ips table's utf8mb4 collation is case-INsensitive
 * — IPv6 case-variants that pass the case-sensitive different:/distinct
 * rules hit the (service_id, address) unique index as a 500, and a
 * case-variant "update" of an unchanged address wiped its whois/notes.
 * Also: hostname had no max (MySQL strict 500 past 255), ram*1024 could
 * overflow the int ram_as_mb column, ips[] was unbounded, and PUT silently
 * ignored ip1/ip2 from reused POST payloads.
 */
class Round39RegressionTest extends TestCase
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
            'hostname' => 'r39.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ssh_port' => 22, 'ram' => 2048, 'ram_type' => 'MB',
            'disk' => 50, 'disk_type' => 'GB',
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
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);
    }

    public function test_over_length_hostname_is_a_400_not_a_mysql_500()
    {
        $long = str_repeat('a', 300) . '.example.com';
        $this->postJson('/api/servers', $this->apiServerPayload(['hostname' => $long]), $this->apiHeaders())
            ->assertStatus(400);

        $this->makeServer('r39host1');
        $this->putJson('/api/servers/r39host1', ['hostname' => $long], $this->apiHeaders())
            ->assertStatus(400);
    }

    public function test_ipv6_case_variant_duplicates_are_rejected_not_500()
    {
        // The unique index is case-insensitive; case-sensitive rules missed these
        $this->postJson('/api/servers', $this->apiServerPayload([
            'ip1' => '2001:db8::1', 'ip2' => '2001:DB8::1',
        ]), $this->apiHeaders())->assertStatus(400);

        $this->makeServer('r39ipci1');
        $this->putJson('/api/servers/r39ipci1', [
            'ips' => ['2001:db8::1', '2001:DB8::1'],
        ], $this->apiHeaders())->assertStatus(400);

        // Web store: same pair through the form path (explicit web guard —
        // the postJson calls above leave the api TokenGuard resolved)
        $this->actingAs($this->user, 'web')->post(route('servers.store'), [
            'hostname' => 'r39web.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2, 'ram_type' => 'GB', 'disk' => [50], 'disk_type' => ['GB'], 'disk_media' => ['SSD'],
            'cpu' => 2, 'was_promo' => 0, 'owned_since' => '2024-01-01',
            'currency' => 'USD', 'price' => '5.00', 'payment_term' => 1, 'next_due_date' => '2027-01-01',
            'ip1' => '2001:db8::1', 'ip2' => '2001:DB8::1',
        ])->assertSessionHasErrors(['ip2']);
    }

    public function test_case_variant_of_unchanged_address_keeps_its_row()
    {
        $this->makeServer('r39keep1');
        $kept = IPs::insertIP('r39keep1', '2001:db8::a');

        // Same address, different spelling: must be recognized as unchanged
        $this->putJson('/api/servers/r39keep1', ['ips' => ['2001:DB8::A']], $this->apiHeaders())
            ->assertStatus(200);

        $this->assertDatabaseHas('ips', ['id' => $kept->id]);
        $this->assertSame(1, IPs::where('service_id', 'r39keep1')->count());
    }

    public function test_addresses_are_stored_lowercase()
    {
        $ip = IPs::insertIP('r39lc001', '2001:DB8::BEEF');
        $this->assertSame('2001:db8::beef', $ip->address);
    }

    public function test_put_rejects_ip1_ip2_instead_of_silently_ignoring()
    {
        $this->makeServer('r39prohi');
        $this->putJson('/api/servers/r39prohi', ['ip1' => '10.9.9.9'], $this->apiHeaders())
            ->assertStatus(400);
        $this->assertDatabaseMissing('ips', ['service_id' => 'r39prohi']);
    }

    public function test_ips_array_is_bounded_and_ram_cap_protects_ram_as_mb()
    {
        $this->makeServer('r39bound');

        $tooMany = array_map(fn($i) => '10.0.' . intdiv($i, 250) . '.' . ($i % 250 + 1), range(0, 64));
        $this->putJson('/api/servers/r39bound', ['ips' => $tooMany], $this->apiHeaders())
            ->assertStatus(400);

        // 1e8 GB * 1024 overflowed the int ram_as_mb column on MySQL
        $this->putJson('/api/servers/r39bound', ['ram' => 100000000, 'ram_type' => 'GB'], $this->apiHeaders())
            ->assertStatus(400);
        $this->postJson('/api/servers', $this->apiServerPayload(['ram' => 100000000, 'ram_type' => 'GB']), $this->apiHeaders())
            ->assertStatus(400);
    }
}
