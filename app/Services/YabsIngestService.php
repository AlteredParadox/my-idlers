<?php

namespace App\Services;

use App\Models\DiskSpeed;
use App\Models\NetworkSpeed;
use App\Models\Yabs;
use DateTime;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class YabsIngestService
{

    public function speedType(string $string): string
    {
        return $this->parseSpeed($string)[1];
    }


    /**
     * One pass over an iperf speed string: [value, unit label, as Mbps].
     * Bit-rates, not byte-rates: iperf reports Gbits/sec and the old
     * GBps label was an 8x mislabel on every network row.
     *
     * @return array{0: float, 1: string, 2: float}
     */
    private function parseSpeed(string $string): array
    {
        $data = explode(" ", $string);
        if ($data[0] === 'busy') {
            return [0.0, 'Mbps', 0.0];
        }
        return [
            (float)$data[0],
            match ($data[1]) {
                "Gbits/sec" => "Gbps",
                "Mbits/sec" => "Mbps",
                default => "Kbps",
            },
            match ($data[1]) {
                "Gbits/sec" => $data[0] * 1000,
                "Mbits/sec" => (float)$data[0],
                default => $data[0] / 1000,//Kbps
            },
        ];
    }


    public function formatRunTime(string $date): string
    {
        return DateTime::createFromFormat('Ymd-His', $date)->format('Y-m-d H:i:s');
    }


    public function gb5IdFromURL(string $url): int
    {
        return str_replace("https://browser.geekbench.com/v5/cpu/", "", $url);
    }


    public function gb6IdFromURL(string $url): int
    {
        return str_replace("https://browser.geekbench.com/v6/cpu/", "", $url);
    }


    public function kbsToMbs(int $kbs): float
    {
        return $kbs / 1000;
    }


    public function ingest(array $data, string $server_id): bool
    {
        $parsed = $this->parse($data, $server_id);

        return $parsed !== null && $this->persist($parsed);
    }


    /**
     * Parse a YABS payload into the rows to persist, or null when the
     * payload is malformed — that's client input (422 on the API), distinct
     * from a persistence failure.
     */
    public function parse(array $data, string $server_id): ?array
    {
        try {
            $yabs_id = Str::random(8);

            return [
                'server_id' => $server_id,
                'cpu_model' => $data['cpu']['model'],
                'yabs' => $this->yabsRow($yabs_id, $server_id, $data),
                // Modern yabs.sh omits these keys when a test auto-skips (fio
                // needs 2GB free; iperf binary download can fail) — the run is
                // still complete, valid output and must ingest.
                'disk_speed' => $this->diskSpeedRow($yabs_id, $server_id, $data['fio'] ?? []),
                'network_speeds' => $this->networkSpeedRows($yabs_id, $server_id, $data['iperf'] ?? [], (bool)($data['net']['ipv4'] ?? 0)),
            ];
        } catch (\Throwable $e) {//Not a valid YABS payload
            // Throwable, not Exception: the parse helpers raise Error/TypeError
            // on malformed input (createFromFormat false, non-geekbench URLs,
            // non-numeric mem values) and those must hit this path too.
            return null;
        }
    }


    /** Persist a parse() result; false means a genuine server-side failure. */
    public function persist(array $parsed): bool
    {
        try {
            DB::transaction(function () use ($parsed) {
                Yabs::create($parsed['yabs']);
                if ($parsed['disk_speed'] !== null) {
                    DiskSpeed::create($parsed['disk_speed']);
                }
                foreach ($parsed['network_speeds'] as $row) {
                    NetworkSpeed::create($row);
                }
                $this->updateServerAfterRun($parsed['server_id'], $parsed['cpu_model']);
            });

            Cache::forget("yabs.{$parsed['yabs']['id']}");
            Cache::forget("all_yabs");
            Cache::forget("server.{$parsed['server_id']}");
            Cache::forget("all_servers");
            // The public servers page renders YABS data too; the delete path
            // clears this via serverRelatedCacheForget, the add path must match.
            Cache::forget("public_server_data");
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }


    private function yabsRow(string $yabs_id, string $server_id, array $data): array
    {
        [$ram_f, $ram_type] = $this->scaleRam($data['mem']['ram']);
        [$disk_f, $disk_type] = $this->scaleDisk($data['mem']['disk']);

        // `vm` is a boolean column. CURRENT yabs.sh reports the is-a-VM
        // answer in os.vm (systemd-detect-virt: "KVM"/"NONE"/...) and made
        // cpu.virt a boolean meaning "host CPU has vmx/svm flags" — deriving
        // from cpu.virt inverts the flag for a KVM guest without nested
        // virt AND for bare metal with VT-x. Prefer os.vm; fall back to the
        // legacy cpu.virt STRING shape (older reports), never its boolean.
        $os_vm = strtolower((string) ($data['os']['vm'] ?? ''));
        if ($os_vm !== '') {
            $is_vm = ($os_vm === 'none') ? 0 : 1;
        } else {
            $virt = $data['cpu']['virt'] ?? '';
            $is_vm = (is_string($virt) && $virt !== '' && strtolower($virt) !== 'none') ? 1 : 0;
        }

        return [
            'id' => $yabs_id,
            'server_id' => $server_id,
            'has_ipv6' => $data['net']['ipv6'],
            'aes' => $data['cpu']['aes'],
            'vm' => $is_vm,
            'distro' => $data['os']['distro'],
            'kernel' => $data['os']['kernel'],
            'uptime' => $this->formatUptime($data['os']['uptime']),
            'cpu_model' => $data['cpu']['model'],
            'cpu_cores' => $data['cpu']['cores'],
            'cpu_freq' => (float)$data['cpu']['freq'],
            'ram' => $ram_f,
            'ram_type' => $ram_type,
            'ram_mb' => ($data['mem']['ram'] / 1024),
            'swap' => $data['mem']['swap'] / 1024,
            'swap_mb' => ($data['mem']['swap'] / 1024),
            'swap_type' => 'MB',
            'disk' => $disk_f,
            'disk_gb' => ($data['mem']['disk'] / 1024 / 1024),
            'disk_type' => $disk_type,
            'output_date' => $this->formatRunTime($data['time']),
        ] + $this->geekbenchScores($data['geekbench'] ?? []);
    }


    /** YABS reports RAM in KB; store as MB or GB */
    private function scaleRam($ram): array
    {
        if ($ram > 999999) {
            return [$ram / 1024 / 1024, 'GB'];
        }
        return [$ram / 1024, 'MB'];
    }


    /** YABS reports disk in KB; store as GB or TB */
    private function scaleDisk($disk): array
    {
        // $disk is in KB; ~1 TB boundary (was 100000000 ≈ 95 GB, which pushed
        // every normal VPS disk into the fractional-TB branch).
        if ($disk > 1000000000) {
            return [$disk / 1024 / 1024 / 1024, 'TB'];
        }
        return [$disk / 1024 / 1024, 'GB'];
    }


    private function geekbenchScores($geekbench): array
    {
        $scores = [
            'gb5_single' => null, 'gb5_multi' => null, 'gb5_id' => null,
            'gb6_single' => null, 'gb6_multi' => null, 'gb6_id' => null,
        ];
        foreach ($geekbench as $gb) {
            if ($gb['version'] === 5) {
                $scores['gb5_single'] = $gb['single'];
                $scores['gb5_multi'] = $gb['multi'];
                $scores['gb5_id'] = $this->gb5IdFromURL($gb['url']);
            } elseif ($gb['version'] === 6) {
                $scores['gb6_single'] = $gb['single'];
                $scores['gb6_multi'] = $gb['multi'];
                $scores['gb6_id'] = $this->gb6IdFromURL($gb['url']);
            }
        }

        return $scores;
    }


    /** Modern yabs.sh emits uptime as raw seconds from /proc/uptime */
    private function formatUptime($uptime): string
    {
        if (!is_numeric($uptime)) {
            return (string) $uptime;
        }
        $seconds = (int) $uptime;
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return trim(($days > 0 ? "$days days, " : '') . "$hours hours, $minutes minutes", ', ');
    }

    private function diskSpeedRow(string $yabs_id, string $server_id, $fio): ?array
    {
        if (empty($fio)) {
            return null;//disk_speed columns are NOT NULL; no row when fio was skipped
        }
        $speeds = [];
        foreach ($fio as $ds) {
            $speeds[$ds['bs']] = $ds['speed_rw'];
        }

        $row = ['id' => $yabs_id, 'server_id' => $server_id];
        foreach (['4k' => 'd_4k', '64k' => 'd_64k', '512k' => 'd_512k', '1m' => 'd_1m'] as $bs => $col) {
            if (!isset($speeds[$bs])) {
                continue;
            }
            $speed = $speeds[$bs];
            $row[$col] = ($speed > 999999) ? ($speed / 1000 / 1000) : $speed / 1000;
            $row["{$col}_type"] = ($speed > 999999) ? 'GB/s' : 'MB/s';
            $row["{$col}_as_mbps"] = $this->kbsToMbs($speed);
        }

        return $row;
    }


    private function networkSpeedRows(string $yabs_id, string $server_id, $iperf, bool $has_ipv4): array
    {
        $rows = [];
        $match = $has_ipv4 ? 'IPv4' : 'IPv6';
        foreach ($iperf as $st) {
            if ($st['mode'] === $match && ($st['send'] !== "busy " || $st['recv'] !== "busy ")) {
                [$send, $send_type, $send_mbps] = $this->parseSpeed($st['send']);
                [$recv, $recv_type, $recv_mbps] = $this->parseSpeed($st['recv']);
                $rows[] = [
                    'id' => $yabs_id,
                    'server_id' => $server_id,
                    'location' => $st['loc'],
                    'send' => $send,
                    'send_type' => $send_type,
                    'send_as_mbps' => $send_mbps,
                    'receive' => $recv,
                    'receive_type' => $recv_type,
                    'receive_as_mbps' => $recv_mbps
                ];
            }
        }

        return $rows;
    }


    /**
     * Don't overwrite user-entered specs with measured values
     * (server_disks is the disk source of truth, and ram/cpu hold
     * as-provisioned amounts; YABS sees usable RAM and only the root
     * filesystem). Flag the benchmark and fill cpu_model when unset.
     */
    private function updateServerAfterRun(string $server_id, string $cpu_model): void
    {
        $server_update = ['has_yabs' => 1];

        if (empty(DB::table('servers')->where('id', $server_id)->value('cpu_model'))) {
            $server_update['cpu_model'] = $cpu_model;
        }

        DB::table('servers')
            ->where('id', $server_id)
            ->update($server_update);
    }

}
