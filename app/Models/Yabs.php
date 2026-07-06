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

    // MySQL returns tinyint/int columns as strings; cast so the strict
    // `=== 1` checks in the edit/detail views work in production.
    protected $casts = [
        'has_ipv6' => 'integer',
        'aes' => 'integer',
        'vm' => 'integer',
    ];

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
                    'amount' => ($data->swap ?? '') . ' ' . ($data->swap_type ?? ''),
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

    /**
     * Delete every YABS for a server plus their disk_speed/network_speed
     * children (there are no DB cascades) and clear the yabs caches.
     */
    public static function deleteForServer(string $server_id): void
    {
        foreach (self::where('server_id', $server_id)->pluck('id') as $yabs_id) {
            DiskSpeed::where('id', $yabs_id)->delete();
            NetworkSpeed::where('id', $yabs_id)->delete();
            Cache::forget("yabs.$yabs_id");
        }
        self::where('server_id', $server_id)->delete();
        Cache::forget('all_yabs');
    }
}
