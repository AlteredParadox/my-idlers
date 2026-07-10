<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|\Illuminate\View\View
     */
    public function create()
    {
        if ($this->registrationClosed()) {
            return redirect('/login');
        }
        return view('auth.register');
    }

    /**
     * Whether the single-user (or MAX_USERS) registration cap has been reached.
     */
    private function registrationClosed(): bool
    {
        $maxUsers = (int) config('custom.max_users', 1);

        return $maxUsers > 0 && User::count() >= $maxUsers;
    }

    /**
     * Handle an incoming registration request.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        // The GET view guards on this too, but the cap MUST be enforced here:
        // a guest can POST directly to /register without ever hitting create().
        // This is only the cheap fast path — the authoritative check runs
        // under a lock below.
        if ($this->registrationClosed()) {
            abort(403, 'Registration is closed.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Count-then-insert is a check/read-then-write race: two concurrent
        // POSTs both read 0 users and both insert, landing 2 accounts under
        // MAX_USERS=1 — and no service table is user-scoped, so the second
        // account reads and writes everything.
        //
        // Serialize on an out-of-band atomic lock, NOT on a data row: there
        // is no user row to lock while the table is empty, and locking the
        // settings row silently no-ops whenever a warm `settings` cache
        // entry shadows a missing row (getSettings() returns the cached
        // ghost, lockForUpdate()->first() finds nothing, no lock is taken).
        // The transaction and the re-check inside it are defence in depth.
        $lock = Cache::lock('registration.cap', 10);

        try {
            // Fail closed: a lock we cannot take means someone else is
            // registering right now, and we must not count-then-insert past
            // them. Timeout is configurable so tests can assert the wait.
            $lock->block((int) config('custom.registration_lock_seconds', 5));

            $user = DB::transaction(function () use ($request) {
                if ($this->registrationClosed()) {
                    return null;
                }

                // unique:users validated BEFORE the lock, so two concurrent
                // same-email submits can both pass it; the loser lands here
                // on the unique index. Surface it as the normal validation
                // error instead of a raw QueryException 500.
                return $this->createUniquely(fn() => User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'api_token' => User::hashApiToken(Str::random(60))
                ]), 'email');
            });
        } finally {
            $lock->release();
        }

        if (is_null($user)) {
            abort(403, 'Registration is closed.');
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect('/');
    }
}
