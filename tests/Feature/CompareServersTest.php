<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompareServersTest extends TestCase
{
    use RefreshDatabase;

    private int $providerId;
    private int $locationId;
    private int $osId;

    protected function setUp(): void
    {
        parent::setUp();
        // Capture real ids — MySQL auto-increment doesn't reset on rollback, so
        // hardcoding 1 points at a non-existent row across the full suite.
        $this->providerId = Providers::create(['name' => 'P'])->id;
        $this->locationId = Locations::create(['name' => 'L'])->id;
        $this->osId = OS::create(['name' => 'Ubuntu 22.04'])->id;
        Settings::create(['id' => 1]);
    }

    /** Create a server with a lifetime (term 7 -> usd_per_month 0) price and a full YABS run. */
    private function makeLifetimeServerWithYabs(string $hostname): string
    {
        $server_id = Str::random(8);
        // term 7 == one-time / lifetime; costAsPerMonth returns 0
        (new Pricing)->insertPricing(1, $server_id, 'USD', 15.00, 7, null);

        Server::create([
            'id' => $server_id, 'hostname' => $hostname, 'server_type' => 1, 'os_id' => $this->osId,
            'provider_id' => $this->providerId, 'location_id' => $this->locationId, 'ram' => 2, 'ram_type' => 'GB', 'ram_as_mb' => 2048,
            'disk' => 40, 'disk_type' => 'GB', 'disk_as_gb' => 40, 'cpu' => 2, 'has_yabs' => 0,
            'was_promo' => 0, 'active' => 1, 'show_public' => 0, 'bandwidth' => 1000, 'owned_since' => '2024-01-01',
        ]);

        app(YabsIngestService::class)->ingest([
            'version' => 'v', 'time' => '20260705-120000',
            'os' => ['arch' => 'x86_64', 'distro' => 'Ubuntu', 'kernel' => '5.15', 'uptime' => '1 day'],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'CPU', 'cores' => 2, 'freq' => '2400', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 2048000, 'swap' => 0, 'disk' => 41943040],
            'geekbench' => [['version' => 5, 'single' => 900, 'multi' => 3000, 'url' => 'https://browser.geekbench.com/v5/cpu/1']],
            'fio' => [
                ['bs' => '4k', 'speed_rw' => 150000], ['bs' => '64k', 'speed_rw' => 500000],
                ['bs' => '512k', 'speed_rw' => 800000], ['bs' => '1m', 'speed_rw' => 900000],
            ],
            'iperf' => [['mode' => 'IPv4', 'loc' => 'London', 'send' => '1.00 Gbits/sec', 'recv' => '1.00 Gbits/sec']],
        ], $server_id);

        return $server_id;
    }

    public function test_compare_page_does_not_divide_by_zero_for_lifetime_servers()
    {
        $s1 = $this->makeLifetimeServerWithYabs('a.example.com');
        $s2 = $this->makeLifetimeServerWithYabs('b.example.com');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.compare', ['server1' => $s1, 'server2' => $s2]));

        // Before the fix, the per-USD rows threw DivisionByZeroError (500)
        $response->assertStatus(200);
        $response->assertViewIs('servers.compare');
    }
}
