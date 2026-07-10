<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Session;

class Settings extends Model
{
    use HasFactory;

    protected $table = 'settings';

    // The PK is the fixed row id 1, NOT auto-increment: without this,
    // Eloquent overwrites a supplied id with MySQL's lastInsertId (0) on
    // create(), so ->fresh()/route-key reads chase a nonexistent row.
    public $incrementing = false;

    protected $fillable = ['id', 'show_versions_footer', 'show_servers_public', 'show_server_value_ip', 'show_server_value_hostname', 'show_server_value_provider', 'show_server_value_location', 'show_server_value_price', 'show_server_value_yabs', 'default_currency', 'default_server_os', 'due_soon_amount', 'recently_added_amount', 'dark_mode', 'dashboard_currency', 'sort_on', 'favicon', 'servers_index_cards', 'default_per_page', 'prometheus_enabled', 'prometheus_url', 'prometheus_check_interval'];

    // MySQL returns tinyint/int columns as strings, which broke strict `=== 1`
    // checks in the views and the public-page gate (they silently failed
    // closed). Cast at the source so every consumer gets a real int under
    // both MySQL and SQLite.
    protected $casts = [
        'show_versions_footer' => 'integer',
        'show_servers_public' => 'integer',
        'show_server_value_ip' => 'integer',
        'show_server_value_hostname' => 'integer',
        'show_server_value_provider' => 'integer',
        'show_server_value_location' => 'integer',
        'show_server_value_price' => 'integer',
        'show_server_value_yabs' => 'integer',
        'dark_mode' => 'integer',
        'servers_index_cards' => 'integer',
        'prometheus_enabled' => 'integer',
        'default_server_os' => 'integer',
        'due_soon_amount' => 'integer',
        'recently_added_amount' => 'integer',
        'sort_on' => 'integer',
        'default_per_page' => 'integer',
        'prometheus_check_interval' => 'integer',
    ];

    public static function getSettings(): Settings
    {
        return Cache::remember('settings', now()->addWeek(1), function () {
            $settings = self::where('id', 1)->first();
            if (is_null($settings)){
                // fresh(): the model create() returns carries only the
                // attributes PHP supplied — none of the column defaults —
                // and it gets cached for a week; every ->attribute read on a
                // fresh install would be null otherwise. The id must be
                // explicit: settings.id is NOT auto-increment, so MySQL's
                // lastInsertId is 0 and an id-less create()->fresh() would
                // re-query id=0 → null (SQLite's rowid alias masks this).
                $settings = Settings::create(['id' => 1])->fresh();
            }
            return $settings;
        });
    }

    public static function setSettingsToSession($settings): void
    {
        Session::put('dark_mode', $settings->dark_mode ?? 0);
        Session::put('timer_version_footer', $settings->show_versions_footer ?? 1);
        // show_servers_public / show_server_value_* / servers_index_cards /
        // favicon are deliberately NOT snapshotted here: nothing reads the
        // session copies — their consumers use the live Settings row
        // (session snapshots are written once per visitor and never
        // re-synced; a stale favicon snapshot 404'd the icon in every OTHER
        // session after an extension change deleted the old file).
        Session::put('default_currency', $settings->default_currency ?? 'USD');
        Session::put('default_server_os', $settings->default_server_os ?? 1);
        Session::put('due_soon_amount', $settings->due_soon_amount ?? 6);
        Session::put('recently_added_amount', $settings->recently_added_amount ?? 6);
        Session::put('dashboard_currency', $settings->dashboard_currency ?? 'USD');
        Session::put('sort_on', $settings->sort_on ?? 1);
        Session::put('default_per_page', $settings->default_per_page ?? 100);
        Session::put('prometheus_enabled', $settings->prometheus_enabled ?? 0);
        Session::put('prometheus_url', $settings->prometheus_url ?? '');
        Session::put('prometheus_check_interval', $settings->prometheus_check_interval ?? 20);
        Session::save();
    }

    public static function orderByProcess(int $value): array
    {
        return match ($value) {
            1 => ['created_at', 'asc'],
            3 => ['next_due_date', 'asc'],
            4 => ['next_due_date', 'desc'],
            5 => ['as_usd', 'asc'],
            6 => ['as_usd', 'desc'],
            7 => ['owned_since', 'asc'],
            8 => ['owned_since', 'desc'],
            9 => ['updated_at', 'asc'],
            10 => ['updated_at', 'desc'],
            default => ['created_at', 'desc'],
        };
    }

}
