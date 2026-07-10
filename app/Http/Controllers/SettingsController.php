<?php

namespace App\Http\Controllers;

use App\Models\Home;
use App\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index', ['setting' => Settings::where('id', 1)->first()]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'dark_mode' => 'required|integer|min:0|max:2',
            'show_versions_footer' => 'required|integer|min:0|max:1',
            'show_server_value_ip' => 'required|integer|min:0|max:1',
            'show_server_value_hostname' => 'required|integer|min:0|max:1',
            'show_server_value_provider' => 'required|integer|min:0|max:1',
            'show_server_value_location' => 'required|integer|min:0|max:1',
            'show_server_value_price' => 'required|integer|min:0|max:1',
            'show_server_value_yabs' => 'required|integer|min:0|max:1',
            'show_servers_public' => 'required|integer|min:0|max:1',
            'default_currency' => 'required|string|size:3|' . \App\Models\Pricing::currencyRule(),
            // exists: a forged id would persist a dangling default that the
            // create-server form then silently fails to preselect
            'default_server_os' => 'required|integer|exists:os,id',
            'due_soon_amount' => 'required|integer|between:0,12',
            'recently_added_amount' => 'required|integer|between:0,12',
            'dashboard_currency' => 'required|string|size:3|' . \App\Models\Pricing::currencyRule(),
            'sort_on' => 'required|integer|between:1,10',
            'favicon' => 'sometimes|nullable|mimes:ico,jpg,png|max:40',
            'servers_index_cards' => 'required|integer|min:0|max:1',
            'default_per_page' => 'required|integer|in:10,25,50,100,250,500',
            'prometheus_enabled' => 'required|integer|min:0|max:1',
            'prometheus_url' => 'nullable|url|max:255',
            'prometheus_check_interval' => 'required|integer|between:5,300',
        ]);

        $settings = Settings::where('id', 1)->first();
        $old_favicon = $settings->favicon;

        $favicon_filename = null;
        if ($request->favicon) {//Has a favicon upload
            $stored = $this->storeFavicon($request->favicon);
            if ($stored instanceof \Illuminate\Http\RedirectResponse) {
                return $stored;
            }
            $favicon_filename = $stored;
        }

        $do_update = $settings->update([
            'dark_mode' => $request->dark_mode,
            'show_versions_footer' => $request->show_versions_footer,
            'show_servers_public' => $request->show_servers_public,
            'show_server_value_ip' => $request->show_server_value_ip,
            'show_server_value_hostname' => $request->show_server_value_hostname,
            'show_server_value_provider' => $request->show_server_value_provider,
            'show_server_value_location' => $request->show_server_value_location,
            'show_server_value_price' => $request->show_server_value_price,
            'show_server_value_yabs' => $request->show_server_value_yabs,
            'default_currency' => $request->default_currency,
            'default_server_os' => $request->default_server_os,
            'due_soon_amount' => $request->due_soon_amount,
            'recently_added_amount' => $request->recently_added_amount,
            'dashboard_currency' => $request->dashboard_currency,
            'sort_on' => $request->sort_on,
            'favicon' => $favicon_filename ?? $settings->favicon,
            'servers_index_cards' => $request->servers_index_cards,
            'default_per_page' => $request->default_per_page,
            'prometheus_enabled' => $request->prometheus_enabled,
            'prometheus_url' => $request->prometheus_url,
            'prometheus_check_interval' => $request->prometheus_check_interval ?? 20
        ]);

        Cache::forget('due_soon');//Main page due_soon cache
        Cache::forget('recently_added');//Main page recently_added cache
        Cache::forget('pricing_breakdown');//Main page pricing breakdown

        Cache::forget('settings');//Main page settings cache
        //Clear because they are affected by settings change (sort_on)
        Home::forgetAllServiceListCaches();

        Settings::setSettingsToSession(Settings::getSettings());

        // FS/DB boundary: the old favicon file outlives the row update so a
        // failed update never leaves settings referencing a deleted file;
        // conversely a new file the row never came to reference is removed.
        // The shipped favicon.ico is never deleted — it is the fallback.
        if ($favicon_filename !== null && $favicon_filename !== $old_favicon) {
            $stale = $do_update ? $old_favicon : $favicon_filename;
            if ($stale !== 'favicon.ico') {
                Storage::disk('public_uploads')->delete($stale);
            }
        }

        return redirect()->route('settings.index')
            ->with($do_update ? 'success' : 'error',
                $do_update ? 'Settings Updated Successfully.' : 'Settings failed to update.');
    }

    /**
     * Validate and atomically store an uploaded favicon. Returns the stored
     * filename, or an error redirect for the caller to return as-is. The
     * previous favicon is NOT touched here — the caller deletes it only
     * after the settings row actually references the new one.
     */
    private function storeFavicon(\Illuminate\Http\UploadedFile $file): string|\Illuminate\Http\RedirectResponse
    {
        // Content-derived extension, never the client filename: the file
        // lands in the webroot, so a PNG/PHP polyglot named x.php would
        // otherwise be stored as favicon.php and be executable.
        $extension = strtolower($file->guessExtension() ?? '');
        if (!in_array($extension, ['ico', 'png', 'jpg', 'jpeg'], true)) {
            return redirect()->route('settings.index')
                ->with('error', 'Favicon must be an ico, png or jpg file.');
        }
        $favicon_filename = "favicon.$extension";

        // Atomic replace: write to a temp name, verify, then rename over
        // the target. rename(2) needs only DIRECTORY write — it replaces
        // even the root-owned shipped favicon.ico (the hardened container
        // keeps shipped files root-owned) — and a failed or partial write
        // leaves the existing favicon untouched instead of destroying it.
        // Blindly repointing settings.favicon at a file that was never
        // written 404s the favicon site-wide behind a success flash.
        $tmp_name = "$favicon_filename.tmp";
        if ($file->storeAs("", $tmp_name, "public_uploads") === false
            || Storage::disk('public_uploads')->move($tmp_name, $favicon_filename) === false) {
            Storage::disk('public_uploads')->delete($tmp_name);

            return redirect()->route('settings.index')
                ->with('error', 'Favicon could not be saved — the web server cannot write to the public directory.');
        }

        return $favicon_filename;
    }
}
