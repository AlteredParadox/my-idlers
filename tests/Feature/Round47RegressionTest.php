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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Review round 47: (a) round 46's yabs destroy wrap introduced a MySQL
 * REPEATABLE READ race — two concurrent destroys of the last two YABS
 * each saw the other's uncommitted row in their count snapshot, both
 * skipped the clear, and has_yabs stranded at 1 (show page 500s on
 * yabs[0]); the flag is now an unconditional set-from-truth EXISTS
 * update, pinned here behaviorally. (b) sort_on (and the home-page
 * amounts/currency) were read from the per-session snapshot INSIDE the
 * month-TTL shared cache closures — a stale session re-priming a cleared
 * cache poisoned the shared order for every session; the closures now
 * read the live cached Settings row.
 */
class Round47RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(string $id, float $usd_per_month): void
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => $usd_per_month, 'term' => 1, 'as_usd' => $usd_per_month,
            'usd_per_month' => $usd_per_month,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => $id, 'hostname' => "$id.example.com", 'server_type' => 1,
            'os_id' => OS::firstOrCreate(['name' => 'D'])->id,
            'provider_id' => Providers::firstOrCreate(['name' => 'P'])->id,
            'location_id' => Locations::firstOrCreate(['name' => 'L'])->id,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);
    }

    private function makeYabs(string $id, string $server_id): void
    {
        Yabs::create([
            'id' => $id, 'server_id' => $server_id, 'has_ipv6' => 0, 'aes' => 1, 'vm' => 1,
            // Distinct per row: (server_id, output_date) is unique — two runs
            // on one server are two different benchmark outputs.
            'output_date' => '2024-01-20 10:3' . substr($id, -1) . ':00', 'cpu_model' => 'EPYC', 'cpu_cores' => 4,
            'cpu_freq' => 2900, 'ram' => 8, 'ram_type' => 'GB', 'ram_mb' => 8192,
            'disk' => 100, 'disk_type' => 'GB', 'disk_gb' => 100,
            'gb5_single' => 1200, 'gb5_multi' => 4500,
        ]);
    }

    public function test_has_yabs_is_recomputed_from_truth_not_a_count_snapshot()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1]);
        $this->makeServer('r47srv01', 5.00);
        $this->makeYabs('r47yabs1', 'r47srv01');
        $this->makeYabs('r47yabs2', 'r47srv01');

        // Ghost shape: flag WRONG at 0 with two rows present. The old
        // count-then-maybe-clear code could only ever clear — set-from-truth
        // must REPAIR it to 1 when rows remain (this is the observable delta
        // of the fix; the RR double-miss itself needs two connections).
        Server::where('id', 'r47srv01')->update(['has_yabs' => 0]);

        $this->actingAs($user)->delete(route('yabs.destroy', 'r47yabs1'));
        $this->assertDatabaseHas('servers', ['id' => 'r47srv01', 'has_yabs' => 1]);

        // ...destroying the last must clear it
        $this->actingAs($user)->delete(route('yabs.destroy', 'r47yabs2'));
        $this->assertDatabaseHas('servers', ['id' => 'r47srv01', 'has_yabs' => 0]);
    }

    public function test_list_ordering_follows_live_settings_not_the_session_snapshot()
    {
        Settings::firstOrCreate(['id' => 1])->update(['sort_on' => 5]); // as_usd ASC
        Cache::flush();

        // A stale session claiming the old default must not poison the
        // shared cache it re-primes
        Session::put('sort_on', 2); // created_at DESC (the old snapshot)

        $this->makeServer('r47chp01', 1.00);   // cheap, created first
        $this->makeServer('r47exp01', 99.00);  // expensive, created second
        // Distinct created_at: same-second rows tie under the stale-session
        // ordering and would return insertion order — masking the regression
        Server::where('id', 'r47exp01')->update(['created_at' => now()->addMinute()]);

        $ordered = Server::allActiveServers()->pluck('id')->all();

        // Live setting (price ASC): cheap first. Under the stale-session
        // read this would be created_at DESC: expensive first.
        $this->assertSame(['r47chp01', 'r47exp01'], $ordered);
    }
}
