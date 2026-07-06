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
        foreach ($up_results as $r) {
            $instance = $r['metric']['instance'] ?? '';
            if ($instance === '' || PromQL::isUp($r)) {
                continue;
            }
            $data = $this->client->query('last_over_time(node_uname_info{job="node",instance="' . PromQL::quote($instance) . '"}[30d])');
            if (isset($data[0]['metric']['nodename']) && PromQL::hostMatches($hostname, $data[0]['metric']['nodename'])) {
                return $instance;
            }
        }

        return null;
    }
}
