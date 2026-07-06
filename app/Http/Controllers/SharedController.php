<?php

namespace App\Http\Controllers;

use App\Models\Home;
use App\Models\Note;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\Pricing;
use App\Models\Shared;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SharedController extends Controller
{
    public function index()
    {
        $shared = Shared::allActiveSharedHosting();
        $non_active_shared = Shared::allNonActiveSharedHosting();
        return view('shared.index', compact(['shared', 'non_active_shared']));
    }

    public function create()
    {
        return view('shared.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'domain' => 'required|min:4|max:255',
            'shared_type' => 'required|string',
            'disk' => 'integer',
            'os_id' => 'integer',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            'price' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'payment_term' => 'required|integer',
            'was_promo' => 'integer',
            'owned_since' => 'sometimes|nullable|date',
            'domains' => 'integer',
            'sub_domains' => 'integer',
            'bandwidth' => 'integer',
            'link_speed' => 'sometimes|nullable|numeric',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'email' => 'integer',
            'ftp' => 'integer',
            'db' => 'integer',
            'dedicated_ip' => 'sometimes|nullable|ip',
            'next_due_date' => 'sometimes|nullable|date',
            'label1' => 'sometimes|nullable|string|exists:labels,id',
            'label2' => 'sometimes|nullable|string|exists:labels,id',
            'label3' => 'sometimes|nullable|string|exists:labels,id',
            'label4' => 'sometimes|nullable|string|exists:labels,id',
        ]);

        $link_speed_mbps = null;
        if ($request->link_speed) {
            $link_speed_mbps = $request->link_speed_type === 'Gbps' ? $request->link_speed * 1000 : $request->link_speed;
        }

        $shared_id = Str::random(8);

        // Atomic: a failed shared insert must not orphan pricing/IP rows.
        DB::transaction(function () use ($request, $shared_id, $link_speed_mbps) {
            (new Pricing())->insertPricing(2, $shared_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $shared_id);

            if (!is_null($request->dedicated_ip)) {
                IPs::insertIP($shared_id, $request->dedicated_ip);
            }

            Shared::create([
            'id' => $shared_id,
            'main_domain' => $request->domain,
            'shared_type' => $request->shared_type,
            'provider_id' => $request->provider_id,
            'location_id' => $request->location_id,
            'disk' => $request->disk,
            'disk_type' => 'GB',
            'disk_as_gb' => $request->disk,
            'owned_since' => $request->owned_since,
            'bandwidth' => $request->bandwidth,
            'link_speed' => $link_speed_mbps,
            'was_promo' => $request->was_promo,
            'transferrable' => (isset($request->transferrable)) ? 1 : 0,
            'domains_limit' => $request->domains,
            'subdomains_limit' => $request->sub_domains,
                'email_limit' => $request->email,
                'ftp_limit' => $request->ftp,
                'db_limit' => $request->db
            ]);
        });

        Cache::forget('all_shared');
        Cache::forget('all_active_shared');
        Cache::forget('non_active_shared');
        Home::homePageCacheForget();

        return redirect()->route('shared.index')
            ->with('success', 'Shared hosting created Successfully.');
    }

    public function show(Shared $shared)
    {
        $shared = Shared::sharedHosting($shared->id);
        return view('shared.show', compact(['shared']));
    }

    public function edit(Shared $shared)
    {
        $shared = Shared::sharedHosting($shared->id);
        return view('shared.edit', compact(['shared']));
    }

    public function update(Request $request, Shared $shared)
    {
        $request->validate([
            'domain' => 'required|min:4|max:255',
            'shared_type' => 'required|string',
            'disk' => 'integer',
            'os_id' => 'integer',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            'price' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'payment_term' => 'required|integer',
            'was_promo' => 'integer',
            'owned_since' => 'sometimes|nullable|date',
            'domains' => 'integer',
            'sub_domains' => 'integer',
            'bandwidth' => 'integer',
            'link_speed' => 'sometimes|nullable|numeric',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'email' => 'integer',
            'ftp' => 'integer',
            'db' => 'integer',
            'dedicated_ip' => 'sometimes|nullable|ip',
            'next_due_date' => 'sometimes|nullable|date',
            'label1' => 'sometimes|nullable|string|exists:labels,id',
            'label2' => 'sometimes|nullable|string|exists:labels,id',
            'label3' => 'sometimes|nullable|string|exists:labels,id',
            'label4' => 'sometimes|nullable|string|exists:labels,id',
        ]);

        // Validate BEFORE any write: failing after $shared->update()
        // left persisted changes with every cache forget skipped.
        // Collect the dedicated IP plus any ipN fields (IPs assigned via the
        // IPs page round-trip through the edit form) — syncing only the one
        // dedicated_ip slot silently deleted the others and their notes.
        $submitted_ips = is_null($request->dedicated_ip) ? [] : [$request->dedicated_ip];
        $extra_ip_fields = [];
        foreach ($request->all() as $key => $value) {
            if (preg_match('/^ip\d+$/', $key) && !is_null($value)) {
                $extra_ip_fields[$key] = $value;
            }
        }
        $request->validate(array_fill_keys(array_keys($extra_ip_fields), 'ip'));

        $link_speed_mbps = null;
        if ($request->link_speed) {
            $link_speed_mbps = $request->link_speed_type === 'Gbps' ? $request->link_speed * 1000 : $request->link_speed;
        }

        $is_active = (isset($request->is_active)) ? 1 : 0;

        $shared->update([
            'main_domain' => $request->domain,
            'shared_type' => $request->shared_type,
            'provider_id' => $request->provider_id,
            'location_id' => $request->location_id,
            'disk' => $request->disk,
            'disk_type' => 'GB',
            'disk_as_gb' => $request->disk,
            'owned_since' => $request->owned_since,
            'bandwidth' => $request->bandwidth,
            'link_speed' => $link_speed_mbps,
            'was_promo' => $request->was_promo,
            'transferrable' => (isset($request->transferrable)) ? 1 : 0,
            'domains_limit' => $request->domains,
            'subdomains_limit' => $request->sub_domains,
            'email_limit' => $request->email,
            'ftp_limit' => $request->ftp,
            'db_limit' => $request->db,
            'active' => $is_active
        ]);

        $pricing = new Pricing();
        $pricing->updatePricing($shared->id, $request->currency, $request->price, $request->payment_term, $request->next_due_date, $is_active);

        Labels::deleteLabelsAssignedTo($shared->id);
        Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $shared->id);

        // Collect the dedicated IP plus any ipN fields (IPs assigned via the
        // IPs page round-trip through the edit form) — syncing only the one
        // dedicated_ip slot silently deleted the others and their notes.
        $submitted_ips = is_null($request->dedicated_ip) ? [] : [$request->dedicated_ip];
        $extra_ip_fields = [];
        foreach ($request->all() as $key => $value) {
            if (preg_match('/^ip\d+$/', $key) && !is_null($value)) {
                $extra_ip_fields[$key] = $value;
            }
        }
        $request->validate(array_fill_keys(array_keys($extra_ip_fields), 'ip'));
        IPs::syncForService($shared->id, array_merge($submitted_ips, array_values($extra_ip_fields)));

        Cache::forget("note.{$shared->id}");//embeds the shared relation
        Cache::forget('all_notes');
        Cache::forget("shared_hosting.{$shared->id}");
        Cache::forget('all_shared');
        Cache::forget('all_active_shared');
        Cache::forget('non_active_shared');
        Home::homePageCacheForget();

        return redirect()->route('shared.index')
            ->with('success', 'Shared hosting updated Successfully.');
    }

    public function destroy(Shared $shared)
    {
        if ($shared->delete()) {
            $p = new Pricing();
            $p->deletePricing($shared->id);

            Labels::deleteLabelsAssignedTo($shared->id);

            IPs::deleteIPsAssignedTo($shared->id);

            Note::deleteForService($shared->id);

            Cache::forget("shared_hosting.$shared->id");
            Cache::forget('all_shared');
        Cache::forget('all_active_shared');
        Cache::forget('non_active_shared');
            Home::homePageCacheForget();

            return redirect()->route('shared.index')
                ->with('success', 'Shared hosting was deleted Successfully.');
        }

        return redirect()->route('shared.index')
            ->with('error', 'Shared was not deleted.');
    }

}
