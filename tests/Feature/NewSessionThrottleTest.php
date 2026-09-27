<?php

namespace Tests\Feature;

use App\Http\Middleware\ThrottleNewSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Anonymous requests can fill the database with sessions (Daybreak Blue).
 * Measured against a live server before the fix: 40 cookie-less GET /login
 * = 40 rows, with 10 of them answered 429 by the guest-page throttle --
 * StartSession had already run. Auth redirects and CSRF 419s wrote rows too.
 */
class NewSessionThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        User::factory()->create(); // an empty install redirects /login to /register
    }

    /**
     * The framework registers StartSession as a singleton, so in one test
     * process every request shares one session manager whose database handler
     * remembers that "its" row exists and turns later inserts into no-op
     * updates: 60 requests collapsed into one row. Forget all three so each
     * request behaves like a separate php-fpm process.
     */
    private function asFreshProcess(): void
    {
        $this->app->forgetInstance(StartSession::class);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
    }

    private function cookieless(string $uri)
    {
        $this->asFreshProcess();

        return $this->get($uri);
    }

    public function test_the_gate_sits_before_session_start_for_every_web_route()
    {
        $web = app(\App\Http\Kernel::class)->getMiddlewareGroups()['web'];
        $this->assertLessThan(array_search(StartSession::class, $web), array_search(ThrottleNewSessions::class, $web));

        // Position in the group is not enough: the router re-sorts by priority.
        // Assert on what actually runs for a route.
        $resolved = Route::gatherRouteMiddleware(Route::getRoutes()->getByName('login'));
        $this->assertLessThan(array_search(StartSession::class, $resolved), array_search(ThrottleNewSessions::class, $resolved),
            'the router reordered the gate behind StartSession');
    }

    public function test_cookieless_requests_beyond_the_budget_are_refused_without_storing_a_session()
    {
        $limit = ThrottleNewSessions::PER_MINUTE;

        // Not /login: its own 30/min guest-page throttle would answer first.
        for ($i = 0; $i < $limit; $i++) {
            $this->cookieless('/servers')->assertRedirect('/login');
        }
        $this->assertSame($limit, DB::table('sessions')->count(), 'each cookie-less request is its own row');

        for ($i = 0; $i < 5; $i++) {
            $this->cookieless('/servers')->assertStatus(429)->assertHeader('Retry-After');
        }
        $this->assertSame($limit, DB::table('sessions')->count(), 'a refused request must not store a session');
    }

    public function test_the_budget_covers_routes_that_only_redirect_or_reject()
    {
        for ($i = 0; $i < ThrottleNewSessions::PER_MINUTE; $i++) {
            $this->cookieless('/servers')->assertRedirect('/login');
        }
        $rows = DB::table('sessions')->count();

        $this->cookieless('/servers')->assertStatus(429);
        $this->cookieless('/login')->assertStatus(429);
        $this->assertSame($rows, DB::table('sessions')->count());
    }

    public function test_a_client_holding_its_cookie_is_exempt_and_reuses_one_row()
    {
        $cookie = $this->cookieless('/servers')->assertRedirect('/login')->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie);

        for ($i = 0; $i < ThrottleNewSessions::PER_MINUTE + 5; $i++) {
            $this->asFreshProcess();
            $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue())->get('/servers')->assertRedirect('/login');
        }

        $this->assertSame(1, DB::table('sessions')->count(), 'a replayed cookie maps to one row');
    }

    public function test_a_cookie_that_does_not_decrypt_counts_as_a_new_session()
    {
        for ($i = 0; $i < ThrottleNewSessions::PER_MINUTE; $i++) {
            $this->asFreshProcess();
            $this->withUnencryptedCookie(config('session.cookie'), str_repeat('x', 40))->get('/servers')->assertRedirect('/login');
        }

        $this->asFreshProcess();
        $this->withUnencryptedCookie(config('session.cookie'), str_repeat('x', 40))->get('/servers')->assertStatus(429);
    }
}
