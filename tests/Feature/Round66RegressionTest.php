<?php

namespace Tests\Feature;

use App\Providers\RouteServiceProvider;
use Tests\TestCase;

/**
 * Review round 66: URL::forceScheme('https') fired on APP_ENV=production
 * unconditionally — and run.sh hardcodes that env into every container —
 * so a plain-HTTP LAN install (an explicitly supported deployment; the
 * SESSION_SECURE_COOKIE=false default exists for it) generated https
 * asset/form/redirect URLs against an unserved origin: a completely dead
 * app with zero server-side errors. The force is now keyed on the
 * CONFIGURED scheme — the operator's declared intent.
 */
class Round66RegressionTest extends TestCase
{
    public function test_https_forcing_follows_the_configured_scheme_not_the_env()
    {
        $this->assertTrue(RouteServiceProvider::shouldForceHttps('https://idlers.example.com'));
        $this->assertFalse(RouteServiceProvider::shouldForceHttps('http://192.168.1.50:8000'), 'plain-HTTP LAN installs must not be forced to https');
        $this->assertFalse(RouteServiceProvider::shouldForceHttps(null));
        $this->assertFalse(RouteServiceProvider::shouldForceHttps(''));

        // The boot path must consume the helper (and not the old env check)
        $provider = file_get_contents(app_path('Providers/RouteServiceProvider.php'));
        $this->assertStringContainsString('self::shouldForceHttps(config(\'app.url\'))', $provider);
        $this->assertStringNotContainsString("config('app.env') === 'production'", $provider);
    }
}
