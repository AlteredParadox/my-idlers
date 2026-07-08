<?php

namespace App\Models;

use App\Models\Concerns\OrdersBySessionSetting;
use App\Models\Concerns\SortsByPricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Reseller extends Model
{
    use HasFactory, OrdersBySessionSetting, SortsByPricing;

    protected $table = 'reseller_hosting';

    protected $keyType = 'string';

    protected $fillable = ['id', 'active', 'accounts', 'main_domain', 'has_dedicated_ip', 'ip', 'reseller_type', 'provider_id', 'location_id', 'bandwidth', 'link_speed', 'disk', 'disk_type', 'disk_as_gb', 'domains_limit', 'subdomains_limit', 'ftp_limit', 'email_limit', 'db_limit', 'was_promo', 'transferrable', 'owned_since'];

    // MySQL returns tinyint/int columns as strings; cast so the strict
    // `=== 1` checks in the edit/detail views work in production.
    protected $casts = [
        'active' => 'integer',
        'was_promo' => 'integer',
        'transferrable' => 'integer',
    ];

    public $incrementing = false;

    public static function allResellerHosting()
    {//All reseller hosting and relationships (no using joins)
        return Cache::remember("all_reseller", now()->addMonth(1), function () {
            $query = Reseller::with(['location', 'provider', 'price', 'ips', 'labels']);
            self::applyPricingSort($query);
            return $query->get();
        });
    }

    public static function allActiveResellerHosting()
    {
        return Cache::remember("all_active_reseller", now()->addMonth(1), function () {
            $query = Reseller::where('active', 1)->with(['location', 'provider', 'price', 'ips', 'labels']);
            self::applyPricingSort($query);
            return $query->get();
        });
    }

    public static function allNonActiveResellerHosting()
    {
        return Cache::remember("non_active_reseller", now()->addMonth(1), function () {
            return Reseller::where('active', 0)->with(['location', 'provider', 'price', 'ips', 'labels'])->get();
        });
    }

    public static function resellerHosting(string $reseller_id)
    {//Single reseller hosting and relationships (no using joins)
        return Cache::remember("reseller_hosting.$reseller_id", now()->addMonth(1), function () use ($reseller_id) {
            return Reseller::where('id', $reseller_id)
                ->with(['location', 'provider', 'price', 'ips', 'labels'])->first();
        });
    }

    public function ips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IPs::class, 'service_id', 'id');
    }

    public function location(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Locations::class, 'id', 'location_id');
    }

    public function provider(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Providers::class, 'id', 'provider_id');
    }

    public function price(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Pricing::class, 'service_id', 'id');
    }

    public function labels(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LabelsAssigned::class, 'service_id', 'id');
    }

    public function note(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Note::class, 'service_id', 'id');
    }

}
