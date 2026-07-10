<?php

namespace App\Http\Controllers;

use App\Models\DNS;
use App\Models\Note;
use App\Models\Labels;
use App\Models\Reseller;
use App\Models\Server;
use App\Models\Domains;
use App\Models\Shared;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DNSController extends Controller
{
    public function index()
    {
        $dn = DB::table('d_n_s')->get();
        return view('dns.index', compact(['dn']));
    }

    public function create()
    {
        // Dropdowns render id + one display column; skip the other ~20 columns
        $servers = Server::all(['id', 'hostname']);
        $domains = Domains::all(['id', 'domain', 'extension']);
        $shareds = Shared::all(['id', 'main_domain']);
        $resellers = Reseller::all(['id', 'main_domain']);
        return view('dns.create', compact(['servers', 'domains', 'shareds', 'resellers']));
    }

    /**
     * The DNS create/edit selects submit the literal string 'null' for an
     * unlinked service; otherwise the value must be a real id in $table.
     * Without this a forged request stored dangling ids that 404 on the
     * show page's relation links.
     */
    private function serviceIdRule(string $table): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($table) {
            if ($value === null || $value === 'null' || $value === '') {
                return;
            }
            if (!\DB::table($table)->where('id', $value)->exists()) {
                $fail("The selected {$attribute} does not exist.");
            }
        };
    }

    public function store(Request $request)
    {
        $request->validate([
            'hostname' => 'required|string|min:2|max:255',
            'address' => 'required|string|min:2|max:255',
            'dns_type' => ['required', 'string', Rule::in(DNS::$dnsTypes)],
            'server_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('servers')],
            'shared_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('shared_hosting')],
            'reseller_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('reseller_hosting')],
            'domain_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('domains')],
            ...\App\Models\Labels::validationRules(),
        ]);

        $dns_id = Str::random(8);

        // Atomic like the other multi-row creates (this one was missed by
        // that pass): a label-insert failure must not strand a DNS row.
        DB::transaction(function () use ($request, $dns_id) {
            DNS::create([
                'id' => $dns_id,
                'hostname' => $request->hostname,
                'dns_type' => $request->dns_type,
                'address' => $request->address,
                'server_id' => ($request->server_id !== 'null') ? $request->server_id : null,
                'shared_id' => ($request->shared_id !== 'null') ? $request->shared_id : null,
                'reseller_id' => ($request->reseller_id !== 'null') ? $request->reseller_id : null,
                'domain_id' => ($request->domain_id !== 'null') ? $request->domain_id : null
            ]);

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $dns_id);
        });

        Cache::forget('dns_count');

        return redirect()->route('dns.index')
            ->with('success', 'DNS Created Successfully.');
    }

    public function show(DNS $dn)
    {
        $dns = $dn;//route-model binding already fetched (or 404ed) this row

        $labels = DB::table('labels_assigned as l')
            ->join('labels', 'l.label_id', 'labels.id')
            ->where('l.service_id', $dn->id)
            ->get(['labels.label']);

        return view('dns.show', compact(['dn', 'dns', 'labels']));
    }

    public function edit(DNS $dn)
    {
        $servers = Server::all(['id', 'hostname']);
        $domains = Domains::all(['id', 'domain', 'extension']);
        $shareds = Shared::all(['id', 'main_domain']);
        $resellers = Reseller::all(['id', 'main_domain']);
        $labels = DB::table('labels_assigned as l')
            ->join('labels', 'l.label_id', 'labels.id')
            ->where('l.service_id', $dn->id)
            ->get(['labels.id']);

        return view('dns.edit', compact(['dn', 'labels', 'servers', 'domains', 'shareds', 'resellers']));
    }

    public function update(Request $request, DNS $dn): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'hostname' => 'required|string|min:2|max:255',
            'address' => 'required|string|min:2|max:255',
            'dns_type' => ['required', 'string', Rule::in(DNS::$dnsTypes)],
            'server_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('servers')],
            'shared_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('shared_hosting')],
            'reseller_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('reseller_hosting')],
            'domain_id' => ['sometimes', 'nullable', 'string', $this->serviceIdRule('domains')],
            ...\App\Models\Labels::validationRules(),
        ]);

        // Atomic (this update predated the round-14 atomicity pass): the
        // model write and label re-sync must commit together, and the
        // locked re-check stops a concurrent destroy from letting the
        // label inserts recreate rows for a deleted record.
        $updated = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $dn) {
            if (!$this->lockedRowStillExists($dn)) {
                return false;
            }

            $dn->update([
                'hostname' => $request->hostname,
                'dns_type' => $request->dns_type,
                'address' => $request->address,
                'server_id' => ($request->server_id !== 'null') ? $request->server_id : null,
                'shared_id' => ($request->shared_id !== 'null') ? $request->shared_id : null,
                'reseller_id' => ($request->reseller_id !== 'null') ? $request->reseller_id : null,
                'domain_id' => ($request->domain_id !== 'null') ? $request->domain_id : null
            ]);

            Labels::deleteLabelsAssignedTo($dn->id);

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $dn->id);

            return true;
        });

        if (!$updated) {
            return redirect()->route('dns.index')
                ->with('error', 'DNS record no longer exists.');
        }

        Cache::forget("note.{$dn->id}");//embeds the dns relation
        Cache::forget('all_notes');

        return redirect()->route('dns.index')
            ->with('success', 'DNS updated Successfully.');
    }

    public function destroy(DNS $dn): \Illuminate\Http\RedirectResponse
    {
        // Atomic: label/note rows have no DB cascades — a failure mid-cleanup
        // must not orphan them behind an already-deleted record.
        $deleted = DB::transaction(function () use ($dn) {
            if (!$dn->delete()) {
                return false;
            }
            Labels::deleteLabelsAssignedTo($dn->id);
            Note::deleteForService($dn->id);
            return true;
        });

        if ($deleted) {
            Cache::forget('dns_count');

            return redirect()->route('dns.index')
                ->with('success', 'DNS was deleted Successfully.');
        }

        return redirect()->route('dns.index')
            ->with('error', 'DNS was not deleted.');
    }
}
