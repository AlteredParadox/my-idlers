<?php

namespace App\Models;

use App\Models\Concerns\SortsByPricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Domains extends Model
{
    // No OrdersBySessionSetting here: domains deliberately have no
    // column-order global scope, only the pricing sort.
    use HasFactory, SortsByPricing;

    public $incrementing = false;

    protected $table = 'domains';

    protected $keyType = 'string';

    protected $fillable = ['id', 'active', 'domain', 'extension', 'ns1', 'ns2', 'ns3', 'price', 'currency', 'payment_term', 'owned_since', 'provider_id', 'next_due_date', 'transferrable'];

    // MySQL returns tinyint/int columns as strings; cast so the strict
    // `=== 1` checks in the edit/detail views work in production.
    protected $casts = [
        'active' => 'integer',
        'transferrable' => 'integer',
    ];


    public static function allDomains()
    {//All domains and relationships (no using joins)
        return Cache::remember("all_domains", now()->addMonth(1), function () {
            $query = Domains::with(['provider', 'price', 'labels']);
            self::applyPricingSort($query);
            return $query->get();
        });
    }

    public static function allActiveDomains()
    {
        return Cache::remember("all_active_domains", now()->addMonth(1), function () {
            $query = Domains::where('active', 1)->with(['provider', 'price', 'labels']);
            self::applyPricingSort($query);
            return $query->get();
        });
    }

    public static function allNonActiveDomains()
    {
        return Cache::remember("non_active_domains", now()->addMonth(1), function () {
            return Domains::where('active', 0)->with(['provider', 'price', 'labels'])->get();
        });
    }

    public static function domain(string $domain_id)
    {//Single domains and relationships (no using joins)
        return Cache::remember("domain.$domain_id", now()->addMonth(1), function () use ($domain_id) {
            return Domains::where('id', $domain_id)
                ->with(['provider', 'price', 'labels'])->first();
        });
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
