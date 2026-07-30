<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /api/online and /tools/online start a child process per request. Two bounds
 * keep one caller from occupying PHP-FPM workers and process slots:
 * a wall-clock timeout on the child, and a dedicated per-minute limiter on
 * both routes. The API route used to have neither -- it inherited only the
 * generic 60/min api bucket, six times the web sibling's allowance.
 */
class PingResourceBoundsTest extends TestCase
{
    use RefreshDatabase;

    private function routeFor(string $uri)
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        $this->fail("route $uri not registered");
    }

    public function test_both_ping_routes_carry_the_dedicated_limiter()
    {
        foreach (['api/online/{hostname}', 'tools/online/{hostname}'] as $uri) {
            $this->assertContains(
                'throttle:10,1',
                $this->routeFor($uri)->gatherMiddleware(),
                "$uri lost its dedicated ping limiter"
            );
        }
    }

    /**
     * ping's -W/-w bounds only the wait for a reply. Name resolution before the
     * probe, and a child that never exits, sit outside it -- so the bound has
     * to be on the process, not on ping's own options.
     */
    public function test_the_ping_child_has_a_wall_clock_timeout()
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/ToolsController.php'));

        $this->assertStringContainsString('setTimeout(self::PING_TIMEOUT_SECONDS)', $source);
        $this->assertStringContainsString('ProcessTimedOutException', $source);

        // No shell: Process takes an argument array, so metacharacters in a
        // hostname can never be interpreted in the first place. Comments are
        // stripped so the prose explaining why exec() is gone doesn't match.
        $code = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $source);

        foreach (['exec(', 'shell_exec', 'passthru', 'proc_open', 'popen('] as $sink) {
            $this->assertStringNotContainsString($sink, $code, "ToolsController reintroduced $sink");
        }
    }

    public function test_a_timed_out_probe_answers_offline_rather_than_erroring()
    {
        $user = User::factory()->create();

        // 203.0.113.0/24 is TEST-NET-3: reserved for documentation, never
        // routed, so the probe gets no reply and must come back is_online=false
        // within the bound instead of 500ing or hanging.
        $started = microtime(true);

        $this->actingAs($user)->get('/tools/online/203.0.113.1')
            ->assertStatus(200)
            ->assertJson(['is_online' => false]);

        $this->assertLessThan(10, microtime(true) - $started, 'the probe exceeded its wall-clock bound');
    }

    public function test_the_api_ping_route_still_requires_a_bearer_token()
    {
        $plain = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($plain)]);

        $this->getJson('/api/online/203.0.113.1')->assertStatus(401);

        $this->getJson('/api/online/203.0.113.1', ['Authorization' => 'Bearer ' . $plain])
            ->assertStatus(200)
            ->assertJsonStructure(['is_online']);
    }
}
