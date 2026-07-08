<?php

namespace App\Models\Concerns;

use App\Models\Pricing;
use App\Models\Settings;
use Illuminate\Support\Facades\Session;

/**
 * Price-based sort options (sort_on 3-6) order by a pricings subquery
 * instead of the model's own columns; every service-type list query
 * applies this the same way.
 */
trait SortsByPricing
{
    protected static function applyPricingSort($query): void
    {
        if (in_array(Session::get('sort_on'), [3, 4, 5, 6], true)) {
            $options = Settings::orderByProcess(Session::get('sort_on'));
            $table = $query->getModel()->getTable();
            $query->orderBy(Pricing::select("pricings.$options[0]")->whereColumn('pricings.service_id', "$table.id"), $options[1]);
        }
    }
}
