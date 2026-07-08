<?php

namespace App\Models\Concerns;

use App\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Session;

/**
 * Global 'order' scope from the session's sort_on setting, shared by the
 * service-type models. Domains deliberately does NOT use this trait — it
 * has no column-order scope and only sorts via pricing (SortsByPricing).
 */
trait OrdersBySessionSetting
{
    protected static function bootOrdersBySessionSetting(): void
    {
        static::addGlobalScope('order', function (Builder $builder) {
            $array = Settings::orderByProcess(Session::get('sort_on') ?? 2);//created_at desc if not set
            if (!in_array(Session::get('sort_on'), [3, 4, 5, 6], true)) {
                $builder->orderBy($array[0], $array[1]);
            }
        });
    }
}
