<?php

namespace Tests\Feature;

use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Review round 53: the layouts rendered asset(Session::get('favicon')) —
 * the last consumer-bearing session-snapshot residual. After an admin
 * changed the favicon's extension (old file deleted from disk), every
 * OTHER live session kept emitting the dead path — a broken icon
 * reference per page view until that session's snapshot expired. The
 * layouts now read the live cached settings row like every other
 * converted consumer, and favicon is no longer snapshotted at all.
 */
class Round53RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_favicon_follows_live_settings_not_the_session_snapshot()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1])->update(['favicon' => 'favicon.png']);
        Cache::forget('settings');

        // The GUEST layout first (before actingAs — /login bounces authed
        // users): it is a sibling consumer, and post-fix nothing writes the
        // session key at all, so a regressed guest layout would emit the
        // shipped default forever on login/register pages (round 54:
        // reverting only guest.blade.php left the suite green)
        $guest = $this->withSession(['dark_mode' => 0, 'favicon' => 'favicon.ico'])->get('/login');
        $guest->assertStatus(200);
        $this->assertStringContainsString('favicon.png', $guest->getContent());

        // A stale session claiming the old icon must not leak into the markup
        $response = $this->actingAs($user)
            ->withSession(['dark_mode' => 0, 'favicon' => 'favicon.ico'])
            ->get('/');

        $response->assertStatus(200);
        $this->assertStringContainsString('favicon.png', $response->getContent());
    }
}
