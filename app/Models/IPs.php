<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * Diff a service's IPs against the submitted set: rows whose address is
     * unchanged keep their id, fetched whois data and attached notes; only
     * genuinely removed addresses are deleted (with their notes) and only
     * new addresses inserted. (Delete-all/reinsert wiped whois + notes on
     * EVERY edit; replace-on-any-difference still wiped the untouched rows.)
     */
    public static function syncForService(string $service_id, array $addresses): void
    {
        // Dedupe case-insensitively: (service_id, address) is unique under a
        // ci collation, so 'DB8' and 'db8' IPv6 variants are the SAME row —
        // a case-sensitive diff would 500 on insert (or wipe an unchanged
        // row's whois/notes just to reinsert a case-variant of it).
        $submitted = array_values(array_unique(array_map('strtolower', array_filter($addresses, 'is_string'))));
        $existing = self::normalizeLegacyRows($service_id); // id => lowercase address

        foreach (array_keys(array_diff($existing, $submitted)) as $ip_id) {
            Note::deleteForService($ip_id);
            self::where('id', $ip_id)->delete();
        }

        foreach (array_diff($submitted, $existing) as $address) {
            self::insertIP($service_id, $address);
        }
    }

    /**
     * Bring a service's legacy rows onto the lowercase convention and
     * collapse case-variant duplicates (SQLite's unique index is
     * case-SENSITIVE, so pre-normalization variants of one address can
     * coexist as rows — and an un-normalized survivor would let insertIP
     * mint a fresh duplicate). Among duplicates the row with an attached
     * note wins (notes aren't refetchable), then one with whois data,
     * then any. Returns the surviving id => lowercase-address map.
     */
    private static function normalizeLegacyRows(string $service_id): array
    {
        $kept = [];
        foreach (self::where('service_id', $service_id)->get() as $row) {
            $key = strtolower($row->address);

            if (isset($kept[$key])) {
                $incumbent = $kept[$key];
                $rowWins = (Note::where('service_id', $row->id)->exists()
                        && !Note::where('service_id', $incumbent->id)->exists())
                    || (!is_null($row->fetched_at) && is_null($incumbent->fetched_at)
                        && !Note::where('service_id', $incumbent->id)->exists());
                $loser = $rowWins ? $incumbent : $row;
                if ($rowWins) {
                    $kept[$key] = $row;
                }
                Note::deleteForService($loser->id);
                self::where('id', $loser->id)->delete();
                continue;
            }

            $kept[$key] = $row;
        }

        $existing = [];
        foreach ($kept as $key => $row) {
            if ($row->address !== $key) {
                self::where('id', $row->id)->update(['address' => $key]);
            }
            $existing[$row->id] = $key;
        }

        return $existing;
    }

    public static function insertIP(string $service_id, string $address): IPs
    {
        // Stored lowercase: the unique index is case-insensitive, so
        // case-variant IPv6 spellings must normalize to one canonical form
        $address = strtolower($address);

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
