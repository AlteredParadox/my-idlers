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

    protected $fillable = ['id', 'show_versions_footer', 'show_servers_public', 'show_server_value_ip', 'show_server_value_hostname', 'show_server_value_provider', 'show_server_value_location', 'show_server_value_price', 'show_server_value_yabs', 'save_yabs_as_txt', 'default_currency', 'default_server_os', 'due_soon_amount', 'recently_added_amount', 'dark_mode', 'dashboard_currency', 'sort_on', 'favicon', 'servers_index_cards', 'default_per_page', 'prometheus_enabled', 'prometheus_url', 'prometheus_check_interval'];

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
        'save_yabs_as_txt' => 'integer',
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
                $settings = Settings::create();
            }
            return $settings;
        });
    }

    public static function setSettingsToSession($settings): void
    {
        Session::put('dark_mode', $settings->dark_mode ?? 0);
        Session::put('timer_version_footer', $settings->show_versions_footer ?? 1);
        Session::put('show_servers_public', $settings->show_servers_public ?? 0);
        Session::put('show_server_value_ip', $settings->show_server_value_ip ?? 0);
        Session::put('show_server_value_hostname', $settings->show_server_value_hostname ?? 0);
        Session::put('show_server_value_price', $settings->show_server_value_price ?? 0);
        Session::put('show_server_value_yabs', $settings->show_server_value_yabs ?? 0);
        Session::put('show_server_value_provider', $settings->show_server_value_provider ?? 0);
        Session::put('show_server_value_location', $settings->show_server_value_location ?? 0);
        Session::put('save_yabs_as_txt', $settings->save_yabs_as_txt ?? 0);
        Session::put('default_currency', $settings->default_currency ?? 'USD');
        Session::put('default_server_os', $settings->default_server_os ?? 1);
        Session::put('due_soon_amount', $settings->due_soon_amount ?? 6);
        Session::put('recently_added_amount', $settings->recently_added_amount ?? 6);
        Session::put('dashboard_currency', $settings->dashboard_currency ?? 'USD');
        Session::put('sort_on', $settings->sort_on ?? 1);
        Session::put('favicon', $settings->favicon ?? 'favicon.ico');
        Session::put('servers_index_cards', $settings->servers_index_cards ?? 0);
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
