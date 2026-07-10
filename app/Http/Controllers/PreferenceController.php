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

        $value = $request->json()->all();
        if ($value === [] || strlen(json_encode($value)) > self::MAX_BYTES) {
            return response()->json(['result' => 'fail', 'error' => 'Invalid preference payload'], 422);
        }

        UserPreference::put($request->user()->id, $key, $value);

        return response()->json(['result' => 'success']);
    }
}
