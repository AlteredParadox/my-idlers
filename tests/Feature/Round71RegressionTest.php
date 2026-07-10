<?php

namespace Tests\Feature;

use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 71: the MAX_USERS cap was a lock-free check-then-insert —
 * two concurrent POST /register requests both read 0 users, both passed
 * the guard, and both inserted, landing two accounts under MAX_USERS=1.
 * No account is scoped (no user_id on any service table), so the second
 * account reads and writes everything. The rounds 43-47 locked-re-read
 * lens covered every update and destroy path but never this one. The cap
 * is now re-checked inside a transaction behind a locked sentinel row.
 */
class Round71RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_cap_is_rechecked_under_lock()
    {
        config(['custom.max_users' => 1]);
        Settings::firstOrCreate(['id' => 1]);
        $this->assertSame(0, User::count());

        // Simulate the concurrent registration committing between the outer
        // guard (which saw 0 users) and our insert: create the competing user
        // the moment the transaction's locked sentinel read fires. Keyed on
        // the NESTED transaction level — RefreshDatabase holds level 1, so
        // level >= 2 means we are inside store()'s own transaction. Without
        // it the hook fires on the LoadSettings middleware's settings read,
        // which happens before the outer guard and makes the test pass
        // against unfixed code.
        $raced = false;
        DB::listen(function ($query) use (&$raced) {
            if ($raced || DB::transactionLevel() < 2) {
                return;
            }
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'select') && str_contains($sql, 'settings')) {
                $raced = true;
                User::create([
                    'name' => 'Racer', 'email' => 'racer@example.com',
                    'password' => Hash::make('password'),
                    'api_token' => User::hashApiToken(Str::random(60)),
                ]);
            }
        });

        // Pre-fix this created a SECOND user (the outer guard had already
        // passed with 0 users); the in-transaction re-check must refuse.
        $this->post('/register', [
            'name' => 'Operator', 'email' => 'operator@example.com',
            'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
        ])->assertStatus(403);

        $this->assertSame(1, User::count(), 'the cap must hold against a racing registration');
        $this->assertDatabaseMissing('users', ['email' => 'operator@example.com']);
        $this->assertGuest();
    }

    public function test_first_registration_still_succeeds_and_second_is_refused()
    {
        config(['custom.max_users' => 1]);
        Settings::firstOrCreate(['id' => 1]);

        $this->post('/register', [
            'name' => 'Operator', 'email' => 'operator@example.com',
            'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
        ])->assertRedirect('/');
        $this->assertSame(1, User::count());

        auth()->logout();

        $this->post('/register', [
            'name' => 'Intruder', 'email' => 'intruder@example.com',
            'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
        ])->assertStatus(403);
        $this->assertSame(1, User::count());
    }

    public function test_unlimited_registrations_when_the_cap_is_zero()
    {
        config(['custom.max_users' => 0]);
        Settings::firstOrCreate(['id' => 1]);

        foreach (['a', 'b'] as $name) {
            $this->post('/register', [
                'name' => $name, 'email' => "$name@example.com",
                'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
            ])->assertRedirect('/');
            auth()->logout();
        }

        $this->assertSame(2, User::count());
    }
}
