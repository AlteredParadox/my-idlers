<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class Home extends Model
{
    use HasFactory;

    public static function homePageCacheForget(): void
    {
        Cache::forget('services_count');//Main page services_count cache
        Cache::forget('due_soon');//Main page due_soon cache
        Cache::forget('recently_added');//Main page recently_added cache
        Cache::forget('all_pricing');//All the pricing
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

    public static function dueSoonData()
    {
        return Cache::remember('due_soon', now()->addHours(6), function () {
            return DB::table('pricings as p')
                ->leftJoin('servers as s', 'p.service_id', 's.id')
                ->leftJoin('shared_hosting as sh', 'p.service_id', 'sh.id')
                ->leftJoin('reseller_hosting as r', 'p.service_id', 'r.id')
                ->leftJoin('domains as d', 'p.service_id', 'd.id')
                ->leftJoin('misc_services as ms', 'p.service_id', 'ms.id')
                ->leftJoin('seedboxes as sb', 'p.service_id', 'sb.id')
                ->where('p.active', 1)
                ->orderBy('next_due_date', 'ASC')
                ->limit(Session::get('due_soon_amount'))
                ->get(['p.*', 's.hostname', 'd.domain', 'd.extension', 'r.main_domain as reseller', 'sh.main_domain', 'ms.name', 'sb.title']);
        });
    }

    public static function serverSummary()
    {
        return Cache::remember('servers_summary', now()->addHours(6), function () {
            $cpu_sum = DB::table('servers')->get()->where('active', 1)->sum('cpu');
            $ram_mb = DB::table('servers')->get()->where('active', 1)->sum('ram_as_mb');
            $disk_gb = DB::table('server_disks')
                ->join('servers', 'server_disks.server_id', '=', 'servers.id')
                ->where('servers.active', 1)
                ->sum('server_disks.disk_as_gb');
            $bandwidth = DB::table('servers')->get()->where('active', 1)->sum('bandwidth');
            $locations_sum = DB::table('servers')->get()->where('active', 1)->groupBy('location_id')->count();
            $providers_sum = DB::table('servers')->get()->where('active', 1)->groupBy('provider_id')->count();
            return array(
                'cpu_sum' => $cpu_sum,
                'ram_mb_sum' => $ram_mb,
                'disk_gb_sum' => $disk_gb,
                'bandwidth_sum' => $bandwidth,
                'locations_sum' => $locations_sum,
                'providers_sum' => $providers_sum,
            );
        });
    }

    public static function recentlyAdded()
    {
        return Cache::remember('recently_added', now()->addHours(6), function () {
            return DB::table('pricings as p')
                ->leftJoin('servers as s', 'p.service_id', 's.id')
                ->leftJoin('shared_hosting as sh', 'p.service_id', 'sh.id')
                ->leftJoin('reseller_hosting as r', 'p.service_id', 'r.id')
                ->leftJoin('domains as d', 'p.service_id', 'd.id')
                ->leftJoin('misc_services as ms', 'p.service_id', 'ms.id')
                ->leftJoin('seedboxes as sb', 'p.service_id', 'sb.id')
                ->where('p.active', 1)
                ->orderBy('created_at', 'DESC')
                ->limit(Session::get('recently_added_amount'))
                ->get(['p.*', 's.hostname', 'd.domain', 'd.extension', 'r.main_domain as reseller', 'sh.main_domain', 'ms.name', 'sb.title']);
        });
    }

    public static function doDueSoon($due_soon)
    {
        $pricing = new Pricing();
        $count = $altered_due_soon = 0;
        $server_due_date_changed = false;
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
                $new_due_date = Carbon::createFromFormat('Y-m-d', $service->next_due_date)->addMonths($months)->format('Y-m-d');
                DB::table('pricings')//Update the DB
                ->where('service_id', $service->service_id)
                    ->update(['next_due_date' => $new_due_date]);
                $due_soon[$count]->next_due_date = $new_due_date;//Update array being sent to view
                $altered_due_soon = 1;
                if ($service->service_type === 1) {
                    $server_due_date_changed = true;
                    Server::serverSpecificCacheForget($service->service_id);
                }
            } else {
                break;//Break because if this date isnt past than the ones after it in the loop wont be either
            }
            $count++;
        }

        if ($server_due_date_changed) {
            Server::serverRelatedCacheForget();
        }

        if ($altered_due_soon === 1) {//Made changes to due soon so re-write it
            Cache::put('due_soon', $due_soon);
        }

        return $due_soon;
    }

    public static function breakdownPricing($all_pricing): array
    {
        return Cache::remember('pricing_breakdown', now()->addWeek(1), function () use ($all_pricing) {
            $total_cost_pm = 0;
            $currency = Session::get('dashboard_currency', 'USD');

            foreach ($all_pricing as $price) {
                if ($currency !== 'USD') {
                    $total_cost_pm += Pricing::convertFromUSD($price->usd_per_month, $currency);
                } else {
                    $total_cost_pm += $price->usd_per_month;
                }
            }

            return [
                'total_cost_weekly' => $total_cost_pm / 4,
                'total_cost_monthly' => $total_cost_pm,
                'total_cost_yearly' => $total_cost_pm * 12,
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
