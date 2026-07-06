<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Providers;
use App\Models\Server;
use App\Services\PrometheusService;
use Illuminate\Http\Request;

class ToolsController extends Controller
{

    public function getAllProvidersTable(Request $request)
    {
        if ($request->ajax()) {
            $data = Providers::latest()->get();
            return Datatables::of($data)
                ->addIndexColumn()
                ->addColumn('action', function ($row) {
                    return '<form action="' . route('providers.destroy', $row['id']) . '" method="POST"><i class="fas fa-trash text-danger ms-3" @click="modalForm" id="btn-' . $row['name'] . '" title="' . $row['id'] . '"></i> </form>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }
    }


    public function checkHostIsUp(string $hostname)
    {//Check if host/ip is "up" via ping
        $exitCode = 1;
        $pingCmd = stripos(PHP_OS, 'WIN') === 0
            ? "ping -n 1 -w 2000 " . escapeshellarg($hostname)
            : "ping -c 1 -W 2 " . escapeshellarg($hostname);
        exec($pingCmd . " > /dev/null 2>&1", $output, $exitCode);
        return response(array('is_online' => $exitCode === 0), 200);
    }


    public function prometheusStatus(PrometheusService $prometheus)
    {
        if (!$prometheus->isEnabled()) {
            return response()->json(['error' => 'Prometheus integration not enabled'], 404);
        }

        $payload = $prometheus->statusPayload();
        if ($payload === null) {
            return response()->json(['error' => 'Failed to query Prometheus'], 502);
        }

        return response()->json($payload);
    }


    public function prometheusDetail(PrometheusService $prometheus, string $hostname, string $period, int $back)
    {
        if (!$prometheus->isEnabled()) {
            return response()->json(['error' => 'Prometheus not enabled'], 404);
        }

        if (!$prometheus->isValidPeriod($period) || $back < 0) {
            return response()->json(['error' => 'Invalid period'], 400);
        }

        $payload = $prometheus->detailPayload($hostname, $period, $back);
        if ($payload === null) {
            return response()->json(['error' => 'Server not found in Prometheus'], 404);
        }

        return response()->json($payload);
    }


    public function getIpForDomain(string $domainname, string $type)
    {//Gets IP from A record for a domain
        if ($type === "A") {
            $data = dns_get_record($domainname, DNS_A);
            if (isset($data['0']['ip'])) {
                return response(array('ip' => $data['0']['ip']), 200);
            }
        } elseif ($type === "AAAA") {
            $data = dns_get_record($domainname, DNS_AAAA);
            if (isset($data['0']['ipv6'])) {
                return response(array('ip' => $data['0']['ipv6']), 200);
            }
        }
        return response(array('ip' => null), 200);
    }
}
