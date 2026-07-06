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
use Illuminate\Support\Facades\Session;
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

    public function store(Request $request)
    {
        $request->validate([
            'hostname' => 'required|min:5',
            'ip1' => 'sometimes|nullable|ip',
            'ip2' => 'sometimes|nullable|ip',
            'ns1' => 'sometimes|nullable|string',
            'ns2' => 'sometimes|nullable|string',
            'server_type' => 'integer',
            'ssh_port' => 'integer',
            'bandwidth' => 'integer',
            'link_speed' => 'sometimes|nullable|numeric',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'network_type' => 'sometimes|nullable|string|in:IPv4,IPv6,IPv4+IPv6,IPv4 NAT,IPv4 NAT + IPv6',
            'ram' => 'required|numeric',
            'disk' => 'required|array',
            'disk.*' => 'required|integer',
            'disk_type' => 'required|array',
            'disk_type.*' => 'required|string',
            'disk_media' => 'required|array',
            'disk_media.*' => 'required|string',
            'os_id' => 'required|integer',
            'provider_id' => 'required|integer',
            'location_id' => 'required|integer',
            'price' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'payment_term' => 'required|integer',
            'cpu' => 'required|integer',
            'cpu_model' => 'sometimes|nullable|string|max:255',
            'was_promo' => 'integer',
            'next_due_date' => 'sometimes|nullable|date',
            'owned_since' => 'sometimes|nullable|date',
            'label1' => 'sometimes|nullable|string|exists:labels,id',
            'label2' => 'sometimes|nullable|string|exists:labels,id',
            'label3' => 'sometimes|nullable|string|exists:labels,id',
            'label4' => 'sometimes|nullable|string|exists:labels,id',
        ]);

        $link_speed_mbps = null;
        if ($request->link_speed) {
            $link_speed_mbps = (int)($request->link_speed_type === 'Gbps' ? $request->link_speed * 1000 : $request->link_speed);
        }

        $server_id = Str::random(8);

        $pricing = new Pricing();
        $pricing->insertPricing(1, $server_id, $request->currency, $request->price, $request->payment_term, $request->next_due_date);

        if (!is_null($request->ip1)) {
            IPs::insertIP($server_id, $request->ip1);
        }

        if (!is_null($request->ip2)) {
            IPs::insertIP($server_id, $request->ip2);
        }

        // Calculate total disk for backward compat columns
        $total_disk_gb = 0;
        foreach ($request->disk as $i => $disk_size) {
            $unit = $request->disk_type[$i];
            $total_disk_gb += ($unit === 'TB') ? ($disk_size * 1024) : $disk_size;
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
        $request->validate([
            'hostname' => 'required|min:5',
            'ip1' => 'sometimes|nullable|ip',
            'ip2' => 'sometimes|nullable|ip',
            'ns1' => 'sometimes|nullable|string',
            'ns2' => 'sometimes|nullable|string',
            'server_type' => 'integer',
            'ssh_port' => 'integer',
            'bandwidth' => 'integer',
            'link_speed' => 'sometimes|nullable|numeric',
            'link_speed_type' => 'sometimes|nullable|string|in:Mbps,Gbps',
            'network_type' => 'sometimes|nullable|string|in:IPv4,IPv6,IPv4+IPv6,IPv4 NAT,IPv4 NAT + IPv6',
            'ram' => 'required|numeric',
            'disk' => 'required|array',
            'disk.*' => 'required|integer',
            'disk_type' => 'required|array',
            'disk_type.*' => 'required|string',
            'disk_media' => 'required|array',
            'disk_media.*' => 'required|string',
            'os_id' => 'required|integer',
            'provider_id' => 'required|integer',
            'location_id' => 'required|integer',
            'price' => 'required|numeric',
            'currency' => 'required|string|size:3',
            'payment_term' => 'required|integer',
            'cpu' => 'required|integer',
            'cpu_model' => 'sometimes|nullable|string|max:255',
            'was_promo' => 'integer',
            'next_due_date' => 'sometimes|nullable|date',
            'owned_since' => 'sometimes|nullable|date',
            'label1' => 'sometimes|nullable|string|exists:labels,id',
            'label2' => 'sometimes|nullable|string|exists:labels,id',
            'label3' => 'sometimes|nullable|string|exists:labels,id',
            'label4' => 'sometimes|nullable|string|exists:labels,id',
        ]);

        $link_speed_mbps = null;
        if ($request->link_speed) {
            $link_speed_mbps = (int)($request->link_speed_type === 'Gbps' ? $request->link_speed * 1000 : $request->link_speed);
        }

        $is_active = (isset($request->is_active)) ? 1 : 0;

        // Calculate total disk for backward compat columns
        $total_disk_gb = 0;
        foreach ($request->disk as $i => $disk_size) {
            $unit = $request->disk_type[$i];
            $total_disk_gb += ($unit === 'TB') ? ($disk_size * 1024) : $disk_size;
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

        $pricing = new Pricing();
        $pricing->updatePricing($server->id, $request->currency, $request->price, $request->payment_term, $request->next_due_date, $is_active);

        Labels::deleteLabelsAssignedTo($server->id);

        Labels::insertLabelsAssigned([$request->label1, $request->label2, $request->label3, $request->label4], $server->id);

        Disk::deleteDisksForServer($server->id);
        foreach ($request->disk as $i => $disk_size) {
            Disk::insertDisk($server->id, $disk_size, $request->disk_type[$i], $request->disk_media[$i]);
        }

        $submitted_ips = [];
        for ($i = 1; $i <= 8; $i++) {//Max of 8 ips
            $obj = 'ip' . $i;
            if (isset($request->$obj) && !is_null($request->$obj)) {
                $submitted_ips[] = $request->$obj;
            }
        }
        IPs::syncForService($server->id, $submitted_ips);

        Server::serverRelatedCacheForget();
        Server::serverSpecificCacheForget($server->id);

        return redirect()->route('servers.index')
            ->with('success', 'Server Updated Successfully.');
    }

    public function destroy(Server $server)
    {
        if ($server->delete()) {
            $p = new Pricing();
            $p->deletePricing($server->id);

            Labels::deleteLabelsAssignedTo($server->id);

            IPs::deleteIPsAssignedTo($server->id);

            Disk::deleteDisksForServer($server->id);

            Note::deleteForService($server->id);

            Yabs::deleteForServer($server->id);

            Server::serverRelatedCacheForget();
            Server::serverSpecificCacheForget($server->id);

            return redirect()->route('servers.index')
                ->with('success', 'Server was deleted Successfully.');
        }

        return redirect()->route('servers.index')
            ->with('error', 'Server was not deleted.');
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
