<?php

namespace Tests\Feature;

use App\Services\PrometheusService;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

/**
 * Regression for the round-36 review finding: offline nodes vanish from
 * instant uname queries, but the LIST keeps tracking them via
 * last_over_time — the detail page must resolve them the same way or a
 * down server's monitoring panel 404s while its history exists.
 */
class Round36RegressionTest extends TestCase
{
    private function resolve(FakePrometheusClient $client, string $hostname): ?string
    {
        $method = new \ReflectionMethod(PrometheusService::class, 'resolveInstance');

        return $method->invoke(new PrometheusService($client), $hostname);
    }

    /** IP scrape target, node down past the staleness window: uname empty, nodename only in last_over_time. */
    private function offlineClient(string $lastKnownNodename): FakePrometheusClient
    {
        return new FakePrometheusClient(instant: [
            'last_over_time' => [
                ['metric' => ['instance' => '10.0.0.5:9100', 'nodename' => $lastKnownNodename], 'value' => [1700000000, '1']],
            ],
            'node_uname_info' => [],
            'up{job="node"}' => [
                ['metric' => ['instance' => '10.0.0.5:9100'], 'value' => [1700000000, '0']],
            ],
        ]);
    }

    public function test_offline_node_resolves_via_last_known_nodename()
    {
        $this->assertSame(
            '10.0.0.5:9100',
            $this->resolve($this->offlineClient('web1.example.com'), 'web1')
        );
    }

    public function test_offline_fallback_keeps_reject_semantics()
    {
        // last_over_time must not let web10 answer for web1.
        $this->assertNull($this->resolve($this->offlineClient('web10.example.com'), 'web1'));
    }

    public function test_up_nodes_do_not_hit_the_offline_fallback()
    {
        // An UP instance whose nodename doesn't match must stay unresolved
        // rather than falling through to a stale last_over_time answer.
        $client = new FakePrometheusClient(instant: [
            'last_over_time' => [
                ['metric' => ['instance' => '10.0.0.9:9100', 'nodename' => 'web1.example.com'], 'value' => [1700000000, '1']],
            ],
            'node_uname_info' => [
                ['metric' => ['instance' => '10.0.0.9:9100', 'nodename' => 'other.example.com'], 'value' => [1700000000, '1']],
            ],
            'up{job="node"}' => [
                ['metric' => ['instance' => '10.0.0.9:9100'], 'value' => [1700000000, '1']],
            ],
        ]);

        $this->assertNull($this->resolve($client, 'web1'));
    }
}
