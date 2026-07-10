<?php

namespace App\Http\Controllers;

use App\Models\Home;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\Note;
use App\Models\Pricing;
use App\Models\SeedBoxes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedBoxesController extends Controller
{
    use \App\Http\Controllers\Concerns\HandlesServiceUpdates;

    public function index()
    {
        $seedboxes = SeedBoxes::allSeedboxes();
        return view('seedboxes.index', compact(['seedboxes']));
    }

    public function create()
    {
        return view('seedboxes.create');
    }

    /** Identical for store and update. */
    private function rules(): array
    {
        return [
            'title' => 'required|string|min:2|max:255',
            'hostname' => 'sometimes|nullable|string|min:2|max:255',
            'seed_box_type' => 'required|string|max:255',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            ...\App\Models\Pricing::webValidationRules(),
            'was_promo' => 'integer|in:0,1',
            'owned_since' => 'sometimes|nullable|date_format:Y-m-d',
            'disk' => 'integer|min:0|max:1000000',
            'bandwidth' => 'integer|min:0|max:100000000',
            'port_speed' => 'integer|min:0|max:1000000',
            ...\App\Models\Labels::validationRules(),
        ];
    }

    public function store(Request $request)
    {
        $request->validate($this->rules());

        $seedbox_id = Str::random(8);

        // Atomic: a failed seedbox insert must not orphan the pricing row.
        DB::transaction(function () use ($request, $seedbox_id) {
            (new Pricing())->insertPricing(6, $seedbox_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $seedbox_id);

            SeedBoxes::create([
            'id' => $seedbox_id,
            'title' => $request->title,
            'hostname' => $request->hostname,
            'seed_box_type' => $request->seed_box_type,
            'provider_id' => $request->provider_id,
            'location_id' => $request->location_id,
            'disk' => $request->disk,
            'disk_type' => 'GB',
            'disk_as_gb' => $request->disk,
            'owned_since' => $request->owned_since,
            'bandwidth' => $request->bandwidth,
            'port_speed' => $request->port_speed,
                'was_promo' => $request->was_promo,
                'transferrable' => (isset($request->transferrable)) ? 1 : 0
            ]);
        });

        Home::forgetServiceCacheByType(6, $seedbox_id);
        Home::homePageCacheForget();

        return redirect()->route('seedboxes.index')
            ->with('success', 'Seed box created Successfully.');

    }

    public function show(SeedBoxes $seedbox)
    {
        $seedbox_data = SeedBoxes::seedbox($seedbox->id);
        return view('seedboxes.show', compact(['seedbox_data']));
    }

    public function edit(SeedBoxes $seedbox)
    {
        $seedbox_data = SeedBoxes::seedbox($seedbox->id);
        return view('seedboxes.edit', compact(['seedbox_data']));
    }

    public function update(Request $request, SeedBoxes $seedbox)
    {
        $request->validate($this->rules());

        $is_active = (isset($request->is_active)) ? 1 : 0;

        // Atomic: a failure in the pricing/labels writes must not leave a
        // partially updated service.
        $updated = DB::transaction(function () use ($request, $seedbox, $is_active) {
            if (!$this->lockedRowStillExists($seedbox)) {
                return false;
            }
            $seedbox->update([
                'title' => $request->title,
                'hostname' => $request->hostname,
                'seed_box_type' => $request->seed_box_type,
                'location_id' => $request->location_id,
                'provider_id' => $request->provider_id,
                'disk' => $request->disk,
                'disk_type' => 'GB',
                'disk_as_gb' => $request->disk,
                'owned_since' => $request->owned_since,
                'bandwidth' => $request->bandwidth,
                'port_speed' => $request->port_speed,
                'was_promo' => $request->was_promo,
                'transferrable' => (isset($request->transferrable)) ? 1 : 0,
                'active' => $is_active
            ]);

            $this->syncPricingAndLabels($request, $seedbox->id, $is_active);

            return true;
        });

        if (!$updated) {
            return redirect()->route('seedboxes.index')
                ->with('error', 'Seed box no longer exists.');
        }

        Home::forgetServiceCacheByType(6, $seedbox->id);
        Home::homePageCacheForget();

        return redirect()->route('seedboxes.index')
            ->with('success', 'Seed box updated Successfully.');
    }

    public function destroy(SeedBoxes $seedbox)
    {
        // Atomic: child rows have no DB cascades — a failure mid-cleanup
        // must not orphan them behind an already-deleted service.
        $deleted = DB::transaction(function () use ($seedbox) {
            if (!$seedbox->delete()) {
                return false;
            }
            (new Pricing())->deletePricing($seedbox->id);
            Labels::deleteLabelsAssignedTo($seedbox->id);
            // IPs can be assigned to seedboxes (ips.create lists them) —
            // every other IP-capable type deletes them on destroy.
            IPs::deleteIPsAssignedTo($seedbox->id);
            // Legacy/forged notes keyed to this id would linger as ghost rows
            Note::deleteForService($seedbox->id);
            return true;
        });

        if ($deleted) {
            Home::forgetServiceCacheByType(6, $seedbox->id);
            Home::homePageCacheForget();

            return redirect()->route('seedboxes.index')
                ->with('success', 'Seed box was deleted Successfully.');
        }

        return redirect()->route('seedboxes.index')
            ->with('error', 'Seed box was not deleted.');
    }
}
