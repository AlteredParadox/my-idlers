<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Services\ExportTransformer;
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the round-29 review findings: null due-date badge,
 * one-time term in exports, GB6 on yabs surfaces, 128+ thread ingest.
 */
class Round29RegressionTest extends TestCase
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

    private function makeServer(string $id, ?string $nextDueDate): Server
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => $nextDueDate === null ? 7 : 1,
            'as_usd' => 5.00, 'usd_per_month' => $nextDueDate === null ? 0 : 5.00,
            'next_due_date' => $nextDueDate,
        ]);

        return Server::create([
            'id' => $id, 'hostname' => "host-$id.example.com", 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId,
            'location_id' => $this->locationId, 'ram' => 2048, 'ram_type' => 'MB',
            'ram_as_mb' => 2048, 'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'active' => 1,
        ]);
    }

    public function test_cards_view_shows_no_due_badge_for_one_time_services()
    {
        // Carbon::parse(null) is "now": every null-due-date server showed a
        // permanent red "Due today" alarm on the cards index.
        Settings::where('id', 1)->update(['servers_index_cards' => 1]);
        Cache::forget('settings');
        $this->makeServer('onetime1', null);

        $this->actingAs($this->user)->get(route('servers.index'))
            ->assertOk()
            ->assertDontSee('Due today');
    }

    public function test_exports_label_one_time_term()
    {
        $this->assertSame('One time', (new ExportTransformer())->getTermName(7));
    }

    public function test_yabs_json_includes_gb6_scores_and_ingest_accepts_128_threads()
    {
        $server = $this->makeServer('gb6json1', now()->addMonth()->format('Y-m-d'));

        $ok = app(YabsIngestService::class)->ingest([
            'version' => 'v', 'time' => '20260705-120000',
            'os' => ['arch' => 'x86_64', 'distro' => 'Ubuntu', 'kernel' => '5.15', 'uptime' => '1 day'],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            // 128 threads: signed TINYINT capped at 127 and MySQL strict
            // rejected the whole ingest as "not valid YABS".
            'cpu' => ['model' => 'EPYC', 'cores' => 128, 'freq' => '2400', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 2048000, 'swap' => 0, 'disk' => 41943040],
            'geekbench' => [['version' => 6, 'single' => 1500, 'multi' => 9500, 'url' => 'https://browser.geekbench.com/v6/cpu/42']],
            'fio' => [
                ['bs' => '4k', 'speed_rw' => 150000], ['bs' => '64k', 'speed_rw' => 500000],
                ['bs' => '512k', 'speed_rw' => 800000], ['bs' => '1m', 'speed_rw' => 900000],
            ],
            'iperf' => [['mode' => 'IPv4', 'loc' => 'London', 'send' => '1.00 Gbits/sec', 'recv' => '1.00 Gbits/sec']],
        ], $server->id);

        $this->assertTrue($ok, 'ingest failed (cpu_cores range?)');
        $this->assertDatabaseHas('yabs', ['server_id' => 'gb6json1', 'cpu_cores' => 128]);

        // The yabs JSON export omitted GB6 entirely.
        $yabs = \App\Models\Yabs::where('server_id', 'gb6json1')->first();
        $json = $this->actingAs($this->user)->get(route('yabs.json', $yabs->id))->assertOk();
        $json->assertJsonPath('cpu.GB6_single', 1500);
        $json->assertJsonPath('cpu.GB6_multi', 9500);
    }
}
