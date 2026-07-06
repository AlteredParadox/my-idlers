<?php

namespace Tests\Feature;

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
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the round-21 review findings: live settings gating the
 * public page, YABS ingest error handling, label validation and orphan
 * assignments, note cache staleness, IP sync on service edits.
 */
class Round21RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Providers::create(['name' => 'Test Provider']);
        Locations::create(['name' => 'Test Location']);
        OS::create(['name' => 'Ubuntu 22.04']);
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
            'id' => $id,
            'hostname' => "host-$id.example.com",
            'server_type' => 1,
            'os_id' => OS::first()->id,
            'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id,
            'ram' => 2048,
            'disk' => 50,
            'cpu' => 2,
        ]);
    }

    private function yabsPayload(): array
    {
        return [
            'version' => 'v2024-06-09',
            'time' => '20260705-120000',
            'os' => ['arch' => 'x86_64', 'distro' => 'Ubuntu 22.04', 'kernel' => '5.15.0', 'uptime' => '5 days'],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'AMD EPYC', 'cores' => 4, 'freq' => '2299.998', 'aes' => 1, 'virt' => 'KVM'],
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
                ['mode' => 'IPv4', 'loc' => 'Test | City', 'send' => '9.42 Gbits/sec', 'recv' => '9.53 Gbits/sec'],
            ],
        ];
    }

    public function test_public_servers_page_gates_on_live_settings_not_session_snapshot()
    {
        // Disabling the setting must lock out visitors whose session was
        // created while it was on (and vice versa) — the session copy is
        // written once per visitor and never re-synced.
        Settings::where('id', 1)->update(['show_servers_public' => 1]);
        Cache::forget('settings');
        $this->withSession(['dark_mode' => 1, 'show_servers_public' => 0])
            ->get('/servers/public')->assertOk();

        Settings::where('id', 1)->update(['show_servers_public' => 0]);
        Cache::forget('settings');
        $this->withSession(['dark_mode' => 1, 'show_servers_public' => 1])
            ->get('/servers/public')->assertNotFound();
    }

    public function test_yabs_ingest_returns_false_on_malformed_time_and_geekbench_url()
    {
        // The parse helpers raise Error/TypeError (not Exception) on these
        // inputs; catch(Exception) let them escape as 500s.
        $server = $this->makeServer('yabsbad1');
        $service = new YabsIngestService();

        $badTime = $this->yabsPayload();
        $badTime['time'] = 'garbage';
        $this->assertFalse($service->ingest($badTime, $server->id));

        $badUrl = $this->yabsPayload();
        $badUrl['geekbench'][0]['url'] = 'https://example.com/not-geekbench';
        $this->assertFalse($service->ingest($badUrl, $server->id));
    }

    public function test_duplicate_label_name_is_a_validation_error_not_500()
    {
        $this->actingAs($this->user)->post(route('labels.store'), ['label' => 'dup label'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)->post(route('labels.store'), ['label' => 'dup label'])
            ->assertSessionHasErrors('label');

        $this->assertSame(1, Labels::where('label', 'dup label')->count());
    }

    public function test_label_ids_must_exist_and_orphan_assignments_render_safely()
    {
        // Submitting a label id that was deleted in another tab must be a
        // validation error, not a silent orphan row.
        $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'orphan.example.com',
            'address' => '192.0.2.10',
            'dns_type' => 'A',
            'server_id' => 'null', 'shared_id' => 'null',
            'reseller_id' => 'null', 'domain_id' => 'null',
            'label1' => 'zzzzzzzz',
        ])->assertSessionHasErrors('label1');

        // Pre-existing orphan assignments must not 500 the show page.
        $server = $this->makeServer('orphlbl1');
        LabelsAssigned::create(['label_id' => 'gonelbl1', 'service_id' => $server->id]);

        $this->actingAs($this->user)->get(route('servers.show', $server))->assertOk();
    }

    public function test_server_cache_forget_clears_note_caches()
    {
        // note.$id / all_notes embed service relations (hostname etc.) for a
        // month; a service rename must invalidate them.
        Cache::put('note.ntesrv01', 'sentinel', 600);
        Cache::put('all_notes', 'sentinel', 600);

        Server::serverSpecificCacheForget('ntesrv01');

        $this->assertFalse(Cache::has('note.ntesrv01'));
        $this->assertFalse(Cache::has('all_notes'));
    }

    public function test_labels_edit_route_is_not_registered()
    {
        $label = Labels::create(['id' => 'lbledit1', 'label' => 'Some Label']);

        $this->actingAs($this->user)->get("/labels/{$label->id}/edit")->assertNotFound();
    }

    public function test_ip_sync_preserves_unchanged_rows_and_cleans_notes_on_replace()
    {
        $this->makeServer('ipsync01');
        $ip = IPs::insertIP('ipsync01', '192.0.2.1');

        // Unchanged address set: row (and its whois data / notes) must survive.
        IPs::syncForService('ipsync01', ['192.0.2.1']);
        $this->assertDatabaseHas('ips', ['id' => $ip->id, 'address' => '192.0.2.1']);

        // Changed set: old rows go, and notes attached to them must not
        // linger as ghost rows.
        Note::create(['id' => Str::random(8), 'service_id' => $ip->id, 'note' => 'on the ip']);
        IPs::syncForService('ipsync01', ['192.0.2.2']);

        $this->assertDatabaseMissing('ips', ['id' => $ip->id]);
        $this->assertDatabaseMissing('notes', ['service_id' => $ip->id]);
        $this->assertDatabaseHas('ips', ['service_id' => 'ipsync01', 'address' => '192.0.2.2']);
    }

    public function test_max_users_is_read_from_config()
    {
        // env() at request time breaks under config:cache; the cap must come
        // from config/custom.php.
        $this->assertSame(1, (int) config('custom.max_users'));
    }
}
