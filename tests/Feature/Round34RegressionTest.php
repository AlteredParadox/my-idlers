<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression for the round-34 review finding: the list views' Prometheus
 * host matcher must never cross-match two distinct dotted IPs. The old
 * expressions compared first labels (192.168.1.5 "matched" 192.168.1.6
 * via '192' === '192', and web10 answered for web1 before that), showing
 * one node's up/down state and metrics on another node's row.
 */
class Round34RegressionTest extends TestCase
{
    public function test_list_view_host_matchers_use_exact_semantics()
    {
        // The matcher now lives in ONE shared partial included by both index
        // variants (the round-35 lesson: unify duplicated predicates or they
        // drift), so the exact short-label expression appears exactly once
        // repo-wide and is IP-guarded.
        $safe = "hostname === promHost || promHost === hostname.split('.')[0]\n"
            . "        || hostname === promHost.split('.')[0] || hostname.indexOf(promHost + '.') === 0";

        $partial = file_get_contents(resource_path('views/servers/partials/status-js.blade.php'));

        $this->assertSame(1, substr_count($partial, $safe), 'status-js partial lost the exact matcher');
        // The status loop must route through matchHost, not inline the expression.
        $this->assertStringContainsString('if (matchHost(hostname, promHost)) {', $partial);
        // IP sides fall back to exact equality.
        $this->assertStringContainsString('if (isIpAddress(hostname) || isIpAddress(promHost)) {', $partial);

        // The prefix/first-label holes must not reappear.
        $this->assertStringNotContainsString("promHost.split('.')[0] === hostname.split('.')[0]", $partial);
        $this->assertStringNotContainsString("hostname.indexOf(promHost) === 0", $partial);

        // Both index variants must use the shared partial — no forked copies.
        foreach (['servers/index.blade.php', 'servers/index-cards.blade.php'] as $view) {
            $blade = file_get_contents(resource_path("views/$view"));

            $this->assertStringContainsString("@include('servers.partials.status-js'", $blade, $view);
            $this->assertStringNotContainsString('function matchHost', $blade, "$view must not fork the matcher");
        }
    }

    public function test_matcher_semantics_reject_distinct_ips_and_prefix_hosts()
    {
        // PHP mirror of the JS expression — pins the truth table.
        $match = function (string $hostname, string $promHost): bool {
            $hostShort = explode('.', $hostname)[0];
            $promShort = explode('.', $promHost)[0];

            return $hostname === $promHost
                || $promHost === $hostShort
                || $hostname === $promShort
                || str_starts_with($hostname, $promHost . '.');
        };

        // Must match: the shared list/detail truth table (PromQL::hostMatches).
        $this->assertTrue($match('web1.example.com', 'web1'));
        $this->assertTrue($match('web1', 'web1.example.com'));
        $this->assertTrue($match('192.168.1.6', '192.168.1.6'));

        // Must NOT match: the holes.
        $this->assertFalse($match('192.168.1.6', '192.168.1.5'), 'first-octet equality hole');
        $this->assertFalse($match('web1.example.com', 'web10'), 'prefix hole');
        $this->assertFalse($match('db.example.com', 'db.internal.lan'), 'shared first label hole');
    }
}
