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
        $matches = [];
        foreach ($this->client->query('node_uname_info{job="node"}') as $r) {
            $nodename = $r['metric']['nodename'] ?? '';
            if (PromQL::hostMatches($hostname, $nodename)) {
                $matches[] = $r['metric']['instance'] ?? null;
            }
        }

        if ($resolved = $this->exactlyOne($matches, $hostname)) {
            return $resolved;
        }

        return $matches === [] ? $this->fromScrapeTargets($hostname) : null;
    }

    /**
     * One instance, or nothing.
     *
     * hostMatches() deliberately accepts a stored SHORT hostname against an
     * FQDN candidate, which is the convenience the whole feature rests on --
     * but it means 'web01' matches both web01.dc1.example.com and
     * web01.dc2.example.com. Returning the first was a silent coin flip: the
     * charts, filesystem list and uptime for one machine shown under the
     * other's name, with nothing on screen saying so.
     *
     * Ambiguity resolves to null instead. "No monitoring data" is a visible,
     * correctable condition -- disambiguate by storing the FQDN -- whereas
     * confidently wrong data is not.
     */
    private function exactlyOne(array $matches, string $hostname): ?string
    {
        $distinct = array_values(array_unique(array_filter($matches)));

        if (count($distinct) === 1) {
            return $distinct[0];
        }

        if (count($distinct) > 1) {
            \Illuminate\Support\Facades\Log::warning(
                'Prometheus hostname is ambiguous; refusing to bind it to any instance',
                ['hostname' => $hostname, 'candidates' => $distinct]
            );
        }

        return null;
    }

    private function fromScrapeTargets(string $hostname): ?string
    {
        // Try matching by instance directly (hostname might be an IP or the
        // scrape target may be an FQDN while the tracker stores a short name)
        $up_results = $this->client->query('up{job="node"}');
        $matches = [];
        foreach ($up_results as $r) {
            $instance = $r['metric']['instance'] ?? '';
            if (PromQL::hostMatches($hostname, preg_replace('/:\d+$/', '', $instance))) {
                $matches[] = $instance;
            }
        }

        if ($resolved = $this->exactlyOne($matches, $hostname)) {
            return $resolved;
        }
        if ($matches !== []) {
            return null;   // ambiguous, not "keep looking"
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
                $matches[] = $instance;
            }
        }

        return $this->exactlyOne($matches, $hostname);
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
