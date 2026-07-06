<?php

namespace App\Http\Controllers;

use App\Models\OS;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class OsController extends Controller
{
    public function index()
    {
        $os = OS::allOS()->toArray();
        return view('os.index', compact(['os']));
    }

    public function create()
    {
        return view('os.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'os_name' => 'required|string|min:2|max:255'
        ]);

        OS::create([
            'name' => $request->os_name
        ]);

        Cache::forget('operating_systems');

        return redirect()->route('os.index')
            ->with('success', 'OS Created Successfully.');
    }

    public function destroy(OS $o)
    {
        if (Server::where('os_id', $o->id)->exists()) {
            return redirect()->route('os.index')
                ->with('error', 'Cannot delete an OS that is assigned to servers.');
        }

        if ($o->delete()) {
            Cache::forget('operating_systems');

            return redirect()->route('os.index')
                ->with('success', 'OS was deleted Successfully.');
        }

        return redirect()->route('os.index')
            ->with('error', 'OS was not deleted.');

    }
}
