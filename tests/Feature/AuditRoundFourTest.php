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
        $this->assertContains('throttle:30,1', $route->gatherMiddleware(),
            "GET /$uri is unthrottled, so anonymous session rows grow at request rate");
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
