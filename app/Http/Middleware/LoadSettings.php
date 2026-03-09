<?php

namespace App\Http\Middleware;

use App\Models\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class LoadSettings
{
    public function handle(Request $request, Closure $next)
    {
        if (!Session::has('dark_mode')) {
            Settings::setSettingsToSession(Settings::getSettings());
        }

        return $next($request);
    }
}
