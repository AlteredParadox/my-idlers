<?php

namespace App\Http\Controllers;

use App\Models\Domains;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\Misc;
use App\Models\NetworkSpeed;
use App\Models\Note;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use App\Models\User;
use App\Models\Yabs;
use App\Services\ExportService;
use DataTables;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    /**
     * The export service instance.
     *
     * @var ExportService
     */
    protected ExportService $exportService;

    /**
     * Create a new controller instance.
     *
     * @param ExportService $exportService
     */
    public function __construct(ExportService $exportService)
    {
        $this->exportService = $exportService;
    }
    protected function getAllServers()
    {
        $servers = Server::allServers()->toJson(JSON_PRETTY_PRINT);
        return response($servers, 200);
    }

    protected function getServer($id)
    {
        $server = Server::server($id)->toJson(JSON_PRETTY_PRINT);
        return response($server, 200);
    }

    protected function getAllPricing()
    {
        $pricing = Pricing::all()->toJson(JSON_PRETTY_PRINT);
        return response($pricing, 200);
    }

    protected function getPricing($id)
    {
        $pricing = Pricing::where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($pricing, 200);
    }

    protected function getAllNetworkSpeeds()
    {
        $ns = NetworkSpeed::all()->toJson(JSON_PRETTY_PRINT);
        return response($ns, 200);
    }

    protected function getNetworkSpeeds($id)
    {
        $ns = NetworkSpeed::where('server_id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($ns, 200);
    }

    protected function getAllLabels()
    {
        $labels = Labels::all()->toJson(JSON_PRETTY_PRINT);
        return response($labels, 200);
    }

    protected function getLabel($id)
    {
        $label = Labels::where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($label, 200);
    }

    protected function getAllShared()
    {
        $shared = Shared::allSharedHosting()->toJson(JSON_PRETTY_PRINT);
        return response($shared, 200);
    }

    protected function getShared($id)
    {
        $shared = Shared::sharedHosting($id)->toJson(JSON_PRETTY_PRINT);
        return response($shared, 200);
    }

    protected function getAllReseller()
    {
        $reseller = Reseller::allResellerHosting()->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }

    protected function getReseller($id)
    {
        $reseller = Reseller::resellerHosting($id)->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }

    protected function getAllSeedbox()
    {
        $reseller = SeedBoxes::allSeedboxes()->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }

    protected function getSeedbox($id)
    {
        $reseller = SeedBoxes::seedbox($id)->toJson(JSON_PRETTY_PRINT);
        return response($reseller, 200);
    }

    protected function getAllDomains()
    {
        $domains = Domains::allDomains()->toJson(JSON_PRETTY_PRINT);
        return response($domains, 200);
    }

    protected function getDomains($id)
    {
        $domain = Domains::domain($id)->toJson(JSON_PRETTY_PRINT);
        return response($domain, 200);
    }

    protected function getAllMisc()
    {
        $misc = Misc::allMisc()->toJson(JSON_PRETTY_PRINT);
        return response($misc, 200);
    }

    protected function getMisc($id)
    {
        $misc = Misc::misc($id)->toJson(JSON_PRETTY_PRINT);
        return response($misc, 200);
    }

    protected function getAllDns()
    {
        $dns = DB::table('d_n_s')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($dns, 200);
    }

    protected function getDns($id)
    {
        $dns = DB::table('d_n_s')
            ->where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($dns, 200);
    }

    protected function getAllLocations()
    {
        $locations = DB::table('locations')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($locations, 200);
    }

    protected function getLocation($id)
    {
        $location = DB::table('locations')
            ->where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($location, 200);
    }

    protected function getAllProviders()
    {
        $providers = DB::table('providers')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($providers, 200);
    }

    protected function getProvider($id)
    {
        $providers = DB::table('providers')
            ->where('id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($providers, 200);
    }

    protected function getAllSettings()
    {
        $settings = DB::table('settings')
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($settings, 200);
    }

    protected function getAllOs()
    {
        $os = OS::allOS();
        $os = json_encode($os, JSON_PRETTY_PRINT);
        return response($os, 200);
    }

    protected function getOs($id)
    {
        $os = DB::table('os as o')
            ->where('o.id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($os, 200);
    }

    protected function getAllIPs()
    {
        $ip = IPs::all()->toJson(JSON_PRETTY_PRINT);
        return response($ip, 200);
    }

    protected function getIP($id)
    {
        $ip = DB::table('ips as i')
            ->where('i.id', $id)
            ->get()->toJson(JSON_PRETTY_PRINT);
        return response($ip, 200);
    }

    public function getAllProvidersTable(Request $request)
    {
        if ($request->ajax()) {
            $data = Providers::latest()->get();
            $dt = Datatables::of($data)
                ->addIndexColumn()
                ->addColumn('action', function ($row) {
                    $actionBtn = '<form action="' . route('providers.destroy', $row['id']) . '" method="POST"><i class="fas fa-trash text-danger ms-3" @click="modalForm" id="btn-' . $row['name'] . '" title="' . $row['id'] . '"></i> </form>';
                    return $actionBtn;
                })
                ->rawColumns(['action'])
                ->make(true);
            return $dt;
        }
    }

    protected function checkHostIsUp(string $hostname)
    {//Check if host/ip is "up" via ping
        $exitCode = 1;
        $pingCmd = stripos(PHP_OS, 'WIN') === 0
            ? "ping -n 1 -w 2000 " . escapeshellarg($hostname)
            : "ping -c 1 -W 2 " . escapeshellarg($hostname);
        exec($pingCmd . " > /dev/null 2>&1", $output, $exitCode);
        return response(array('is_online' => $exitCode === 0), 200);
    }

    protected function prometheusStatus()
    {
        $settings = \App\Models\Settings::getSettings();

        if (!$settings->prometheus_enabled || empty($settings->prometheus_url)) {
            return response()->json(['error' => 'Prometheus integration not enabled'], 404);
        }

        $baseUrl = rtrim($settings->prometheus_url, '/') . '/api/v1/query';

        try {
            $queries = [
                'up' => 'up{job="node"}',
                'uname' => 'node_uname_info{job="node"}',
                'ram_pct' => '100 * (1 - node_memory_MemAvailable_bytes{job="node"} / node_memory_MemTotal_bytes{job="node"})',
                'disk_pct' => '100 * (1 - sum by (instance) (node_filesystem_avail_bytes{job="node",fstype=~"ext4|xfs|btrfs|zfs"}) / sum by (instance) (node_filesystem_size_bytes{job="node",fstype=~"ext4|xfs|btrfs|zfs"}))',
                'net_rx' => 'sum by (instance) (rate(node_network_receive_bytes_total{job="node",device!~"lo|docker.*|veth.*|br.*|cni.*|flannel.*"}[2m]))',
                'net_tx' => 'sum by (instance) (rate(node_network_transmit_bytes_total{job="node",device!~"lo|docker.*|veth.*|br.*|cni.*|flannel.*"}[2m]))',
                'uptime' => 'node_time_seconds{job="node"} - node_boot_time_seconds{job="node"}',
            ];

            $results = [];
            foreach ($queries as $key => $query) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . '?' . http_build_query(['query' => $query]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200 || $response === false) {
                    return response()->json(['error' => 'Failed to query Prometheus'], 502);
                }
                $results[$key] = json_decode($response, true);
            }

            // Build instance -> hostname map from node_uname_info (online nodes)
            $instanceToHostname = [];
            if (isset($results['uname']['data']['result'])) {
                foreach ($results['uname']['data']['result'] as $result) {
                    $instance = $result['metric']['instance'] ?? '';
                    $nodename = $result['metric']['nodename'] ?? '';
                    if ($instance && $nodename) {
                        $instanceToHostname[$instance] = $nodename;
                    }
                }
            }

            // Find offline instances missing from uname and query 30d history
            $offlineInstances = [];
            if (isset($results['up']['data']['result'])) {
                foreach ($results['up']['data']['result'] as $result) {
                    $instance = $result['metric']['instance'] ?? '';
                    $isUp = isset($result['value'][1]) && $result['value'][1] === '1';
                    if (!$isUp && !isset($instanceToHostname[$instance])) {
                        $offlineInstances[] = $instance;
                    }
                }
            }

            foreach ($offlineInstances as $instance) {
                $query = 'last_over_time(node_uname_info{job="node",instance="' . $instance . '"}[30d])';
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $baseUrl . '?' . http_build_query(['query' => $query]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $response !== false) {
                    $data = json_decode($response, true);
                    if (isset($data['data']['result'][0]['metric']['nodename'])) {
                        $instanceToHostname[$instance] = $data['data']['result'][0]['metric']['nodename'];
                    }
                }
            }

            // Build RAM % map keyed by instance
            // Build per-instance metric maps
            $metricsByInstance = [];
            foreach (['ram_pct', 'disk_pct', 'net_rx', 'net_tx', 'uptime'] as $metricKey) {
                if (isset($results[$metricKey]['data']['result'])) {
                    foreach ($results[$metricKey]['data']['result'] as $result) {
                        $instance = $result['metric']['instance'] ?? '';
                        $val = (float)($result['value'][1] ?? 0);
                        $metricsByInstance[$instance][$metricKey] = ($metricKey === 'uptime') ? round($val) : round($val, 1);
                    }
                }
            }

            // For offline nodes, find when they went down via tiered range query
            $rangeUrl = rtrim($settings->prometheus_url, '/') . '/api/v1/query_range';
            $now = time();
            $tiers = [
                [3600, 15],        // 1h at 15s step
                [86400, 60],       // 24h at 1m step
                [86400 * 7, 300],  // 7d at 5m step
                [86400 * 30, 900], // 30d at 15m step
            ];

            if (isset($results['up']['data']['result'])) {
                foreach ($results['up']['data']['result'] as $result) {
                    $instance = $result['metric']['instance'] ?? '';
                    $isUp = isset($result['value'][1]) && $result['value'][1] === '1';

                    if (!$isUp) {
                        $offlineSince = null;
                        foreach ($tiers as [$lookback, $step]) {
                            $ch = curl_init();
                            curl_setopt_array($ch, [
                                CURLOPT_URL => $rangeUrl . '?' . http_build_query([
                                    'query' => 'up{job="node",instance="' . $instance . '"}',
                                    'start' => $now - $lookback,
                                    'end' => $now,
                                    'step' => $step,
                                ]),
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 5,
                                CURLOPT_CONNECTTIMEOUT => 3,
                            ]);
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);

                            if ($httpCode === 200 && $response !== false) {
                                $data = json_decode($response, true);
                                if (isset($data['data']['result'][0]['values'])) {
                                    foreach ($data['data']['result'][0]['values'] as [$ts, $val]) {
                                        if ($val === '1') {
                                            $offlineSince = (float)$ts;
                                        }
                                    }
                                }
                            }
                            if ($offlineSince !== null) break;
                        }
                        $metricsByInstance[$instance]['offline_since'] = $offlineSince;
                    }
                }
            }

            // Build statuses and metrics keyed by hostname and instance IP
            $statuses = [];
            $metrics = [];
            if (isset($results['up']['data']['result'])) {
                foreach ($results['up']['data']['result'] as $result) {
                    $instance = $result['metric']['instance'] ?? '';
                    $isUp = isset($result['value'][1]) && $result['value'][1] === '1';
                    $instanceMetrics = $metricsByInstance[$instance] ?? [];

                    // Key by hostname from uname if available
                    if (isset($instanceToHostname[$instance])) {
                        $hostname = $instanceToHostname[$instance];
                        $statuses[$hostname] = $isUp;
                        if (!empty($instanceMetrics)) {
                            $metrics[$hostname] = $instanceMetrics;
                        }
                    }

                    // Also key by instance IP (without port) as fallback
                    $host = preg_replace('/:\d+$/', '', $instance);
                    $statuses[$host] = $isUp;
                    if (!empty($instanceMetrics)) {
                        $metrics[$host] = $instanceMetrics;
                    }
                }
            }

            return response()->json([
                'statuses' => $statuses,
                'metrics' => $metrics,
                'interval' => $settings->prometheus_check_interval ?? 20,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Prometheus query failed'], 502);
        }
    }

    private function promQuery(string $baseUrl, string $query)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/api/v1/query?' . http_build_query(['query' => $query]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) return [];
        $data = json_decode($response, true);
        return $data['data']['result'] ?? [];
    }

    private function promRangeQuery(string $baseUrl, string $query, float $start, float $end, int $step)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/api/v1/query_range?' . http_build_query([
                'query' => $query, 'start' => $start, 'end' => $end, 'step' => $step,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) return [];
        $data = json_decode($response, true);
        return $data['data']['result'] ?? [];
    }

    private function resolveInstance(string $baseUrl, string $hostname)
    {
        // Try matching by nodename via node_uname_info
        $results = $this->promQuery($baseUrl, 'node_uname_info{job="node"}');
        foreach ($results as $r) {
            $nodename = $r['metric']['nodename'] ?? '';
            if ($nodename === $hostname || str_starts_with($hostname, $nodename . '.') || str_starts_with($nodename, explode('.', $hostname)[0])) {
                return $r['metric']['instance'] ?? null;
            }
        }
        // Try matching by instance directly (hostname might be an IP)
        $results = $this->promQuery($baseUrl, 'up{job="node"}');
        foreach ($results as $r) {
            $instance = $r['metric']['instance'] ?? '';
            $host = preg_replace('/:\d+$/', '', $instance);
            if ($host === $hostname) {
                return $instance;
            }
        }
        return null;
    }

    public function prometheusDetail(string $hostname, string $period, int $back)
    {
        $settings = \App\Models\Settings::getSettings();

        if (!$settings->prometheus_enabled || empty($settings->prometheus_url)) {
            return response()->json(['error' => 'Prometheus not enabled'], 404);
        }

        $baseUrl = rtrim($settings->prometheus_url, '/');

        $periodConfig = [
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

        if (!isset($periodConfig[$period]) || $back < 0) {
            return response()->json(['error' => 'Invalid period'], 400);
        }

        $cfg = $periodConfig[$period];
        $step = $cfg['step'];
        $duration = $cfg['seconds'];
        $ri = max($step * 2, 120) . 's';

        $now = time();
        $end = $now - ($back * $duration);
        $start = $end - $duration;

        $inst = $this->resolveInstance($baseUrl, $hostname);
        if (!$inst) {
            return response()->json(['error' => 'Server not found in Prometheus'], 404);
        }

        // Range queries for time-series data
        $queries = [
            'cpu'        => "100 * (1 - avg(rate(node_cpu_seconds_total{job=\"node\",instance=\"{$inst}\",mode=\"idle\"}[{$ri}])))",
            'iowait'     => "100 * avg(rate(node_cpu_seconds_total{job=\"node\",instance=\"{$inst}\",mode=\"iowait\"}[{$ri}]))",
            'steal'      => "100 * avg(rate(node_cpu_seconds_total{job=\"node\",instance=\"{$inst}\",mode=\"steal\"}[{$ri}]))",
            'ram'        => "100 * (1 - node_memory_MemAvailable_bytes{job=\"node\",instance=\"{$inst}\"} / node_memory_MemTotal_bytes{job=\"node\",instance=\"{$inst}\"})",
            'swap'       => "clamp_min(100 * (1 - node_memory_SwapFree_bytes{job=\"node\",instance=\"{$inst}\"} / node_memory_SwapTotal_bytes{job=\"node\",instance=\"{$inst}\"}), 0)",
            'disk'       => "100 * (1 - sum(node_filesystem_avail_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"ext4|xfs|btrfs|zfs\"}) / sum(node_filesystem_size_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"ext4|xfs|btrfs|zfs\"}))",
            'net_rx'     => "sum(rate(node_network_receive_bytes_total{job=\"node\",instance=\"{$inst}\",device!~\"lo|docker.*|veth.*|br.*|cni.*|flannel.*\"}[{$ri}]))",
            'net_tx'     => "sum(rate(node_network_transmit_bytes_total{job=\"node\",instance=\"{$inst}\",device!~\"lo|docker.*|veth.*|br.*|cni.*|flannel.*\"}[{$ri}]))",
            'disk_read'  => "sum(rate(node_disk_read_bytes_total{job=\"node\",instance=\"{$inst}\"}[{$ri}]))",
            'disk_write' => "sum(rate(node_disk_written_bytes_total{job=\"node\",instance=\"{$inst}\"}[{$ri}]))",
        ];

        $metricOrder = ['cpu', 'iowait', 'steal', 'ram', 'swap', 'disk', 'net_rx', 'net_tx', 'disk_read', 'disk_write'];

        // Execute range queries
        $raw = [];
        foreach ($queries as $key => $query) {
            $raw[$key] = $this->promRangeQuery($baseUrl, $query, $start, $end, $step);
        }

        // Build time-series data
        $tsValues = [];
        $allTimestamps = [];
        foreach ($metricOrder as $key) {
            $tsValues[$key] = [];
            $results = $raw[$key] ?? [];
            if (!empty($results) && isset($results[0]['values'])) {
                foreach ($results[0]['values'] as [$ts, $val]) {
                    $t = (int)$ts;
                    $allTimestamps[$t] = true;
                    $v = (float)$val;
                    $tsValues[$key][$t] = (is_nan($v) || is_infinite($v)) ? null : $v;
                }
            }
        }

        ksort($allTimestamps);
        $data = [];
        foreach (array_keys($allTimestamps) as $t) {
            $row = [];
            foreach ($metricOrder as $key) {
                $row[] = $tsValues[$key][$t] ?? null;
            }
            $data[(string)$t] = $row;
        }

        // Compute stats
        $statsAvg = [];
        $statsMax = [];
        $statsCur = [];
        for ($i = 0; $i < count($metricOrder); $i++) {
            $vals = [];
            foreach ($data as $row) {
                if ($row[$i] !== null) $vals[] = $row[$i];
            }
            if (!empty($vals)) {
                $statsAvg[] = round(array_sum($vals) / count($vals), 2);
                $statsMax[] = round(max($vals), 2);
                $statsCur[] = round(end($vals), 2);
            } else {
                $statsAvg[] = 0;
                $statsMax[] = 0;
                $statsCur[] = 0;
            }
        }

        // Get per-disk filesystem info
        $diskSizeResults = $this->promQuery($baseUrl, "node_filesystem_size_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"ext4|xfs|btrfs|zfs\"}");
        $diskAvailResults = $this->promQuery($baseUrl, "node_filesystem_avail_bytes{job=\"node\",instance=\"{$inst}\",fstype=~\"ext4|xfs|btrfs|zfs\"}");

        $availByMount = [];
        foreach ($diskAvailResults as $r) {
            $availByMount[$r['metric']['mountpoint'] ?? ''] = (float)$r['value'][1];
        }

        $disks = [];
        foreach ($diskSizeResults as $r) {
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

        return response()->json([
            'info' => ['disks' => $disks],
            'stats' => ['avg' => $statsAvg, 'max' => $statsMax, 'current' => $statsCur],
            'data' => $data,
            'metric_order' => $metricOrder,
            'period' => $period,
            'back' => $back,
        ]);
    }

    protected function getIpForDomain(string $domainname, string $type)
    {//Gets IP from A record for a domain
        switch ($type) {
            case "A":
                $data = dns_get_record($domainname, DNS_A);
                if (isset($data['0']['ip'])) {
                    return response(array('ip' => $data['0']['ip']), 200);
                }
                break;
            case "AAAA":
                $data = dns_get_record($domainname, DNS_AAAA);
                if (isset($data['0']['ipv6'])) {
                    return response(array('ip' => $data['0']['ipv6']), 200);
                }
                break;
        }
        return response(array('ip' => null), 200);
    }

    protected function storeServer(Request $request)
    {
        $rules = [
            'hostname' => 'min:3',
            'server_type' => 'required|integer',
            'os_id' => 'required|integer',
            'provider_id' => 'required|integer',
            'location_id' => 'required|integer',
            'ssh_port' => 'required|integer',
            'ram' => 'required|integer',
            'ram_as_mb' => 'required|integer',
            'disk' => 'required|integer',
            'disk_as_gb' => 'required|integer',
            'cpu' => 'required|integer',
            'bandwidth' => 'required|integer',
            'was_promo' => 'required|integer',
            'active' => 'required|integer',
            'show_public' => 'required|integer',
            'ip1' => 'ip',
            'ip2' => 'ip',
            'owned_since' => 'required|date',
            'ram_type' => 'required|string|size:2',
            'disk_type' => 'required|string|size:2',
            'currency' => 'required|string|size:3',
            'price' => 'required|numeric',
            'payment_term' => 'required|integer',
            'next_due_date' => 'date',
        ];

        $messages = [
            'required' => ':attribute is required',
            'min' => ':attribute must be longer than 3',
            'integer' => ':attribute must be an integer',
            'string' => ':attribute must be a string',
            'size' => ':attribute must be exactly :size characters',
            'numeric' => ':attribute must be a float',
            'ip' => ':attribute must be a valid IP address',
            'date' => ':attribute must be a date Y-m-d',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $server_id = Str::random(8);

        $pricing = new Pricing();
        $pricing->insertPricing(1, $server_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

        if (!is_null($request->ip1)) {
            IPs::insertIP($server_id, $request->ip1);
        }

        if (!is_null($request->ip2)) {
            IPs::insertIP($server_id, $request->ip2);
        }

        $insert = Server::create([
            'id' => $server_id,
            'hostname' => $request->hostname,
            'server_type' => $request->server_type,
            'os_id' => $request->os_id,
            'ssh_port' => $request->ssh_port,
            'provider_id' => $request->provider_id,
            'location_id' => $request->location_id,
            'ram' => $request->ram,
            'ram_type' => $request->ram_type,
            'ram_as_mb' => ($request->ram_type === 'MB') ? $request->ram : ($request->ram * 1024),
            'disk' => $request->disk,
            'disk_type' => $request->disk_type,
            'disk_as_gb' => ($request->disk_type === 'GB') ? $request->disk : ($request->disk * 1024),
            'owned_since' => $request->owned_since,
            'ns1' => $request->ns1,
            'ns2' => $request->ns2,
            'bandwidth' => $request->bandwidth,
            'cpu' => $request->cpu,
            'was_promo' => $request->was_promo,
            'show_public' => (isset($request->show_public)) ? 1 : 0
        ]);

        Server::serverRelatedCacheForget();

        if ($insert) {
            return response()->json(array('result' => 'success', 'server_id' => $server_id), 200);
        }

        return response()->json(array('result' => 'fail', 'request' => $request->post()), 500);
    }

    public function destroyServer(Request $request)
    {
        $items = Server::find($request->id);

        (!is_null($items)) ? $result = $items->delete() : $result = false;

        $p = new Pricing();
        $p->deletePricing($request->id);

        Labels::deleteLabelsAssignedTo($request->id);
        IPs::deleteIPsAssignedTo($request->id);
        Server::serverRelatedCacheForget();

        if ($result) {
            return response()->json(array('result' => 'success'), 200);
        }

        return response()->json(array('result' => 'fail'), 500);
    }

    public function updateServer(Request $request)
    {
        $rules = [
            'hostname' => 'string|min:3',
            'server_type' => 'integer',
            'os_id' => 'integer',
            'provider_id' => 'integer',
            'location_id' => 'integer',
            'ssh_port' => 'integer',
            'ram' => 'integer',
            'ram_as_mb' => 'integer',
            'disk' => 'integer',
            'disk_as_gb' => 'integer',
            'cpu' => 'integer',
            'bandwidth' => 'integer',
            'was_promo' => 'integer',
            'active' => 'integer',
            'show_public' => 'integer',
            'owned_since' => 'date',
            'ram_type' => 'string|size:2',
            'disk_type' => 'string|size:2',
            'currency' => 'string|size:3',
            'price' => 'numeric',
            'payment_term' => 'integer',
            'next_due_date' => 'date',
        ];

        $messages = [
            'required' => ':attribute is required',
            'min' => ':attribute must be longer than 3',
            'integer' => ':attribute must be an integer',
            'string' => ':attribute must be a string',
            'size' => ':attribute must be exactly :size characters',
            'numeric' => ':attribute must be a float',
            'date' => ':attribute must be a date Y-m-d',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $server_update = Server::where('id', $request->id)->update(request()->all());

        Server::serverRelatedCacheForget();
        Server::serverSpecificCacheForget($request->id);

        if ($server_update) {
            return response()->json(array('result' => 'success', 'server_id' => $request->id), 200);
        }

        return response()->json(array('result' => 'fail', 'request' => $request->post()), 500);
    }

    public function updatePricing(Request $request)
    {
        $rules = [
            'price' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'term' => 'required|integer',
            'active' => 'integer',
            'next_due_date' => 'date',
        ];

        $messages = [
            'required' => ':attribute is required',
            'integer' => ':attribute must be an integer',
            'string' => ':attribute must be a string',
            'size' => ':attribute must be exactly :size characters',
            'numeric' => ':attribute must be a float',
            'date' => ':attribute must be a date Y-m-d',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $pricing = new Pricing();

        $request->as_usd = $pricing->convertToUSD($request->price, $request->currency);

        $request->usd_per_month = $pricing->costAsPerMonth($request->as_usd, $request->term);

        $price_update = Pricing::where('id', $request->id)->update(request()->all());

        Cache::forget("all_pricing");
        Server::serverRelatedCacheForget();

        if ($price_update) {
            return response()->json(array('result' => 'success', 'server_id' => $request->id), 200);
        }

        return response()->json(array('result' => 'fail', 'request' => $request->post()), 500);
    }

    public function storeYabs(Request $request, Server $server, string $key): \Illuminate\Http\JsonResponse
    {
        $r = User::where('api_token', $key)->first();
        if (!isset($r->id)) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $insert = Yabs::insertFromJson($request, $server->id);

        if ($insert) {
            Cache::forget('all_active_servers');//all servers cache
            Cache::forget('non_active_servers');//all servers cache
            Cache::forget('all_yabs');//Forget the all YABS cache
            return response()->json(array('message' => 'Successfully added YABS'), 200);
        }

        return response()->json(array('error' => 'Server error'), 500);
    }

    public function getAllYabs()
    {
        $yabs = Yabs::allYabs()->toJson(JSON_PRETTY_PRINT);
        return response($yabs, 200);
    }

    protected function getYabs($id)
    {
        $yabs = Yabs::yabs($id)->toJson(JSON_PRETTY_PRINT);
        return response($yabs, 200);
    }

    protected function getNote($id)
    {
        $note = Note::where('id', $id)->firstOrFail('note')->pluck('note');
        return response($note, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * API endpoint for exporting servers
     * GET /api/export/servers?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportServers(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportServers($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting domains
     * GET /api/export/domains?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportDomains(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportDomains($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting shared hosting
     * GET /api/export/shared?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportShared(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportShared($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting reseller hosting
     * GET /api/export/reseller?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportReseller(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportReseller($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting seedboxes
     * GET /api/export/seedboxes?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportSeedboxes(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportSeedboxes($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting DNS records
     * GET /api/export/dns?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportDns(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportDns($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting misc services
     * GET /api/export/misc?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportMisc(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportMisc($format);

        return $this->createExportResponse($export);
    }

    /**
     * API endpoint for exporting all data
     * GET /api/export/all?format=json|csv
     *
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function exportAll(Request $request)
    {
        $format = $request->query('format', 'json');

        if (!$this->exportService->isValidFormat($format)) {
            return response()->json([
                'error' => 'Invalid format. Supported formats: json, csv'
            ], 400);
        }

        $export = $this->exportService->exportAll($format);

        return $this->createExportResponse($export);
    }

    /**
     * Create a response with appropriate headers for API export
     *
     * @param array{data: string, filename: string, content_type: string} $export
     * @return \Illuminate\Http\Response
     */
    protected function createExportResponse(array $export): \Illuminate\Http\Response
    {
        return response($export['data'], 200)
            ->header('Content-Type', $export['content_type'])
            ->header('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
    }
}
