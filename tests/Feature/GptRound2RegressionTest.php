<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Providers;
use App\Models\User;
use App\Services\PrometheusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 GPT review (2nd batch): duplicate catalog
 * names, ping option-injection, the IP-first-label matcher hole.
 */
class GptRound2RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        $this->user = User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
    }

    public function test_duplicate_catalog_names_are_validation_errors_not_500()
    {
        Providers::create(['name' => 'Dup Provider']);
        Locations::create(['name' => 'Dup Location']);
        OS::create(['name' => 'Dup OS']);

        $this->actingAs($this->user)->post(route('providers.store'), ['provider_name' => 'Dup Provider'])
            ->assertSessionHasErrors('provider_name');
        $this->actingAs($this->user)->post(route('locations.store'), ['location_name' => 'Dup Location'])
            ->assertSessionHasErrors('location_name');
        $this->actingAs($this->user)->post(route('os.store'), ['os_name' => 'Dup OS'])
            ->assertSessionHasErrors('os_name');

        $this->assertSame(1, Providers::where('name', 'Dup Provider')->count());
        $this->assertSame(1, Locations::where('name', 'Dup Location')->count());
        $this->assertSame(1, OS::where('name', 'Dup OS')->count());
    }

    public function test_ping_tool_rejects_option_style_hostnames()
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token];

        // Leading-dash values ping would consume as options.
        foreach (['-V', '-f', '--help'] as $bad) {
            $this->getJson('/api/online/' . rawurlencode($bad), $headers)
                ->assertStatus(422)
                ->assertJson(['is_online' => false]);
        }
    }

    public function test_prometheus_matcher_rejects_bare_first_label_against_ip()
    {
        // A nodename that is a bare first label ('192') must not match a
        // server stored as a dotted IP (192.168.1.6) via short-label logic.
        $client = new FakePrometheusClient(instant: [
            'node_uname_info' => [
                ['metric' => ['instance' => '10.9.9.9:9100', 'nodename' => '192'], 'value' => [1700000000, '1']],
            ],
            'up{job="node"}' => [
                ['metric' => ['instance' => '192.168.1.5:9100'], 'value' => [1700000000, '1']],
            ],
        ]);

        $method = new \ReflectionMethod(PrometheusService::class, 'resolveInstance');
        // stored IP must not resolve to the '192' nodename nor the .5 target
        $this->assertNull($method->invoke(new PrometheusService($client), '192.168.1.6'));
    }

    public function test_prometheus_matcher_still_resolves_exact_ip_and_fqdn()
    {
        $client = new FakePrometheusClient(instant: [
            'node_uname_info' => [
                ['metric' => ['instance' => '192.168.1.6:9100', 'nodename' => 'web1.example.com'], 'value' => [1700000000, '1']],
            ],
            'up{job="node"}' => [],
        ]);
        $method = new \ReflectionMethod(PrometheusService::class, 'resolveInstance');

        // Exact IP (stored == stripped instance) and short-vs-FQDN still work.
        $this->assertSame('192.168.1.6:9100', $method->invoke(new PrometheusService($client), 'web1'));
    }
}
