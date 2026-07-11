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
            'os_name' => 'required|string|min:2|max:255|unique:os,name'
        ]);

        $this->createUniquely(fn() => OS::create([
            'name' => $request->os_name
        ]), 'os_name');

        Cache::forget('operating_systems');

        return redirect()->route('os.index')
            ->with('success', 'OS Created Successfully.');
    }

    public function destroy(OS $o)
    {
        // Friendly fast path; the restrictive FK is the authority — a
        // server created between this check and the delete blocks it.
        $inUse = Server::where('os_id', $o->id)->exists();

        $deleted = $inUse ? null : $this->deleteUnlessReferenced($o);

        if (is_null($deleted)) {
            return redirect()->route('os.index')
                ->with('error', 'Cannot delete an OS that is assigned to servers.');
        }

        if ($deleted) {
            Cache::forget('operating_systems');

            return redirect()->route('os.index')
                ->with('success', 'OS was deleted Successfully.');
        }

        return redirect()->route('os.index')
            ->with('error', 'OS was not deleted.');
    }
}
