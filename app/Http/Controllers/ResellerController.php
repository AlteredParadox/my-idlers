<?php

namespace App\Http\Controllers;

use App\Models\Home;
use App\Models\Note;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\Pricing;
use App\Models\Reseller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResellerController extends Controller
{
    public function index()
    {
        $resellers = Reseller::allActiveResellerHosting();
        $non_active_resellers = Reseller::allNonActiveResellerHosting();
        return view('reseller.index', compact(['resellers', 'non_active_resellers']));
    }

    public function create()
    {
        return view('reseller.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'domain' => 'required|min:4|max:255',
            'reseller_type' => 'required|string|max:255',
            'disk' => 'integer|min:0|max:1000000',
            'os_id' => 'integer',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            'price' => 'required|numeric|min:0|max:99999999',
            'currency' => 'required|string|size:3|' . \App\Models\Pricing::currencyRule(),
            'payment_term' => 'required|integer|in:1,2,3,4,5,6,7',
            'was_promo' => 'integer|in:0,1',
            'owned_since' => 'sometimes|nullable|date',
            'accounts' => 'integer|min:0|max:1000000',
            'domains' => 'integer|min:0|max:1000000',
            'sub_domains' => 'integer|min:0|max:1000000',
            'bandwidth' => 'integer|min:0|max:100000000',
            'link_speed' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'email' => 'integer|min:0|max:1000000',
            'ftp' => 'integer|min:0|max:1000000',
            'db' => 'integer|min:0|max:1000000',
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

        $reseller_id = Str::random(8);

        // Atomic: a failed reseller insert must not orphan pricing/IP rows.
        DB::transaction(function () use ($request, $reseller_id, $link_speed_mbps) {
            (new Pricing())->insertPricing(3, $reseller_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            if (!is_null($request->dedicated_ip)) {
                IPs::insertIP($reseller_id, $request->dedicated_ip);
            }

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $reseller_id);

            Reseller::create([
            'id' => $reseller_id,
            'main_domain' => $request->domain,
            'accounts' => $request->accounts,
            'reseller_type' => $request->reseller_type,
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

        Cache::forget("all_reseller");
        Cache::forget("all_active_reseller");
        Cache::forget("non_active_reseller");
        Home::homePageCacheForget();

        return redirect()->route('reseller.index')
            ->with('success', 'Reseller hosting created Successfully.');
    }

    public function show(Reseller $reseller)
    {
        $reseller = Reseller::resellerHosting($reseller->id);
        return view('reseller.show', compact(['reseller']));
    }

    public function edit(Reseller $reseller)
    {
        $reseller = Reseller::resellerHosting($reseller->id);
        return view('reseller.edit', compact(['reseller']));
    }

    public function update(Request $request, Reseller $reseller)
    {
        $request->validate([
            'domain' => 'required|min:4|max:255',
            'reseller_type' => 'required|string|max:255',
            'disk' => 'integer|min:0|max:1000000',
            'os_id' => 'integer',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            'price' => 'required|numeric|min:0|max:99999999',
            'currency' => 'required|string|size:3|' . \App\Models\Pricing::currencyRule(),
            'payment_term' => 'required|integer|in:1,2,3,4,5,6,7',
            'was_promo' => 'integer|in:0,1',
            'owned_since' => 'sometimes|nullable|date',
            'accounts' => 'integer|min:0|max:1000000',
            'domains' => 'integer|min:0|max:1000000',
            'sub_domains' => 'integer|min:0|max:1000000',
            'bandwidth' => 'integer|min:0|max:100000000',
            'link_speed' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'email' => 'integer|min:0|max:1000000',
            'ftp' => 'integer|min:0|max:1000000',
            'db' => 'integer|min:0|max:1000000',
            'dedicated_ip' => 'sometimes|nullable|ip',
            'next_due_date' => 'sometimes|nullable|date',
            'label1' => 'sometimes|nullable|string|exists:labels,id',
            'label2' => 'sometimes|nullable|string|exists:labels,id',
            'label3' => 'sometimes|nullable|string|exists:labels,id',
            'label4' => 'sometimes|nullable|string|exists:labels,id',
        ]);

        // Validate BEFORE any write: failing after $reseller->update()
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

        $reseller->update([
            'main_domain' => $request->domain,
            'reseller_type' => $request->reseller_type,
            'provider_id' => $request->provider_id,
            'location_id' => $request->location_id,
            'accounts' => $request->accounts,
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
        $pricing->updatePricing($reseller->id, $request->currency, $request->price, $request->payment_term, $request->next_due_date, $is_active);

        Labels::deleteLabelsAssignedTo($reseller->id);
        Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $reseller->id);

        IPs::syncForService($reseller->id, array_merge($submitted_ips, array_values($extra_ip_fields)));

        Cache::forget("note.{$reseller->id}");//embeds the reseller relation
        Cache::forget('all_notes');
        Cache::forget("all_reseller");
        Cache::forget("all_active_reseller");
        Cache::forget("non_active_reseller");
        Cache::forget("reseller_hosting.{$reseller->id}");

        Home::homePageCacheForget();

        return redirect()->route('reseller.index')
            ->with('success', 'Reseller hosting updated Successfully.');
    }

    public function destroy(Reseller $reseller)
    {
        if ($reseller->delete()) {
            $p = new Pricing();
            $p->deletePricing($reseller->id);

            Labels::deleteLabelsAssignedTo($reseller->id);

            IPs::deleteIPsAssignedTo($reseller->id);

            Note::deleteForService($reseller->id);

            Cache::forget("all_reseller");
        Cache::forget("all_active_reseller");
        Cache::forget("non_active_reseller");
            Cache::forget("reseller_hosting.$reseller->id");
            Home::homePageCacheForget();

            return redirect()->route('reseller.index')
                ->with('success', 'Reseller hosting was deleted Successfully.');
        }

        return redirect()->route('reseller.index')
            ->with('error', 'Reseller was not deleted.');

    }
}
