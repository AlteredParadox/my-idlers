<?php

namespace Tests\Feature;

use App\Models\Labels;
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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Review round 46: the YABS surface was the one file both fix-series
 * stopped short of — Yabs::deleteForServer forgot its caches inside the
 * callers' destroy transactions (pre-commit re-prime → a deleted server's
 * benchmarks ghost-render for a month), and YabsController::destroy was
 * the last non-atomic destroy path (orphaned disk_speed/network_speed
 * rows collide with future ingests; has_yabs could stick at 1 with zero
 * YABS). LabelsController::destroy had the same unwrapped shape.
 */
class Round46RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function makeServerWithYabs(string $id): void
    {
        Settings::firstOrCreate(['id' => 1]);
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => $id, 'hostname' => "$id.example.com", 'server_type' => 1,
            'os_id' => OS::firstOrCreate(['name' => 'D'])->id,
            'provider_id' => Providers::firstOrCreate(['name' => 'P'])->id,
            'location_id' => Locations::firstOrCreate(['name' => 'L'])->id,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01', 'has_yabs' => 1,
        ]);
        Yabs::create([
            'id' => 'y' . substr($id, 1), 'server_id' => $id, 'has_ipv6' => 0, 'aes' => 1, 'vm' => 1,
            'output_date' => '2024-01-20 10:30:00', 'cpu_model' => 'EPYC', 'cpu_cores' => 4,
            'cpu_freq' => 2900, 'ram' => 8, 'ram_type' => 'GB', 'ram_mb' => 8192,
            'disk' => 100, 'disk_type' => 'GB', 'disk_gb' => 100,
            'gb5_single' => 1200, 'gb5_multi' => 4500,
        ]);
    }

    public function test_yabs_cache_forgets_defer_until_the_destroy_commits()
    {
        $this->makeServerWithYabs('r46srv01');
        Yabs::allYabs(); // prime
        $this->assertTrue(Cache::has('all_yabs'));

        DB::transaction(function () {
            Yabs::deleteForServer('r46srv01');
            // Pre-commit forget would be re-primeable with the pre-delete
            // snapshot — the deleted server's benchmarks ghosting for a month
            $this->assertTrue(Cache::has('all_yabs'), 'forgot before commit');
        });

        $this->assertFalse(Cache::has('all_yabs'), 'not forgotten after commit');
    }

    public function test_yabs_destroy_is_atomic()
    {
        $user = User::factory()->create();
        $this->makeServerWithYabs('r46srv02');

        // Fail the LAST cleanup step — pre-fix, the yabs delete had already
        // committed, orphaning children and stranding has_yabs
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete') && str_contains($query->sql, 'network_speed')) {
                throw new \RuntimeException('injected delete failure');
            }
        });

        $this->actingAs($user)->delete(route('yabs.destroy', 'y46srv02'))->assertStatus(500);

        $this->assertDatabaseHas('yabs', ['id' => 'y46srv02']);
        $this->assertDatabaseHas('servers', ['id' => 'r46srv02', 'has_yabs' => 1]);
    }

    public function test_label_destroy_is_atomic()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1]);
        Labels::create(['id' => 'r46labl1', 'label' => 'atomic']);

        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete') && str_contains($query->sql, 'labels_assigned')) {
                throw new \RuntimeException('injected delete failure');
            }
        });

        $this->actingAs($user)->delete(route('labels.destroy', 'r46labl1'))->assertStatus(500);

        $this->assertDatabaseHas('labels', ['id' => 'r46labl1']);
    }
}
