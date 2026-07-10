<?php

namespace Tests\Feature;

use App\Models\Home;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Process;
use App\Services\PrometheusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

/**
 * Regressions for the round-20 review findings: due-date month overflow,
 * live pricing cache keys, null due dates in the Due Soon window, float
 * compare deltas, route-param shadowing on delete, Prometheus host match.
 */
class Round20RegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        Providers::create(['name' => 'Test Provider']);
        Locations::create(['name' => 'Test Location']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function makePricing(string $serviceId, int $type, ?string $nextDueDate): Pricing
    {
        return Pricing::create([
            'service_id' => $serviceId, 'service_type' => $type, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => $nextDueDate,
        ]);
    }

    private function makeServer(string $id): Server
    {
        $this->makePricing($id, 1, now()->addMonth()->format('Y-m-d'));

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

    public function test_due_date_advance_does_not_overflow_month_end()
    {
        // addMonths(1) on Jan 31 lands on Mar 3, silently skipping the
        // February renewal; the drift compounds because the stored date is
        // the next cycle's base. Must clamp to Feb 28.
        $this->makePricing('sbdue001', 6, '2026-01-31');
        SeedBoxes::create(['id' => 'sbdue001', 'title' => 'Due SB', 'active' => 1]);

        $dueSoon = [(object) [
            'next_due_date' => '2026-01-31',
            'term' => 1,
            'service_id' => 'sbdue001',
            'service_type' => 6,
        ]];

        Home::doDueSoon($dueSoon);

        $this->assertDatabaseHas('pricings', [
            'service_id' => 'sbdue001',
            'next_due_date' => '2026-02-28',
        ]);
    }

    public function test_api_update_pricing_clears_live_pricing_and_recently_added_caches()
    {
        // The route used to forget 'all_pricing' — a key nothing ever writes.
        // The live keys are 'all_active_pricing' (dashboard totals, 1-week
        // TTL) and 'recently_added'; both must clear for non-server types.
        $pricing = $this->makePricing('dom00002', 4, now()->addYear()->format('Y-m-d'));

        Cache::put('all_active_pricing', 'sentinel', 600);
        Cache::put('recently_added', 'sentinel', 600);

        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 50.00, 'currency' => 'USD', 'term' => 4,
        ], $this->apiHeaders())->assertStatus(200);

        $this->assertFalse(Cache::has('all_active_pricing'));
        $this->assertFalse(Cache::has('recently_added'));
    }

    public function test_due_soon_window_excludes_null_due_dates()
    {
        // NULLs sort first on both drivers; with the window capped they
        // crowd out real renewals, which then never get auto-advanced.
        SeedBoxes::create(['id' => 'sbnull01', 'title' => 'No Date', 'active' => 1]);
        SeedBoxes::create(['id' => 'sbdate01', 'title' => 'Has Date', 'active' => 1]);
        $this->makePricing('sbnull01', 6, null);
        $this->makePricing('sbdate01', 6, now()->addDays(3)->format('Y-m-d'));

        // The limit reads the live settings row (round 47), not the session
        \App\Models\Settings::firstOrCreate(['id' => 1])->update(['due_soon_amount' => 1]);
        Cache::forget('settings');
        Cache::forget('due_soon');

        $ids = Home::dueSoonData()->pluck('service_id')->all();

        $this->assertSame(['sbdate01'], $ids);
    }

    public function test_table_row_compare_float_mode_shows_fractional_delta()
    {
        // Int-casting 4.50 vs 4.90 rendered them "equal".
        $cell = Process::tableRowCompare('4.50', '4.90', '/mo', false);
        $this->assertStringContainsString('neg-td', $cell);
        $this->assertStringContainsString('-0.4', $cell);

        $equal = Process::tableRowCompare('0.36', '0.36', '', false);
        $this->assertStringContainsString('equal-td', $equal);
    }

    public function test_api_delete_server_uses_route_param_not_request_body()
    {
        // Request input shadows route params: a body {"id": "..."} used to
        // delete a different server than the one in the URL.
        $this->makeServer('delsrv01');
        $this->makeServer('keepsrv1');

        $this->deleteJson('/api/servers/delsrv01', ['id' => 'keepsrv1'], $this->apiHeaders())
            ->assertStatus(200);

        $this->assertDatabaseMissing('servers', ['id' => 'delsrv01']);
        $this->assertDatabaseHas('servers', ['id' => 'keepsrv1']);

        // An array body must not reach find() and blow up with a 500 either.
        $this->deleteJson('/api/servers/zzzzzz99', ['id' => ['x']], $this->apiHeaders())
            ->assertStatus(404);
    }

    public function test_prometheus_resolve_instance_requires_exact_short_name()
    {
        // Prefix matching let web10 answer for web1 depending on response order.
        $client = new FakePrometheusClient(instant: [
            'node_uname_info' => [
                ['metric' => ['instance' => '10.0.0.10:9100', 'nodename' => 'web10'], 'value' => [1700000000, '1']],
                ['metric' => ['instance' => '10.0.0.1:9100', 'nodename' => 'web1'], 'value' => [1700000000, '1']],
            ],
            'up{job="node"}' => [],
        ]);

        $method = new \ReflectionMethod(PrometheusService::class, 'resolveInstance');
        $instance = $method->invoke(new PrometheusService($client), 'web1.example.com');

        $this->assertSame('10.0.0.1:9100', $instance);
    }
}
