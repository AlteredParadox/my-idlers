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
    use \App\Http\Controllers\Concerns\ValidatesHostingQuotas;
    use \App\Http\Controllers\Concerns\HandlesServiceUpdates;

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

    /** Identical for store and update. */
    private function rules(): array
    {
        return [
            'domain' => 'required|min:4|max:255',
            'shared_type' => 'required|string|max:255',
            ...$this->hostingQuotaRules(),
            'os_id' => 'integer',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            ...\App\Models\Pricing::webValidationRules(),
            'was_promo' => 'integer|in:0,1',
            'owned_since' => 'sometimes|nullable|date',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'dedicated_ip' => 'sometimes|nullable|ip',
            ...\App\Models\Labels::validationRules(),
        ];
    }

    public function store(Request $request)
    {
        $request->validate($this->rules());

        $link_speed_mbps = $this->linkSpeedAsMbps($request);

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

        Home::forgetServiceCacheByType(2, $shared_id);
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
        $request->validate($this->rules());

        $submitted_ips = $this->collectSubmittedIps($request);

        $link_speed_mbps = $this->linkSpeedAsMbps($request);

        $is_active = (isset($request->is_active)) ? 1 : 0;

        // Atomic: a failure in any later write (pricing, labels, IPs) must
        // not leave a partially updated service.
        DB::transaction(function () use ($request, $shared, $is_active, $link_speed_mbps, $submitted_ips) {
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

            $this->syncPricingAndLabels($request, $shared->id, $is_active);

            IPs::syncForService($shared->id, $submitted_ips);
        });

        Cache::forget("note.{$shared->id}");//embeds the shared relation
        Cache::forget('all_notes');
        Home::forgetServiceCacheByType(2, $shared->id);
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

            Home::forgetServiceCacheByType(2, $shared->id);
            Home::homePageCacheForget();

            return redirect()->route('shared.index')
                ->with('success', 'Shared hosting was deleted Successfully.');
        }

        return redirect()->route('shared.index')
            ->with('error', 'Shared was not deleted.');
    }

}
