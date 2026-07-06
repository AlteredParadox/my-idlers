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
        // Notes can attach to IPs; without this they linger as ghost rows
        // with a blank Service column after the IP rows are bulk-deleted.
        foreach (DB::table('ips')->where('service_id', $service_id)->pluck('id') as $ip_id) {
            Note::deleteForService($ip_id);
        }
        DB::table('ips')->where('service_id', $service_id)->delete();
    }

    /**
     * Replace a service's IPs only when the address set actually changed:
     * the old delete-all/reinsert on every edit regenerated row ids, threw
     * away fetched whois data and orphaned notes attached to the IPs.
     */
    public static function syncForService(string $service_id, array $addresses): void
    {
        $submitted = array_values($addresses);
        $existing = self::where('service_id', $service_id)->pluck('address')->all();

        $a = $submitted;
        $b = $existing;
        sort($a);
        sort($b);
        if ($a === $b) {
            return;
        }

        self::deleteIPsAssignedTo($service_id);
        foreach ($submitted as $address) {
            self::insertIP($service_id, $address);
        }
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

        // ipwhois.app returns HTTP 200 with success:false when rate-limited;
        // bail so we don't overwrite good whois data with nulls.
        $data = $response->ok() ? $response->json() : null;
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

}
