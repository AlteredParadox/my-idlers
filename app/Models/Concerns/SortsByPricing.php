<?php

namespace App\Models\Concerns;

use App\Models\Pricing;
use App\Models\Settings;

/**
 * Price-based sort options (sort_on 3-6) order by a pricings subquery
 * instead of the model's own columns; every service-type list query
 * applies this the same way. Live settings read, not the session
 * snapshot — see OrdersBySessionSetting.
 */
trait SortsByPricing
{
    protected static function applyPricingSort($query): void
    {
        $sort_on = Settings::getSettings()->sort_on;
        if (in_array($sort_on, [3, 4, 5, 6], true)) {
            $options = Settings::orderByProcess($sort_on);
            $table = $query->getModel()->getTable();
            $query->orderBy(Pricing::select("pricings.$options[0]")->whereColumn('pricings.service_id', "$table.id"), $options[1]);
        }
    }
}
