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
    }
}
