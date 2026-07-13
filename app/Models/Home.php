<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Home extends Model
{
    use HasFactory;

    public static function homePageCacheForget(): void
    {
        Cache::forget('services_count');//Main page services_count cache
        Cache::forget('due_soon');//Main page due_soon cache
        Cache::forget('recently_added');//Main page recently_added cache
        Cache::forget('all_active_pricing');
        Cache::forget('services_count_all');
        Cache::forget('pricing_breakdown');
        Cache::forget('servers_summary');
    }

    public static function servicesCount()
    {
        return Cache::remember('services_count', now()->addHours(6), function () {
            return [
                'servers' => DB::table('servers')->where('active', 1)->count(),
                'shared' => DB::table('shared_hosting')->where('active', 1)->count(),
                'reseller' => DB::table('reseller_hosting')->where('active', 1)->count(),
                'domains' => DB::table('domains')->where('active', 1)->count(),
                'other' => DB::table('misc_services')->where('active', 1)->count(),
                'seedbox' => DB::table('seedboxes')->where('active', 1)->count(),
            ];
        });
    }

    /**
     * Active pricing rows joined to every service table so each row can
     * carry its service's display name. Shared by dueSoonData/recentlyAdded.
     */
    private static function pricingWithServiceNames()
    {
        return DB::table('pricings as p')
            ->leftJoin('servers as s', 'p.service_id', 's.id')
            ->leftJoin('shared_hosting as sh', 'p.service_id', 'sh.id')
            ->leftJoin('reseller_hosting as r', 'p.service_id', 'r.id')
            ->leftJoin('domains as d', 'p.service_id', 'd.id')
            ->leftJoin('misc_services as ms', 'p.service_id', 'ms.id')
            ->leftJoin('seedboxes as sb', 'p.service_id', 'sb.id')
            ->where('p.active', 1);
    }

    /** One name column per joined service table */
    private const SERVICE_NAME_COLUMNS = [
        'p.*', 's.hostname', 'd.domain', 'd.extension',
        'r.main_domain as reseller', 'sh.main_domain', 'ms.name', 'sb.title',
    ];

    /**
     * Raw DB rows arrive as strings under MySQL; the views compare
     * service_type strictly (=== 1), so normalize to int here.
     */
    private static function castServiceTypes($rows)
    {
        return $rows->map(function ($row) {
            $row->service_type = (int) $row->service_type;
            return $row;
        });
    }

    public static function dueSoonData()
    {
        return Cache::remember('due_soon', now()->addHours(6), function () {
            return self::castServiceTypes(self::pricingWithServiceNames()
                ->where('p.term', '!=', 7)
                // NULLs sort first on both drivers and would crowd real
                // renewals out of the limited window (and doDueSoon only
                // advances dates for rows inside this cached set).
                ->whereNotNull('p.next_due_date')
                ->where(function ($query) {
                    $query->whereIn('p.service_id', DB::table('servers')->where('active', 1)->select('id'))
                        ->orWhereIn('p.service_id', DB::table('shared_hosting')->where('active', 1)->select('id'))
                        ->orWhereIn('p.service_id', DB::table('reseller_hosting')->where('active', 1)->select('id'))
                        ->orWhereIn('p.service_id', DB::table('domains')->where('active', 1)->select('id'))
                        ->orWhereIn('p.service_id', DB::table('misc_services')->where('active', 1)->select('id'))
                        ->orWhereIn('p.service_id', DB::table('seedboxes')->where('active', 1)->select('id'));
                })
                ->orderBy('next_due_date', 'ASC')
                // Live settings, not the session snapshot: this runs inside
                // the cache closure, and a stale session re-priming it would
                // bake its old limit into the shared key
                ->limit(Settings::getSettings()->due_soon_amount ?? 6)
                ->get(self::SERVICE_NAME_COLUMNS));
        });
    }

    public static function serverSummary()
    {
        return Cache::remember('servers_summary', now()->addHours(6), function () {
            // One aggregate query instead of five full-table fetches summed in
            // PHP. location_id/provider_id are NOT NULL (default 9999), so
            // COUNT(DISTINCT ...) matches the old groupBy()->count() exactly;
            // int casts match Collection sums (MySQL returns SUM as string).
            $totals = DB::table('servers')->where('active', 1)->selectRaw(
                'COALESCE(SUM(cpu), 0) as cpu_sum, '
                . 'COALESCE(SUM(ram_as_mb), 0) as ram_mb_sum, '
                . 'COALESCE(SUM(bandwidth), 0) as bandwidth_sum, '
                . 'COUNT(DISTINCT location_id) as locations_sum, '
                . 'COUNT(DISTINCT provider_id) as providers_sum'
            )->first();
            $disk_gb = DB::table('server_disks')
                ->join('servers', 'server_disks.server_id', '=', 'servers.id')
                ->where('servers.active', 1)
                ->sum('server_disks.disk_as_gb');
            return array(
                'cpu_sum' => (int) $totals->cpu_sum,
                'ram_mb_sum' => (int) $totals->ram_mb_sum,
                'disk_gb_sum' => $disk_gb,
                'bandwidth_sum' => (int) $totals->bandwidth_sum,
                'locations_sum' => (int) $totals->locations_sum,
                'providers_sum' => (int) $totals->providers_sum,
            );
        });
    }

    public static function recentlyAdded()
    {
        return Cache::remember('recently_added', now()->addHours(6), function () {
            return self::castServiceTypes(self::pricingWithServiceNames()
                ->orderBy('created_at', 'DESC')
                ->limit(Settings::getSettings()->recently_added_amount ?? 6)
                ->get(self::SERVICE_NAME_COLUMNS));
        });
    }

    public static function doDueSoon($due_soon)
    {
        $pricing = new Pricing();
        $count = $altered_due_soon = 0;
        foreach ($due_soon as $service) {
            if (is_null($service->next_due_date)) {
                $count++;
                continue;
            }
            if (Carbon::createFromFormat('Y-m-d', $service->next_due_date)->isPast()) {
                $months = $pricing->termAsMonths($service->term);//Get months for term to update the next due date to
                if ($months === 0) {//one-time payment, don't auto-advance due date
                    $count++;
                    continue;
                }
                // NoOverflow: plain addMonths turns Jan 31 +1mo into Mar 3,
                // silently skipping a renewal cycle and compounding forever.
                $new_due_date = Carbon::createFromFormat('Y-m-d', $service->next_due_date)->addMonthsNoOverflow($months)->format('Y-m-d');
                DB::table('pricings')//Update the DB
                ->where('service_id', $service->service_id)
                    ->update(['next_due_date' => $new_due_date]);
                $due_soon[$count]->next_due_date = $new_due_date;//Update array being sent to view
                $altered_due_soon = 1;
                // The advanced date lives in the service's cached `price` relation
                // for every type, not just servers — clear the right caches.
                self::forgetServiceCacheByType((int) $service->service_type, $service->service_id);
            } else {
                break;//Break because if this date isnt past than the ones after it in the loop wont be either
            }
            $count++;
        }

        if ($altered_due_soon === 1) {//Made changes to due soon so re-write it
            Cache::put('due_soon', $due_soon, now()->addHours(6));//keep the original 6h TTL
        }

        return $due_soon;
    }

    /**
     * Forget the list + per-item caches for a service type whose pricing changed.
     * (No DB foreign keys / cascades; cache fan-out is manual.)
     */
    /**
     * Cache keys per service type: [list-key prefix, per-item key prefix].
     * Types 2-5 also have all_active_/non_active_ list variants; seedboxes
     * (6) only have the plain list. Servers (1) use their own helpers.
     */
    private const TYPE_CACHE_KEYS = [
        2 => ['shared', 'shared_hosting', true],
        3 => ['reseller', 'reseller_hosting', true],
        4 => ['domains', 'domain', true],
        5 => ['misc', 'misc', true],
        6 => ['seedboxes', 'seedbox', false],
    ];

    public static function forgetServiceCacheByType(int $type, string $service_id): void
    {
        if ($type === 1) {
            Server::serverSpecificCacheForget($service_id);
            Server::serverRelatedCacheForget();

            return;
        }

        if (!isset(self::TYPE_CACHE_KEYS[$type])) {
            // A new service type must be added here, or its cached price
            // data goes stale silently after doDueSoon advances dates.
            Log::warning("forgetServiceCacheByType: unknown service type $type for $service_id");

            return;
        }

        [$list, $item, $hasActiveVariants] = self::TYPE_CACHE_KEYS[$type];
        self::forgetListCaches($list, $hasActiveVariants);
        Cache::forget("$item.$service_id");
    }

    /** The list caches for one service type: its all_ list plus variants. */
    private static function forgetListCaches(string $list, bool $hasActiveVariants): void
    {
        Cache::forget("all_$list");
        if ($hasActiveVariants) {
            Cache::forget("all_active_$list");
            Cache::forget("non_active_$list");
        }
    }

    /**
     * Every service-type LIST cache (they all embed the session sort order,
     * so a settings change invalidates the lot).
     */
    public static function forgetAllServiceListCaches(): void
    {
        Cache::forget('all_servers');
        Cache::forget('all_active_servers');
        Cache::forget('non_active_servers');
        Cache::forget('public_server_data');
        foreach (self::TYPE_CACHE_KEYS as [$list, , $hasActiveVariants]) {
            self::forgetListCaches($list, $hasActiveVariants);
        }
    }

    public static function breakdownPricing($all_pricing): array
    {
        return Cache::remember('pricing_breakdown', now()->addWeek(1), function () use ($all_pricing) {
            $total_cost_pm = 0;
            // Summed from usdPerYear(), not $total_cost_pm * 12: usd_per_month
            // is rounded to cents, so scaling it up multiplied each row's
            // rounding error by 12 (a 44.46/yr service contributed 44.52).
            $total_cost_py = 0;
            $currency = Settings::getSettings()->dashboard_currency ?? 'USD';
            // Look the rate up once, not once per row (each lookup is a cache
            // read). Same per-row multiply, so the float math is unchanged.
            $rate = $currency !== 'USD' ? Pricing::convertFromUSD('1', $currency) : 1.0;

            foreach ($all_pricing as $price) {
                $total_cost_pm += $price->usd_per_month * $rate;
                $total_cost_py += $price->usdPerYear() * $rate;
            }

            return [
                'total_cost_weekly' => $total_cost_pm / 4,
                'total_cost_monthly' => $total_cost_pm,
                'total_cost_yearly' => $total_cost_py,
                'inactive_count' => 0,
            ];
        });
    }

    public static function doServicesCount($services_count): array
    {
        $services_count['total'] = array_sum($services_count);
        return $services_count;
    }


}
