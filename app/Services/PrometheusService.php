<?php

namespace App\Services;


class PrometheusService
{
    private const FSTYPES = 'ext4|xfs|btrfs|zfs';

    private const NET_DEVICE_EXCLUDE = 'lo|docker.*|veth.*|br.*|cni.*|flannel.*';

    private const STATUS_QUERIES = [
        'up' => 'up{job="node"}',
        'uname' => 'node_uname_info{job="node"}',
        'ram_pct' => '100 * (1 - node_memory_MemAvailable_bytes{job="node"} / node_memory_MemTotal_bytes{job="node"})',
        'disk_pct' => '100 * (1 - sum by (instance) (node_filesystem_avail_bytes{job="node",fstype=~"' . self::FSTYPES . '"}) / sum by (instance) (node_filesystem_size_bytes{job="node",fstype=~"' . self::FSTYPES . '"}))',
        'net_rx' => 'sum by (instance) (rate(node_network_receive_bytes_total{job="node",device!~"' . self::NET_DEVICE_EXCLUDE . '"}[2m]))',
        'net_tx' => 'sum by (instance) (rate(node_network_transmit_bytes_total{job="node",device!~"' . self::NET_DEVICE_EXCLUDE . '"}[2m]))',
        'uptime' => 'node_time_seconds{job="node"} - node_boot_time_seconds{job="node"}',
    ];

    // Lookback windows for finding when an offline node was last up
    private const OFFLINE_TIERS = [
        [3600, 15],        // 1h at 15s step
        [86400, 60],       // 24h at 1m step
        [86400 * 7, 300],  // 7d at 5m step
        [86400 * 30, 900], // 30d at 15m step
    ];

    private const DETAIL_METRIC_ORDER = ['cpu', 'iowait', 'steal', 'ram', 'swap', 'disk', 'net_rx', 'net_tx', 'disk_read', 'disk_write'];

    private PrometheusClient $client;

