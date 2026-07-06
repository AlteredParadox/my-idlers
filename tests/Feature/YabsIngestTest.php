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
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class YabsIngestTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(?string $cpu_model = null): Server
    {
        $provider = Providers::create(['name' => 'Test Provider']);
        $location = Locations::create(['name' => 'Test Location']);
        $os = OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);

        $server_id = Str::random(8);
        (new Pricing)->insertPricing(1, $server_id, 'USD', 5.00, 1, '2027-01-01');

        $server = Server::create([
            'id' => $server_id,
            'hostname' => 'yabs-test.example.com',
            'server_type' => 1,
            'os_id' => $os->id,
            'provider_id' => $provider->id,
            'location_id' => $location->id,
            'ram' => 4,
            'ram_type' => 'GB',
            'ram_as_mb' => 4096,
            'disk' => 50,
            'disk_type' => 'GB',
            'disk_as_gb' => 1074, // 50 GB + 1 TB across two disks
            'cpu' => 4,
            'cpu_model' => $cpu_model,
            'has_yabs' => 0,
            'was_promo' => 0,
            'active' => 1,
            'show_public' => 0,
            'bandwidth' => 1000,
            'owned_since' => '2024-01-01',
        ]);

        Disk::insertDisk($server_id, 50, 'GB', 'NVMe');
        Disk::insertDisk($server_id, 1, 'TB', 'HDD');

        return $server;
    }

    private function yabsPayload(): array
    {
        return [
            'version' => 'v2024-06-09',
            'time' => '20260705-120000',
            'os' => [
                'arch' => 'x86_64',
                'distro' => 'Ubuntu 22.04.4 LTS',
                'kernel' => '5.15.0-105-generic',
                'uptime' => '5 days, 4 hours',
            ],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => [
                'model' => 'AMD EPYC 7642 48-Core Processor',
                'cores' => 4,
                'freq' => '2299.998',
                'aes' => 1,
                'virt' => 'KVM',
            ],
            // YABS reports usable amounts in KB: ~3.8 GB RAM, ~47 GB root fs
            'mem' => ['ram' => 4014080, 'swap' => 524288, 'disk' => 49283072],
            'geekbench' => [
                ['version' => 6, 'single' => 1500, 'multi' => 4500, 'url' => 'https://browser.geekbench.com/v6/cpu/123456'],
            ],
            'fio' => [
                ['bs' => '4k', 'speed_rw' => 150000],
                ['bs' => '64k', 'speed_rw' => 500000],
                ['bs' => '512k', 'speed_rw' => 800000],
                ['bs' => '1m', 'speed_rw' => 900000],
            ],
            'iperf' => [
                ['mode' => 'IPv4', 'loc' => 'Clouvider | London, UK', 'send' => '9.42 Gbits/sec', 'recv' => '9.53 Gbits/sec'],
            ],
        ];
    }

    private function postYabs(Server $server): \Illuminate\Testing\TestResponse
    {
        $url = URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $server->id]);

        return $this->postJson($url, $this->yabsPayload());
    }

    public function test_yabs_ingest_stores_benchmark_and_speed_results()
    {
        $server = $this->makeServer();

        $this->postYabs($server)->assertStatus(200);

        $this->assertDatabaseHas('yabs', [
            'server_id' => $server->id,
            'cpu_model' => 'AMD EPYC 7642 48-Core Processor',
            'cpu_cores' => 4,
            'gb6_single' => 1500,
            'gb6_multi' => 4500,
        ]);
        $this->assertDatabaseHas('disk_speed', ['server_id' => $server->id]);
        $this->assertDatabaseHas('network_speed', [
            'server_id' => $server->id,
            'send_type' => 'GBps',
        ]);
    }

    public function test_yabs_ingest_does_not_overwrite_user_entered_specs()
    {
        $server = $this->makeServer();

        $this->postYabs($server)->assertStatus(200);

        // User-entered values survive; YABS-measured usable RAM (~3.8 GB) and
        // root-fs size (~47 GB) must not replace them. server_disks untouched.
        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'ram' => 4,
            'ram_type' => 'GB',
            'ram_as_mb' => 4096,
            'disk' => 50,
            'disk_type' => 'GB',
            'disk_as_gb' => 1074,
            'cpu' => 4,
            'has_yabs' => 1,
        ]);
        $this->assertSame(2, Disk::where('server_id', $server->id)->count());
    }

    public function test_yabs_ingest_fills_cpu_model_only_when_unset()
    {
        $blank = $this->makeServer();
        $this->postYabs($blank)->assertStatus(200);
        $this->assertDatabaseHas('servers', [
            'id' => $blank->id,
            'cpu_model' => 'AMD EPYC 7642 48-Core Processor',
        ]);
    }

    public function test_yabs_ingest_preserves_existing_cpu_model()
    {
        $server = $this->makeServer('Intel Xeon E5-2680 v4');
        $this->postYabs($server)->assertStatus(200);
        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'cpu_model' => 'Intel Xeon E5-2680 v4',
        ]);
    }

    public function test_guests_cannot_paste_yabs_json()
    {
        $server = $this->makeServer();

        $this->post(route('yabs.store'), [
            'server_id' => $server->id,
            'yabs_json' => json_encode($this->yabsPayload()),
        ])->assertRedirect(route('login'));
    }

    public function test_yabs_can_be_added_by_pasting_json()
    {
        $server = $this->makeServer();

        $response = $this->actingAs(User::factory()->create())->post(route('yabs.store'), [
            'server_id' => $server->id,
            'yabs_json' => json_encode($this->yabsPayload()),
        ]);

        $response->assertRedirect(route('yabs.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('yabs', [
            'server_id' => $server->id,
            'cpu_model' => 'AMD EPYC 7642 48-Core Processor',
        ]);
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'has_yabs' => 1]);
    }

    public function test_pasting_invalid_json_is_rejected()
    {
        $server = $this->makeServer();

        $response = $this->actingAs(User::factory()->create())->post(route('yabs.store'), [
            'server_id' => $server->id,
            'yabs_json' => 'this is not json',
        ]);

        $response->assertSessionHasErrors('yabs_json');
        $this->assertDatabaseMissing('yabs', ['server_id' => $server->id]);
    }

    public function test_pasting_incomplete_yabs_json_is_rejected()
    {
        $server = $this->makeServer();

        $response = $this->actingAs(User::factory()->create())->post(route('yabs.store'), [
            'server_id' => $server->id,
            'yabs_json' => json_encode(['time' => '20260705-120000']),
        ]);

        $response->assertSessionHasErrors('yabs_json');
        $this->assertDatabaseMissing('yabs', ['server_id' => $server->id]);
    }

    public function test_ingest_stores_vm_as_integer_flag_not_the_virt_string()
    {
        $server = $this->makeServer();
        // payload cpu.virt is 'KVM'; vm is a boolean column, so it must become 1.
        // The raw string broke every ingest on MySQL (integer column rejects it).
        app(\App\Services\YabsIngestService::class)->ingest($this->yabsPayload(), $server->id);

        $this->assertDatabaseHas('yabs', ['server_id' => $server->id, 'vm' => 1]);
    }

    public function test_normal_vps_disk_is_stored_in_gb_not_fractional_tb()
    {
        $server = $this->makeServer();
        $payload = $this->yabsPayload();
        $payload['mem']['disk'] = 200 * 1048576; // 200 GB in KB

        app(\App\Services\YabsIngestService::class)->ingest($payload, $server->id);

        // Before the fix, a ~200 GB disk crossed the (too-low) TB threshold
        $this->assertDatabaseHas('yabs', ['server_id' => $server->id, 'disk_type' => 'GB']);
    }

    public function test_ingest_invalidates_the_public_server_cache()
    {
        $server = $this->makeServer();
        \Illuminate\Support\Facades\Cache::put('public_server_data', 'stale');

        app(\App\Services\YabsIngestService::class)->ingest($this->yabsPayload(), $server->id);

        // The public servers page renders YABS data; adding a YABS must clear it
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('public_server_data'));
    }

    public function test_deleting_a_yabs_invalidates_the_server_cache()
    {
        $server = $this->makeServer();
        app(\App\Services\YabsIngestService::class)->ingest($this->yabsPayload(), $server->id);
        $yab = \App\Models\Yabs::where('server_id', $server->id)->firstOrFail();

        \Illuminate\Support\Facades\Cache::put("server.{$server->id}", 'stale');

        $this->actingAs(User::factory()->create())->delete(route('yabs.destroy', $yab));

        // Server caches embed the yabs relation and must be cleared on delete
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has("server.{$server->id}"));
    }

    public function test_deleting_a_yabs_removes_its_disk_and_network_speed_rows()
    {
        $server = $this->makeServer();
        app(\App\Services\YabsIngestService::class)->ingest($this->yabsPayload(), $server->id);
        $yab = \App\Models\Yabs::where('server_id', $server->id)->firstOrFail();

        $this->actingAs(User::factory()->create())->delete(route('yabs.destroy', $yab));

        $this->assertDatabaseMissing('disk_speed', ['id' => $yab->id]);
        $this->assertDatabaseMissing('network_speed', ['id' => $yab->id]);
    }

    public function test_deleting_a_server_removes_its_yabs_and_speed_rows()
    {
        $server = $this->makeServer();
        app(\App\Services\YabsIngestService::class)->ingest($this->yabsPayload(), $server->id);
        $yab = \App\Models\Yabs::where('server_id', $server->id)->firstOrFail();

        $this->actingAs(User::factory()->create())->delete(route('servers.destroy', $server->id));

        // Server delete used to orphan the yabs + disk_speed + network_speed rows
        $this->assertDatabaseMissing('yabs', ['server_id' => $server->id]);
        $this->assertDatabaseMissing('disk_speed', ['id' => $yab->id]);
        $this->assertDatabaseMissing('network_speed', ['id' => $yab->id]);
    }
}
