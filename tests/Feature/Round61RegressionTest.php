<?php

namespace Tests\Feature;

use App\Models\Misc;
use App\Models\Pricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Review round 61: the demo-seed surface had never been audited. Misc
 * pricing was seeded as service_type 6 (seedbox) — blank names, wrong
 * badges and 404ing View buttons on the demo home page, plus wrong-type
 * cache fan-out on due-date advancement; the demo servers had no
 * server_disks parity rows (dashboard showed 0 GB total disk); and the
 * demo flag was read via env(), which is null under a cached config —
 * silently skipping the whole demo set (the CLI residual of the
 * max_users class, now config('custom.seed_demo_data') with a warn).
 */
class Round61RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_invariants_hold()
    {
        // The flag must flow through config (cached-config safe), and
        // string 'true' from an env file must count as enabled
        config(['custom.seed_demo_data' => 'true']);

        $this->seed();

        // Demo data actually seeded (the cached-config path used to skip
        // everything silently)
        $this->assertGreaterThan(0, DB::table('users')->count());
        $serverCount = DB::table('servers')->count();
        $this->assertGreaterThan(0, $serverCount);

        // Disk parity: every demo server carries its server_disks row —
        // Home::serverSummary sums ONLY that table
        $this->assertSame($serverCount, DB::table('server_disks')->count());
        $this->assertGreaterThan(0, (int) DB::table('server_disks')->sum('disk_as_gb'));

        // Misc pricing rows must carry the canonical misc type (5), not
        // the seedbox type (6) that mislabeled and 404'd the demo home page
        $miscIds = Misc::pluck('id');
        $this->assertGreaterThan(0, $miscIds->count());
        $this->assertSame(
            0,
            Pricing::whereIn('service_id', $miscIds)->where('service_type', '!=', 5)->count(),
            'misc pricing rows must use service_type 5'
        );

        // Round 62: re-seeding must skip gracefully — not crash on the demo
        // user's unique email, and not duplicate the demo set under fresh
        // random ids
        $pricingCount = Pricing::count();
        $this->seed();
        $this->assertSame($serverCount, DB::table('servers')->count(), 're-seed must not duplicate demo servers');
        $this->assertSame($pricingCount, Pricing::count(), 're-seed must not duplicate pricing rows');
    }
}
