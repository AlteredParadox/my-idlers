<?php

namespace App\Models;

use App\Models\Concerns\OrdersBySessionSetting;
use App\Models\Concerns\SortsByPricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SeedBoxes extends Model
{
    use HasFactory, OrdersBySessionSetting, SortsByPricing;

    protected $table = 'seedboxes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'active', 'title', 'hostname', 'seed_box_type', 'provider_id', 'location_id', 'bandwidth', 'port_speed', 'disk', 'disk_type', 'disk_as_gb', 'was_promo', 'transferrable', 'owned_since'];

    // MySQL returns tinyint/int columns as strings; cast so the strict
    // `=== 1` checks in the edit/detail views work in production.
    protected $casts = [
        'active' => 'integer',
        'was_promo' => 'integer',
        'transferrable' => 'integer',
    ];

    public function ips(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IPs::class, 'service_id', 'id');
    }

    public static function allSeedboxes()
    {//All seedboxes and relationships (no using joins)
        return Cache::remember("all_seedboxes", now()->addMonth(1), function () {
            $query = SeedBoxes::with(['location', 'provider', 'price', 'ips']);
            self::applyPricingSort($query);
            return $query->get();
        });
    }

    public static function seedbox(string $seedbox_id)
    {//Single seedbox and relationships (no using joins)
        return Cache::remember("seedbox.$seedbox_id", now()->addMonth(1), function () use ($seedbox_id) {
            return SeedBoxes::where('id', $seedbox_id)
                ->with(['location', 'provider', 'price', 'ips'])->first();
        });
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

}
