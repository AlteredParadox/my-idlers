<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Pricing extends Model
{
    use HasFactory;

    protected $table = 'pricings';

    protected $fillable = ['service_id', 'service_type', 'currency', 'price', 'term', 'as_usd', 'usd_per_month', 'next_due_date', 'active'];

    private static function refreshRates(): object
    {
        // Single read, not has()+get(): halves the cache round-trips (this
        // runs per convert call) and drops the check-then-read race.
        $rates = Cache::get("currency_rates");
        if ($rates !== null) {
            return $rates;
        }

        $url = config('services.exchange_rates.url');
        if (empty($url)) {
            return (object)null;
        }

        try {
            $response = Http::timeout(5)->get($url)->throw()->object();
            if ('success' === ($response->result ?? null)) {
                return Cache::remember("currency_rates", now()->addWeek(1), function () use ($response) {
                    return $response->rates;
                });
            }
            Log::error("exchange rate response is " . ($response->result ?? 'unknown') . ", expecting success");
        } catch (Exception $e) {
            Log::error("failed to fetch exchange rates", ['err' => $e]);
        }

        return (object)null;
    }

    private static function getRates($currency): float
    {
        // Null coalescing on the property access itself: without it, a missing
        // currency (e.g. rates fetch failed) raises ErrorException before any
        // fallback can apply.
        $rate = self::refreshRates()->$currency ?? null;
        if ($rate === null) {
            if ($currency !== 'USD') {
                // Reachable only for legacy/stale rows: validation blocks new
                // writes with unrated currencies. Display paths must not 500,
                // so degrade to 1:1 — but loudly.
                Log::warning("no exchange rate available for $currency; converting 1:1");
            }

            return 1.00;
        }

        return $rate;
    }

    /**
     * With no rate data there is nothing we can CONVERT, so only USD (the
     * app's normalization currency, rate 1 by definition) is offered and
     * accepted. Offering EUR/GBP here stored amounts silently converted 1:1
     * as USD — the exact corruption the currency rule exists to stop.
     */
    public const FALLBACK_CURRENCIES = ['USD'];

    /**
     * ISO 4217 active alpha codes. Validation uses this FIXED list rather
     * than getCurrencyList(): the live list depends on the exchange-rate API
     * being reachable, and an outage must not brick editing non-USD services.
     * Unknown codes previously passed size:3 and were silently converted 1:1
     * as USD, corrupting as_usd/usd_per_month and every total built on them.
     */
    public const ISO_CURRENCIES = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY',
        'COP', 'CRC', 'CUP', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP',
        'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD',
        'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS',
        'INR', 'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR',
        'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD',
        'LSL', 'LYD', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU',
        'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK',
        'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG',
        'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK',
        'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL',
        'THB', 'TJS', 'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH',
        'UGX', 'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST', 'XAF', 'XCD',
        'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWL',
    ];

    /**
     * The web-form pricing rules shared by every service type's store and
     * update (the API uses date_format variants and its own optionality).
     */
    public static function webValidationRules(): array
    {
        return [
            'price' => 'required|numeric|min:0|max:99999999',
            'currency' => 'required|string|size:3|' . self::currencyRule(),
            'payment_term' => 'required|integer|in:1,2,3,4,5,6,7',
            'next_due_date' => 'sometimes|nullable|date',
        ];
    }

    /**
     * Validation fragment for currency fields: only currencies we can
     * actually CONVERT — the rated codes when the exchange API is reachable
     * (rates are cached for a week, so a brief outage keeps the full list),
     * else the explicit FALLBACK_CURRENCIES. Accepting any ISO code let a
     * valid-but-unrated currency (e.g. JPY with no rates configured) be
     * stored and silently converted 1:1 as USD, corrupting every total.
     * The ISO list still filters out junk keys from the rates API itself.
     */
    public static function currencyRule(): string
    {
        $accepted = array_values(array_intersect(self::getCurrencyList(), self::ISO_CURRENCIES));

        return 'in:' . implode(',', $accepted ?: self::FALLBACK_CURRENCIES);
    }

    public static function getCurrencyList(): array
    {
        $currencies = array_keys((array)self::refreshRates());

        return $currencies ?: self::FALLBACK_CURRENCIES;
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
            // Unknown term → don't auto-advance (0), never the old 62 months.
            // Validation now rejects terms outside 1-7, but any legacy row
            // with a bad term must not have its due date advanced 5+ years.
            default => 0,
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
            // Subqueries instead of plucking every active id into PHP first:
            // one query, same active-service id set (as in Home::dueSoonData).
            return Pricing::where('active', 1)
                ->where(function ($query) {
                    $query->whereIn('service_id', DB::table('servers')->where('active', 1)->select('id'))
                        ->orWhereIn('service_id', DB::table('shared_hosting')->where('active', 1)->select('id'))
                        ->orWhereIn('service_id', DB::table('reseller_hosting')->where('active', 1)->select('id'))
                        ->orWhereIn('service_id', DB::table('domains')->where('active', 1)->select('id'))
                        ->orWhereIn('service_id', DB::table('misc_services')->where('active', 1)->select('id'))
                        ->orWhereIn('service_id', DB::table('seedboxes')->where('active', 1)->select('id'));
                })
                ->get();
        });
    }

}
