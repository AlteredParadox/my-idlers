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
        // Raw body, NOT $request->json(): the global TrimStrings and
        // ConvertEmptyStringsToNull middleware rewrite the "" search terms
        // inside a DataTables state to null, and restoring a null search
        // crashes the table init on the next page load.
        $raw = $request->getContent();

        $error = $this->rejectReason($request, $key, $raw);
        if ($error !== null) {
            return response()->json(['result' => 'fail', 'error' => $error], 422);
        }

        UserPreference::put($request->user()->id, $key, json_decode($raw, true));

        return response()->json(['result' => 'success']);
    }

    private function rejectReason(Request $request, string $key, string $raw): ?string
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return 'Unknown preference key';
        }

        // Size gate BEFORE decoding: don't burn CPU/memory json_decoding a
        // multi-megabyte body just to reject it.
        $value = strlen($raw) > self::MAX_BYTES ? null : json_decode($raw, true);
        if (!is_array($value) || $value === []) {
            return 'Invalid preference payload';
        }

        $overQuota = UserPreference::where('user_id', $request->user()->id)
                ->where('key', '!=', $key)->count() >= self::MAX_KEYS;

        return $overQuota ? 'Preference limit reached' : null;
    }
}
