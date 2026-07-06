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

    public function speedAsMbps(string $string): float
    {
        $data = explode(" ", $string);
        if ($data[0] === 'busy') {
            return 0;
        }
        return match ($data[1]) {
            "Gbits/sec" => $data[0] * 1000,
            "Mbits/sec" => $data[0],
            default => $data[0] / 1000,//Kbps
        };
    }


    public function speedType(string $string): string
    {
        $data = explode(" ", $string);
        if ($data[0] === 'busy') {
            return "MBps";
        }
        return match ($data[1]) {
            "Gbits/sec" => "GBps",
            "Mbits/sec" => "MBps",
            default => "KBps",//Kbps
        };
    }


    public function speedAsFloat(string $string): float
    {
        $data = explode(" ", $string);
        if ($data[0] === 'busy') {
            return 0;
        }
        return (float)$data[0];
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
        try {
            $yabs_id = Str::random(8);

            DB::transaction(function () use ($yabs_id, $server_id, $data) {
                $this->insertYabsRow($yabs_id, $server_id, $data);
                $this->insertDiskSpeeds($yabs_id, $server_id, $data['fio']);
                $this->insertNetworkSpeeds($yabs_id, $server_id, $data['iperf'], (bool)$data['net']['ipv4']);
                $this->updateServerAfterRun($server_id, $data['cpu']['model']);
            });

            Cache::forget("yabs.$yabs_id");
            Cache::forget("all_yabs");
            Cache::forget("server.$server_id");
            Cache::forget("all_servers");
            // The public servers page renders YABS data too; the delete path
            // clears this via serverRelatedCacheForget, the add path must match.
            Cache::forget("public_server_data");
        } catch (Exception $e) {//Not a valid YABS payload
            return false;
        }
        return true;
    }


    private function insertYabsRow(string $yabs_id, string $server_id, array $data): void
    {
        [$ram_f, $ram_type] = $this->scaleRam($data['mem']['ram']);
        [$disk_f, $disk_type] = $this->scaleDisk($data['mem']['disk']);

        // `vm` is a boolean column, but YABS reports cpu.virt as a string
        // ("KVM"/"none"/...). Store the is-virtualized flag; the raw string
        // would fail to insert on MySQL (integer column) and break every ingest.
        $virt = strtolower((string) ($data['cpu']['virt'] ?? ''));
        $is_vm = ($virt === '' || $virt === 'none') ? 0 : 1;

        Yabs::create([
            'id' => $yabs_id,
            'server_id' => $server_id,
            'has_ipv6' => $data['net']['ipv6'],
            'aes' => $data['cpu']['aes'],
            'vm' => $is_vm,
            'distro' => $data['os']['distro'],
            'kernel' => $data['os']['kernel'],
            'uptime' => $data['os']['uptime'],
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
        ] + $this->geekbenchScores($data['geekbench']));
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


    private function insertDiskSpeeds(string $yabs_id, string $server_id, $fio): void
    {
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

        DiskSpeed::create($row);
    }


    private function insertNetworkSpeeds(string $yabs_id, string $server_id, $iperf, bool $has_ipv4): void
    {
        $match = $has_ipv4 ? 'IPv4' : 'IPv6';
        foreach ($iperf as $st) {
            if ($st['mode'] === $match && ($st['send'] !== "busy " || $st['recv'] !== "busy ")) {
                NetworkSpeed::create([
                    'id' => $yabs_id,
                    'server_id' => $server_id,
                    'location' => $st['loc'],
                    'send' => $this->speedAsFloat($st['send']),
                    'send_type' => $this->speedType($st['send']),
                    'send_as_mbps' => $this->speedAsMbps($st['send']),
                    'receive' => $this->speedAsFloat($st['recv']),
                    'receive_type' => $this->speedType($st['recv']),
                    'receive_as_mbps' => $this->speedAsMbps($st['recv'])
                ]);
            }
        }
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
