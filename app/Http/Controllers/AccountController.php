<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index()
    {
        return view('account.index');
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore(Auth::id())],
            'rotate_api_token' => ['sometimes', 'boolean'],
        ]);

        $user = Auth::user();
        $user->name = $request->name;
        $user->email = $request->email;
        // The ignore-self unique rule races a concurrent registration or
        // account update committing the same email (reachable with
        // MAX_USERS >= 2); the loser would hit users_email_unique raw.
        $this->createUniquely(fn() => $user->save(), 'email');

        if ($request->boolean('rotate_api_token')) {
            $token = $user->rotateApiToken();

            return redirect()->route('account.index')
                ->with('success', 'Account Updated Successfully. Copy your new API token now; it will not be shown again.')
                ->with('new_api_token', $token);
        }

        return redirect()->route('account.index')
            ->with('success', 'Account Updated Successfully.');
    }
}