    public function __construct(?PrometheusClient $client = null)
    {
        $this->client = $client ?? new PrometheusClient();
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    public function isValidPeriod(string $period): bool
    {
        return $this->client->isValidPeriod($period);
    }

    /**
     * Statuses and current metrics for all monitored nodes,
     * or null when Prometheus cannot be queried.
     */
    public function statusPayload(): ?array
    {
        try {
            $results = $this->collectStatusMetrics();
            if ($results === null) {
                return null;
            }

            $hostnames = $this->hostnameByInstance($results['uname']);
            $this->resolveOfflineHostnames($results['up'], $hostnames);
            $metrics = $this->currentMetricsByInstance($results);
            $this->addOfflineSince($results['up'], $metrics);
            [$statuses, $metricsOut] = $this->keyByHostname($results['up'], $hostnames, $metrics);

            return [
                'statuses' => $statuses,
                'metrics' => $metricsOut,
                'interval' => $this->client->checkInterval(),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /** @return array<string, array>|null result arrays per metric, null when any query fails */
    private function collectStatusMetrics(): ?array
    {
        $results = [];
        foreach (self::STATUS_QUERIES as $key => $query) {
            $body = $this->client->rawQuery($query);
            if ($body === null) {
                return null;
            }
            $results[$key] = $body['data']['result'] ?? [];
        }

        return $results;
    }

    /** Map instance -> hostname from node_uname_info (online nodes) */
    private function hostnameByInstance(array $unameResults): array
    {
        $map = [];
        foreach ($unameResults as $result) {
            $instance = $result['metric']['instance'] ?? '';
            $nodename = $result['metric']['nodename'] ?? '';
            if ($instance && $nodename) {
                $map[$instance] = $nodename;
            }
        }

        return $map;
    }

    /** Offline instances are missing from uname; look up their last known nodename */
    private function resolveOfflineHostnames(array $upResults, array &$hostnames): void
    {
        foreach ($upResults as $result) {
            $instance = $result['metric']['instance'] ?? '';
            if (PromQL::isUp($result) || isset($hostnames[$instance])) {
                continue;
            }

            $data = $this->client->query('last_over_time(node_uname_info{job="node",instance="' . PromQL::quote($instance) . '"}[30d])');
            if (isset($data[0]['metric']['nodename'])) {
                $hostnames[$instance] = $data[0]['metric']['nodename'];
            }
        }
    }

    private function currentMetricsByInstance(array $results): array
    {
        $metrics = [];
        foreach (['ram_pct', 'disk_pct', 'net_rx', 'net_tx', 'uptime'] as $metricKey) {
            foreach ($results[$metricKey] as $result) {
                $instance = $result['metric']['instance'] ?? '';
                $val = (float)($result['value'][1] ?? 0);
                $metrics[$instance][$metricKey] = ($metricKey === 'uptime') ? round($val) : round($val, 1);
            }
        }

        return $metrics;
    }

    private function addOfflineSince(array $upResults, array &$metrics): void
    {
        foreach ($upResults as $result) {
            if (PromQL::isUp($result)) {
                continue;
            }
            $instance = $result['metric']['instance'] ?? '';
            $metrics[$instance]['offline_since'] = $this->offlineSince($instance);
        }
    }

    /** Timestamp the instance was last seen up within tiered lookback windows */
    private function offlineSince(string $instance): ?float
    {
        $now = time();
        foreach (self::OFFLINE_TIERS as [$lookback, $step]) {
            $offlineSince = null;
            $results = $this->client->rangeQuery('up{job="node",instance="' . PromQL::quote($instance) . '"}', $now - $lookback, $now, $step);
            foreach ($results[0]['values'] ?? [] as [$ts, $val]) {
                if ($val === '1') {
                    $offlineSince = (float)$ts;
                }
            }
            if ($offlineSince !== null) {
                return $offlineSince;
            }
        }

        return null;
    }

    /** Key statuses/metrics by hostname (when known) and by instance IP as fallback */
    private function keyByHostname(array $upResults, array $hostnames, array $metrics): array
    {
        $statuses = [];
        $metricsOut = [];
        foreach ($upResults as $result) {
            $instance = $result['metric']['instance'] ?? '';
            $isUp = PromQL::isUp($result);
            $instanceMetrics = $metrics[$instance] ?? [];

            $keys = [preg_replace('/:\d+$/', '', $instance)];
            if (isset($hostnames[$instance])) {
                $keys[] = $hostnames[$instance];
            }

            foreach ($keys as $key) {
                $statuses[$key] = $isUp;
                if (!empty($instanceMetrics)) {
                    $metricsOut[$key] = $instanceMetrics;
                }
            }
        }

        return [$statuses, $metricsOut];
    }

    /**
     * Time-series detail for one host, or null when the host
     * is not known to Prometheus.
     */
    public function detailPayload(string $hostname, string $period, int $back): ?array
    {
        $cfg = PrometheusClient::PERIODS[$period];
        $end = time() - ($back * $cfg['seconds']);
        $start = $end - $cfg['seconds'];

        $inst = $this->resolveInstance($hostname);
        if (!$inst) {
            return null;
        }

        $raw = [];
        foreach ($this->detailQueries($inst, $cfg['step']) as $key => $query) {
            $raw[$key] = $this->client->rangeQuery($query, $start, $end, $cfg['step']);
        }

        $data = $this->buildTimeSeries($raw);

        return [
            'info' => ['disks' => $this->filesystemInfo($inst)],
            'stats' => $this->computeStats($data),
            'data' => $data,
            'metric_order' => self::DETAIL_METRIC_ORDER,
            'period' => $period,
            'back' => $back,
        ];
    }

    /** Thin wrapper: resolution lives in PrometheusInstanceResolver. */
    private function resolveInstance(string $hostname): ?string
    {
        return (new PrometheusInstanceResolver($this->client))->resolve($hostname);
    }

    private function detailQueries(string $inst, int $step): array
    {
        $inst = PromQL::quote($inst);
        $ri = max($step * 2, 120) . 's';
        $fstypes = self::FSTYPES;
        $netExclude = self::NET_DEVICE_EXCLUDE;

        return [
            'cpu'        => "100 * (1 - avg(rate(node_cpu_seconds_total{job=\"node\",instance=\"{$inst}\",mode=\"idle\"}[{$ri}])))",
            'iowait'     => "100 * avg(rate(node_cpu_seconds_total{job=\"node\",instance=\"{$inst}\",mode=\"iowait\"}[{$ri}]))",
            'steal'      => "100 * avg(rate(node_cpu_seconds_total{job=\"node\",instance=\"{$inst}\",mode=\"steal\"}[{$ri}]))",
            'ram'        => "100 * (1 - node_memory_MemAvailable_bytes{job=\"node\",instance=\"{$inst}\"} / node_memory_MemTotal_bytes{job=\"node\",instance=\"{$inst}\"})",
            'swap'       => "clamp_min(100 * (1 - node_memory_SwapFree_bytes{job=\"node\",instance=\"{$inst}\"} / node_memory_SwapTotal_bytes{job=\"node\",instance=\"{$inst}\"}), 0)",
            'disk'       => "100 * (1 - sum(node_filesystem_avail_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"{$fstypes}\"}) / sum(node_filesystem_size_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"{$fstypes}\"}))",
            'net_rx'     => "sum(rate(node_network_receive_bytes_total{job=\"node\",instance=\"{$inst}\",device!~\"{$netExclude}\"}[{$ri}]))",
            'net_tx'     => "sum(rate(node_network_transmit_bytes_total{job=\"node\",instance=\"{$inst}\",device!~\"{$netExclude}\"}[{$ri}]))",
            'disk_read'  => "sum(rate(node_disk_read_bytes_total{job=\"node\",instance=\"{$inst}\"}[{$ri}]))",
            'disk_write' => "sum(rate(node_disk_written_bytes_total{job=\"node\",instance=\"{$inst}\"}[{$ri}]))",
        ];
    }

    /** @return array<string, array> timestamp => one value per metric in DETAIL_METRIC_ORDER */
    private function buildTimeSeries(array $raw): array
    {
        $tsValues = [];
        $allTimestamps = [];
        foreach (self::DETAIL_METRIC_ORDER as $key) {
            $tsValues[$key] = [];
            foreach ($raw[$key][0]['values'] ?? [] as [$ts, $val]) {
                $t = (int)$ts;
                $allTimestamps[$t] = true;
                $v = (float)$val;
                $tsValues[$key][$t] = (is_nan($v) || is_infinite($v)) ? null : $v;
            }
        }

        ksort($allTimestamps);
        $data = [];
        foreach (array_keys($allTimestamps) as $t) {
            $row = [];
            foreach (self::DETAIL_METRIC_ORDER as $key) {
                $row[] = $tsValues[$key][$t] ?? null;
            }
            $data[(string)$t] = $row;
        }

        return $data;
    }

    /** @return array{avg: array, max: array, current: array} */
    private function computeStats(array $data): array
    {
        $stats = ['avg' => [], 'max' => [], 'current' => []];
        for ($i = 0; $i < count(self::DETAIL_METRIC_ORDER); $i++) {
            $vals = [];
            foreach ($data as $row) {
                if ($row[$i] !== null) {
                    $vals[] = $row[$i];
                }
            }
            $stats['avg'][] = empty($vals) ? 0 : round(array_sum($vals) / count($vals), 2);
            $stats['max'][] = empty($vals) ? 0 : round(max($vals), 2);
            $stats['current'][] = empty($vals) ? 0 : round(end($vals), 2);
        }

        return $stats;
    }

    private function filesystemInfo(string $inst): array
    {
        $inst = PromQL::quote($inst);
        $fstypes = self::FSTYPES;
        $sizeResults = $this->client->query("node_filesystem_size_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"{$fstypes}\"}");
        $availResults = $this->client->query("node_filesystem_avail_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"{$fstypes}\"}");

        $availByMount = [];
        foreach ($availResults as $r) {
            $availByMount[$r['metric']['mountpoint'] ?? ''] = (float)$r['value'][1];
        }

        $disks = [];
        foreach ($sizeResults as $r) {
            $mount = $r['metric']['mountpoint'] ?? '';
            $size = (float)$r['value'][1];
            $avail = $availByMount[$mount] ?? $size;
            $disks[] = [
                'device' => $r['metric']['device'] ?? '?',
                'mountpoint' => $mount,
                'fstype' => $r['metric']['fstype'] ?? '?',
                'size' => $size,
                'avail' => $avail,
                'used_pct' => $size > 0 ? round(100 * (1 - $avail / $size), 1) : 0,
            ];
        }
        usort($disks, fn($a, $b) => strcmp($a['mountpoint'], $b['mountpoint']));

        return $disks;
    }
}
