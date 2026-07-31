<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // We will send the password reset link to this user, but always answer
        // with the same generic message: echoing the real status (user not
        // found / throttled) tells the form whether an account exists.
        //
        // The try/catch is part of that same guarantee, not belt-and-braces.
        // Delivery is only ATTEMPTED for an address that exists, so anything
        // thrown out of it happens for real accounts and not for unknown ones:
        // with an unreachable mail server this endpoint answered 500 for a
        // registered address and 302 for an unregistered one, which is a
        // cleaner account-existence oracle than any timing difference. That is
        // reachable wherever delivery is inline -- QUEUE_CONNECTION=sync, which
        // .env.example ships and the documented non-Docker production install
        // therefore inherits. The container's queue worker owns delivery, so it
        // was never exposed there.
        //
        // Throwable rather than just the mail transport exceptions: the
        // security property is that the RESPONSE cannot depend on whether the
        // account exists, and that has to hold for whatever the delivery path
        // throws. The failure is not swallowed -- it is logged with its
        // exception so a genuinely broken mail configuration stays diagnosable,
        // which is where an operator should be reading it from anyway.
        try {
            Password::sendResetLink(
                $request->only('email')
            );
        } catch (\Throwable $e) {
            Log::error('password reset link could not be delivered', ['err' => $e]);
        }

        return back()->with('status', __(Password::RESET_LINK_SENT));
    }
}
