<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use Database\Seeders\UsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeploymentHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The demo set creates a verified administrator whose password is published
     * in the README, so seeding it on a reachable deployment hands that
     * deployment to anyone who reads the docs.
     */
    public function test_demo_data_is_refused_in_production_by_default()
    {
        config(['custom.seed_demo_data' => true, 'custom.allow_demo_data_in_production' => false]);
        app()['env'] = 'production';

        $this->artisan('db:seed', ['--force' => true]);

        $this->assertDatabaseMissing('users', ['email' => UsersSeeder::DEMO_EMAIL]);
        $this->assertSame(0, DB::table('servers')->count(), 'demo servers were seeded in production');
    }

    /** A public demo instance is legitimate, so there is an explicit opt-in. */
    public function test_demo_data_can_be_forced_in_production_with_the_second_switch()
    {
        config(['custom.seed_demo_data' => true, 'custom.allow_demo_data_in_production' => true]);
        app()['env'] = 'production';

        $this->artisan('db:seed', ['--force' => true]);

        $this->assertDatabaseHas('users', ['email' => UsersSeeder::DEMO_EMAIL]);
    }

    /** Outside production the flag alone still works, as before. */
    public function test_demo_data_still_seeds_outside_production()
    {
        config(['custom.seed_demo_data' => true]);
        app()['env'] = 'local';

        $this->seed();

        $this->assertDatabaseHas('users', ['email' => UsersSeeder::DEMO_EMAIL]);
    }

    public function test_demo_data_stays_off_when_the_flag_is_unset()
    {
        config(['custom.seed_demo_data' => false]);

        $this->seed();

        $this->assertDatabaseMissing('users', ['email' => UsersSeeder::DEMO_EMAIL]);
    }

    /**
     * Laravel's default trusts every subdomain of APP_URL. Password-reset mail
     * is built from the request host, so a subdomain an attacker controls would
     * put the reset link on their own domain.
     */
    public function test_the_trusted_host_pattern_is_anchored_and_quoted()
    {
        config(['app.url' => 'https://idlers.example.com']);

        // These are REGEXES, not literals. The anchors and preg_quote are the
        // control, not decoration -- see symfonyAccepts() below.
        $this->assertSame(['^idlers\.example\.com$'], (new TrustHosts(app()))->hosts());
    }

    public static function untrustedHosts(): array
    {
        return [
            'suffix domain' => ['idlers.example.com.attacker.invalid'],
            'subdomain'     => ['evil.idlers.example.com'],
            'unescaped dot' => ['idlersXexample.com'],
            'prefix'        => ['notidlers.example.com'],
            'unrelated'     => ['attacker.invalid'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedHosts')]
    public function test_untrusted_hosts_are_rejected(string $host)
    {
        config(['app.url' => 'https://idlers.example.com']);

        $this->assertFalse($this->symfonyAccepts($host), "$host was trusted");
    }

    public function test_the_exact_host_is_still_accepted()
    {
        config(['app.url' => 'https://idlers.example.com']);

        $this->assertTrue($this->symfonyAccepts('idlers.example.com'));
        $this->assertTrue($this->symfonyAccepts('IDLERS.EXAMPLE.COM'), 'host matching is case-insensitive');
    }

    /**
     * Applies the pattern the way Symfony does -- wrapped as `{pattern}i` and
     * matched UNANCHORED -- rather than adding anchors in the test.
     *
     * That distinction is the whole point: an earlier version of this test
     * anchored the pattern itself, which made a bare 'idlers.example.com'
     * pattern look safe while Symfony was accepting
     * 'idlers.example.com.attacker.invalid' and putting that host into emailed
     * password-reset links.
     */
    private function symfonyAccepts(string $host): bool
    {
        foreach ((new TrustHosts(app()))->hosts() as $pattern) {
            if (preg_match(sprintf('{%s}i', $pattern), $host)) {
                return true;
            }
        }

        return false;
    }

    public function test_an_unparseable_app_url_falls_back_rather_than_rejecting_everything()
    {
        config(['app.url' => '']);

        $this->assertNotEmpty((new TrustHosts(app()))->hosts());
    }
}
