<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regressions for the 2026-10-19 weekly review (commit range f1821c6..f8b224a).
 *
 * The High (symfony/http-foundation CVE-2026-108082) is a lockfile bump and
 * is covered by `composer audit --locked` in CI, not here.
 */
class WeeklyReview20261019Test extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Providers $provider;
    private Locations $location;
    private OS $os;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->provider = Providers::create(['name' => 'Review Provider']);
        $this->location = Locations::create(['name' => 'Review Location']);
        $this->os = OS::create(['name' => 'Debian 13']);
        Settings::create(['id' => 1]);
    }

    private function serverPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'review.example.com',
            'server_type' => 1,
            'os_id' => $this->os->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'ram' => 2048,
            'ram_type' => 'MB',
            'disk' => [50],
            'disk_type' => ['GB'],
            'disk_media' => ['SSD'],
            'cpu' => 2,
            'bandwidth' => 1000,
            'ssh_port' => 22,
            'was_promo' => 0,
            'currency' => 'USD',
            'price' => 5.00,
            'payment_term' => 1,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ], $overrides);
    }

    // ---- Medium 2: the reset token and the Referer header ----------------

    /**
     * Under the container's `Referrer-Policy: same-origin`, the reset page's
     * same-origin asset loads carry the full /reset-password/{token} URL as
     * Referer -- redacted in the container's own log, plaintext in any
     * proxy's log in front of it. A document-level policy overrides the
     * header for the guest pages only.
     */
    public function test_guest_pages_declare_a_no_referrer_policy_and_authenticated_pages_do_not()
    {
        $meta = '<meta name="referrer" content="no-referrer">';

        $this->get(route('login'))->assertOk()->assertSee($meta, false);
        $this->get(route('password.request'))->assertOk()->assertSee($meta, false);
        $this->get(url('/reset-password/sometoken?email=a@example.com'))->assertOk()->assertSee($meta, false);

        // back() on every authenticated form relies on the Referer (issue
        // #121), so the app layout must keep the header's same-origin policy.
        $this->actingAs($this->user)->get('/')->assertOk()->assertDontSee($meta, false);
        $this->actingAs($this->user)->get(route('servers.create'))->assertOk()->assertDontSee($meta, false);
    }

    /**
     * With no Referer, back() falls through to the session's previous URL,
     * which the reset page's own GET sets -- so a failed reset submission
     * still returns to the token page, not the dashboard.
     */
    public function test_a_failed_reset_submission_without_a_referer_returns_to_the_reset_page()
    {
        // Built the way StartSession stores it (fullUrl(), query re-encoded)
        $resetUrl = url('/reset-password/sometoken') . '?' . http_build_query(['email' => $this->user->email]);

        $this->get($resetUrl)->assertOk();

        $this->post(route('password.update'), [
            'token' => 'sometoken',
            'email' => $this->user->email,
            'password' => 'new-password-1',
            'password_confirmation' => 'does-not-match',
        ])->assertRedirect($resetUrl)->assertSessionHasErrors('password');
    }

    public function test_a_failed_login_without_a_referer_returns_to_the_login_page()
    {
        $this->get(route('login'))->assertOk();

        $this->post(route('login'), ['email' => $this->user->email, 'password' => 'wrong'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    /**
     * StartSession records the previous URL for every routed, non-ajax GET.
     * The Prometheus detail poll on the server page was the one fetch() GET
     * in the app without X-Requested-With, i.e. the one request that could
     * overwrite the previous URL out from under a later back().
     */
    public function test_every_fetch_get_in_the_views_identifies_itself_as_ajax()
    {
        $show = file_get_contents(resource_path('views/servers/show.blade.php'));
        $this->assertSame(1, preg_match('/fetch\(\'\/tools\/prometheus\/detail\/.*?\{(.*?)\}\)/s', $show, $m));
        $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $m[1]);
    }

    // ---- Low 3: unbounded disk[] overflows the int disk_as_gb column -------

    public function test_a_disk_total_past_the_int_column_is_a_validation_error_not_a_500()
    {
        // Three disks at the per-entry maximum: 3 * 1000000 TB = 3072000000 GB,
        // past signed INT. Under MySQL strict mode the insert raised error
        // 1264 inside the transaction -- a 500 where a 422 belongs.
        $this->actingAs($this->user)
            ->from(route('servers.create'))
            ->post(route('servers.store'), $this->serverPayload([
                'disk' => [1000000, 1000000, 1000000],
                'disk_type' => ['TB', 'TB', 'TB'],
                'disk_media' => ['HDD', 'HDD', 'HDD'],
            ]))
            ->assertRedirect(route('servers.create'))
            ->assertSessionHasErrors('disk');

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_the_disk_array_length_is_capped()
    {
        $n = 65;
        $this->actingAs($this->user)
            ->from(route('servers.create'))
            ->post(route('servers.store'), $this->serverPayload([
                'disk' => array_fill(0, $n, 10),
                'disk_type' => array_fill(0, $n, 'GB'),
                'disk_media' => array_fill(0, $n, 'SSD'),
            ]))
            ->assertRedirect(route('servers.create'))
            ->assertSessionHasErrors('disk');

        $this->assertDatabaseCount('servers', 0);
        $this->assertDatabaseCount('server_disks', 0);
    }

    public function test_a_large_but_representable_disk_set_still_saves()
    {
        $this->actingAs($this->user)
            ->post(route('servers.store'), $this->serverPayload([
                'disk' => [2, 4000],
                'disk_type' => ['TB', 'GB'],
                'disk_media' => ['HDD', 'NVMe'],
            ]))
            ->assertRedirect(route('servers.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2048 + 4000, (int) Server::first()->disk_as_gb);
    }

    // ---- Low 4: seedboxes was the one service table without a pricing FK --

    public function test_seedboxes_carries_the_same_pricing_foreign_key_as_the_other_service_tables()
    {
        foreach (['servers', 'shared_hosting', 'reseller_hosting', 'domains', 'misc_services', 'seedboxes'] as $table) {
            $fk = collect(Schema::getForeignKeys($table))->first(fn ($fk) => $fk['columns'] === ['id']);

            $this->assertNotNull($fk, "$table.id must carry a foreign key to pricings");
            $this->assertSame('pricings', $fk['foreign_table']);
            $this->assertSame(['service_id'], $fk['foreign_columns']);
        }
    }

    public function test_a_seedbox_without_a_pricing_row_is_rejected_by_the_database()
    {
        $this->expectException(QueryException::class);

        DB::table('seedboxes')->insert([
            'id' => 'sbx00001', 'title' => 'orphan', 'active' => 1,
            'provider_id' => $this->provider->id, 'location_id' => $this->location->id,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10, 'was_promo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deleting_a_pricing_row_cascades_to_its_seedbox_like_every_other_service()
    {
        (new Pricing)->insertPricing(6, 'sbx00002', 'USD', 5, 1, '2027-01-01');
        DB::table('seedboxes')->insert([
            'id' => 'sbx00002', 'title' => 'cascade', 'active' => 1,
            'provider_id' => $this->provider->id, 'location_id' => $this->location->id,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10, 'was_promo' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        (new Pricing)->deletePricing('sbx00002');

        $this->assertDatabaseMissing('seedboxes', ['id' => 'sbx00002']);
    }

    // ---- Low 5: whois strings vs varchar(255) -----------------------------

    public function test_an_overlong_whois_field_is_clipped_to_the_column_not_a_500()
    {
        $longOrg = str_repeat('Autonomous System Organisation ', 12); // 372 chars
        Http::fake(['ipwhois.app/*' => Http::response([
            'success' => true,
            'continent' => 'Europe', 'country' => 'Germany', 'region' => 'Hesse', 'city' => 'Frankfurt',
            'org' => $longOrg, 'isp' => ['unexpected' => 'shape'], 'asn' => 'AS64496', 'timezone_gmt' => '+01:00',
        ])]);

        (new Pricing)->insertPricing(1, 'srv00009', 'USD', 5, 1, '2027-01-01');
        Server::create([
            'id' => 'srv00009', 'hostname' => 'whois.example.com', 'server_type' => 1,
            'os_id' => $this->os->id, 'provider_id' => $this->provider->id,
            'location_id' => $this->location->id, 'ram' => 1024, 'disk' => 10, 'cpu' => 1,
        ]);

        $this->actingAs($this->user)
            ->post(route('IPs.store'), ['address' => '203.0.113.9', 'ip_type' => 'ipv4', 'service_id' => 'srv00009'])
            ->assertRedirect(route('IPs.index'))
            ->assertSessionHas('success');

        $ip = IPs::where('address', '203.0.113.9')->firstOrFail();
        $this->assertSame(255, mb_strlen($ip->org));
        $this->assertSame(mb_substr($longOrg, 0, 255), $ip->org);
        $this->assertNull($ip->isp, 'a non-scalar field is dropped, not stringified');
        $this->assertSame('Frankfurt', $ip->city);
    }

    // ---- Low 6: POST /login was outside the credentials budget ------------

    public function test_post_login_is_in_the_shared_credentials_budget()
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'login' && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route);
        $this->assertContains('throttle:credentials', $route->gatherMiddleware(),
            'RouteServiceProvider::RATE_LIMITS documents login as part of the credentials budget');
    }

    public function test_an_address_cannot_post_login_without_limit_across_distinct_emails()
    {
        // LoginRequest's own lockout is keyed by email|ip, so rotating the
        // email sidestepped it entirely; the route budget is per address.
        for ($i = 1; $i <= 6; $i++) {
            $this->post(route('login'), ['email' => "guess{$i}@example.com", 'password' => 'wrong'])
                ->assertRedirect();
        }

        $this->post(route('login'), ['email' => 'guess7@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    // ---- Low 7: base images pinned ----------------------------------------

    public function test_the_dockerfile_pins_its_base_images_by_version_and_digest()
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^FROM php:8\.\d+\.\d+-fpm-alpine@sha256:[0-9a-f]{64}$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^COPY --from=composer:2\.\d+\.\d+@sha256:[0-9a-f]{64} /m', $dockerfile);
        $this->assertStringNotContainsString(':latest', $dockerfile);
    }
}
