<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The account update endpoint can move the account recovery email AND mint a
 * fresh API token in one request. Without a step-up, a stolen session converts
 * itself into permanent access: change the email so password reset goes to the
 * attacker, rotate a token so access survives a password change, and the real
 * owner has no way back.
 *
 * Laravel's RequirePassword middleware answers that: it demands the password
 * again if it has not been confirmed recently, independent of the session.
 */
class AccountStepUpTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make('correct-horse')]);
    }

    public function test_account_update_requires_recent_password_confirmation()
    {
        $user = $this->user();

        $this->actingAs($user)
            ->put(route('account.update', $user->id), [
                'name' => 'Renamed',
                'email' => 'attacker@example.com',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($user->email, $user->fresh()->email, 'the email changed without a password check');
    }

    public function test_token_rotation_requires_recent_password_confirmation()
    {
        $user = $this->user();
        $before = $user->api_token;

        $this->actingAs($user)
            ->put(route('account.update', $user->id), [
                'name' => $user->name,
                'email' => $user->email,
                'rotate_api_token' => 1,
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($before, $user->fresh()->api_token, 'the API token rotated without a password check');
    }

    public function test_update_succeeds_once_the_password_has_been_confirmed()
    {
        $user = $this->user();

        $this->actingAs($user)->post('/confirm-password', ['password' => 'correct-horse'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->put(route('account.update', $user->id), [
                'name' => 'Renamed',
                'email' => 'new@example.com',
            ])
            ->assertRedirect(route('account.index'));

        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertSame('Renamed', $user->fresh()->name);
    }

    /** Viewing must stay open, or the confirm screen is unreachable from it. */
    public function test_account_page_itself_does_not_require_confirmation()
    {
        $this->actingAs($this->user())->get(route('account.index'))->assertStatus(200);
    }

    /**
     * The confirm endpoint checks a password on every call, so unthrottled it
     * is a password oracle for anyone holding a session. Every other
     * credential-checking POST in routes/auth.php already carries throttle:6,1.
     */
    public function test_password_confirmation_is_rate_limited()
    {
        $user = $this->user();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->post('/confirm-password', ['password' => 'wrong-' . $i]);
        }

        $this->actingAs($user)
            ->post('/confirm-password', ['password' => 'wrong-again'])
            ->assertStatus(429);
    }
}
