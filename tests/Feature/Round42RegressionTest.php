<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 42 (audit of round 41's own fixes): the migrate retry had
 * been added only to the guard branch — the README-recommended
 * AUTO_MIGRATE=true path still failed silently on a slow database; the
 * hostname route constraints stayed ASCII-only so non-ASCII hostnames
 * 404ed at the route layer despite the mb case-fold fix; the ping guard
 * rejected edge-compressed IPv6 ('::1'); and colliding notes on legacy
 * duplicate rows were destroyed instead of merged.
 */
class Round42RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_guard_accepts_edge_compressed_ipv6_but_not_options()
    {
        $user = User::factory()->create();

        // Valid IPs that fail the alnum-edges label rule must be allowed
        // (ping of ::1 resolves loopback — response shape is all we pin)
        $this->actingAs($user)->get('/tools/online/::1')
            ->assertStatus(200)->assertJsonStructure(['is_online']);

        // The dash-option injection defense must survive the loosening
        $this->actingAs($user)->get('/tools/online/-V')
            ->assertStatus(422);
    }

    public function test_non_ascii_hostname_reaches_the_detail_controller()
    {
        $user = User::factory()->create();

        // Pre-fix this was a route-layer HTML 404; the controller's JSON
        // 404 (prometheus disabled) proves the route now matches.
        $this->actingAs($user)
            ->withSession(['prometheus_enabled' => 0, 'dark_mode' => 0])
            ->get('/tools/prometheus/detail/' . rawurlencode('münchen.example.com') . '/1h/0')
            ->assertStatus(404)->assertJson(['error' => 'Prometheus not enabled']);
    }

    public function test_colliding_notes_on_legacy_duplicates_are_merged_not_destroyed()
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('legacy case-variant duplicates can only exist under SQLite');
        }

        $a = IPs::create(['id' => Str::random(8), 'service_id' => 'r42note1', 'address' => '2001:DB8::E', 'is_ipv4' => 0, 'active' => 1]);
        $b = IPs::create(['id' => Str::random(8), 'service_id' => 'r42note1', 'address' => '2001:db8::e', 'is_ipv4' => 0, 'active' => 1]);
        Note::create(['id' => 'r42nta', 'service_id' => $a->id, 'note' => 'first note']);
        Note::create(['id' => 'r42ntb', 'service_id' => $b->id, 'note' => 'second note']);

        // Prime the winner's note cache — the merge must invalidate it
        // (round 43: the only note-write path that didn't forget note.{id})
        Note::note($a->id);
        Note::note($b->id);

        IPs::syncForService('r42note1', ['2001:db8::e']);

        $this->assertSame(1, IPs::where('service_id', 'r42note1')->count());
        $survivor = IPs::where('service_id', 'r42note1')->first();
        $merged = Note::where('service_id', $survivor->id)->first();
        $this->assertNotNull($merged);
        $this->assertStringContainsString('first note', $merged->note);
        $this->assertStringContainsString('second note', $merged->note);
        // Cached copy reflects the merge, not the primed pre-merge text
        $this->assertStringContainsString('second note', Note::note($survivor->id)->note);
    }
}
