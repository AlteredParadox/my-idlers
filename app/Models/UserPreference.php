<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-user UI preferences (DataTables state, view toggles) as JSON blobs
 * keyed like `dt.servers-table` / `ui.servers`. Stored in the database so
 * they survive browsers, devices and container redeploys — nothing lands
 * in localStorage or the file session store.
 */
class UserPreference extends Model
{
    protected $fillable = ['user_id', 'key', 'value'];

    /** Decoded key => value map for one user */
    public static function valuesFor(int $user_id): array
    {
        return self::where('user_id', $user_id)
            ->pluck('value', 'key')
            ->map(fn($json) => json_decode($json, true))
            ->all();
    }

    public static function put(int $user_id, string $key, array $value): void
    {
        self::updateOrCreate(
            ['user_id' => $user_id, 'key' => $key],
            ['value' => json_encode($value)]
        );
    }
}
