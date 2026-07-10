<?php

namespace App\Models\Concerns;

use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global 'order' scope from the sort_on setting, shared by the
 * service-type models. Domains deliberately does NOT use this trait — it
 * has no column-order scope and only sorts via pricing (SortsByPricing).
 *
 * Reads the LIVE (cached) settings row, not the per-session snapshot:
 * these orderings run inside the month-TTL list-cache closures, and a
 * stale session re-priming a cleared cache would poison the shared order
 * for every session until the next write.
 */
trait OrdersBySessionSetting
{
    protected static function bootOrdersBySessionSetting(): void
    {
        static::addGlobalScope('order', function (Builder $builder) {
            $sort_on = Settings::getSettings()->sort_on ?? 2;//created_at desc if not set
            if (!in_array($sort_on, [3, 4, 5, 6], true)) {
                $array = Settings::orderByProcess($sort_on);
                $builder->orderBy($array[0], $array[1]);
            }
        });
    }
}
