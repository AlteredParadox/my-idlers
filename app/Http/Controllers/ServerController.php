<?php

namespace App\Http\Controllers;

use App\Models\Disk;
use App\Models\Note;
use App\Models\IPs;
use App\Models\Labels;
use App\Models\Pricing;
use App\Models\Server;
use App\Models\Settings;
use App\Models\Yabs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServerController extends Controller
{

    public function index()
    {
        $servers = Server::allActiveServers();
        $non_active_servers = Server::allNonActiveServers();
        $settings = Settings::getSettings();
        $view = $settings->servers_index_cards ? 'servers.index-cards' : 'servers.index';
        return view($view, compact(['servers', 'non_active_servers']));
    }

    public function showServersPublic()
    {
        // Gate on live settings, not the per-session snapshot: session copies
        // are written once per visitor and never re-synced, so toggling the
        // setting off left existing anonymous sessions with full access.
        $settings = Settings::getSettings();
        if ((int) $settings->show_servers_public === 1) {
            $servers = Server::allPublicServers();
            return view('servers.public-index', compact('servers', 'settings'));
        }
        abort(404);
    }

    public function create()
    {
        return view('servers.create');
    }

    /**
     * Identical for store and update except ip2: on store a duplicate of ip1
     * hits the (service_id, address) unique index as a QueryException 500
     * (leaving an orphaned pricing row); the update path dedupes via
     * IPs::syncForService instead.
     */
    private function rules(bool $for_store): array
    {
        return [
            'hostname' => 'required|min:5|max:255',
            'ip1' => 'sometimes|nullable|ip',
            'ip2' => $for_store ? 'sometimes|nullable|ip|different:ip1' : 'sometimes|nullable|ip',
            'ns1' => 'sometimes|nullable|string|max:255',
            'ns2' => 'sometimes|nullable|string|max:255',
            'server_type' => 'integer|in:1,2,3,4,5,6,7',
            'ssh_port' => 'integer|min:1|max:65535',
            'bandwidth' => 'integer|min:0|max:100000000',
            'link_speed' => 'sometimes|nullable|numeric|min:0|max:1000000',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'network_type' => 'sometimes|nullable|string|in:IPv4,IPv6,IPv4+IPv6,IPv4 NAT,IPv4 NAT + IPv6',
            // ram cap: the GB→MB derivation (*1024) must fit the int
            // ram_as_mb column — 2097151 GB is the largest safe input
            'ram' => 'required|numeric|min:0|max:2097151',
            // in: rules — these land in char(2)/varchar(4) columns, so a
            // forged value is a MySQL-strict truncation 500 without them
            'ram_type' => 'required|in:MB,GB',
            'disk' => 'required|array',
            'disk.*' => 'required|integer|min:1|max:1000000',
            'disk_type' => 'required|array',
            'disk_type.*' => 'required|in:GB,TB',
            'disk_media' => 'required|array',
            'disk_media.*' => 'required|in:SSD,HDD,NVMe',
            'os_id' => 'required|integer|exists:os,id',
            'provider_id' => 'required|integer|exists:providers,id',
            'location_id' => 'required|integer|exists:locations,id',
            ...\App\Models\Pricing::webValidationRules(),
            'cpu' => 'required|integer|min:1|max:1024',
            'cpu_model' => 'sometimes|nullable|string|max:255',
            'was_promo' => 'integer|in:0,1',
            'owned_since' => 'sometimes|nullable|date_format:Y-m-d',
            ...\App\Models\Labels::validationRules(),
        ];
    }

    public function store(Request $request)
    {
        // Lowercase before validation: different:ip1 is case-sensitive but
        // the (service_id, address) unique index is not — an IPv6
        // case-variant pair would pass the rule and 500 on the second insert
        foreach (['ip1', 'ip2'] as $ip_field) {
            if (is_string($request->input($ip_field))) {
                $request->merge([$ip_field => strtolower($request->input($ip_field))]);
            }
        }

        $request->validate($this->rules(true));

        $this->assertDiskArraysAligned($request);

        $link_speed_mbps = $this->linkSpeedAsMbps($request);

        $server_id = Str::random(8);

        $total_disk_gb = $this->totalDiskAsGb($request);

        // Atomic: pricing/IPs insert first (FK order), so a failed server
        // insert would otherwise orphan them.
        DB::transaction(function () use ($request, $server_id, $link_speed_mbps, $total_disk_gb) {
            (new Pricing())->insertPricing(1, $server_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

            if (!is_null($request->ip1)) {
                IPs::insertIP($server_id, $request->ip1);
            }

            if (!is_null($request->ip2)) {
                IPs::insertIP($server_id, $request->ip2);
            }

            Server::create([
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
            'disk' => $request->disk[0],
            'disk_type' => $request->disk_type[0],
            'disk_as_gb' => $total_disk_gb,
            'owned_since' => $request->owned_since,
            'ns1' => $request->ns1,
            'ns2' => $request->ns2,
            'bandwidth' => $request->bandwidth,
            'link_speed' => $link_speed_mbps,
            'network_type' => $request->network_type,
            'cpu' => $request->cpu,
            'cpu_model' => $request->cpu_model,
            'was_promo' => $request->was_promo,
                'transferrable' => (isset($request->transferrable)) ? 1 : 0,
                'show_public' => (isset($request->show_public)) ? 1 : 0
            ]);

            foreach ($request->disk as $i => $disk_size) {
                Disk::insertDisk($server_id, $disk_size, $request->disk_type[$i], $request->disk_media[$i]);
            }

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $server_id);
        });

        Server::serverRelatedCacheForget();

        return redirect()->route('servers.index')
            ->with('success', 'Server Created Successfully.');
    }

    public function show(Server $server)
    {
        $server_data = Server::server($server->id);

        return view('servers.show', compact(['server_data']));
    }

    public function edit(Server $server)
    {
        $server_data = Server::server($server->id);

        return view('servers.edit', compact(['server_data']));
    }

    public function update(Request $request, Server $server)
    {
        $request->validate($this->rules(false));

        $this->assertDiskArraysAligned($request);

        $ip_fields = $this->collectAndValidateIpFields($request);

        $link_speed_mbps = $this->linkSpeedAsMbps($request);

        $is_active = (isset($request->is_active)) ? 1 : 0;

        $total_disk_gb = $this->totalDiskAsGb($request);

        // Atomic: a failure in any later write (pricing, labels, disks, IPs)
        // must not leave a partially updated server; caches are only cleared
        // after the whole sequence commits.
        $updated = DB::transaction(function () use ($request, $server, $is_active, $link_speed_mbps, $total_disk_gb, $ip_fields) {
            if (!$this->lockedRowStillExists($server)) {
                return false;
            }
            $server->update([
                'hostname' => $request->hostname,
                'server_type' => $request->server_type,
                'os_id' => $request->os_id,
                'ssh' => $request->ssh_port,
                'provider_id' => $request->provider_id,
                'location_id' => $request->location_id,
                'ram' => $request->ram,
                'ram_type' => $request->ram_type,
                'ram_as_mb' => ($request->ram_type === 'MB') ? $request->ram : ($request->ram * 1024),
                'disk' => $request->disk[0],
                'disk_type' => $request->disk_type[0],
                'disk_as_gb' => $total_disk_gb,
                'owned_since' => $request->owned_since,
                'ns1' => $request->ns1,
                'ns2' => $request->ns2,
                'bandwidth' => $request->bandwidth,
                'link_speed' => $link_speed_mbps,
                'network_type' => $request->network_type,
                'cpu' => $request->cpu,
                'cpu_model' => $request->cpu_model,
                'was_promo' => $request->was_promo,
                'transferrable' => (isset($request->transferrable)) ? 1 : 0,
                'active' => $is_active,
                'show_public' => (isset($request->show_public)) ? 1 : 0
            ]);

            (new Pricing())->updatePricing($server->id, $request->currency, $request->price, $request->payment_term, $request->next_due_date, $is_active);

            Labels::deleteLabelsAssignedTo($server->id);

            Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $server->id);

            Disk::deleteDisksForServer($server->id);
            foreach ($request->disk as $i => $disk_size) {
                Disk::insertDisk($server->id, $disk_size, $request->disk_type[$i], $request->disk_media[$i]);
            }

            IPs::syncForService($server->id, array_values($ip_fields));

            return true;
        });

        if (!$updated) {
            return redirect()->route('servers.index')
                ->with('error', 'Server no longer exists.');
        }

        Server::serverRelatedCacheForget();
        Server::serverSpecificCacheForget($server->id);

        return redirect()->route('servers.index')
            ->with('success', 'Server Updated Successfully.');
    }

    public function destroy(Server $server)
    {
        // Atomic: child rows (pricing, labels, IPs, disks, notes, YABS) have
        // no DB cascades — a failure mid-cleanup must not orphan them behind
        // an already-deleted server.
        $deleted = DB::transaction(function () use ($server) {
            if (!$server->delete()) {
                return false;
            }
            (new Pricing())->deletePricing($server->id);
            Labels::deleteLabelsAssignedTo($server->id);
            IPs::deleteIPsAssignedTo($server->id);
            Disk::deleteDisksForServer($server->id);
            Note::deleteForService($server->id);
            Yabs::deleteForServer($server->id);
            return true;
        });

        if ($deleted) {
            Server::serverRelatedCacheForget();
            Server::serverSpecificCacheForget($server->id);

            return redirect()->route('servers.index')
                ->with('success', 'Server was deleted Successfully.');
        }

        return redirect()->route('servers.index')
            ->with('error', 'Server was not deleted.');
    }

    private function linkSpeedAsMbps(Request $request): ?int
    {
        if (!$request->link_speed) {
            return null;
        }

        return (int) ($request->link_speed_type === 'Gbps' ? $request->link_speed * 1000 : $request->link_speed);
    }

    /** Total disk for the backward-compat servers.disk_as_gb column */
    private function totalDiskAsGb(Request $request): int
    {
        $total_disk_gb = 0;
        foreach ($request->disk as $i => $disk_size) {
            $total_disk_gb += ($request->disk_type[$i] === 'TB') ? ($disk_size * 1024) : $disk_size;
        }

        return $total_disk_gb;
    }

    /**
     * The edit form renders one ipN field per assigned IP (not just two):
     * validate them ALL so garbage in ip3+ is rejected, and collect them all
     * so a 9th+ IP isn't silently deleted on save.
     */
    private function collectAndValidateIpFields(Request $request): array
    {
        $ip_fields = [];
        foreach ($request->all() as $key => $value) {
            if (preg_match('/^ip\d+$/', $key) && !is_null($value)) {
                $ip_fields[$key] = $value;
            }
        }
        $request->validate(array_fill_keys(array_keys($ip_fields), 'ip'));

        return $ip_fields;
    }

    /**
     * disk[], disk_type[], disk_media[] are validated as independent arrays;
     * the insert loop assumes identical indexes. A forged request with
     * unequal counts otherwise 500s mid-loop AFTER the server/pricing/labels
     * writes (a partial update). Reject the mismatch before any write.
     */
    private function assertDiskArraysAligned(Request $request): void
    {
        $count = count((array) $request->disk);
        if (count((array) $request->disk_type) !== $count || count((array) $request->disk_media) !== $count) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'disk' => 'Each disk must have a matching type and media.',
            ]);
        }
    }

    public function chooseCompare()
    {//NOTICE: Selecting servers is not cached yet
        $all_servers = Server::where('has_yabs', 1)->get();

        if (isset($all_servers[1])) {
            return view('servers.choose-compare', compact('all_servers'));
        }

        return redirect()->route('servers.index')
            ->with('error', 'You need atleast 2 servers with a YABS to do a compare');
    }

    public function compareServers($server1, $server2)
    {
        $server1_data = Server::server($server1);

        if (!$server1_data || !isset($server1_data->yabs[0])) {
            abort(404);
        }

        $server2_data = Server::server($server2);

        if (!$server2_data || !isset($server2_data->yabs[0])) {
            abort(404);
        }
        
        // Wrap in array for view compatibility
        $server1_data = [$server1_data];
        $server2_data = [$server2_data];
        
        return view('servers.compare', compact('server1_data', 'server2_data'));
    }
}
