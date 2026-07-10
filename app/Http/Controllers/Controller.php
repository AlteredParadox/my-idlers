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

    /**
     * Run an insert whose unique rule was already validated, surfacing a
     * lost duplicate race as the standard validation error instead of a
     * raw QueryException 500: the unique:... rule runs before the insert,
     * so two concurrent same-value submits can both pass it and the loser
     * lands on the unique index.
     */
    protected function createUniquely(callable $create, string $field): mixed
    {
        try {
            return $create();
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                // Match the validator's own message byte-for-byte
                // (it displays attributes with underscores as spaces).
                $field => trans('validation.unique', ['attribute' => str_replace('_', ' ', $field)]),
            ]);
        }
    }
}
