<?php

namespace App\Models;

use App\Models\Concerns\OrdersBySessionSetting;
use App\Models\Concerns\SortsByPricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Misc extends Model
{
    use HasFactory, OrdersBySessionSetting, SortsByPricing;

    public $incrementing = false;

    protected $table = 'misc_services';

    protected $keyType = 'string';

    protected $fillable = ['id', 'active', 'name', 'owned_since'];

    // MySQL returns tinyint/int columns as strings; cast so the strict
    // `=== 1` checks in the edit/detail views work in production.
    protected $casts = [
        'active' => 'integer',
    ];

    public static function allMisc()
    {//All misc and relationships (no using joins)
        return Cache::remember("all_misc", now()->addMonth(1), function () {
            $query = Misc::with(['price']);
            self::applyPricingSort($query);
            return $query->get();
        });
    }

    public static function allActiveMisc()
    {
        return Cache::remember("all_active_misc", now()->addMonth(1), function () {
            $query = Misc::where('active', 1)->with(['price']);
            self::applyPricingSort($query);
            return $query->get();
        });
    }

    public static function allNonActiveMisc()
    {
        return Cache::remember("non_active_misc", now()->addMonth(1), function () {
            return Misc::where('active', 0)->with(['price'])->get();
        });
    }

    public static function misc(string $misc_id)
    {//Single misc and relationships (no using joins)
        return Cache::remember("misc.$misc_id", now()->addMonth(1), function () use ($misc_id) {
            return Misc::where('id', $misc_id)
                ->with(['price'])->first();
        });
    }

    public function price(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Pricing::class, 'service_id', 'id');
    }

}
