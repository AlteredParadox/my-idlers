<?php

namespace App\Services;

/** Maps a tracker hostname/IP to the Prometheus instance that monitors it. */
class PrometheusInstanceResolver
{
    public function __construct(private PrometheusClient $client)
    {
    }

    public function resolve(string $hostname): ?string
    {
        // Try matching by nodename via node_uname_info
        foreach ($this->client->query('node_uname_info{job="node"}') as $r) {
            $nodename = $r['metric']['nodename'] ?? '';
            if (PromQL::hostMatches($hostname, $nodename)) {
                return $r['metric']['instance'] ?? null;
            }
        }

        return $this->fromScrapeTargets($hostname);
    }

    private function fromScrapeTargets(string $hostname): ?string
    {
        // Try matching by instance directly (hostname might be an IP or the
        // scrape target may be an FQDN while the tracker stores a short name)
        $up_results = $this->client->query('up{job="node"}');
        foreach ($up_results as $r) {
            $instance = $r['metric']['instance'] ?? '';
            if (PromQL::hostMatches($hostname, preg_replace('/:\d+$/', '', $instance))) {
                return $instance;
            }
        }

        // Offline nodes vanish from instant uname queries after Prometheus's
        // staleness window, but the LIST still tracks them via last_over_time
        // (resolveOfflineHostnames) — without this pass a down node's detail
        // page 404s ("Failed to load monitoring data") while its history
        // exists and the index shows the downtime counter.
        $lastKnown = null;
        foreach ($up_results as $r) {
            $instance = $r['metric']['instance'] ?? '';
            if ($instance === '' || PromQL::isUp($r)) {
                continue;
            }
            $lastKnown ??= $this->lastKnownNodenames();
            if (isset($lastKnown[$instance]) && PromQL::hostMatches($hostname, $lastKnown[$instance])) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * instance => last-known nodename, one query for every instance rather
     * than one per offline node. Shared by the detail path above and the
     * list path (PrometheusService::resolveOfflineHostnames) so both
     * resolve offline nodes from the same candidate set — keep them unified.
     */
    public function lastKnownNodenames(): array
    {
        $map = [];
        foreach ($this->client->query('last_over_time(node_uname_info{job="node"}[30d])') as $r) {
            $instance = $r['metric']['instance'] ?? '';
            $nodename = $r['metric']['nodename'] ?? '';
            // first result wins, matching the old per-instance query's [0]
            if ($instance !== '' && $nodename !== '' && !isset($map[$instance])) {
                $map[$instance] = $nodename;
            }
        }

        return $map;
    }
}
