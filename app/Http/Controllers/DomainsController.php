<?php

namespace App\Http\Controllers;

use App\Models\Domains;
use App\Models\Note;
use App\Models\Home;
use App\Models\Labels;
use App\Models\Pricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DomainsController extends Controller
{
    public function index()
    {
        $domains = Domains::allActiveDomains();
        $non_active_domains = Domains::allNonActiveDomains();
        return view('domains.index', compact(['domains', 'non_active_domains']));
    }

    public function show(Domains $domain)
    {//Need to modern
        $domain_info = Domains::domain($domain->id);
        return view('domains.show', compact(['domain_info']));
    }

    public function create()
    {
        return view('domains.create');
    }

    /** Identical for store and update. */
    private function rules(): array
    {
        return [
            'domain' => 'required|string|min:2|max:255',
            'extension' => 'required|string|min:2|max:255',
            'ns1' => 'sometimes|nullable|min:2|max:255',
            'ns2' => 'sometimes|nullable|min:2|max:255',
            'ns3' => 'sometimes|nullable|min:2|max:255',
            'provider_id' => 'required|integer|exists:providers,id',
            ...\App\Models\Pricing::webValidationRules(),
            'owned_since' => 'sometimes|nullable|date',
            ...\App\Models\Labels::validationRules(),
        ];
    }

    public function store(Request $request)
    {
        $request->validate($this->rules());

        $domain_id = Str::random(8);

        // Atomic: pricing inserts first (FK order), so a failed domain insert
        // would otherwise orphan an active pricing row.
        DB::transaction(function () use ($request, $domain_id) {
            (new Pricing())->insertPricing(4, $domain_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            Domains::create([
                'id' => $domain_id,
                'domain' => $request->domain,
                'extension' => $request->extension,
                'ns1' => $request->ns1,
                'ns2' => $request->ns2,
                'ns3' => $request->ns3,
                'provider_id' => $request->provider_id,
                'owned_since' => $request->owned_since,
                'transferrable' => (isset($request->transferrable)) ? 1 : 0
            ]);

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $domain_id);
        });

        Home::forgetServiceCacheByType(4, $domain_id);
        Home::homePageCacheForget();

        return redirect()->route('domains.index')
            ->with('success', 'Domain Created Successfully.');
    }

    public function edit(Domains $domain)
    {
        $domain_info = Domains::domain($domain->id);
        return view('domains.edit', compact(['domain_info']));
    }

    public function update(Request $request, Domains $domain)
    {
        $request->validate($this->rules());

        $is_active = (isset($request->is_active)) ? 1 : 0;

        // Atomic: a failure in the model/labels writes must not leave the
        // already-written pricing row pointing at stale service data.
        $updated = DB::transaction(function () use ($request, $domain, $is_active) {
            if (!$this->lockedRowStillExists($domain)) {
                return false;
            }
            (new Pricing())->updatePricing($domain->id, $request->currency, $request->price, $request->payment_term, $request->next_due_date, $is_active);

            $domain->update([
                'domain' => $request->domain,
                'extension' => $request->extension,
                'ns1' => $request->ns1,
                'ns2' => $request->ns2,
                'ns3' => $request->ns3,
                'provider_id' => $request->provider_id,
                'owned_since' => $request->owned_since,
                'transferrable' => (isset($request->transferrable)) ? 1 : 0,
                'active' => $is_active
            ]);

            Labels::deleteLabelsAssignedTo($domain->id);
            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $domain->id);

            return true;
        });

        if (!$updated) {
            return redirect()->route('domains.index')
                ->with('error', 'Domain no longer exists.');
        }

        Cache::forget("note.{$domain->id}");//embeds the domain relation
        Cache::forget('all_notes');
        Home::forgetServiceCacheByType(4, $domain->id);
        Home::homePageCacheForget();

        return redirect()->route('domains.index')
            ->with('success', 'Domain Updated Successfully.');
    }

    public function destroy(Domains $domain)
    {
        // Atomic: child rows have no DB cascades — a failure mid-cleanup
        // must not orphan them behind an already-deleted domain.
        $deleted = DB::transaction(function () use ($domain) {
            if (!$domain->delete()) {
                return false;
            }
            (new Pricing())->deletePricing($domain->id);
            Labels::deleteLabelsAssignedTo($domain->id);
            Note::deleteForService($domain->id);
            return true;
        });

        if ($deleted) {
            Home::forgetServiceCacheByType(4, $domain->id);
            Home::homePageCacheForget();

            return redirect()->route('domains.index')
                ->with('success', 'Domain was deleted Successfully.');
        }

        return redirect()->route('domains.index')
            ->with('error', 'Domain was not deleted.');
    }

}
