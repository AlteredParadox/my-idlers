<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\PrometheusInstanceResolver;
use App\Support\BoundedHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

class AuditRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    // ---- #15: whois/rates fetches must not follow redirects --------------

    /**
     * Both callers target a fixed third-party endpoint. Following a redirect
     * hands the destination to that third party: a 302 to 169.254.169.254 or
     * an internal address turns this into a blind SSRF from inside the
     * network the app runs on.
     */
    public function test_bounded_fetch_does_not_follow_redirects()
    {
        Http::fake([
            'first.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response(json_encode(['secret' => 'creds']), 200),
        ]);

        $this->assertNull(BoundedHttp::json('https://first.test/json/1.2.3.4', 1024));

        Http::assertNotSent(fn($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_bounded_fetch_declares_redirects_off()
    {
        $source = file_get_contents(app_path('Support/BoundedHttp.php'));

        $this->assertStringContainsString("'allow_redirects' => false", $source);
    }

    // ---- #11/#14: an ambiguous hostname must not bind to an instance -----

    private function resolverFor(array $nodes): PrometheusInstanceResolver
    {
        $rows = [];
        foreach ($nodes as $instance => $nodename) {
            $rows[] = ['metric' => ['instance' => $instance, 'nodename' => $nodename], 'value' => [0, '1']];
        }

        return new PrometheusInstanceResolver(new FakePrometheusClient(
            instant: ['node_uname_info' => $rows, 'up{job="node"}' => $rows],
        ));
    }

    public function test_an_unambiguous_short_hostname_still_resolves()
    {
        $resolver = $this->resolverFor(['10.0.0.1:9100' => 'web01.dc1.example.com']);

        $this->assertSame('10.0.0.1:9100', $resolver->resolve('web01'));
    }

    /**
     * Two machines sharing a first DNS label used to resolve to whichever came
     * back first -- one server's charts, filesystems and uptime rendered under
     * the other's name, with nothing on screen saying so.
     */
    public function test_an_ambiguous_short_hostname_resolves_to_nothing()
    {
        $resolver = $this->resolverFor([
            '10.0.0.1:9100' => 'web01.dc1.example.com',
            '10.0.0.2:9100' => 'web01.dc2.example.com',
        ]);

        $this->assertNull($resolver->resolve('web01'),
            'an ambiguous hostname bound to an instance anyway');
    }

    public function test_a_full_fqdn_disambiguates()
    {
        $resolver = $this->resolverFor([
            '10.0.0.1:9100' => 'web01.dc1.example.com',
            '10.0.0.2:9100' => 'web01.dc2.example.com',
        ]);

        $this->assertSame('10.0.0.2:9100', $resolver->resolve('web01.dc2.example.com'));
    }

    // ---- #10: the public page must not load every historical benchmark ---

    public function test_the_public_server_query_caps_benchmarks_per_server()
    {
        $source = file_get_contents(app_path('Models/Server.php'));

        $this->assertMatchesRegularExpression(
            "/'yabs' => fn\(\\\$query\) => \\\$query->limit\(1\)/",
            $source,
            'the public eager load is unconstrained again'
        );
    }

    // ---- #4: the one unauthenticated page must be throttled --------------

    public function test_the_public_server_page_is_rate_limited()
    {
        $route = collect(Route::getRoutes())->first(fn($r) => $r->uri() === 'servers/public');

        $this->assertNotNull($route);
        $this->assertContains('throttle:public-page', $route->gatherMiddleware(),
            'the public page can be hit without limit, and each request persists a session row');
    }

    // ---- #9: root must not execute PHP that www-data can write -----------

    public function test_migration_source_is_not_writable_by_the_php_identity()
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $run = file_get_contents(base_path('run.sh'));

        // The entrypoint runs `artisan migrate` as root, so anything www-data
        // can write under /app/database is code root will execute next boot.
        $this->assertStringContainsString(
            'chown -R root:root /app/database/migrations /app/database/seeders /app/database/factories',
            $dockerfile
        );
        $this->assertStringNotContainsString('chown -R www-data:www-data /app/database', $run,
            'the entrypoint re-grants write on the migration source every boot');
    }

    // ---- #7: the mail transport must be able to require TLS --------------

    public function test_mail_config_exposes_scheme_rather_than_the_ignored_encryption_key()
    {
        $mail = file_get_contents(config_path('mail.php'));

        // Laravel 13's createSmtpTransport reads only 'scheme'; 'encryption'
        // looked like a TLS requirement while enforcing nothing.
        $this->assertStringContainsString("'scheme' => env('MAIL_SCHEME')", $mail);
        $this->assertStringNotContainsString("'encryption' => env(", $mail);
        $this->assertNull(config('mail.mailers.smtp.encryption'));
    }

    // ---- #8: the reset form must not answer "does this account exist" ----

    public function test_reset_failures_are_indistinguishable()
    {
        $unknownUser = __('passwords.user');
        $badToken = __('passwords.token');

        $this->assertSame($badToken, $unknownUser,
            'an unknown email and a bad token render different errors, enumerating accounts');
    }

    public function test_a_reset_attempt_with_an_unknown_email_looks_like_a_bad_token()
    {
        User::factory()->create(['email' => 'real@example.com']);

        $unknown = $this->from('/reset-password/x')->post('/reset-password', [
            'token' => 'bogus', 'email' => 'nobody@example.com',
            'password' => 'sufficiently-long-pw1', 'password_confirmation' => 'sufficiently-long-pw1',
        ]);
        $known = $this->from('/reset-password/x')->post('/reset-password', [
            'token' => 'bogus', 'email' => 'real@example.com',
            'password' => 'sufficiently-long-pw1', 'password_confirmation' => 'sufficiently-long-pw1',
        ]);

        $this->assertSame(
            session()->get('errors')?->first('email'),
            $known->getSession()->get('errors')?->first('email')
        );
        $this->assertEquals(
            $unknown->getSession()->get('errors')->first('email'),
            $known->getSession()->get('errors')->first('email'),
            'the two failures are distinguishable'
        );
    }

    // ---- #13: throttle key must fold the same way the DB lookup does -----

    public function test_login_throttle_key_folds_accents_like_the_database_lookup()
    {
        $make = function (string $email) {
            $request = \App\Http\Requests\Auth\LoginRequest::create('/login', 'POST', ['email' => $email]);
            $request->setLaravelSession(app('session.store'));

            return $request->throttleKey();
        };

        // MySQL's accent-insensitive collation treats these as one account, so
        // two throttle buckets doubled the allowance against it.
        $this->assertSame($make('jose@example.com'), $make('josé@example.com'));
        $this->assertSame($make('jose@example.com'), $make('JOSE@example.com'));
    }

    // ---- #12: the reset notification must be queueable -------------------

    public function test_the_password_reset_notification_is_queueable()
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new \App\Notifications\QueuedResetPassword('token'),
            'with a real queue configured this is what takes the SMTP conversation '
            . 'off the request and closes the /forgot-password timing oracle'
        );
    }
}
