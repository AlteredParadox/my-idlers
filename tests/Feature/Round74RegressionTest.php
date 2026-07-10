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
 * Review round 74: two concurrent same-email registrations both pass the
 * unique:users rule (validation runs before the serialization lock), and
 * the loser's insert hit the users.email unique index raw — a 500 error
 * page instead of "the email has already been taken". Reachable when the
 * cap doesn't refuse the loser first (MAX_USERS=0 or >= 2). The insert is
 * now wrapped: a UniqueConstraintViolationException becomes the standard
 * email validation error.
 */
class Round74RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_email_race_yields_a_validation_error_not_a_500()
    {
        config(['custom.max_users' => 0]); // unlimited: the cap must not mask the race

        Settings::firstOrCreate(['id' => 1]);

        // Commit the competing same-email account immediately BEFORE the
        // loser's insert runs — inside store()'s own transaction (level 2;
        // RefreshDatabase holds level 1), after validation has passed.
        $raced = false;
        DB::beforeExecuting(function ($query) use (&$raced) {
            if ($raced || DB::transactionLevel() < 2) {
                return;
            }
            $sql = strtolower(ltrim($query));
            if (str_starts_with($sql, 'insert') && str_contains($sql, 'users')) {
                $raced = true; // set FIRST: our own create fires this hook too
                User::create([
                    'name' => 'Racer', 'email' => 'contested@example.com',
                    'password' => Hash::make('password'),
                    'api_token' => User::hashApiToken(Str::random(60)),
                ]);
            }
        });

        $response = $this->from('/register')->post('/register', [
            'name' => 'Loser', 'email' => 'contested@example.com',
            'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        // The injected racer shares store()'s transaction, so the rollback
        // wipes it too (a real racer commits on its own connection). The
        // property pinned above is the response: validation error, not 500.
        $this->assertSame(0, User::count());
        $this->assertDatabaseMissing('users', ['name' => 'Loser']);
    }

    public function test_sequential_duplicate_email_still_fails_normal_validation()
    {
        config(['custom.max_users' => 0]);
        Settings::firstOrCreate(['id' => 1]);

        $payload = [
            'name' => 'First', 'email' => 'taken@example.com',
            'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
        ];
        $this->post('/register', $payload)->assertRedirect('/');
        auth()->logout();

        $this->from('/register')->post('/register', array_merge($payload, ['name' => 'Second']))
            ->assertRedirect('/register')
            ->assertSessionHasErrors('email');
        $this->assertSame(1, User::count());
    }
}
