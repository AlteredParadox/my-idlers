<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Disk;
use App\Models\Home;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\Note;
use App\Models\Pricing;
use App\Models\Server;
use App\Models\Yabs;
use App\Services\YabsIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ServerManagementController extends Controller
{
    private function notFound(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['result' => 'fail', 'error' => 'Not found'], 404);
    }

    private const VALIDATION_MESSAGES = [
        'required' => ':attribute is required',
        'min' => ':attribute must be longer than 3',
        'integer' => ':attribute must be an integer',
        'string' => ':attribute must be a string',
        'size' => ':attribute must be exactly :size characters',
        'numeric' => ':attribute must be a float',
        'ip' => ':attribute must be a valid IP address',
        'date' => ':attribute must be a date Y-m-d',
        'date_format' => ':attribute must be a date Y-m-d',
        'prohibited' => ':attribute is not supported on update — send the full IP set as ips[]',
    ];


    /**
     * Store and update share one field contract (like the web controller's
     * rules(bool)): every field required at create is optional on update,
     * so a partial PUT touches only the submitted columns.
     */
    private function rules(bool $for_store): array
    {
        $required = $for_store ? 'required|' : '';

        $rules = [
            // max:255 — over-length passes SQLite silently but is a MySQL
            // strict "Data too long" 500
            'hostname' => $required . 'string|min:3|max:255',
            'server_type' => $required . 'integer|in:1,2,3,4,5,6,7',
            // exists: no FK guards these columns, and a dangling id 500s the
            // servers index and public page on the null relation
            'os_id' => $required . 'integer|exists:os,id',
            'provider_id' => $required . 'integer|exists:providers,id',
            'location_id' => $required . 'integer|exists:locations,id',
            'ssh_port' => $required . 'integer|min:1|max:65535',
            // ram cap: the GB→MB derivation (*1024) must fit the int
            // ram_as_mb column — 2097151 GB is the largest safe input
            'ram' => $required . 'integer|min:0|max:2097151',
            'disk' => $required . 'integer|min:0|max:1000000',
            'cpu' => $required . 'integer|min:1|max:1024',
            'bandwidth' => $required . 'integer|min:0|max:100000000',
            'was_promo' => $required . 'integer|in:0,1',
            'transferrable' => 'integer|in:0,1',
            'active' => $required . 'integer|in:0,1',
            'show_public' => $required . 'integer|in:0,1',
            'owned_since' => $required . 'date_format:Y-m-d',
            // in: enums (not size:2) — the case-sensitive === derivations turn
            // e.g. 'gb' into a silent 1024x disk_as_gb corruption, no error
            'ram_type' => $required . 'in:MB,GB',
            'disk_type' => $required . 'in:GB,TB',
            // Web-form parity fields; all optional in both directions
            'disk_media' => 'nullable|in:SSD,HDD,NVMe',
            'ns1' => 'nullable|string|max:255',
            'ns2' => 'nullable|string|max:255',
            'cpu_model' => 'nullable|string|max:255',
            'network_type' => 'nullable|string|in:IPv4,IPv6,IPv4+IPv6,IPv4 NAT,IPv4 NAT + IPv6',
            'link_speed' => 'nullable|numeric|min:0|max:1000000',
            // a unit-less speed would be silently stored as Mbps
            'link_speed_type' => 'required_with:link_speed|in:Mbps,Gbps',
            'labels' => 'array|max:4',
            'labels.*' => 'string|distinct|exists:labels,id',
            'currency' => $required . 'string|size:3|' . \App\Models\Pricing::currencyRule(),
            'price' => array_merge($for_store ? ['required'] : [],
                ['numeric', 'min:0', 'max:99999999', new \App\Rules\PriceFitsStorableUsd()]),
            'payment_term' => $required . 'integer|in:1,2,3,4,5,6,7',
            'next_due_date' => 'date_format:Y-m-d',
        ];

        if ($for_store) {
            $rules['ip1'] = 'nullable|ip';
            // different:ip1 — duplicate would hit the (service_id, address)
            // unique index as a QueryException 500 instead of a 400
            $rules['ip2'] = 'nullable|ip|different:ip1';
        } else {
            // update replaces the full IP set (web edit-form semantics):
            // [] clears, absent leaves the assigned IPs untouched
            $rules['ips'] = 'array|max:64';
            $rules['ips.*'] = 'ip|distinct';
            // reject rather than silently ignore a reused POST payload —
            // clients would think they changed an IP when nothing happened
            $rules['ip1'] = 'prohibited';
            $rules['ip2'] = 'prohibited';
        }

        return $rules;
    }

    /**
     * Lowercase submitted addresses BEFORE validation: the ips table's
     * utf8mb4 collation is case-insensitive, so IPv6 case-variants that
     * pass the case-sensitive different:/distinct rules would still hit
     * the (service_id, address) unique index as a QueryException 500.
     */
    private function normalizeIpInput(Request $request): void
    {
        $merge = [];
        foreach (['ip1', 'ip2'] as $field) {
            if (is_string($request->input($field))) {
                $merge[$field] = strtolower($request->input($field));
            }
        }
        if (is_array($request->input('ips'))) {
            $merge['ips'] = array_map(
                fn($ip) => is_string($ip) ? strtolower($ip) : $ip,
                $request->input('ips')
            );
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    protected function storeServer(Request $request)
    {
        $this->normalizeIpInput($request);
        $validator = Validator::make($request->all(), $this->rules(true), self::VALIDATION_MESSAGES);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $validated = $validator->validated();
        $server_id = Str::random(8);

        $insert = DB::transaction(function () use ($request, $validated, $server_id) {
            (new Pricing())->insertPricing(1, $server_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            if (!is_null($request->ip1)) {
                IPs::insertIP($server_id, $request->ip1);
            }

            if (!is_null($request->ip2)) {
                IPs::insertIP($server_id, $request->ip2);
            }

            $server = Server::create($this->applyLinkSpeed([
                'id' => $server_id,
                'hostname' => $request->hostname,
                'server_type' => $request->server_type,
                'os_id' => $request->os_id,
                'ssh' => $request->ssh_port,
                'provider_id' => $request->provider_id,
                'location_id' => $request->location_id,
                'ram' => $request->ram,
                'ram_type' => $request->ram_type,
                'ram_as_mb' => ($request->ram_type === 'MB') ? $request->ram : ($request->ram * 1024),
                'disk' => $request->disk,
                'disk_type' => $request->disk_type,
                'disk_as_gb' => ($request->disk_type === 'GB') ? $request->disk : ($request->disk * 1024),
                'owned_since' => $request->owned_since,
                'ns1' => $request->ns1,
                'ns2' => $request->ns2,
                'network_type' => $request->network_type,
                'bandwidth' => $request->bandwidth,
                'cpu' => $request->cpu,
                'cpu_model' => $request->cpu_model,
                'was_promo' => $request->was_promo,
                'transferrable' => $request->transferrable,
                'active' => $request->boolean('active') ? 1 : 0,
                'show_public' => $request->boolean('show_public') ? 1 : 0
            ], $validated));

            // Parity with the web create path: record a server_disks row so disk
            // totals (which join server_disks) include API-created servers.
            Disk::insertDisk($server_id, (int) $request->disk, $request->disk_type, $request->disk_media ?? 'SSD');

            $this->syncLabels($server_id, $validated);

            return $server;
        });

        Server::serverRelatedCacheForget();

        if ($insert) {
            return response()->json(array('result' => 'success', 'server_id' => $server_id), 200);
        }

        return response()->json(array('result' => 'fail'), 500);
    }


    public function destroyServer(Request $request, string $id)
    {
        // Route parameter, NOT $request->id: request input shadows route
        // params, so a body {"id": "..."} would delete a different server.
        $items = Server::find($id);

        if (is_null($items)) {
            return $this->notFound();
        }

        // Atomic like the web destroy: child rows have no DB cascades — a
        // failure mid-cleanup must not orphan them behind a deleted server.
        $deleted = DB::transaction(function () use ($items, $id) {
            if (!$items->delete()) {
                return false;
            }
            (new Pricing())->deletePricing($id);
            Labels::deleteLabelsAssignedTo($id);
            IPs::deleteIPsAssignedTo($id);
            Disk::deleteDisksForServer($id);
            Note::deleteForService($id);
            Yabs::deleteForServer($id);
            return true;
        });

        if ($deleted) {
            Server::serverRelatedCacheForget();
            Server::serverSpecificCacheForget($id);

            return response()->json(array('result' => 'success'), 200);
        }

        return response()->json(array('result' => 'fail'), 500);
    }


    public function updateServer(Request $request, string $id)
    {
        $this->normalizeIpInput($request);
        $validator = Validator::make($request->all(), $this->rules(false), self::VALIDATION_MESSAGES);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $validated = $validator->validated();
        // ip1/ip2 must be excluded too: 'prohibited' passes them through
        // validated() when null/empty, and servers has no such columns.
        $updateData = collect($validated)
            ->except(['currency', 'price', 'payment_term', 'next_due_date', 'ssh_port', 'link_speed_type', 'disk_media', 'labels', 'ips', 'ip1', 'ip2'])
            ->toArray();

        if ($request->has('ssh_port')) {
            $updateData['ssh'] = $request->integer('ssh_port');
        }

        // Atomic like the web update: server row, disk parity row, pricing,
        // labels and IPs commit or roll back together. The row is read
        // LOCKED inside the transaction: the derived columns are computed
        // from it (a concurrent partial PUT could interleave and persist
        // an inconsistent ram/ram_as_mb pair), and a concurrent destroy
        // must yield a 404 here — not ghost child inserts for a deleted id.
        $found = DB::transaction(function () use ($request, $id, $validated, $updateData) {
            $server_row = Server::where('id', $id)->lockForUpdate()->first();
            if (is_null($server_row)) {
                return false;
            }

            $updateData = $this->deriveAsColumns($updateData, $validated, $server_row);
            $updateData = $this->applyLinkSpeed($updateData, $validated);

            // update() returns the CHANGED-row count; MySQL (no MYSQL_ATTR_FOUND_ROWS)
            // reports 0 for an idempotent re-save, so success is keyed on existence,
            // not the dirty count. Empty set (labels/ips-only PUT): nothing to write.
            if ($updateData !== []) {
                Server::where('id', $id)->update($updateData);
            }

            $this->syncDiskParityRow($id, $validated, $server_row);

            if ($request->hasAny(['currency', 'price', 'payment_term', 'next_due_date', 'active'])) {
                $this->applyPricingFields($id, $validated);
            }

            $this->syncLabels($id, $validated);

            if (array_key_exists('ips', $validated)) {
                IPs::syncForService($id, $validated['ips']);
            }

            return true;
        });

        if (!$found) {
            return $this->notFound();
        }

        Server::serverRelatedCacheForget();
        Server::serverSpecificCacheForget($id);

        return response()->json(array('result' => 'success', 'server_id' => $id), 200);
    }

    /**
     * link_speed arrives as a value + Mbps/Gbps unit pair (web-form parity)
     * but is stored as a single Mbps column. Clients can't set the column
     * directly: an unconverted Gbps figure would corrupt the stored speed.
     */
    private function applyLinkSpeed(array $data, array $validated): array
    {
        if (!array_key_exists('link_speed', $validated)) {
            return $data;
        }

        $speed = $validated['link_speed'];
        if (!$speed) {
            $data['link_speed'] = null;

            return $data;
        }

        $type = $validated['link_speed_type'] ?? null;
        $data['link_speed'] = (int) ($type === 'Gbps' ? $speed * 1000 : $speed);

        return $data;
    }

    /**
     * Replace the label assignments when `labels` was submitted: [] clears,
     * absent leaves them untouched (partial-update semantics).
     */
    private function syncLabels(string $id, array $validated): void
    {
        $labels = $validated['labels'] ?? null;
        if (!is_array($labels)) {
            return;
        }

        Labels::deleteLabelsAssignedTo($id);
        Labels::insertLabelsAssigned(array_pad(array_values($labels), 4, null), $id);
    }


    /**
     * Derive the _as_ columns like storeServer does — the index and public
     * pages render RAM/disk exclusively from them, so a partial update would
     * otherwise show the old size forever. Clients cannot supply
     * ram_as_mb/disk_as_gb directly (not in the rules): an explicit value
     * could contradict ram/ram_type (ram=8 GB with ram_as_mb=1) and the
     * views sort and render from the derived columns.
     */
    private function deriveAsColumns(array $updateData, array $validated, Server $server_row): array
    {
        if (array_key_exists('ram', $validated) || array_key_exists('ram_type', $validated)) {
            $ram = (int) ($validated['ram'] ?? $server_row->ram);
            $ram_type = $validated['ram_type'] ?? $server_row->ram_type;
            $updateData['ram_as_mb'] = ($ram_type === 'MB') ? $ram : ($ram * 1024);
        }
        if (array_key_exists('disk', $validated) || array_key_exists('disk_type', $validated)) {
            $disk = (int) ($validated['disk'] ?? $server_row->disk);
            $disk_type = $validated['disk_type'] ?? $server_row->disk_type;
            $updateData['disk_as_gb'] = ($disk_type === 'GB') ? $disk : ($disk * 1024);
        }

        return $updateData;
    }

    /**
     * The UI disk totals prefer server_disks rows when they exist — updating
     * only servers.disk left the old size showing forever. Multi-disk servers
     * are left alone: the API's single disk field can't represent them, and
     * rewriting destroyed per-disk media.
     */
    private function syncDiskParityRow(string $id, array $validated, Server $server_row): void
    {
        $media = $validated['disk_media'] ?? null;

        if (!array_key_exists('disk', $validated) && !array_key_exists('disk_type', $validated) && is_null($media)) {
            return;
        }

        $size = (int) ($validated['disk'] ?? $server_row->disk);
        $unit = $validated['disk_type'] ?? $server_row->disk_type;
        $disk_rows = Disk::where('server_id', $id)->get();

        if ($disk_rows->count() === 1) {
            $row = [
                'disk_size' => $size,
                'disk_unit' => $unit,
                'disk_as_gb' => ($unit === 'TB') ? ($size * 1024) : $size,
            ];
            if (!is_null($media)) {
                $row['disk_media'] = $media;
            }
            $disk_rows->first()->update($row);
        } elseif ($disk_rows->isEmpty()) {
            Disk::insertDisk($id, $size, $unit, $media ?? 'SSD');
        }
    }

    /**
     * currency/price/payment_term/next_due_date live on the pricing row —
     * apply them rather than silently dropping them; 'active' must fan in too
     * or a reactivated server stays hidden from the cost breakdown and
     * due-soon feed (both filter pricings.active = 1).
     */
    private function applyPricingFields(string $id, array $validated): void
    {
        // Locked: runs inside the update transaction, and the merged values
        // below are derived from this row — an unlocked read could silently
        // revert a concurrent PUT /api/pricing/{id} commit.
        $pricing_row = Pricing::where('service_id', $id)->lockForUpdate()->first();
        if (is_null($pricing_row)) {
            return;
        }

        (new Pricing())->updatePricing(
            $id,
            $validated['currency'] ?? $pricing_row->currency,
            (float) ($validated['price'] ?? $pricing_row->price),
            (int) ($validated['payment_term'] ?? $pricing_row->term),
            $validated['next_due_date'] ?? $pricing_row->next_due_date,
            (int) ($validated['active'] ?? $pricing_row->active)
        );
    }

    public function updatePricing(Request $request, string $id)
    {
        $rules = [
            'price' => ['required', 'numeric', 'min:0', 'max:99999999', new \App\Rules\PriceFitsStorableUsd()],
            'currency' => 'required|string|size:3|' . \App\Models\Pricing::currencyRule(),
            'term' => 'required|integer|in:1,2,3,4,5,6,7',
            'active' => 'integer|in:0,1',
            'next_due_date' => 'date_format:Y-m-d',
        ];

        $validator = Validator::make($request->all(), $rules, self::VALIDATION_MESSAGES);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $pricing = new Pricing();

        $as_usd = $pricing->convertToUSD($request->price, $request->currency);
        $usd_per_month = $pricing->costAsPerMonth($as_usd, $request->term);

        $validated = $validator->validated();
        $updateData = [
            'price' => $validated['price'],
            'currency' => $validated['currency'],
            'term' => $validated['term'],
            'as_usd' => $as_usd,
            'usd_per_month' => $usd_per_month,
        ];

        if (array_key_exists('next_due_date', $validated)) {
            $updateData['next_due_date'] = $validated['next_due_date'];
        }

        if (array_key_exists('active', $validated)) {
            $updateData['active'] = $validated['active'];
        }

        // Locked read inside the transaction, like every other update path:
        // a destroy committing between an unlocked read and the UPDATE would
        // return success for a deleted service (and fan caches out from a
        // stale service_type). Success is keyed on existence, not update()'s
        // changed-row count (0 on an idempotent re-save under MySQL).
        $row = DB::transaction(function () use ($id, $updateData) {
            $row = Pricing::where('id', $id)->lockForUpdate()->first(['service_id', 'service_type']);
            if (is_null($row)) {
                return null;
            }
            Pricing::where('id', $id)->update($updateData);

            return $row;
        });

        if (is_null($row)) {
            return $this->notFound();
        }

        // This route takes any pricing row, not just a server's — fan out to
        // the owning type's caches plus every home-page key embedding prices
        // (due_soon, recently_added, all_active_pricing, pricing_breakdown).
        Home::homePageCacheForget();
        Home::forgetServiceCacheByType((int) $row->service_type, $row->service_id);

        return response()->json(array('result' => 'success', 'server_id' => $id), 200);
    }


    public function storeYabs(YabsIngestService $ingest, Request $request, Server $server): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'time' => ['required', 'string'],
            'version' => ['required'],
            'net' => ['required', 'array'],
            'os' => ['required', 'array'],
            'cpu' => ['required', 'array'],
            'mem' => ['required', 'array'],
            // sometimes: yabs.sh omits these keys when a test auto-skips
            'geekbench' => ['sometimes', 'array'],
            'fio' => ['sometimes', 'array'],
            'iperf' => ['sometimes', 'array'],
        ]);

        // A signed-but-malformed payload is client input (422), not a server
        // error — only a genuine persistence failure stays a 500.
        $parsed = $ingest->parse($request->all(), $server->id);
        if ($parsed === null) {
            return response()->json(array('error' => 'Invalid YABS payload'), 422);
        }

        if ($ingest->persist($parsed)) {
            Cache::forget('all_active_servers');//all servers cache
            Cache::forget('non_active_servers');//all servers cache
            Cache::forget('all_yabs');//Forget the all YABS cache
            return response()->json(array('message' => 'Successfully added YABS'), 200);
        }

        return response()->json(array('error' => 'Server error'), 500);
    }
}
