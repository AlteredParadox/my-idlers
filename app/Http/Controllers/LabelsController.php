<?php

namespace App\Http\Controllers;

use App\Models\Labels;
use App\Models\LabelsAssigned;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LabelsController extends Controller
{

    public function index()
    {
        $labels = Labels::all();
        return view('labels.index', compact(['labels']));
    }

    public function create()
    {
        return view('labels.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            // unique + max: the column is unique/varchar(255); without the
            // rules a duplicate or over-length name is a QueryException 500
            'label' => 'required|string|min:2|max:255|unique:labels,label'
        ]);

        Labels::create([
            'id' => Str::random(8),
            'label' => $request->label
        ]);

        Cache::forget('all_labels');
        Cache::forget('labels_count');

        return redirect()->route('labels.index')
            ->with('success', 'Label Created Successfully.');
    }

    public function show(Labels $label)
    {
        $labels = DB::table('labels_assigned as las')
            ->leftJoin('pricings as p', 'las.service_id', 'p.service_id')
            ->leftJoin('servers as s', 'las.service_id', 's.id')
            ->leftJoin('shared_hosting as sh', 'las.service_id', 'sh.id')
            ->leftJoin('reseller_hosting as r', 'las.service_id', 'r.id')
            ->leftJoin('domains as d', 'las.service_id', 'd.id')
            ->leftJoin('seedboxes as sb', 'las.service_id', 'sb.id')
            ->leftJoin('d_n_s as dns', 'las.service_id', 'dns.id')
            ->where('las.label_id', $label->id)
            // las.service_id (not p.service_id) so DNS records, which have no
            // pricing row, still carry a usable id.
            ->get(['p.service_type', 'las.service_id as service_id', 's.hostname', 'sh.main_domain as shared', 'r.main_domain as reseller', 'd.domain', 'd.extension', 'sb.title as seedbox', 'dns.hostname as dns_hostname'])
            ->map(function ($row) {
                // Raw DB rows arrive as strings under MySQL; the view compares
                // service_type strictly (=== 1..4), so normalize to int here.
                $row->service_type = (int) $row->service_type;
                return $row;
            });

        return view('labels.show', compact(['label', 'labels']));
    }

    public function destroy(Labels $label)
    {
        // Atomic: the assignment rows have no DB cascade — a failure between
        // the label delete and their cleanup would strand orphaned
        // assignments (rendered as empty badges) behind a deleted label.
        // The service-id capture happens INSIDE the transaction: the caches
        // it feeds embed the labels relation, and an assignment committed
        // between an outside pluck and the delete would keep serving the
        // deleted label for a month.
        $serviceIds = collect();
        $deleted = DB::transaction(function () use ($label, &$serviceIds) {
            $serviceIds = LabelsAssigned::where('label_id', $label->id)->pluck('service_id');
            if (!$label->delete()) {
                return false;
            }
            Labels::deleteLabelAssignedAs($label->id);

            return true;
        });

        if ($deleted) {
            Cache::forget('labels_count');

            Cache::forget('all_labels');

            foreach ($serviceIds as $sid) {
                Cache::forget("server.$sid");
                Cache::forget("shared_hosting.$sid");
                Cache::forget("reseller_hosting.$sid");
                Cache::forget("domain.$sid");
            }
            Server::serverRelatedCacheForget();
            foreach (['shared', 'reseller', 'domains'] as $type) {
                Cache::forget("all_{$type}");
                Cache::forget("all_active_{$type}");
                Cache::forget("non_active_{$type}");
            }

            return redirect()->route('labels.index')
                ->with('success', 'Label was deleted Successfully.');
        }

        return redirect()->route('labels.index')
            ->with('error', 'Label was not deleted.');
    }
}
