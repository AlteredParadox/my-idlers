<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PrometheusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

class PrometheusToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_prometheus_status()
    {
        $this->get('/tools/prometheus/status')->assertRedirect(route('login'));
    }

    public function test_status_returns_404_when_prometheus_disabled()
    {
        $this->app->instance(PrometheusClient::class, new FakePrometheusClient(enabled: false));

        $this->actingAs(User::factory()->create())
            ->getJson('/tools/prometheus/status')
            ->assertStatus(404);
    }

    public function test_status_returns_payload_when_enabled()
    {
        $this->app->instance(PrometheusClient::class, new FakePrometheusClient(
            instant: [
                'node_uname_info' => [['metric' => ['instance' => '10.0.0.1:9100', 'nodename' => 'web1'], 'value' => [1700000000, '1']]],
                'up{job="node"}' => [['metric' => ['instance' => '10.0.0.1:9100'], 'value' => [1700000000, '1']]],
            ],
        ));

        $this->actingAs(User::factory()->create())
            ->getJson('/tools/prometheus/status')
            ->assertStatus(200)
            ->assertJsonPath('statuses.web1', true)
            ->assertJsonPath('interval', 30);
    }

    public function test_detail_rejects_unknown_period()
    {
        $this->app->instance(PrometheusClient::class, new FakePrometheusClient());

        // '5h' passes the route regex but is not a defined period
        $this->actingAs(User::factory()->create())
            ->getJson('/tools/prometheus/detail/web1/5h/0')
            ->assertStatus(400);
    }

    public function test_detail_returns_404_for_host_unknown_to_prometheus()
    {
        $this->app->instance(PrometheusClient::class, new FakePrometheusClient());

        $this->actingAs(User::factory()->create())
            ->getJson('/tools/prometheus/detail/unknown-host/24h/0')
            ->assertStatus(404);
    }
}
