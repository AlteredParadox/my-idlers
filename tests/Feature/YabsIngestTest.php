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
}
