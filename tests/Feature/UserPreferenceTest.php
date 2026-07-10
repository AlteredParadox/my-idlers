<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-user UI preferences (table sort/column-visibility state, view
 * toggles) live in the user_preferences table — not localStorage, not the
 * file session store — so they survive browsers and container redeploys.
 */
class UserPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function state(): array
    {
        return ['order' => [[13, 'desc']], 'length' => 50, 'columns' => [['visible' => false]]];
    }

    public function test_guests_cannot_save_preferences()
    {
        $this->putJson('/preferences/dt.servers-table', $this->state())->assertStatus(401);
        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_preferences_save_and_upsert_per_key()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/preferences/dt.servers-table', $this->state())
            ->assertStatus(200)->assertJson(['result' => 'success']);
        $this->actingAs($user)->putJson('/preferences/dt.servers-table', ['order' => [[0, 'asc']]])
            ->assertStatus(200);
        $this->actingAs($user)->putJson('/preferences/ui.servers', ['hide_domains' => 1, 'hide_stats' => 0])
            ->assertStatus(200);

        // Same key upserts; different keys coexist
        $this->assertDatabaseCount('user_preferences', 2);
        $prefs = UserPreference::valuesFor($user->id);
        $this->assertSame([[0, 'asc']], $prefs['dt.servers-table']['order']);
        $this->assertSame(1, $prefs['ui.servers']['hide_domains']);
    }

    public function test_rejects_unknown_keys_and_oversized_or_empty_payloads()
    {
        $user = User::factory()->create();

        foreach (['evil', 'dt.', 'dt.UPPER', 'cfg.servers', 'dt.' . str_repeat('a', 49)] as $bad) {
            $this->actingAs($user)->putJson("/preferences/$bad", $this->state())->assertStatus(422);
        }

        $this->actingAs($user)->putJson('/preferences/dt.servers-table', [])->assertStatus(422);
        $this->actingAs($user)->putJson('/preferences/dt.servers-table', ['blob' => str_repeat('x', 17000)])
            ->assertStatus(422);

        $this->assertDatabaseCount('user_preferences', 0);
    }

    public function test_preferences_are_per_user()
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($a)->putJson('/preferences/ui.servers', ['hide_domains' => 1])->assertStatus(200);

        $this->assertArrayHasKey('ui.servers', UserPreference::valuesFor($a->id));
        $this->assertSame([], UserPreference::valuesFor($b->id));
    }

    public function test_index_pages_inject_saved_state_and_persistent_init()
    {
        $user = User::factory()->create();
        UserPreference::put($user->id, 'dt.servers-table', $this->state());

        $servers = $this->actingAs($user)->get('/servers');
        $servers->assertStatus(200);
        $servers->assertSee('idlersPrefs', false);
        $servers->assertSee('dt.servers-table', false);
        $servers->assertSee('idlersDataTable(\'#servers-table\'', false);

        // Pages on the shared partial get the same treatment
        $this->actingAs($user)->get('/domains')
            ->assertStatus(200)
            ->assertSee('idlersDataTable(\'#domain-table\'', false);
    }
}
