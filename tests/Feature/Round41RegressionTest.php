<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Note;
use App\Services\PromQL;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 41 (audit of round 40's own fixes): the legacy-duplicate
 * self-heal kept the MIXED-CASE row un-normalized, so on SQLite (binary
 * unique index) a later insertIP of the same address minted a fresh
 * duplicate instead of colliding — and the survivor choice could destroy
 * the one row carrying a user note. Also: PHP strtolower (ASCII) diverged
 * from JS toLowerCase (Unicode) in the Prometheus host matcher for
 * non-ASCII hostnames, splitting the shared truth table.
 */
class Round41RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function legacyRow(string $service_id, string $address): IPs
    {
        // Raw create simulates pre-normalization rows (bypasses insertIP)
        return IPs::create([
            'id' => Str::random(8), 'service_id' => $service_id,
            'address' => $address, 'is_ipv4' => 0, 'active' => 1,
        ]);
    }

    public function test_self_heal_normalizes_the_surviving_row()
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('legacy mixed-case rows can only exist under SQLite');
        }

        $this->legacyRow('r41norm1', '2001:DB8::9');

        IPs::syncForService('r41norm1', ['2001:db8::9']);

        // The survivor must hold the canonical lowercase form...
        $this->assertDatabaseHas('ips', ['service_id' => 'r41norm1', 'address' => '2001:db8::9']);
        $this->assertDatabaseMissing('ips', ['service_id' => 'r41norm1', 'address' => '2001:DB8::9']);

        // ...so a re-insert of the same address collides instead of
        // silently minting a duplicate (the round-40 cycle).
        try {
            IPs::insertIP('r41norm1', '2001:DB8::9');
            $this->fail('expected the unique index to reject the duplicate');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        }
        $this->assertSame(1, IPs::where('service_id', 'r41norm1')->count());
    }

    public function test_self_heal_prefers_the_row_with_a_note()
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('legacy case-variant duplicates can only exist under SQLite');
        }

        $noted = $this->legacyRow('r41note1', '2001:DB8::C');
        $this->legacyRow('r41note1', '2001:db8::c');
        Note::create(['id' => 'r41note9', 'service_id' => $noted->id, 'note' => 'keep me']);

        IPs::syncForService('r41note1', ['2001:db8::c']);

        $this->assertSame(1, IPs::where('service_id', 'r41note1')->count());
        // The noted row survived (normalized), and its note with it
        $this->assertDatabaseHas('ips', ['id' => $noted->id, 'address' => '2001:db8::c']);
        $this->assertDatabaseHas('notes', ['service_id' => $noted->id, 'note' => 'keep me']);
    }

    public function test_dns_export_includes_labels()
    {
        // DNS records take labels through the web form; the export must not
        // silently drop them (same class as round 40's server-export gap)
        \App\Models\DNS::create(['id' => 'r41dns01', 'hostname' => 'a.example.com', 'dns_type' => 'A', 'address' => '192.0.2.1']);
        \App\Models\Labels::create(['id' => 'r41labl1', 'label' => 'dns-label']);
        \App\Models\LabelsAssigned::create(['label_id' => 'r41labl1', 'service_id' => 'r41dns01']);

        $export = (new \App\Services\ExportService())->exportDns('json');
        $row = json_decode($export['data'], true)[0];

        $this->assertSame(['dns-label'], $row['labels']);
    }

    public function test_ipv6_shaped_hostname_reaches_the_prometheus_detail_route()
    {
        // The route constraint rejected ':' — the index matched IPv6-shaped
        // hostnames (live status shown) while the detail endpoint 404ed.
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['prometheus_enabled' => 0, 'dark_mode' => 0])
            ->get('/tools/prometheus/detail/2001:db8::10/1h/0');

        // Prometheus is disabled, so the CONTROLLER answers 404 with a JSON
        // body — the pre-fix failure was a route-level 404 with no JSON.
        $response->assertStatus(404)->assertJson(['error' => 'Prometheus not enabled']);
    }

    public function test_host_matching_handles_non_ascii_like_the_js_side()
    {
        // PHP mb_strtolower must agree with JS toLowerCase — one truth table
        $this->assertTrue(PromQL::hostMatches('MÜNCHEN.example.com', 'münchen.example.com'));
        $this->assertTrue(PromQL::hostMatches('münchen.example.com', 'münchen'));
    }
}
