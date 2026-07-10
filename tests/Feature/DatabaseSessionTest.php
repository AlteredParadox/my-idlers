<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Production stores sessions in the database (run.sh / .env.example set
 * SESSION_DRIVER=database) so logins and the settings snapshot survive
 * container redeploys on both SQLite and MySQL.
 */
class DatabaseSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sessions_table_exists_for_the_database_driver()
    {
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    public function test_database_driver_persists_a_session_row()
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertSame($user->id, (int) DB::table('sessions')->value('user_id'));
    }
}
