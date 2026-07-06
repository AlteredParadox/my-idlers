<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class IPs extends Model
{
    use HasFactory;

    public $table = 'ips';

    protected $keyType = 'string';

    protected $fillable = ['id', 'service_id', 'address', 'is_ipv4', 'active', 'continent', 'country', 'region', 'city', 'org', 'isp', 'asn', 'timezone_gmt', 'fetched_at'];

    // MySQL returns tinyint/int columns as strings; cast so the strict
    // `=== 1` checks in the edit/detail views work in production.
    protected $casts = [
        'is_ipv4' => 'integer',
        'active' => 'integer',
    ];

    public $incrementing = false;

    public static function deleteIPsAssignedTo($service_id): void
    {
        DB::table('ips')->where('service_id', $service_id)->delete();
    }

    public static function insertIP(string $service_id, string $address): IPs
    {
        return self::create(
            [
                'id' => Str::random(8),
                'service_id' => $service_id,
                'address' => $address,
                'is_ipv4' => (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) ? 0 : 1,
                'active' => 1
            ]
        );
    }

    public static function ipsForServer(string $server_id)
    {
        return Cache::remember("ip_addresses.$server_id", now()->addHours(1), function () use ($server_id) {
            return json_decode(DB::table('ips as i')
                ->where('i.service_id', $server_id)
                ->get(), true);
        });
    }

    public function note(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Note::class, 'service_id', 'id');
    }

    public static function getUpdateIpInfo(IPs $ip): bool
    {
        try {
            $response = Http::get("https://ipwhois.app/json/{$ip->address}");
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // DNS/timeout/unreachable: don't 500 the IP create; callers treat
            // false as "whois unavailable" and continue.
            return false;
        }

        if ($response->ok()) {

            $data = $response->json();

            // ipwhois.app returns HTTP 200 with success:false when rate-limited;
            // bail so we don't overwrite good whois data with nulls.
            if (!($data['success'] ?? false)) {
                return false;
            }

            $ip->update([
                'continent' => $data['continent'],
                'country' => $data['country'],
                'region' => $data['region'],
                'city' => $data['city'],
                'org' => $data['org'],
                'isp' => $data['isp'],
                'asn' => $data['asn'],
                'timezone_gmt' => $data['timezone_gmt'],
                'fetched_at' => now()
            ]);

            return true;
        }

        return false;
    }

}
