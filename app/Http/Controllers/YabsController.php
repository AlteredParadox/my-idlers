<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Yabs;
use App\Services\YabsIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class YabsController extends Controller
{
    public function index()
    {
        return view('yabs.index', ['yabs' => Yabs::allYabs()]);
    }

    public function create()
    {
        $servers = Server::allServers();

        return view('yabs.create', compact('servers'));
    }

    public function store(Request $request, YabsIngestService $ingest)
    {
        $request->validate([
            'server_id' => 'required|string|exists:servers,id',
            'yabs_json' => 'required|string',
        ]);

        $data = json_decode($request->yabs_json, true);

        if (!is_array($data) || !$ingest->ingest($data, $request->server_id)) {
            return back()->withInput()->withErrors([
                'yabs_json' => 'Could not parse this as YABS JSON output. Run yabs.sh with the -j flag and paste the complete JSON.',
            ]);
        }

        Cache::forget('all_active_servers');
        Cache::forget('non_active_servers');
        Cache::forget('all_yabs');

        return redirect()->route('yabs.index')
            ->with('success', 'YABS added Successfully.');
    }

    public function show(Yabs $yab)
    {
        return view('yabs.show', ['yabs' => Yabs::yabs($yab->id)]);
    }

    public function destroy(Yabs $yab)
    {
        if ($yab->delete()) {
            if (Server::serverYabsAmount($yab->server_id) === 0) {
                DB::table('servers')
                    ->where('id', $yab->server_id)
                    ->update(['has_yabs' => 0]);
            }

            Cache::forget('all_yabs');
            Cache::forget("yabs.{$yab->id}");

            return redirect()->route('yabs.index')
                ->with('success', 'YABS was deleted Successfully.');
        }

        return redirect()->route('yabs.index')
            ->with('error', 'YABS was not deleted.');
    }

    public function chooseYabsCompare()
    {
        $all_yabs = Yabs::allYabs();

        if (isset($all_yabs[1])) {
            return view('yabs.choose-compare', compact('all_yabs'));
        }

        return redirect()->route('yabs.index')
            ->with('error', 'You need atleast 2 YABS to do a compare');
    }

    public function compareYabs(string $yabs1, string $yabs2)
    {
        $yabs1_data = Yabs::yabs($yabs1);

        if (is_null($yabs1_data)) {
            abort(404);
        }

        $yabs2_data = Yabs::yabs($yabs2);

        if (is_null($yabs2_data)) {
            abort(404);
        }

        return view('yabs.compare', compact('yabs1_data', 'yabs2_data'));
    }

    public function yabsToJson(Yabs $yab): array
    {
        $all_yabs = Yabs::yabs($yab->id);
        return Yabs::buildYabsArray($all_yabs);
    }

}
