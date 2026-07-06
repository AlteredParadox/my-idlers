<?php

namespace App\Services;

use App\Models\Settings;

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

    public const PERIODS = [
        '6h'  => ['seconds' => 21600,    'step' => 60],
        '12h' => ['seconds' => 43200,    'step' => 120],
        '24h' => ['seconds' => 86400,    'step' => 240],
        '3d'  => ['seconds' => 259200,   'step' => 720],
        '7d'  => ['seconds' => 604800,   'step' => 1680],
        '14d' => ['seconds' => 1209600,  'step' => 3360],
        '28d' => ['seconds' => 2419200,  'step' => 6720],
        '3m'  => ['seconds' => 7776000,  'step' => 21600],
        '6m'  => ['seconds' => 15552000, 'step' => 43200],
        '1y'  => ['seconds' => 31536000, 'step' => 86400],
    ];

    private const DETAIL_METRIC_ORDER = ['cpu', 'iowait', 'steal', 'ram', 'swap', 'disk', 'net_rx', 'net_tx', 'disk_read', 'disk_write'];

    private ?object $settings = null;

    private function settings(): object
    {
        return $this->settings ??= Settings::getSettings();
    }

    public function isEnabled(): bool
    {
        $settings = $this->settings();

        return (bool)($settings->prometheus_enabled ?? false) && !empty($settings->prometheus_url);
    }

    public function isValidPeriod(string $period): bool
    {
        return isset(self::PERIODS[$period]);
    }

    public function checkInterval(): int
    {
        return $this->settings()->prometheus_check_interval ?? 20;
    }

    private function baseUrl(): string
    {
        return rtrim($this->settings()->prometheus_url, '/');
    }

    private function fetch(string $url, int $timeout = 5): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    public function query(string $query): array
    {
        $body = $this->fetch($this->baseUrl() . '/api/v1/query?' . http_build_query(['query' => $query]));

        return $body['data']['result'] ?? [];
    }

    public function rangeQuery(string $query, float $start, float $end, int $step): array
    {
        $body = $this->fetch($this->baseUrl() . '/api/v1/query_range?' . http_build_query([
            'query' => $query, 'start' => $start, 'end' => $end, 'step' => $step,
        ]), 10);

        return $body['data']['result'] ?? [];
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
                'interval' => $this->checkInterval(),
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
            $body = $this->fetch($this->baseUrl() . '/api/v1/query?' . http_build_query(['query' => $query]));
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
            if ($this->isUp($result) || isset($hostnames[$instance])) {
                continue;
            }

            $data = $this->query('last_over_time(node_uname_info{job="node",instance="' . $instance . '"}[30d])');
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
            if ($this->isUp($result)) {
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
            $results = $this->rangeQuery('up{job="node",instance="' . $instance . '"}', $now - $lookback, $now, $step);
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
            $isUp = $this->isUp($result);
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

    private function isUp(array $result): bool
    {
        return isset($result['value'][1]) && $result['value'][1] === '1';
    }

    /**
     * Time-series detail for one host, or null when the host
     * is not known to Prometheus.
     */
    public function detailPayload(string $hostname, string $period, int $back): ?array
    {
        $cfg = self::PERIODS[$period];
        $end = time() - ($back * $cfg['seconds']);
        $start = $end - $cfg['seconds'];

        $inst = $this->resolveInstance($hostname);
        if (!$inst) {
            return null;
        }

        $raw = [];
        foreach ($this->detailQueries($inst, $cfg['step']) as $key => $query) {
            $raw[$key] = $this->rangeQuery($query, $start, $end, $cfg['step']);
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

    private function resolveInstance(string $hostname): ?string
    {
        // Try matching by nodename via node_uname_info
        foreach ($this->query('node_uname_info{job="node"}') as $r) {
            $nodename = $r['metric']['nodename'] ?? '';
            if ($nodename === $hostname || str_starts_with($hostname, $nodename . '.') || str_starts_with($nodename, explode('.', $hostname)[0])) {
                return $r['metric']['instance'] ?? null;
            }
        }

        // Try matching by instance directly (hostname might be an IP)
        foreach ($this->query('up{job="node"}') as $r) {
            $instance = $r['metric']['instance'] ?? '';
            if (preg_replace('/:\d+$/', '', $instance) === $hostname) {
                return $instance;
            }
        }

        return null;
    }

    private function detailQueries(string $inst, int $step): array
    {
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
        $fstypes = self::FSTYPES;
        $sizeResults = $this->query("node_filesystem_size_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"{$fstypes}\"}");
        $availResults = $this->query("node_filesystem_avail_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"{$fstypes}\"}");

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
