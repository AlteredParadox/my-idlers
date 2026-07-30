<?php

namespace Tests\Feature;

use App\Models\Domains;
use App\Models\Labels;
use App\Models\Locations;
use App\Models\Misc;
use App\Models\NetworkSpeed;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Settings;
use App\Models\Shared;
use App\Models\User;
use App\Models\Yabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The query API serializes to a JSON string and used to hand that string to the
 * generic response() helper, which sets no Content-Type -- so Symfony labelled
 * every one of these endpoints `text/html`. Stored `<img onerror=...>` inside a
 * record then became an active element in the app's own origin for anyone who
 * loaded the endpoint in a browser.
 */
class ApiResponseContentTypeTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    /**
     * Sweep every parameterless API GET route rather than listing them, so a
     * newly added collection endpoint cannot reintroduce the class unnoticed.
     */
    public function test_no_parameterless_api_get_route_responds_as_html()
    {
        $checked = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (!in_array('GET', $route->methods(), true) || !Str::startsWith($uri, 'api/')) {
                continue;
            }
            if (Str::contains($uri, '{')) {
                continue;   // covered by the single-record test below
            }

            $type = $this->get('/' . $uri, $this->auth())->headers->get('Content-Type');

            $this->assertStringNotContainsString(
                'text/html',
                (string) $type,
                "GET /{$uri} responded as HTML ({$type})"
            );
            $checked[] = $uri;
        }

        // Guard the guard: an empty sweep would pass vacuously.
        $this->assertGreaterThan(10, count($checked));
    }

    public function test_collection_routes_declare_application_json()
    {
        foreach (['servers', 'domains', 'shared', 'reseller', 'seedbox', 'misc', 'yabs',
                  'providers', 'locations', 'os', 'labels', 'dns', 'IPs',
                  'pricing', 'networkSpeeds', 'settings'] as $collection) {
            $this->get("/api/{$collection}/", $this->auth())
                ->assertStatus(200)
                ->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        }
    }

    public function test_single_record_routes_declare_application_json()
    {
        $provider = Providers::create(['name' => 'Acme']);
        $location = Locations::create(['name' => 'Falkenstein']);
        $os = OS::create(['name' => 'Ubuntu 24.04']);
        $label = Labels::create(['id' => Str::random(8), 'label' => 'prod']);

        $routes = [
            "/api/providers/{$provider->id}",
            "/api/locations/{$location->id}",
            "/api/os/{$os->id}",
            "/api/labels/{$label->id}",
        ];

        foreach ($routes as $route) {
            $this->get($route, $this->auth())
                ->assertStatus(200)
                ->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        }
    }

    /**
     * The single-record service endpoints, which the audit reported one by one.
     * Each returns a serialized model through the same helper, and each was
     * previously answered as text/html.
     */
    public function test_single_service_routes_declare_application_json()
    {
        $provider = Providers::create(['name' => 'Acme']);
        $location = Locations::create(['name' => 'Falkenstein']);
        $os = OS::create(['name' => 'Ubuntu 24.04']);
        Settings::create(['id' => 1]);

        $serverId = Str::random(8);
        (new Pricing())->insertPricing(1, $serverId, 'USD', 5.00, 1, '2027-01-01');
        Server::create([
            'id' => $serverId, 'hostname' => 'box.example.com', 'server_type' => 1,
            'os_id' => $os->id, 'provider_id' => $provider->id, 'location_id' => $location->id,
            'ram' => 2048, 'ram_type' => 'MB', 'ram_as_mb' => 2048,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50, 'cpu' => 2,
            'has_yabs' => 0, 'was_promo' => 0, 'active' => 1, 'show_public' => 0,
            'bandwidth' => 1000, 'owned_since' => '2026-01-01',
        ]);

        (new Pricing())->insertPricing(2, 'ctshared', 'USD', 5.00, 1, '2027-01-01');
        $shared = Shared::create([
            'id' => 'ctshared', 'main_domain' => 'shared.example.com', 'shared_type' => 'cPanel',
            'provider_id' => $provider->id, 'location_id' => $location->id,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50, 'bandwidth' => 500,
            'domains_limit' => 10, 'subdomains_limit' => 50, 'ftp_limit' => 10,
            'email_limit' => 100, 'db_limit' => 10, 'active' => 1, 'owned_since' => '2026-01-01',
        ]);

        (new Pricing())->insertPricing(3, 'ctresell', 'USD', 5.00, 1, '2027-01-01');
        $reseller = Reseller::create([
            'id' => 'ctresell', 'main_domain' => 'reseller.example.com', 'reseller_type' => 'WHM',
            'accounts' => 15, 'provider_id' => $provider->id, 'location_id' => $location->id,
            'disk' => 200, 'disk_type' => 'GB', 'disk_as_gb' => 200, 'bandwidth' => 2000,
            'domains_limit' => 100, 'subdomains_limit' => 500, 'ftp_limit' => 100,
            'email_limit' => 1000, 'db_limit' => 100, 'active' => 1, 'owned_since' => '2026-01-01',
        ]);

        (new Pricing())->insertPricing(6, 'ctseedbx', 'USD', 5.00, 1, '2027-01-01');
        $seedbox = SeedBoxes::create([
            'id' => 'ctseedbx', 'title' => 'Box', 'hostname' => 'seed.example.com',
            'seed_box_type' => 'Dedicated', 'provider_id' => $provider->id,
            'location_id' => $location->id, 'disk' => 2000, 'disk_type' => 'GB',
            'disk_as_gb' => 2000, 'bandwidth' => 10000, 'port_speed' => 1000,
            'active' => 1, 'owned_since' => '2026-01-01',
        ]);

        (new Pricing())->insertPricing(4, 'ctdomain', 'USD', 5.00, 1, '2027-01-01');
        $domain = Domains::create([
            'id' => 'ctdomain', 'domain' => 'example', 'extension' => 'com',
            'provider_id' => $provider->id, 'owned_since' => '2026-01-01',
        ]);

        (new Pricing())->insertPricing(5, 'ctmiscsv', 'USD', 5.00, 1, '2027-01-01');
        $misc = Misc::create([
            'id' => 'ctmiscsv', 'name' => 'Backups', 'owned_since' => '2026-01-01',
        ]);

        $yabs = Yabs::create([
            'id' => 'ctyabsid', 'server_id' => $serverId, 'output_date' => '2026-01-01',
            'has_ipv6' => 0, 'aes' => 0, 'vm' => 0, 'cpu_cores' => 2,
            'cpu_freq' => 2400, 'cpu_model' => 'Test CPU', 'disk_speed' => 100,
            'disk_speed_type' => 'MB/s', 'ram' => 2048, 'ram_type' => 'MB',
            'ram_mb' => 2048, 'disk' => 50, 'disk_type' => 'GB', 'disk_gb' => 50,
        ]);

        NetworkSpeed::create([
            // network_speed.id is a FK to yabs.id -- one speed row per benchmark
            'id' => $yabs->id, 'server_id' => $serverId, 'location' => 'Amsterdam',
            'send' => 900, 'send_type' => 'MBps', 'send_as_mbps' => 900,
            'receive' => 900, 'receive_type' => 'MBps', 'receive_as_mbps' => 900,
        ]);

        $routes = [
            "/api/servers/{$serverId}",
            "/api/shared/{$shared->id}",
            "/api/reseller/{$reseller->id}",
            "/api/seedbox/{$seedbox->id}",
            "/api/domains/{$domain->id}",
            "/api/misc/{$misc->id}",
            "/api/yabs/{$yabs->id}",
            "/api/networkSpeeds/{$serverId}",
        ];

        foreach ($routes as $route) {
            $this->get($route, $this->auth())
                ->assertStatus(200)
                ->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        }
    }

    /**
     * The payload is unchanged -- markup is still stored and returned verbatim,
     * because escaping JSON string content would corrupt the data. What changes
     * is that the browser is told to parse it as data, not as a document.
     */
    public function test_stored_markup_is_returned_as_json_not_as_a_parsable_document()
    {
        $payload = '<img src=x onerror=alert(1)>';
        Providers::create(['name' => $payload]);

        $response = $this->get('/api/providers/', $this->auth())
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $decoded = json_decode($response->getContent(), true);

        $this->assertSame($payload, $decoded[0]['name']);
    }
}
