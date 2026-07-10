<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Locked existence re-check at the top of an update transaction: a
     * destroy committing between route binding and the transaction would
     * otherwise let the label/IP/pricing re-inserts recreate child rows
     * for a deleted service (no FK constraint stops them), and derived
     * columns computed from a pre-transaction snapshot could interleave
     * with a concurrent write.
     */
    protected function lockedRowStillExists(\Illuminate\Database\Eloquent\Model $model): bool
    {
        return !is_null($model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first());
    }
}
