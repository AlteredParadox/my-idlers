<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        // Keyed on the CONFIGURED scheme, not APP_ENV: run.sh hardcodes
        // APP_ENV=production into every container, and an unconditional
        // force made plain-HTTP LAN installs (an explicitly supported
        // deployment — see the SESSION_SECURE_COOKIE default) silently
        // unusable: every asset/form/redirect URL pointed at an unserved
        // https origin with zero server-side errors to diagnose.
        if (self::shouldForceHttps(config('app.url'))) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Force-https only when the operator configured an https origin —
     * the deployment's declared intent, unlike APP_ENV.
     */
    public static function shouldForceHttps(?string $app_url): bool
    {
        return str_starts_with((string) $app_url, 'https://');
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        // Named limiters, because an INLINE `throttle:n,1` does not get its own
        // counter. ThrottleRequests::resolveRequestSignature keys a guest by
        // sha1($route->getDomain().'|'.$request->ip()) and an authenticated
        // caller by user id -- the ROUTE is not part of the key. Every inline
        // throttle on the site therefore shares ONE counter per identity, and
        // each route merely compares that shared count against its own limit.
        //
        // The effect is that a high-limit route starves a low-limit one:
        // twelve ordinary login-page views (limit 30) pushed the shared count
        // past 6, so the next POST /forgot-password (limit 6) answered 429 and
        // the user could not request a reset. Measured, not theorised.
        //
        // A named limiter uses the key from by(), so each bucket below is
        // genuinely independent.
        foreach (self::RATE_LIMITS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
                ->by($name . '|' . (optional($request->user())->id ?: $request->ip())));
        }
    }

    /**
     * Bucket name => requests per minute.
     *
     * `credentials` deliberately covers login, registration, reset request,
     * reset submission, password confirmation and the verification resend as
     * ONE budget: those are the credential-guessing surface and sharing a
     * counter across them is the point, which is also what the previous inline
     * `throttle:6,1` on each of them added up to.
     */
    private const RATE_LIMITS = [
        'credentials'  => 6,    // credential-checking POSTs
        'guest-pages'  => 30,   // login/register/forgot/reset GETs
        'public-page'  => 30,   // the one unauthenticated content page
        'tools'        => 10,   // ping / whois pulls: each starts outbound work
        'dns-tools'    => 20,   // DNS lookups
        'exports'      => 10,   // eager-load whole tables and build files
        'preferences'  => 60,   // DataTables state saves; chatty by design
        'prometheus'   => 30,   // proxied monitoring reads
        'yabs-ingest'  => 4,    // signed benchmark submissions
        'ping'         => 10,   // API ping, mirroring its web sibling
    ];
}
