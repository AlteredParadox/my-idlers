<?php

namespace Tests\Feature;

use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 71/72: the MAX_USERS cap was a lock-free check-then-insert —
 * two concurrent POST /register requests both read 0 users, both passed
 * the guard, and both inserted, landing two accounts under MAX_USERS=1.
 * No service table is user-scoped, so the extra account reads and writes
 * everything. The rounds 43-47 locked-re-read lens covered every update
 * and destroy path but never this one.
 *
 * Serialization uses an out-of-band atomic lock, not a data row: a warm
 * `settings` cache entry shadowing a deleted row made getSettings()
 * return a ghost model while lockForUpdate()->first() found nothing and
 * took no lock at all (round 72, demonstrated live).
 */
class Round71RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(string $name): array
    {
        return [
            'name' => $name, 'email' => "$name@example.com",
            'password' => 'Password!2345', 'password_confirmation' => 'Password!2345',
        ];
    }

    public function test_registration_blocks_when_the_cap_lock_is_held()
    {
        // Pins the LOCK itself (round 72: the re-check pin passed with
        // lockForUpdate deleted, so serialization was unpinned). A held
        // lock means a concurrent registration is in flight — we must fail
        // closed, never count-then-insert past it.
        config(['custom.max_users' => 1, 'custom.registration_lock_seconds' => 0]);
        Settings::firstOrCreate(['id' => 1]);

        $held = Cache::lock('registration.cap', 10);
        $this->assertTrue($held->get(), 'precondition: the lock is acquirable');

        try {
            $this->post('/register', $this->registerPayload('blocked'))->assertStatus(500);
            $this->assertSame(0, User::count(), 'no account may be created while the cap lock is held');
        } finally {
            $held->release();
        }
    }

    public function test_registration_cap_is_rechecked_inside_the_transaction()
    {
        config(['custom.max_users' => 1]);
        Settings::firstOrCreate(['id' => 1]);
        $this->assertSame(0, User::count());

        // Simulate the competitor committing after our outer guard saw 0
        // users: create it immediately BEFORE the transaction's own count
        // query runs (beforeExecuting, not listen — listen fires after the
        // count has already returned 0). Keyed on the NESTED transaction
        // level: RefreshDatabase holds level 1, so >= 2 means we are inside
        // store()'s transaction. A hook without that key fires on earlier
        // middleware queries and makes the test pass against unfixed code.
        $raced = false;
        DB::beforeExecuting(function ($query) use (&$raced) {
            if ($raced || DB::transactionLevel() < 2) {
                return;
            }
            $sql = strtolower(ltrim($query));
            if (str_starts_with($sql, 'select') && str_contains($sql, 'users')) {
                $raced = true;
                User::create([
                    'name' => 'Racer', 'email' => 'racer@example.com',
                    'password' => Hash::make('password'),
                    'api_token' => User::hashApiToken(Str::random(60)),
                ]);
            }
        });

        $this->post('/register', $this->registerPayload('operator'))->assertStatus(403);

        $this->assertSame(1, User::count(), 'the cap must hold against a racing registration');
        $this->assertDatabaseMissing('users', ['email' => 'operator@example.com']);
        $this->assertGuest();
    }

    public function test_the_cap_holds_even_when_the_settings_row_is_missing()
    {
        // Round 72: the old fix locked the settings row, which a warm cache
        // over a deleted row silently reduced to a no-op. Serialization must
        // not depend on that row existing at all.
        config(['custom.max_users' => 1]);
        Settings::firstOrCreate(['id' => 1]);
        Settings::getSettings();              // warm the cache
        DB::table('settings')->delete();      // ...then lose the row

        $this->post('/register', $this->registerPayload('first'))->assertRedirect('/');
        auth()->logout();
        $this->post('/register', $this->registerPayload('second'))->assertStatus(403);

        $this->assertSame(1, User::count());
    }

    public function test_first_registration_succeeds_and_second_is_refused()
    {
        config(['custom.max_users' => 1]);
        Settings::firstOrCreate(['id' => 1]);

        $this->post('/register', $this->registerPayload('operator'))->assertRedirect('/');
        $this->assertSame(1, User::count());

        auth()->logout();

        $this->post('/register', $this->registerPayload('intruder'))->assertStatus(403);
        $this->assertSame(1, User::count());
    }

    public function test_unlimited_registrations_when_the_cap_is_zero()
    {
        config(['custom.max_users' => 0]);
        Settings::firstOrCreate(['id' => 1]);

        foreach (['a', 'b'] as $name) {
            $this->post('/register', $this->registerPayload($name))->assertRedirect('/');
            auth()->logout();
        }

        $this->assertSame(2, User::count());
    }
}
