<?php

namespace Tests\Feature;

use App\Models\Disk;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\LabelsAssigned;
use App\Models\Locations;
use App\Models\Note;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The API server endpoints must accept everything the web forms accept:
 * the columns added since v4 (link_speed, network_type, cpu_model,
 * disk_media), nameservers on update, and the labels/IP assignments —
 * and their write sequences must be as atomic as the web paths.
 */
class ApiServerParityTest extends TestCase
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
        Labels::create(['id' => 'lblparA1', 'label' => 'parity-a']);
        Labels::create(['id' => 'lblparB2', 'label' => 'parity-b']);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function apiServerPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'parity.example.com', 'server_type' => 1,
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
        Disk::insertDisk($id, 50, 'GB', 'SSD');

        return Server::create([
            'id' => $id, 'hostname' => "host-$id.example.com", 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);
    }

    public function test_store_persists_all_web_parity_fields()
    {
        $this->postJson('/api/servers', $this->apiServerPayload([
            'ns1' => 'ns1.example.com', 'ns2' => 'ns2.example.com',
            'network_type' => 'IPv4+IPv6', 'cpu_model' => 'EPYC 7402P',
            'link_speed' => 2, 'link_speed_type' => 'Gbps',
            'disk_media' => 'NVMe',
            'labels' => ['lblparA1', 'lblparB2'],
            'ip1' => '10.1.1.1', 'ip2' => '2001:db8::1',
        ]), $this->apiHeaders())->assertStatus(200);

        $server = Server::where('hostname', 'parity.example.com')->firstOrFail();
        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'ns1' => 'ns1.example.com', 'ns2' => 'ns2.example.com',
            'network_type' => 'IPv4+IPv6', 'cpu_model' => 'EPYC 7402P',
            'link_speed' => 2000, // Gbps converted on the way in
        ]);
        $this->assertDatabaseHas('server_disks', ['server_id' => $server->id, 'disk_media' => 'NVMe']);
        $this->assertDatabaseHas('labels_assigned', ['service_id' => $server->id, 'label_id' => 'lblparA1']);
        $this->assertDatabaseHas('labels_assigned', ['service_id' => $server->id, 'label_id' => 'lblparB2']);
        $this->assertDatabaseHas('ips', ['service_id' => $server->id, 'address' => '10.1.1.1']);
        $this->assertDatabaseHas('ips', ['service_id' => $server->id, 'address' => '2001:db8::1']);
    }

    public function test_store_rejects_out_of_domain_parity_fields()
    {
        foreach ([
            ['link_speed' => 100], // unit-less speed would silently store as Mbps
            ['link_speed' => 1, 'link_speed_type' => 'Tbps'],
            ['disk_media' => 'Floppy'],
            ['network_type' => 'IPv5'],
            ['labels' => ['no-such-label']],
            ['labels' => ['lblparA1', 'lblparA1']], // duplicates
            ['labels' => ['a', 'b', 'c', 'd', 'e']], // web forms cap at 4
        ] as $bad) {
            $this->postJson('/api/servers', $this->apiServerPayload($bad), $this->apiHeaders())
                ->assertStatus(400);
        }

        $this->assertDatabaseMissing('servers', ['hostname' => 'parity.example.com']);
    }

    public function test_update_changes_parity_fields_and_replaces_labels()
    {
        $this->makeServer('parupd01');
        LabelsAssigned::create(['label_id' => 'lblparA1', 'service_id' => 'parupd01']);

        $this->putJson('/api/servers/parupd01', [
            'ns1' => 'ns-new.example.com',
            'network_type' => 'IPv4 NAT',
            'cpu_model' => 'Ryzen 9 7950X',
            'link_speed' => 500, 'link_speed_type' => 'Mbps',
            'disk_media' => 'HDD',
            'labels' => ['lblparB2'],
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('servers', [
            'id' => 'parupd01', 'ns1' => 'ns-new.example.com',
            'network_type' => 'IPv4 NAT', 'cpu_model' => 'Ryzen 9 7950X', 'link_speed' => 500,
        ]);
        $this->assertDatabaseHas('server_disks', ['server_id' => 'parupd01', 'disk_media' => 'HDD']);
        $this->assertDatabaseMissing('labels_assigned', ['service_id' => 'parupd01', 'label_id' => 'lblparA1']);
        $this->assertDatabaseHas('labels_assigned', ['service_id' => 'parupd01', 'label_id' => 'lblparB2']);
    }

    public function test_update_syncs_ips_preserving_unchanged_rows()
    {
        $this->makeServer('paripsy1');
        $kept = IPs::insertIP('paripsy1', '10.2.2.2');
        IPs::insertIP('paripsy1', '10.3.3.3');

        $this->putJson('/api/servers/paripsy1', [
            'ips' => ['10.2.2.2', '10.4.4.4'],
        ], $this->apiHeaders())->assertStatus(200);

        // Unchanged address keeps its row (id, whois, notes survive)
        $this->assertDatabaseHas('ips', ['id' => $kept->id, 'address' => '10.2.2.2']);
        $this->assertDatabaseMissing('ips', ['service_id' => 'paripsy1', 'address' => '10.3.3.3']);
        $this->assertDatabaseHas('ips', ['service_id' => 'paripsy1', 'address' => '10.4.4.4']);

        // Bad address in the set: reject, don't partially sync
        $this->putJson('/api/servers/paripsy1', [
            'ips' => ['10.2.2.2', 'not-an-ip'],
        ], $this->apiHeaders())->assertStatus(400);
        $this->assertSame(2, IPs::where('service_id', 'paripsy1')->count());
    }

    public function test_partial_update_leaves_labels_and_ips_untouched()
    {
        $this->makeServer('parpart1');
        LabelsAssigned::create(['label_id' => 'lblparA1', 'service_id' => 'parpart1']);
        IPs::insertIP('parpart1', '10.5.5.5');

        // labels-only PUT: no servers-column write at all must still succeed
        $this->putJson('/api/servers/parpart1', ['labels' => ['lblparB2']], $this->apiHeaders())
            ->assertStatus(200);
        $this->assertDatabaseHas('ips', ['service_id' => 'parpart1', 'address' => '10.5.5.5']);

        $this->putJson('/api/servers/parpart1', ['ram' => 4096], $this->apiHeaders())
            ->assertStatus(200);

        $this->assertDatabaseHas('servers', ['id' => 'parpart1', 'ram' => 4096, 'ram_as_mb' => 4096]);
        $this->assertDatabaseHas('labels_assigned', ['service_id' => 'parpart1', 'label_id' => 'lblparB2']);
        $this->assertDatabaseHas('ips', ['service_id' => 'parpart1', 'address' => '10.5.5.5']);
    }

    public function test_update_clears_fields_with_nulls_and_empty_arrays()
    {
        $server = $this->makeServer('parclr01');
        $server->update(['ns1' => 'old-ns.example.com', 'link_speed' => 1000]);
        LabelsAssigned::create(['label_id' => 'lblparA1', 'service_id' => 'parclr01']);
        IPs::insertIP('parclr01', '10.6.6.6');

        $this->putJson('/api/servers/parclr01', [
            'ns1' => null, 'link_speed' => null, 'labels' => [], 'ips' => [],
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertDatabaseHas('servers', ['id' => 'parclr01', 'ns1' => null, 'link_speed' => null]);
        $this->assertDatabaseMissing('labels_assigned', ['service_id' => 'parclr01']);
        $this->assertDatabaseMissing('ips', ['service_id' => 'parclr01']);
    }

    public function test_api_destroy_rolls_back_when_a_child_delete_fails()
    {
        $this->makeServer('pardel01');
        LabelsAssigned::create(['label_id' => 'lblparA1', 'service_id' => 'pardel01']);
        Note::create(['id' => 'parnote1', 'service_id' => 'pardel01', 'note' => 'still here']);

        // Fail on the LAST cleanup step (query-builder delete — model events
        // can't hook it), exactly like the web DestroyAtomicityTest.
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete') && str_contains($query->sql, 'notes')) {
                throw new \RuntimeException('injected delete failure');
            }
        });

        $this->deleteJson('/api/servers/pardel01', [], $this->apiHeaders())->assertStatus(500);

        $this->assertDatabaseHas('servers', ['id' => 'pardel01']);
        $this->assertDatabaseHas('pricings', ['service_id' => 'pardel01']);
        $this->assertDatabaseHas('labels_assigned', ['service_id' => 'pardel01']);
        $this->assertDatabaseHas('notes', ['service_id' => 'pardel01']);
    }

    public function test_api_update_rolls_back_as_one_transaction()
    {
        $this->makeServer('parupat1');

        // Label sync runs after the server-row update inside the same
        // transaction — failing it must roll the hostname change back too.
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'labels_assigned')) {
                throw new \RuntimeException('injected insert failure');
            }
        });

        $this->putJson('/api/servers/parupat1', [
            'hostname' => 'rolled-back.example.com',
            'labels' => ['lblparA1'],
        ], $this->apiHeaders())->assertStatus(500);

        $this->assertDatabaseHas('servers', ['id' => 'parupat1', 'hostname' => 'host-parupat1.example.com']);
    }
}
