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
        $safe = "hostname === promHost || promHost === hostname.split('.')[0] || "
            . "hostname === promHost.split('.')[0] || hostname.indexOf(promHost + '.') === 0";

        foreach (['servers/index.blade.php', 'servers/index-cards.blade.php'] as $view) {
            $blade = file_get_contents(resource_path("views/$view"));

            // Both the inline status loop and matchHost() carry the matcher.
            $this->assertSame(2, substr_count($blade, $safe), "$view lost the exact matcher");

            // The two prefix/first-label holes must not reappear.
            $this->assertStringNotContainsString("promHost.split('.')[0] === hostname.split('.')[0]", $blade, $view);
            $this->assertStringNotContainsString("hostname.indexOf(promHost) === 0", $blade, $view);
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

        // Must match: the shapes resolveInstance supports.
        $this->assertTrue($match('web1.example.com', 'web1'));
        $this->assertTrue($match('web1', 'web1.example.com'));
        $this->assertTrue($match('192.168.1.6', '192.168.1.6'));

        // Must NOT match: the holes.
        $this->assertFalse($match('192.168.1.6', '192.168.1.5'), 'first-octet equality hole');
        $this->assertFalse($match('web1.example.com', 'web10'), 'prefix hole');
        $this->assertFalse($match('db.example.com', 'db.internal.lan'), 'shared first label hole');
    }
}
