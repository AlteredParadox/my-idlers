<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #121 ("Can't add more than 10 idlers"): in the Docker image every
 * failed create form "redirected to the dashboard" with no error shown.
 *
 * Root cause: docker/nginx.conf sent `Referrer-Policy: strict-origin`, which
 * trims the Referer to the bare origin. Every validation failure (and the
 * YABS parse failure) is a Laravel back(), which redirects to the Referer
 * URL -- so it went to `/`, the one page that did not render the flashed
 * errors. Nothing limits the number of servers; the reporter had hit an
 * ordinary validation error and could not see it.
 */
class Issue121BackRedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Providers $provider;
    private Locations $location;
    private OS $os;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->provider = Providers::create(['name' => 'Test Provider']);
        $this->location = Locations::create(['name' => 'Test Location']);
        $this->os = OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function serverPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'issue121.example.com',
            'server_type' => 1,
            'os_id' => $this->os->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'ram' => 2048,
            'ram_type' => 'MB',
            'disk' => [50],
            'disk_type' => ['GB'],
            'disk_media' => ['SSD'],
            'cpu' => 2,
            'bandwidth' => 1000,
            'ssh_port' => 22,
            'was_promo' => 0,
            'currency' => 'USD',
            'price' => 5.00,
            'payment_term' => 1,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ], $overrides);
    }

    public function test_the_docker_referrer_policy_keeps_the_path_on_same_origin_requests()
    {
        $conf = file_get_contents(base_path('docker/nginx.conf'));

        $this->assertSame(1, preg_match('/add_header Referrer-Policy "([^"]+)"/', $conf, $m));

        // strict-origin, origin and no-referrer all break back(): the first
        // two redirect every failed form to `/`, the last falls back to the
        // session's previous URL, which any plain fetch() GET overwrites.
        $this->assertSame('same-origin', $m[1]);
    }

    public function test_a_validation_failure_behind_an_origin_only_referer_still_shows_its_errors()
    {
        // What strict-origin produced: Referer is the bare origin, so back()
        // lands on the dashboard. Laravel honours the Referer -- the fix is
        // the header -- but the dashboard must not swallow the errors either.
        $this->actingAs($this->user)
            ->from('http://localhost')
            ->post(route('servers.store'), $this->serverPayload(['hostname' => 'nas']))
            ->assertRedirect('/')
            ->assertSessionHasErrors('hostname');

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee('The hostname must be at least 5 characters.');

        $this->assertDatabaseCount('servers', 0);
    }

    public function test_a_yabs_parse_failure_behind_an_origin_only_referer_still_shows_its_error()
    {
        Pricing::create([
            'service_id' => 'srv00001', 'service_type' => 1, 'currency' => 'USD', 'price' => 5.00,
            'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'srv00001', 'hostname' => 'yabs-target.example.com', 'server_type' => 1,
            'os_id' => $this->os->id, 'provider_id' => $this->provider->id,
            'location_id' => $this->location->id, 'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);

        $this->actingAs($this->user)
            ->from('http://localhost')
            ->post(route('yabs.store'), ['server_id' => 'srv00001', 'yabs_json' => 'not yabs output'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('yabs_json');

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee('Could not parse this as YABS JSON output');
    }

    public function test_there_is_no_limit_of_ten_servers()
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($this->user)
                ->post(route('servers.store'), $this->serverPayload(['hostname' => sprintf('server-%02d.example.com', $i)]))
                ->assertRedirect(route('servers.index'))
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('servers', 12);
    }
}
