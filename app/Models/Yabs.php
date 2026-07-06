<?php

namespace App\Models;

use DateTime;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Yabs extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'yabs';

    protected $fillable = ['id', 'server_id', 'has_ipv6', 'aes', 'vm', 'output_date', 'cpu_cores', 'cpu_freq', 'cpu_model', 'ram', 'ram_type', 'ram_mb', 'disk', 'disk_type', 'disk_gb', 'gb5_single', 'gb5_multi', 'gb5_id', 'gb6_single', 'gb6_multi', 'gb6_id', '4k', '4k_type', '4k_as_mbps', '64k', '64k_type', '64k_as_mbps', '512k', '512k_type', '512k_as_mbps', '1m', '1m_type', '1m_as_mbps', 'location', 'send', 'send_type', 'send_as_mbps', 'receive', 'receive_type', 'receive_as_mbps', 'uptime', 'distro', 'kernel', 'swap', 'swap_type', 'swap_mb'];

    public static function yabs(string $yabs_id)
    {
        return Cache::remember("yabs.$yabs_id", now()->addMonth(1), function () use ($yabs_id) {
            return self::where('id', $yabs_id)->with(['server', 'disk_speed', 'network_speed', 'server.location', 'server.provider'])
                ->first();
        });
    }

    public static function allYabs()
    {
        return Cache::remember("all_yabs", now()->addMonth(1), function () {
            return self::with(['server', 'disk_speed', 'network_speed', 'server.location', 'server.provider'])
                ->get();
        });
    }

    public function server(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Server::class, 'id', 'server_id');
    }

    public function disk_speed(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DiskSpeed::class, 'id', 'id');
    }

    public function network_speed(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NetworkSpeed::class, 'id', 'id');
    }

    public static function buildYabsArray($data): array
    {
        $speed_tests = [];
        foreach ($data->network_speed as $ns) {
            $speed_tests[] = [
                'location' => $ns->location,
                'send' => $ns->send . ' ' . $ns->send_type,
                'receive' => $ns->receive . ' ' . $ns->receive_type,
            ];
        }
        return [
            'date_time' => $data->output_date,
            'location' => $data->server->location->name,
            'provider' => $data->server->provider->name,
            'uptime' => $data->uptime,
            'distro' => $data->distro,
            'kernel' => $data->kernel,
            'cpu' => [
                'cores' => $data->cpu_cores,
                'speed_mhz' => $data->cpu_freq,
                'model' => $data->cpu_model,
                'aes' => $data->aes === 1,
                'vm' => $data->vm === 1,
                'GB5_single' => $data->gb5_single,
                'GB5_multi' => $data->gb5_multi,
            ],
            'ram' => [
                'amount' => $data->ram . ' ' . $data->ram_type,
                'mb' => $data->ram_mb,
                'swap' => [
                    'amount' => $data->swap ?? null . ' ' . $data->swap_type ?? null,
                    'mb' => $data->swap_mb ?? null,
                ],
            ],
            'disk' => [
                'amount' => $data->disk . ' ' . $data->disk_type,
                'gb' => $data->disk_gb,
                'speed_tests' => [
                    '4k' => $data->disk_speed->d_4k . ' ' . $data->disk_speed->d_4k_type,
                    '64k' => $data->disk_speed->d_64k . ' ' . $data->disk_speed->d_64k_type,
                    '512k' => $data->disk_speed->d_512k . ' ' . $data->disk_speed->d_512k_type,
                    '1m' => $data->disk_speed->d_1m . ' ' . $data->disk_speed->d_1m_type,
                ],
            ],
            'network' => [
                'has_ipv6' => $data->has_ipv6 === 1,
                'speed_tests' => $speed_tests
            ],
        ];
    }

    public static function speedAsMbps(string $string): float
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

    public static function speedType(string $string): string
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

    public static function speedAsFloat(string $string): float
    {
        $data = explode(" ", $string);
        if ($data[0] === 'busy') {
            return 0;
        }
        return (float)$data[0];
    }

    public static function formatRunTime(string $date): string
    {
        return DateTime::createFromFormat('Ymd-His', $date)->format('Y-m-d H:i:s');
    }

    public static function gb5IdFromURL(string $url): int
    {
        return str_replace("https://browser.geekbench.com/v5/cpu/", "", $url);
    }

    public static function gb6IdFromURL(string $url): int
    {
        return str_replace("https://browser.geekbench.com/v6/cpu/", "", $url);
    }

    public static function KBstoMBs(int $kbs): float
    {
        return $kbs / 1000;
    }

    public static function insertFromJson($data, string $server_id): bool
    {
        $data = (object)$data;
        try {
            $yabs_id = Str::random(8);

            self::insertYabsRow($yabs_id, $server_id, $data);
            self::insertDiskSpeeds($yabs_id, $server_id, $data['fio']);
            self::insertNetworkSpeeds($yabs_id, $server_id, $data['iperf'], (bool)$data['net']['ipv4']);
            self::updateServerAfterRun($server_id, $data['cpu']['model']);

            Cache::forget("yabs.$yabs_id");
            Cache::forget("all_yabs");
            Cache::forget("server.$server_id");
            Cache::forget("all_servers");
        } catch (Exception $e) {//Not a valid YABS payload
            return false;
        }
        return true;
    }

    private static function insertYabsRow(string $yabs_id, string $server_id, object $data): void
    {
        [$ram_f, $ram_type] = self::scaleRam($data['mem']['ram']);
        [$disk_f, $disk_type] = self::scaleDisk($data['mem']['disk']);

        self::create([
            'id' => $yabs_id,
            'server_id' => $server_id,
            'has_ipv6' => $data['net']['ipv6'],
            'aes' => $data['cpu']['aes'],
            'vm' => $data['cpu']['virt'],
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
            'output_date' => self::formatRunTime($data->time),
        ] + self::geekbenchScores($data['geekbench']));
    }

    /** YABS reports RAM in KB; store as MB or GB */
    private static function scaleRam($ram): array
    {
        if ($ram > 999999) {
            return [$ram / 1024 / 1024, 'GB'];
        }
        return [$ram / 1024, 'MB'];
    }

    /** YABS reports disk in KB; store as GB or TB */
    private static function scaleDisk($disk): array
    {
        if ($disk > 100000000) {
            return [$disk / 1024 / 1024 / 1024, 'TB'];
        }
        return [$disk / 1024 / 1024, 'GB'];
    }

    private static function geekbenchScores($geekbench): array
    {
        $scores = [
            'gb5_single' => null, 'gb5_multi' => null, 'gb5_id' => null,
            'gb6_single' => null, 'gb6_multi' => null, 'gb6_id' => null,
        ];
        foreach ($geekbench as $gb) {
            if ($gb['version'] === 5) {
                $scores['gb5_single'] = $gb['single'];
                $scores['gb5_multi'] = $gb['multi'];
                $scores['gb5_id'] = self::gb5IdFromURL($gb['url']);
            } elseif ($gb['version'] === 6) {
                $scores['gb6_single'] = $gb['single'];
                $scores['gb6_multi'] = $gb['multi'];
                $scores['gb6_id'] = self::gb6IdFromURL($gb['url']);
            }
        }

        return $scores;
    }

    private static function insertDiskSpeeds(string $yabs_id, string $server_id, $fio): void
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
            $row["{$col}_as_mbps"] = self::KBstoMBs($speed);
        }

        DiskSpeed::create($row);
    }

    private static function insertNetworkSpeeds(string $yabs_id, string $server_id, $iperf, bool $has_ipv4): void
    {
        $match = $has_ipv4 ? 'IPv4' : 'IPv6';
        foreach ($iperf as $st) {
            if ($st['mode'] === $match && ($st['send'] !== "busy " || $st['recv'] !== "busy ")) {
                NetworkSpeed::create([
                    'id' => $yabs_id,
                    'server_id' => $server_id,
                    'location' => $st['loc'],
                    'send' => self::speedAsFloat($st['send']),
                    'send_type' => self::speedType($st['send']),
                    'send_as_mbps' => self::speedAsMbps($st['send']),
                    'receive' => self::speedAsFloat($st['recv']),
                    'receive_type' => self::speedType($st['recv']),
                    'receive_as_mbps' => self::speedAsMbps($st['recv'])
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
    private static function updateServerAfterRun(string $server_id, string $cpu_model): void
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
