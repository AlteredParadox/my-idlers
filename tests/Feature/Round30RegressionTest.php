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
 * Regressions for the round-30 review findings: modern yabs.sh output
 * with auto-skipped fio/iperf, GB6 on the compare pages, numeric
 * uptime, bit-rate labels.
 */
class Round30RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $providerId;
    private int $locationId;
    private int $osId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->providerId = Providers::create(['name' => 'P'])->id;
        $this->locationId = Locations::create(['name' => 'L'])->id;
        $this->osId = OS::create(['name' => 'Ubuntu 22.04'])->id;
        Settings::create(['id' => 1]);
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
            'location_id' => $this->locationId, 'ram' => 2048, 'ram_type' => 'MB',
            'ram_as_mb' => 2048, 'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'active' => 1,
        ]);
    }

    /** Modern yabs.sh run: fio auto-skipped (<2GB free), iperf download failed, GB6 only, numeric uptime. */
    private function modernPayload(): array
    {
        return [
            'version' => 'v2025-04-20', 'time' => '20260705-120000',
            'os' => ['arch' => 'x86_64', 'distro' => 'Ubuntu', 'kernel' => '5.15', 'uptime' => 93784.55],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'CPU', 'cores' => 2, 'freq' => '2400', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 2048000, 'swap' => 0, 'disk' => 41943040],
            'geekbench' => [['version' => 6, 'single' => 1600, 'multi' => 5000, 'url' => 'https://browser.geekbench.com/v6/cpu/7']],
            // no 'fio' key, no 'iperf' key — emitted only when the tests ran
        ];
    }

    public function test_ingest_accepts_output_with_skipped_fio_and_iperf()
    {
        // yabs.sh omits fio (<2GB free disk) and iperf (binary download
        // failure) automatically; the ingest treated the missing keys as
        // "not valid YABS" and the API rules 422ed the complete output.
        $server = $this->makeServer('nofio001');

        $ok = app(YabsIngestService::class)->ingest($this->modernPayload(), $server->id);

        $this->assertTrue($ok);
        $yabs = Yabs::where('server_id', 'nofio001')->first();
        $this->assertNotNull($yabs);
        $this->assertNull($yabs->disk_speed); // no bogus all-null row
        // numeric uptime humanized, not stored raw
        $this->assertSame('1 days, 2 hours, 3 minutes', $yabs->uptime);

        // The pages that render disk speeds must survive the missing row.
        $this->actingAs($this->user)->get(route('yabs.index'))->assertOk();
        $this->actingAs($this->user)->get(route('yabs.show', $yabs->id))->assertOk();
        $this->actingAs($this->user)->get(route('servers.show', 'nofio001'))->assertOk();
        $this->actingAs($this->user)->get(route('yabs.json', $yabs->id))->assertOk();
    }

    public function test_compare_pages_fall_back_to_gb6_scores()
    {
        $s1 = $this->makeServer('gb6cmp01');
        $s2 = $this->makeServer('gb6cmp02');
        app(YabsIngestService::class)->ingest($this->modernPayload(), $s1->id);
        app(YabsIngestService::class)->ingest($this->modernPayload(), $s2->id);

        // Round 29 fixed index/show/JSON; the two compare pages still showed
        // every benchmark row as em-dashes for GB6-only runs.
        $this->actingAs($this->user)
            ->get(route('servers.compare', ['server1' => $s1->id, 'server2' => $s2->id]))
            ->assertOk()
            ->assertSee('1600 (v6)');

        $y1 = Yabs::where('server_id', $s1->id)->first();
        $y2 = Yabs::where('server_id', $s2->id)->first();
        $this->actingAs($this->user)
            ->get(route('yabs.compare', ['yabs1' => $y1->id, 'yabs2' => $y2->id]))
            ->assertOk()
            ->assertSee('1600 (v6)');
    }

    public function test_network_speed_labels_are_bit_rates()
    {
        $service = new YabsIngestService();

        // iperf reports bits; the old labels claimed bytes (8x mislabel).
        $this->assertSame('Gbps', $service->speedType('9.42 Gbits/sec'));
        $this->assertSame('Mbps', $service->speedType('940 Mbits/sec'));
    }
}
