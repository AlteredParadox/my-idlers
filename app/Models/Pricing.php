<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Pricing extends Model
{
    use HasFactory;

    protected $table = 'pricings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['service_id', 'service_type', 'currency', 'price', 'term', 'as_usd', 'usd_per_month', 'next_due_date'];

    private static function refreshRates(): object
    {
        if (Cache::has("currency_rates")) {
            return Cache::get("currency_rates");
        }
        $response_json = file_get_contents(config('services.exchange_rates.url'));
        if (false === $response_json) {
            Log::error("do file_get_contents failed");
            return (object)null;
        }
        try {
            $response = json_decode($response_json);
            if ('success' === $response->result) {
                return Cache::remember("currency_rates", now()->addWeek(1), function () use ($response) {
                    return $response->rates;
                });
            }
            Log::error("server response is " . $response->result . ", expecting success");
        } catch (Exception $e) {
            Log::error("failed to request v6.exchangerate-api.com", ['err' => $e]);
        }
        return (object)null;
    }

    private static function getRates($currency): float
    {
        $rate = self::refreshRates()->$currency;
        return $rate ?? 1.00;
    }

    public static function getCurrencyList(): array
    {
        return array_keys((array)self::refreshRates());
    }

    public static function convertFromUSD(string $amount, string $convert_to): float
    {
        return $amount * self::getRates($convert_to);
    }

    public function convertToUSD(string $amount, string $convert_from): float
    {
        return $amount / self::getRates($convert_from);
    }

    public function costAsPerMonth(string $cost, int $term): float
    {
        return match ($term) {
            2 => $cost / 3,
            3 => $cost / 6,
            4 => $cost / 12,
            5 => $cost / 24,
            6 => $cost / 36,
            7 => 0,
            default => $cost,
        };
    }

    public function termAsMonths(int $term): int
    {
        return match ($term) {
            1 => 1,
            2 => 3,
            3 => 6,
            4 => 12,
            5 => 24,
            6 => 36,
            7 => 0,
            default => 62,
        };
    }

    public function deletePricing($id): void
    {
        DB::table('pricings')->where('service_id', $id)->delete();
    }

    public function insertPricing(int $type, string $service_id, string $currency, float $price, int $term, ?string $next_due_date, int $is_active = 1): Pricing
    {
        $as_usd = $this->convertToUSD($price, $currency);
        return self::create([
            'service_type' => $type,
            'service_id' => $service_id,
            'currency' => $currency,
            'price' => $price,
            'term' => $term,
            'as_usd' => $as_usd,
            'usd_per_month' => $this->costAsPerMonth($as_usd, $term),
            'next_due_date' => $next_due_date,
            'active' => $is_active
        ]);
    }

    public function updatePricing(string $service_id, string $currency, float $price, int $term, ?string $next_due_date, int $is_active = 1): int
    {
        $as_usd = $this->convertToUSD($price, $currency);
        return DB::table('pricings')
            ->where('service_id', $service_id)
            ->update([
                'currency' => $currency,
                'price' => $price,
                'term' => $term,
                'as_usd' => $as_usd,
                'usd_per_month' => $this->costAsPerMonth($as_usd, $term),
                'next_due_date' => $next_due_date,
                'active' => $is_active
            ]);
    }

    public static function allPricing()
    {
        return Cache::remember('all_active_pricing', now()->addWeek(1), function () {
            $serviceIds = collect()
                ->merge(DB::table('servers')->where('active', 1)->pluck('id'))
                ->merge(DB::table('shared_hosting')->where('active', 1)->pluck('id'))
                ->merge(DB::table('reseller_hosting')->where('active', 1)->pluck('id'))
                ->merge(DB::table('domains')->where('active', 1)->pluck('id'))
                ->merge(DB::table('misc_services')->where('active', 1)->pluck('id'))
                ->merge(DB::table('seedboxes')->where('active', 1)->pluck('id'));

            return Pricing::where('active', 1)
                ->whereIn('service_id', $serviceIds)
                ->get();
        });
    }

}
