<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditRoundFourTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every request through the `web` group persists a session row with the
     * database driver -- measured at one row per fresh cookie-less request,
     * including for a 404. These pages cannot simply drop their session (the
     * login and reset forms need a CSRF token bound to one), so the bound is a
     * rate limit: growth becomes at most rate x SESSION_LIFETIME, which the
     * session GC lottery prunes.
     */
    public static function guestPages(): array
    {
        return [
            'login'           => ['login'],
            'register'        => ['register'],
            'forgot password' => ['forgot-password'],
            'reset password'  => ['reset-password/{token}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestPages')]
    public function test_guest_get_routes_are_throttled(string $uri)
    {
        $route = collect(Route::getRoutes())->first(
            fn($r) => $r->uri() === $uri && in_array('GET', $r->methods(), true)
        );

        $this->assertNotNull($route, "no GET route for $uri");
        $this->assertContains('throttle:guest-pages', $route->gatherMiddleware(),
            "GET /$uri is unthrottled, so anonymous session rows grow at request rate");
    }

    /**
     * Delivery is only ATTEMPTED for an address that exists, so anything the
     * delivery path throws happens for real accounts and not for unknown ones.
     * With an unreachable mail server this endpoint answered 500 for a
     * registered address and 302 for an unregistered one -- a cleaner
     * account-existence oracle than any timing difference, and reachable
     * wherever delivery is inline (QUEUE_CONNECTION=sync, which .env.example
     * ships and the documented non-Docker install inherits).
     */
    public function test_a_delivery_failure_is_indistinguishable_from_an_unknown_address()
    {
        \App\Models\User::factory()->create(['email' => 'known@example.com']);

        // Fail delivery the way an unreachable mail server does: a real
        // connection attempt to a closed port, rather than a mocked sender
        // that might not throw from the same place.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
            'queue.default' => 'sync',
        ]);

        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);
        $known = $this->post('/forgot-password', ['email' => 'known@example.com']);

        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode(),
            'a delivery failure reveals that the account exists');
        $this->assertSame(302, $known->getStatusCode());
    }

    /**
     * Throttle buckets must be INDEPENDENT.
     *
     * An inline `throttle:n,1` does not get its own counter: Laravel keys a
     * guest by sha1(domain|ip) and an authenticated caller by user id, with
     * the route absent from the key. Every inline throttle therefore shared
     * one counter, and a high-limit route starved a low-limit one -- twelve
     * ordinary login-page views (limit 30) pushed the shared count past 6, so
     * the next POST /forgot-password (limit 6) answered 429 and the user could
     * not request a reset.
     *
     * Named limiters key on by(), so this asserts no route is left using the
     * inline numeric form.
     */
    public function test_no_route_uses_an_inline_numeric_throttle()
    {
        $offenders = [];

        foreach (['routes/web.php', 'routes/auth.php', 'routes/api.php'] as $file) {
            if (preg_match_all('/throttle:\d+/', file_get_contents(base_path($file)), $m)) {
                foreach ($m[0] as $hit) {
                    $offenders[] = "$file: $hit";
                }
            }
        }

        $this->assertSame([], $offenders,
            'an inline numeric throttle shares one counter with every other throttled route');
    }

    public function test_page_views_do_not_consume_the_credential_budget()
    {
        \App\Models\User::factory()->create(['email' => 'known@example.com']);

        // Well past the credential limit of 6, but these are page views.
        for ($i = 0; $i < 12; $i++) {
            $this->get('/login')->assertStatus(200);
        }

        $this->post('/forgot-password', ['email' => 'known@example.com'])
            ->assertStatus(302);
    }

    /**
     * Isolating the buckets must not have loosened the credential budget.
     * /forgot-password is used rather than /login because the login POST hits
     * Laravel's own per-email limiter first, which answers with a validation
     * error rather than a 429 -- a different control from the route throttle
     * under test here.
     */
    public function test_the_credential_budget_still_bites()
    {
        \App\Models\User::factory()->create(['email' => 'known@example.com']);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => 'known@example.com']);
        }

        $this->post('/forgot-password', ['email' => 'known@example.com'])
            ->assertStatus(429);
    }

    /**
     * null means "no application-level timeout": a stalled or tarpitting SMTP
     * server holds the PHP worker talking to it for as long as it likes.
     */
    public function test_smtp_has_an_explicit_timeout()
    {
        $timeout = config('mail.mailers.smtp.timeout');

        $this->assertNotNull($timeout, 'SMTP has no application-level deadline');
        $this->assertIsNumeric($timeout);
        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(30, $timeout, 'a deadline this long still pins a worker');
    }

    // ---- the queue that takes SMTP off the request ----------------------

    public function test_the_queue_backing_tables_exist()
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        // A queue whose failures vanish is worse than none: password-reset
        // mail would stop silently.
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }

    public function test_the_image_runs_a_supervised_queue_worker()
    {
        $supervisor = file_get_contents(base_path('docker/supervisord.conf'));

        $this->assertStringContainsString('[program:queue]', $supervisor);
        $this->assertStringContainsString('artisan queue:work', $supervisor);
        $this->assertMatchesRegularExpression('/\[program:queue\][\s\S]*autorestart=true/', $supervisor);
        // Recycled rather than immortal, so it cannot accumulate memory or
        // hold a stale config/DB handle.
        $this->assertStringContainsString('--max-time=3600', $supervisor);
        // Failures land in failed_jobs instead of looping forever.
        $this->assertStringContainsString('--tries=3', $supervisor);
    }

    public function test_the_image_selects_the_database_queue()
    {
        $run = file_get_contents(base_path('run.sh'));

        $this->assertStringContainsString('QUEUE_CONNECTION=database', $run);
        $this->assertStringNotContainsString('QUEUE_CONNECTION=sync', $run,
            'the shipped image would send mail inline, leaving the timing oracle open');
    }

    /**
     * The worker must not run as root -- it executes application code and
     * needs nothing the fpm workers do not already have.
     */
    public function test_the_queue_worker_is_unprivileged()
    {
        $supervisor = file_get_contents(base_path('docker/supervisord.conf'));

        $this->assertMatchesRegularExpression(
            '/\[program:queue\][\s\S]*user=www-data/',
            $supervisor
        );
    }
}
