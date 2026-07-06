<?php

namespace Tests\Unit;

use App\Services\PrometheusService;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

class PrometheusServiceTest extends TestCase
{
    private function upRow(string $instance, bool $up): array
    {
        return ['metric' => ['instance' => $instance], 'value' => [1700000000, $up ? '1' : '0']];
    }

    private function metricRow(string $instance, string $value): array
    {
        return ['metric' => ['instance' => $instance], 'value' => [1700000000, $value]];
    }

    private function statusClient(): FakePrometheusClient
    {
        return new FakePrometheusClient(
            instant: [
                // order matters: most specific substrings first
                'last_over_time' => [['metric' => ['instance' => '10.0.0.2:9100', 'nodename' => 'down1'], 'value' => [1700000000, '1']]],
                'node_uname_info' => [['metric' => ['instance' => '10.0.0.1:9100', 'nodename' => 'web1'], 'value' => [1700000000, '1']]],
                'up{job="node"}' => [$this->upRow('10.0.0.1:9100', true), $this->upRow('10.0.0.2:9100', false)],
                'MemAvailable' => [$this->metricRow('10.0.0.1:9100', '42.44')],
                'filesystem_avail' => [$this->metricRow('10.0.0.1:9100', '10.06')],
                'receive' => [$this->metricRow('10.0.0.1:9100', '1000.77')],
                'transmit' => [$this->metricRow('10.0.0.1:9100', '2000.22')],
                'boot_time' => [$this->metricRow('10.0.0.1:9100', '86400.9')],
            ],
            range: [
                // offline-since lookback for the down instance: last seen up at t=200
                'up{job="node",instance="10.0.0.2:9100"}' => [['values' => [[100, '1'], [200, '1'], [300, '0']]]],
            ],
        );
    }

    public function test_status_payload_keys_by_hostname_and_instance_ip()
    {
        $payload = (new PrometheusService($this->statusClient()))->statusPayload();

        $this->assertNotNull($payload);
        $this->assertSame([
            '10.0.0.1' => true,
            'web1' => true,
            '10.0.0.2' => false,
            'down1' => false,
        ], $payload['statuses']);
        $this->assertSame(30, $payload['interval']);
    }

    public function test_status_payload_rounds_metrics_and_resolves_offline_since()
    {
        $payload = (new PrometheusService($this->statusClient()))->statusPayload();

        $this->assertEqualsWithDelta(42.4, $payload['metrics']['web1']['ram_pct'], 0.001);
        $this->assertEqualsWithDelta(10.1, $payload['metrics']['web1']['disk_pct'], 0.001);
        $this->assertEqualsWithDelta(1000.8, $payload['metrics']['web1']['net_rx'], 0.001);
        $this->assertEquals(86401, $payload['metrics']['web1']['uptime']);
        // last timestamp where up == '1'
        $this->assertSame(200.0, $payload['metrics']['down1']['offline_since']);
        $this->assertSame(200.0, $payload['metrics']['10.0.0.2']['offline_since']);
    }

    public function test_status_payload_is_null_when_a_query_fails()
    {
        $client = new FakePrometheusClient(instant: ['up{job="node"}' => null]);

        $this->assertNull((new PrometheusService($client))->statusPayload());
    }

    private function detailClient(): FakePrometheusClient
    {
        return new FakePrometheusClient(
            instant: [
                'node_uname_info' => [['metric' => ['instance' => '10.0.0.1:9100', 'nodename' => 'web1'], 'value' => [1700000000, '1']]],
                'node_filesystem_size_bytes' => [
                    ['metric' => ['mountpoint' => '/home', 'device' => '/dev/sdb1', 'fstype' => 'ext4'], 'value' => [1700000000, '2000']],
                    ['metric' => ['mountpoint' => '/', 'device' => '/dev/sda1', 'fstype' => 'ext4'], 'value' => [1700000000, '1000']],
                ],
                'node_filesystem_avail_bytes' => [
                    ['metric' => ['mountpoint' => '/'], 'value' => [1700000000, '250']],
                    ['metric' => ['mountpoint' => '/home'], 'value' => [1700000000, '1000']],
                ],
            ],
            range: [
                // every detail metric gets the same two-point series
                'node_' => [['values' => [[100, '10'], [200, '20']]]],
            ],
        );
    }

    public function test_detail_payload_builds_time_series_and_stats()
    {
        $payload = (new PrometheusService($this->detailClient()))->detailPayload('web1', '24h', 0);

        $this->assertNotNull($payload);
        $this->assertCount(10, $payload['metric_order']);
        // PHP normalizes numeric-string array keys to ints
        $this->assertSame([100, 200], array_keys($payload['data']));
        $this->assertSame(array_fill(0, 10, 10.0), $payload['data']['100']);
        $this->assertSame(15.0, $payload['stats']['avg'][0]);
        $this->assertSame(20.0, $payload['stats']['max'][0]);
        $this->assertSame(20.0, $payload['stats']['current'][0]);
        $this->assertSame('24h', $payload['period']);
    }

    public function test_detail_payload_sorts_disks_and_computes_usage()
    {
        $payload = (new PrometheusService($this->detailClient()))->detailPayload('web1', '24h', 0);

        $disks = $payload['info']['disks'];
        $this->assertSame(['/', '/home'], array_column($disks, 'mountpoint'));
        $this->assertSame(75.0, $disks[0]['used_pct']);
        $this->assertSame(50.0, $disks[1]['used_pct']);
    }

    public function test_detail_payload_is_null_for_unknown_host()
    {
        $client = new FakePrometheusClient(instant: []);

        $this->assertNull((new PrometheusService($client))->detailPayload('nope', '24h', 0));
    }
}
