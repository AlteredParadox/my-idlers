<?php

namespace App\Http\Controllers;

use App\Models\Home;
use App\Models\Misc;
use App\Models\Note;
use App\Models\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MiscController extends Controller
{
    public function index()
    {
        $misc = Misc::allActiveMisc();
        $non_active_misc = Misc::allNonActiveMisc();
        return view('misc.index', compact(['misc', 'non_active_misc']));
    }

    public function create()
    {
        return view('misc.create');
    }

    public function show(Misc $misc)
    {
        $misc_data = Misc::misc($misc->id);
        return view('misc.show', compact(['misc_data']));
    }

    /** Identical for store and update. */
    private function rules(): array
    {
        return [
            'name' => 'required|string|min:3|max:255',
            ...\App\Models\Pricing::webValidationRules(),
            'owned_since' => 'sometimes|nullable|date',
        ];
    }

    public function store(Request $request)
    {
        $request->validate($this->rules());

        $misc_id = Str::random(8);

        // Atomic: pricing must insert first (FK), so a failed service insert
        // would otherwise orphan an active pricing row.
        DB::transaction(function () use ($request, $misc_id) {
            (new Pricing())->insertPricing(5, $misc_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            Misc::create([
                'id' => $misc_id,
                'name' => $request->name,
                'owned_since' => $request->owned_since
            ]);
        });

        Home::forgetServiceCacheByType(5, $misc_id);
        Home::homePageCacheForget();

        return redirect()->route('misc.index')
            ->with('success', 'Misc service created Successfully.');
    }

    public function edit(Misc $misc)
    {
        $misc_data = Misc::misc($misc->id);
        return view('misc.edit', compact('misc_data'));
    }

    public function update(Request $request, Misc $misc)
    {
        $request->validate($this->rules());

        $is_active = (isset($request->is_active)) ? 1 : 0;

        // Atomic: a failure in the pricing write must not leave a partially
        // updated service.
        DB::transaction(function () use ($request, $misc, $is_active) {
            $misc->update([
                'name' => $request->name,
                'owned_since' => $request->owned_since,
                'active' => $is_active
            ]);

            (new Pricing())->updatePricing($misc->id, $request->currency, $request->price, $request->payment_term, $request->next_due_date, $is_active);
        });

        Home::forgetServiceCacheByType(5, $misc->id);
        Home::homePageCacheForget();

        return redirect()->route('misc.index')
            ->with('success', 'Misc service updated Successfully.');
    }

    public function destroy(Misc $misc)
    {
        // Atomic: child rows have no DB cascades — a failure mid-cleanup
        // must not orphan them behind an already-deleted service.
        $deleted = DB::transaction(function () use ($misc) {
            if (!$misc->delete()) {
                return false;
            }
            (new Pricing())->deletePricing($misc->id);
            // Legacy/forged notes keyed to this id would linger as ghost rows
            Note::deleteForService($misc->id);
            return true;
        });

        if ($deleted) {
            Home::forgetServiceCacheByType(5, $misc->id);
            Home::homePageCacheForget();

            return redirect()->route('misc.index')
                ->with('success', 'Misc service was deleted Successfully.');
        }

        return redirect()->route('misc.index')
            ->with('error', 'Misc service was not deleted.');
    }
}
