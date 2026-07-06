<?php

namespace Tests\Feature;

use App\Services\PrometheusService;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

/**
 * Regression for the round-35 review finding: the list views' JS matcher
 * accepted a stored short hostname against an FQDN nodename, but
 * resolveInstance did not — the servers index showed live monitoring
 * while the detail page permanently 404ed for the same server.
 */
class Round35RegressionTest extends TestCase
{
    private function resolve(FakePrometheusClient $client, string $hostname): ?string
    {
        $method = new \ReflectionMethod(PrometheusService::class, 'resolveInstance');

        return $method->invoke(new PrometheusService($client), $hostname);
    }

    public function test_short_stored_hostname_resolves_against_fqdn_nodename()
    {
        $client = new FakePrometheusClient(instant: [
            'node_uname_info' => [
                ['metric' => ['instance' => '10.0.0.1:9100', 'nodename' => 'web1.example.com'], 'value' => [1700000000, '1']],
            ],
            'up{job="node"}' => [],
        ]);

        $this->assertSame('10.0.0.1:9100', $this->resolve($client, 'web1'));
    }

    public function test_short_stored_hostname_resolves_against_fqdn_instance()
    {
        $client = new FakePrometheusClient(instant: [
            'node_uname_info' => [],
            'up{job="node"}' => [
                ['metric' => ['instance' => 'web1.example.com:9100'], 'value' => [1700000000, '1']],
            ],
        ]);

        $this->assertSame('web1.example.com:9100', $this->resolve($client, 'web1'));
    }

    public function test_matcher_still_rejects_prefix_hosts_and_distinct_ips()
    {
        $client = new FakePrometheusClient(instant: [
            'node_uname_info' => [
                ['metric' => ['instance' => '10.0.0.10:9100', 'nodename' => 'web10.example.com'], 'value' => [1700000000, '1']],
            ],
            'up{job="node"}' => [
                ['metric' => ['instance' => '192.168.1.5:9100'], 'value' => [1700000000, '1']],
            ],
        ]);

        $this->assertNull($this->resolve($client, 'web1'), 'web10 must not answer for web1');
        $this->assertNull($this->resolve($client, '192.168.1.6'), 'first-octet equality hole');
    }
}
