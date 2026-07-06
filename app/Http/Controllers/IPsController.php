<?php

namespace App\Http\Controllers;

use App\Models\IPs;
use App\Models\Note;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class IPsController extends Controller
{
    public function index()
    {
        $ips = IPs::all();
        return view('ips.index', compact(['ips']));
    }

    public function create()
    {
        $servers = Server::all();
        $shareds = Shared::all();
        $resellers = Reseller::all();
        $seed_boxes = SeedBoxes::all();
        return view('ips.create', compact(['servers', 'shareds', 'resellers', 'seed_boxes']));
    }

    public function store(Request $request)
    {
        $request->validate([
            'address' => 'required|ip|min:2',
            'ip_type' => 'required|string|size:4',
            'service_id' => 'required|string'
        ]);

        $ip_id = Str::random(8);

        try {
            $ip = IPs::create([
                'id' => $ip_id,
                'address' => $request->address,
                'is_ipv4' => ($request->ip_type === 'ipv4') ? 1 : 0,
                'service_id' => $request->service_id,
                'active' => 1
            ]);
        } catch (QueryException $e) {
            // Unique (service_id, address) — this IP is already on the service
            return redirect()->route('IPs.index')
                ->with('error', 'That IP address is already assigned to this service.');
        }

        IPs::getUpdateIpInfo($ip);
        self::forgetServiceCaches($ip->service_id);

        return redirect()->route('IPs.index')
            ->with('success', 'IP address created Successfully.');
    }

    public function destroy(IPs $ip)
    {
        $service_id = $ip->service_id;

        if ($ip->delete()) {
            self::forgetServiceCaches($service_id);
            Note::deleteForService($ip->id);

            return redirect()->route('IPs.index')
                ->with('success', 'IP address was deleted Successfully.');
        }
        return redirect()->route('IPs.index')
            ->with('error', 'IP was not deleted.');
    }

    public function getUpdateWhoIs(IPs $ip): \Illuminate\Http\RedirectResponse
    {
        $result = IPs::getUpdateIpInfo($ip);

        if ($result) {
            self::forgetServiceCaches($ip->service_id);

            return redirect()->route('IPs.index')
                ->with('success', 'IP address updated Successfully.');
        }
        return redirect()->route('IPs.index')
            ->with('error', 'IP was not updated.');
    }

    /**
     * The IP's service_id may belong to a server (or shared/reseller/seedbox).
     * The server caches embed the ips relation, so a change to IPs must clear
     * them or the show/index pages show stale IP lists for up to a month.
     */
    private static function forgetServiceCaches(string $service_id): void
    {
        Server::serverSpecificCacheForget($service_id);
        Server::serverRelatedCacheForget();

        // The IP's service may instead be a shared or reseller service, which
        // also cache their ips relation for a month.
        foreach (['shared', 'reseller'] as $type) {
            Cache::forget("all_{$type}");
            Cache::forget("all_active_{$type}");
            Cache::forget("non_active_{$type}");
        }
        Cache::forget("shared_hosting.$service_id");
        Cache::forget("reseller_hosting.$service_id");
    }

}
