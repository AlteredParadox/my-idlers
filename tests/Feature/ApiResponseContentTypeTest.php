<?php

namespace Tests\Feature;

use App\Models\Labels;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Providers;
use App\Models\User;
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
