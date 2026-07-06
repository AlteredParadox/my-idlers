<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Disk;
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
    private const VALIDATION_MESSAGES = [
        'required' => ':attribute is required',
        'min' => ':attribute must be longer than 3',
        'integer' => ':attribute must be an integer',
        'string' => ':attribute must be a string',
        'size' => ':attribute must be exactly :size characters',
        'numeric' => ':attribute must be a float',
        'ip' => ':attribute must be a valid IP address',
        'date' => ':attribute must be a date Y-m-d',
    ];


    protected function storeServer(Request $request)
    {
        $rules = [
            'hostname' => 'required|min:3',
            'server_type' => 'required|integer',
            'os_id' => 'required|integer',
            'provider_id' => 'required|integer',
            'location_id' => 'required|integer',
            'ssh_port' => 'required|integer',
            'ram' => 'required|integer',
            'ram_as_mb' => 'required|integer',
            'disk' => 'required|integer',
            'disk_as_gb' => 'required|integer',
            'cpu' => 'required|integer',
            'bandwidth' => 'required|integer',
            'was_promo' => 'required|integer',
            'transferrable' => 'integer',
            'active' => 'required|integer',
            'show_public' => 'required|integer',
            'ip1' => 'ip',
            'ip2' => 'ip',
            'owned_since' => 'required|date',
            'ram_type' => 'required|string|size:2',
            'disk_type' => 'required|string|size:2',
            'currency' => 'required|string|size:3',
            'price' => 'required|numeric',
            'payment_term' => 'required|integer',
            'next_due_date' => 'date',
        ];

        $validator = Validator::make($request->all(), $rules, self::VALIDATION_MESSAGES);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $server_id = Str::random(8);

        $insert = DB::transaction(function () use ($request, $server_id) {
            (new Pricing())->insertPricing(1, $server_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            if (!is_null($request->ip1)) {
                IPs::insertIP($server_id, $request->ip1);
            }

            if (!is_null($request->ip2)) {
                IPs::insertIP($server_id, $request->ip2);
            }

            $server = Server::create([
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
                'bandwidth' => $request->bandwidth,
                'cpu' => $request->cpu,
                'was_promo' => $request->was_promo,
                'transferrable' => $request->transferrable,
                'active' => $request->boolean('active') ? 1 : 0,
                'show_public' => $request->boolean('show_public') ? 1 : 0
            ]);

            // Parity with the web create path: record a server_disks row so disk
            // totals (which join server_disks) include API-created servers.
            Disk::insertDisk($server_id, (int) $request->disk, $request->disk_type, 'SSD');

            return $server;
        });

        Server::serverRelatedCacheForget();

        if ($insert) {
            return response()->json(array('result' => 'success', 'server_id' => $server_id), 200);
        }

        return response()->json(array('result' => 'fail'), 500);
    }


    public function destroyServer(Request $request)
    {
        $items = Server::find($request->id);

        (!is_null($items)) ? $result = $items->delete() : $result = false;

        $p = new Pricing();
        $p->deletePricing($request->id);

        Labels::deleteLabelsAssignedTo($request->id);
        IPs::deleteIPsAssignedTo($request->id);
        Disk::deleteDisksForServer($request->id);
        Note::deleteForService($request->id);
        Yabs::deleteForServer($request->id);
        Server::serverRelatedCacheForget();
        Server::serverSpecificCacheForget($request->id);

        if ($result) {
            return response()->json(array('result' => 'success'), 200);
        }

        return response()->json(array('result' => 'fail'), 500);
    }


    public function updateServer(Request $request, string $id)
    {
        $rules = [
            'hostname' => 'string|min:3',
            'server_type' => 'integer',
            'os_id' => 'integer',
            'provider_id' => 'integer',
            'location_id' => 'integer',
            'ssh_port' => 'integer',
            'ram' => 'integer',
            'ram_as_mb' => 'integer',
            'disk' => 'integer',
            'disk_as_gb' => 'integer',
            'cpu' => 'integer',
            'bandwidth' => 'integer',
            'was_promo' => 'integer',
            'transferrable' => 'integer',
            'active' => 'integer',
            'show_public' => 'integer',
            'owned_since' => 'date',
            'ram_type' => 'string|size:2',
            'disk_type' => 'string|size:2',
            'currency' => 'string|size:3',
            'price' => 'numeric',
            'payment_term' => 'integer',
            'next_due_date' => 'date',
        ];

        $validator = Validator::make($request->all(), $rules, self::VALIDATION_MESSAGES);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $updateData = collect($validator->validated())
            ->except(['currency', 'price', 'payment_term', 'next_due_date', 'ssh_port'])
            ->toArray();

        if ($request->has('ssh_port')) {
            $updateData['ssh'] = $request->integer('ssh_port');
        }

        if (!Server::where('id', $id)->exists()) {
            return response()->json(array('result' => 'fail', 'error' => 'Not found'), 404);
        }

        // update() returns the CHANGED-row count; MySQL (no MYSQL_ATTR_FOUND_ROWS)
        // reports 0 for an idempotent re-save, so success is keyed on existence,
        // not the dirty count.
        Server::where('id', $id)->update($updateData);

        Server::serverRelatedCacheForget();
        Server::serverSpecificCacheForget($id);

        return response()->json(array('result' => 'success', 'server_id' => $id), 200);
    }


    public function updatePricing(Request $request, string $id)
    {
        $rules = [
            'price' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'term' => 'required|integer',
            'active' => 'integer',
            'next_due_date' => 'date',
        ];

        $validator = Validator::make($request->all(), $rules, self::VALIDATION_MESSAGES);

        if ($validator->fails()) {
            return response()->json(['result' => 'fail', 'messages' => $validator->messages()], 400);
        }

        $pricing = new Pricing();

        $request->as_usd = $pricing->convertToUSD($request->price, $request->currency);

        $request->usd_per_month = $pricing->costAsPerMonth($request->as_usd, $request->term);

        $validated = $validator->validated();
        $updateData = [
            'price' => $validated['price'],
            'currency' => $validated['currency'],
            'term' => $validated['term'],
            'as_usd' => $request->as_usd,
            'usd_per_month' => $request->usd_per_month,
        ];

        if (array_key_exists('next_due_date', $validated)) {
            $updateData['next_due_date'] = $validated['next_due_date'];
        }

        $service_id = Pricing::where('id', $id)->value('service_id');
        if (is_null($service_id)) {
            return response()->json(array('result' => 'fail', 'error' => 'Not found'), 404);
        }

        // Success is keyed on existence, not update()'s changed-row count (0 on
        // an idempotent re-save under MySQL).
        Pricing::where('id', $id)->update($updateData);

        Cache::forget("all_pricing");
        Server::serverRelatedCacheForget();
        Server::serverSpecificCacheForget($service_id);

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
            'geekbench' => ['required', 'array'],
            'fio' => ['required', 'array'],
            'iperf' => ['required', 'array'],
        ]);

        $insert = $ingest->ingest($request->all(), $server->id);

        if ($insert) {
            Cache::forget('all_active_servers');//all servers cache
            Cache::forget('non_active_servers');//all servers cache
            Cache::forget('all_yabs');//Forget the all YABS cache
            return response()->json(array('message' => 'Successfully added YABS'), 200);
        }

        return response()->json(array('error' => 'Server error'), 500);
    }
}
