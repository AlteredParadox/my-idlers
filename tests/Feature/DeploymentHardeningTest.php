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
    public function test_only_the_exact_app_url_host_is_trusted()
    {
        config(['app.url' => 'https://idlers.example.com']);

        $hosts = (new TrustHosts(app()))->hosts();

        $this->assertSame(['idlers.example.com'], $hosts);
        $this->assertNotContains('^(.+\.)?idlers\.example\.com$', $hosts);
    }

    public function test_a_subdomain_is_not_trusted()
    {
        config(['app.url' => 'https://idlers.example.com']);

        $hosts = (new TrustHosts(app()))->hosts();

        // TrustHosts patterns are matched as regex by the framework; the exact
        // host must not match an attacker-controlled subdomain.
        $subject = 'evil.idlers.example.com';
        $matched = false;
        foreach ($hosts as $pattern) {
            if (preg_match('#^' . $pattern . '$#i', $subject)) {
                $matched = true;
            }
        }

        $this->assertFalse($matched, 'a subdomain of APP_URL is still trusted');
    }

    public function test_an_unparseable_app_url_falls_back_rather_than_rejecting_everything()
    {
        config(['app.url' => '']);

        $this->assertNotEmpty((new TrustHosts(app()))->hosts());
    }
}
