<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Models\Yabs;
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for the round-31 review finding: comparing a mixed GB5+GB6
 * run against a GB6-only run must display and diff the SAME version's
 * scores (the GB6 pair), not show GB5 values beside a GB6-derived diff.
 */
class Round31RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Providers::create(['name' => 'P']);
        Locations::create(['name' => 'L']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function makeServerWithYabs(string $id, array $geekbench): Server
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        $server = Server::create([
            'id' => $id, 'hostname' => "host-$id.example.com", 'server_type' => 1,
            'os_id' => OS::first()->id, 'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id, 'ram' => 2048, 'ram_type' => 'MB',
            'ram_as_mb' => 2048, 'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'active' => 1,
        ]);

        app(YabsIngestService::class)->ingest([
            'version' => 'v', 'time' => '20260705-120000',
            'os' => ['arch' => 'x86_64', 'distro' => 'Ubuntu', 'kernel' => '5.15', 'uptime' => '1 day'],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'CPU', 'cores' => 2, 'freq' => '2400', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 2048000, 'swap' => 0, 'disk' => 41943040],
            'geekbench' => $geekbench,
            'fio' => [
                ['bs' => '4k', 'speed_rw' => 150000], ['bs' => '64k', 'speed_rw' => 500000],
                ['bs' => '512k', 'speed_rw' => 800000], ['bs' => '1m', 'speed_rw' => 900000],
            ],
            'iperf' => [['mode' => 'IPv4', 'loc' => 'X', 'send' => '1.00 Gbits/sec', 'recv' => '1.00 Gbits/sec']],
        ], $server->id);

        return $server;
    }

    public function test_mixed_gb5_gb6_vs_gb6_only_compares_the_same_version()
    {
        // s1 ran BOTH GB5 and GB6; s2 is GB6-only. The only common version is
        // GB6, so both displayed scores must be the GB6 pair.
        $s1 = $this->makeServerWithYabs('mixgb001', [
            ['version' => 5, 'single' => 950, 'multi' => 3000, 'url' => 'https://browser.geekbench.com/v5/cpu/1'],
            ['version' => 6, 'single' => 1400, 'multi' => 4200, 'url' => 'https://browser.geekbench.com/v6/cpu/2'],
        ]);
        $s2 = $this->makeServerWithYabs('gb6only1', [
            ['version' => 6, 'single' => 1800, 'multi' => 5400, 'url' => 'https://browser.geekbench.com/v6/cpu/3'],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('servers.compare', ['server1' => $s1->id, 'server2' => $s2->id]))
            ->assertOk();

        // Both sides show the GB6 pair (tagged), never the unpaired GB5 950.
        $response->assertSee('1400 (v6)');
        $response->assertSee('1800 (v6)');
        $response->assertDontSee('>950<', false);

        $y1 = Yabs::where('server_id', $s1->id)->first();
        $y2 = Yabs::where('server_id', $s2->id)->first();
        $this->actingAs($this->user)
            ->get(route('yabs.compare', ['yabs1' => $y1->id, 'yabs2' => $y2->id]))
            ->assertOk()
            ->assertSee('1400 (v6)');
    }
}
