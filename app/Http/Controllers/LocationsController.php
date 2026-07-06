<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;


class LocationsController extends Controller
{
    public function index()
    {
        $locations = Locations::allLocations();
        return view('locations.index', compact(['locations']));
    }

    public function create()
    {
        return view('locations.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'location_name' => 'required|string|min:2|max:255'
        ]);

        Locations::create([
            'name' => $request->location_name
        ]);

        Cache::forget('locations');

        return redirect()->route('locations.index')
            ->with('success', 'Location Created Successfully.');
    }

    public function show(Locations $location)
    {
        $servers = DB::table('servers as s')
            ->where('s.location_id', $location->id)
            ->get(['s.id', 's.hostname'])
            ->toArray();

        $shared = DB::table('shared_hosting as s')
            ->where('s.location_id', $location->id)
            ->get(['s.id', 's.main_domain as main_domain_shared'])
            ->toArray();

        $reseller = DB::table('reseller_hosting as r')
            ->where('r.location_id', $location->id)
            ->get(['r.id', 'r.main_domain as main_domain_reseller'])
            ->toArray();

        // Seedboxes also block location deletion — list them as blockers too.
        $seedboxes = DB::table('seedboxes as sb')
            ->where('sb.location_id', $location->id)
            ->get(['sb.id', 'sb.title as seedbox_title'])
            ->toArray();

        $data = array_merge($servers, $shared, $reseller, $seedboxes);

        return view('locations.show', compact(['location', 'data']));
    }

    public function destroy(Locations $location)
    {
        $inUse = Server::where('location_id', $location->id)->exists()
            || Shared::where('location_id', $location->id)->exists()
            || Reseller::where('location_id', $location->id)->exists()
            || SeedBoxes::where('location_id', $location->id)->exists();

        if ($inUse) {
            return redirect()->route('locations.index')
                ->with('error', 'Cannot delete a location that is assigned to services.');
        }

        if ($location->delete()){
            Cache::forget('locations');

            return redirect()->route('locations.index')
                ->with('success', 'Location was deleted Successfully.');
        }

        return redirect()->route('locations.index')
            ->with('error', 'Location was not deleted.');

    }
}
