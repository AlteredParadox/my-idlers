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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 44: (a) updatePricing was left outside round 43's
 * locked-read standard, and applyPricingFields derived its merged write
 * values from an unlocked pricing read inside the fixed transaction —
 * a concurrent PUT /api/pricing commit could be silently reverted;
 * (b) the note-merge cache forget fired before COMMIT (re-primeable with
 * stale text); (c) the YABS show title read a nonexistent $yabs->hostname.
 * The lock placements are structural (single-threaded tests cannot
 * produce the races), so they are pinned by source assertions below.
 */
class Round44RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_yabs_show_title_includes_the_server_hostname()
    {
        $user = User::factory()->create();
        Settings::create(['id' => 1]);
        Pricing::create([
            'service_id' => 'r44srv01', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'r44srv01', 'hostname' => 'title.example.com', 'server_type' => 1,
            'os_id' => OS::create(['name' => 'D'])->id,
            'provider_id' => Providers::create(['name' => 'P'])->id,
            'location_id' => Locations::create(['name' => 'L'])->id,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01', 'has_yabs' => 1,
        ]);
        Yabs::create([
            'id' => 'r44yabs1', 'server_id' => 'r44srv01', 'has_ipv6' => 0, 'aes' => 1, 'vm' => 1,
            'output_date' => '2024-01-20 10:30:00', 'cpu_model' => 'EPYC', 'cpu_cores' => 4,
            'cpu_freq' => 2900, 'ram' => 8, 'ram_type' => 'GB', 'ram_mb' => 8192,
            'disk' => 100, 'disk_type' => 'GB', 'disk_gb' => 100,
            'gb5_single' => 1200, 'gb5_multi' => 4500,
        ]);

        // The title read $yabs->hostname (nonexistent) — tab showed no host
        $this->actingAs($user)->get(route('yabs.show', 'r44yabs1'))
            ->assertStatus(200)
            ->assertSee('title.example.com r44yabs1 YABS', false);
    }

    public function test_pricing_update_of_missing_row_is_404()
    {
        $token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($token)]);

        $this->putJson('/api/pricing/999999', [
            'price' => 10.50, 'currency' => 'USD', 'term' => 1,
        ], ['Authorization' => 'Bearer ' . $token])->assertStatus(404);
    }

    public function test_update_paths_hold_locked_reads_inside_their_transactions()
    {
        // Structural pins: the races these locks close cannot be produced
        // in a single-threaded test, but a refactor silently dropping the
        // locks must fail SOMETHING. Pin the load-bearing source shapes.
        $api = file_get_contents(app_path('Http/Controllers/Api/ServerManagementController.php'));

        // updateServer: locked server read inside the transaction. Ordering
        // via strpos, not one rigid regex — a comment or reformat between
        // the two lines must not false-fail the pin. Both anchors must
        // exist, or Str::between degrades to the whole file and the check
        // vacuously passes against some OTHER method's transaction.
        $this->assertStringContainsString('public function updateServer', $api);
        $this->assertStringContainsString('private function applyLinkSpeed', $api);
        $this->assertLessThan(
            strpos($api, 'private function applyLinkSpeed'),
            strpos($api, 'public function updateServer'),
            'the extraction anchors must delimit updateServer'
        );
        $method = Str::between($api, 'public function updateServer', 'private function applyLinkSpeed');
        $txnPos = strpos($method, 'DB::transaction');
        $lockPos = strpos($method, "Server::where('id', \$id)->lockForUpdate()");
        $this->assertNotFalse($txnPos, 'updateServer must wrap its writes in a transaction');
        $this->assertNotFalse($lockPos, 'updateServer must read the server row lockForUpdate()');
        $this->assertLessThan($lockPos, $txnPos, 'the locked read must sit inside the transaction');
        // applyPricingFields: locked pricing read (merged values derive from it)
        $this->assertStringContainsString(
            "Pricing::where('service_id', \$id)->lockForUpdate()->first()",
            $api,
            'applyPricingFields must lock the pricing row it derives merged values from'
        );
        // updatePricing: transactional locked read
        $this->assertStringContainsString(
            "Pricing::where('id', \$id)->lockForUpdate()->first(['service_id', 'service_type'])",
            $api,
            'updatePricing must lock-read inside its transaction'
        );

        // All seven web controllers guard their update transactions
        foreach (['ServerController', 'SharedController', 'ResellerController',
                  'SeedBoxesController', 'DomainsController', 'MiscController', 'DNSController'] as $controller) {
            $this->assertStringContainsString(
                'lockedRowStillExists',
                file_get_contents(app_path("Http/Controllers/$controller.php")),
                "$controller must re-check its row under lock inside the update transaction"
            );
        }
    }
}
