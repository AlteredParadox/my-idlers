<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Settings;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $maxUsers = (int) env('MAX_USERS', 1);

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
        if ($this->registrationClosed()) {
            abort(403, 'Registration is closed.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'api_token' => User::hashApiToken(Str::random(60))
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect('/');
    }
}
