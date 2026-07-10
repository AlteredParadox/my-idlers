<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PrometheusService;

class ToolsController extends Controller
{
    public function checkHostIsUp(string $hostname)
    {//Check if host/ip is "up" via ping
        // escapeshellarg stops metacharacter injection, but a leading-dash
        // value ('-V', '-f') is still consumed by ping as an OPTION, altering
        // behaviour / faking "online". Accept a validator-confirmed IP (a
        // valid IP can never start with '-', and edge-compressed IPv6 like
        // '::1' fails the label regex's alnum-edges rule) or a real host
        // label — the web route's regex permits a leading dash and the API
        // route had no constraint at all, so enforce it here for both.
        if (!filter_var($hostname, FILTER_VALIDATE_IP)
            && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9._:-]*[A-Za-z0-9])?$/D', $hostname)) {
            return response(array('is_online' => false, 'error' => 'Invalid hostname'), 422);
        }

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
        $error = match (true) {
            !$prometheus->isEnabled() => ['Prometheus not enabled', 404],
            !$prometheus->isValidPeriod($period) || $back < 0 => ['Invalid period', 400],
            default => null,
        };

        if ($error !== null) {
            return response()->json(['error' => $error[0]], $error[1]);
        }

        $payload = $prometheus->detailPayload($hostname, $period, $back);

        return $payload === null
            ? response()->json(['error' => 'Server not found in Prometheus'], 404)
            : response()->json($payload);
    }


    public function getIpForDomain(string $domainname, string $type)
    {//Gets IP from A record for a domain
        // @-suppressed: dns_get_record raises E_WARNING (→ ErrorException →
        // 500) on SERVFAIL — exactly the broken domains this tool diagnoses.
        if ($type === "A") {
            $data = @dns_get_record($domainname, DNS_A) ?: [];
            if (isset($data['0']['ip'])) {
                return response(array('ip' => $data['0']['ip']), 200);
            }
        } elseif ($type === "AAAA") {
            $data = @dns_get_record($domainname, DNS_AAAA) ?: [];
            if (isset($data['0']['ipv6'])) {
                return response(array('ip' => $data['0']['ipv6']), 200);
            }
        }
        return response(array('ip' => null), 200);
    }
}
