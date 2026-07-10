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

        // A stale session claiming the old icon must not leak into the markup
        $response = $this->actingAs($user)
            ->withSession(['dark_mode' => 0, 'favicon' => 'favicon.ico'])
            ->get('/');

        $response->assertStatus(200);
        $this->assertStringContainsString('favicon.png', $response->getContent());
    }
}
