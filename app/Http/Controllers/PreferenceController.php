<?php

namespace App\Http\Controllers;

use App\Models\UserPreference;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    /** Preference keys are namespaced: dt.<table id> or ui.<page> */
    protected const KEY_PATTERN = '/^(dt|ui)\.[a-z0-9-]{1,48}$/';

    /**
     * Serialized size cap — a DataTables state blob for the widest table
     * is ~2KB; anything bigger than this is not a UI preference.
     */
    protected const MAX_BYTES = 16384;

    /**
     * Row quota per user — the app has ~20 tables plus a few ui.* keys;
     * without a cap the allowlist still admits unlimited distinct keys,
     * and every stored row is embedded into every index page render.
     */
    protected const MAX_KEYS = 50;

    public function update(Request $request, string $key)
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return response()->json(['result' => 'fail', 'error' => 'Unknown preference key'], 422);
        }

        // Size gate BEFORE decoding: don't burn CPU/memory json_decoding a
        // multi-megabyte body just to reject it.
        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BYTES) {
            return response()->json(['result' => 'fail', 'error' => 'Invalid preference payload'], 422);
        }

        // Raw body, NOT $request->json(): the global TrimStrings and
        // ConvertEmptyStringsToNull middleware rewrite the "" search terms
        // inside a DataTables state to null, and restoring a null search
        // crashes the table init on the next page load.
        $value = json_decode($raw, true);
        if (!is_array($value) || $value === []) {
            return response()->json(['result' => 'fail', 'error' => 'Invalid preference payload'], 422);
        }

        $user_id = $request->user()->id;

        if (UserPreference::where('user_id', $user_id)->where('key', '!=', $key)->count() >= self::MAX_KEYS) {
            return response()->json(['result' => 'fail', 'error' => 'Preference limit reached'], 422);
        }

        UserPreference::put($user_id, $key, $value);

        return response()->json(['result' => 'success']);
    }
}
