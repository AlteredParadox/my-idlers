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
    use \App\Http\Controllers\Concerns\ValidatesHostingQuotas;
    use \App\Http\Controllers\Concerns\HandlesServiceUpdates;

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

    /** Identical for store and update. */
    private function rules(): array
    {
        return [
            'domain' => 'required|min:4|max:255',
            'reseller_type' => 'required|string|max:255',
            ...$this->hostingQuotaRules(),
            'os_id' => 'integer',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            ...\App\Models\Pricing::webValidationRules(),
            'was_promo' => 'integer|in:0,1',
            'owned_since' => 'sometimes|nullable|date',
            'accounts' => 'integer|min:0|max:1000000',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'dedicated_ip' => 'sometimes|nullable|ip',
            ...\App\Models\Labels::validationRules(),
        ];
    }

    public function store(Request $request)
    {
        $request->validate($this->rules());

        $link_speed_mbps = $this->linkSpeedAsMbps($request);

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

        Home::forgetServiceCacheByType(3, $reseller_id);
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
        $request->validate($this->rules());

        $submitted_ips = $this->collectSubmittedIps($request);

        $link_speed_mbps = $this->linkSpeedAsMbps($request);

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

        $this->syncPricingAndLabels($request, $reseller->id, $is_active);

        IPs::syncForService($reseller->id, $submitted_ips);

        Cache::forget("note.{$reseller->id}");//embeds the reseller relation
        Cache::forget('all_notes');
        Home::forgetServiceCacheByType(3, $reseller->id);
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

            Home::forgetServiceCacheByType(3, $reseller->id);
            Home::homePageCacheForget();

            return redirect()->route('reseller.index')
                ->with('success', 'Reseller hosting was deleted Successfully.');
        }

        return redirect()->route('reseller.index')
            ->with('error', 'Reseller was not deleted.');

    }
}
