<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // The 'api' guard, deliberately NOT Laravel's built-in 'token' driver.
        // TokenGuard::getTokenForRequest() also accepts ?api_token=, a request
        // body field and the basic-auth password, and none of that is
        // configurable. A reusable plaintext credential in a URL leaks into
        // nginx access logs, Referer headers and browser history -- and it lets
        // a plain top-level navigation authenticate an API route, which is what
        // turns a JSON response into a browser-parsed page. The documented
        // interface has always been `Authorization: Bearer <token>`; this is it.
        Auth::viaRequest('api-token', function (Request $request) {
            $token = $request->bearerToken();

            if (!is_string($token) || $token === '') {
                return null;
            }

            // Tokens are stored sha256-hashed, so this compares hash to hash --
            // the plaintext is never persisted and never queried directly.
            return User::where('api_token', User::hashApiToken($token))->first();
        });
    }
}
