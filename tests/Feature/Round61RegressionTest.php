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

    public function test_demo_seed_skips_an_install_with_a_real_user_but_no_servers()
    {
        // Round 63: the guard is users OR servers — an || → && mutant
        // survived the both-populated re-seed pin and injected the full
        // demo set into a real operator's account. Pin the single-operand
        // state explicitly.
        config(['custom.seed_demo_data' => 'true']);
        \App\Models\User::factory()->create();

        $this->seed();

        $this->assertSame(0, DB::table('servers')->count(), 'demo data must not be injected into an install with a real user');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_demo_seed_skips_an_install_with_real_servers_but_no_users()
    {
        // Round 64: the inverse single-operand state — deleting the servers
        // operand from the guard survived both prior cases (users > 0 alone
        // skipped them). This is exactly the 'user renamed/deleted' arm the
        // round-62 commit named: real servers, empty users table.
        config(['custom.seed_demo_data' => 'true']);
        // servers.id has an FK to pricings.service_id — pricing first
        (new Pricing())->insertPricing(1, 'r64probe', 'USD', 5.00, 1, now()->addMonth()->format('Y-m-d'));
        DB::table('servers')->insert([
            'id' => 'r64probe', 'hostname' => 'real.example.com', 'server_type' => 1,
            'os_id' => null, 'provider_id' => null, 'location_id' => null,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);

        $this->seed();

        $this->assertSame(0, DB::table('users')->count(), 'demo data must not be injected into an install with real servers');
        $this->assertSame(1, DB::table('servers')->count());
    }
}
