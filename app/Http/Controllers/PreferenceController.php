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

    public function update(Request $request, string $key)
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return response()->json(['result' => 'fail', 'error' => 'Unknown preference key'], 422);
        }

        // Raw body, NOT $request->json(): the global TrimStrings and
        // ConvertEmptyStringsToNull middleware rewrite the "" search terms
        // inside a DataTables state to null, and restoring a null search
        // crashes the table init on the next page load.
        $raw = $request->getContent();
        $value = json_decode($raw, true);
        if (!is_array($value) || $value === [] || strlen($raw) > self::MAX_BYTES) {
            return response()->json(['result' => 'fail', 'error' => 'Invalid preference payload'], 422);
        }

        UserPreference::put($request->user()->id, $key, $value);

        return response()->json(['result' => 'success']);
    }
}
