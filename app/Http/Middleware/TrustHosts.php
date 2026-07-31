<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * The APP_URL host EXACTLY, not every subdomain of it. Laravel's stock
     * allSubdomainsOfApplicationUrl() trusts `^(.+\.)?example\.com$`, so
     * anyone controlling any subdomain — a stale DNS entry, a shared-hosting
     * neighbour, a takeover of an unused name — could send a Host header the
     * app accepts and get absolute URLs built against it. Password-reset mail
     * is generated from that host, so the reset link would point at them.
     *
     * This also makes the code match what the README already documents:
     * "requests with any other Host header are rejected in production".
     *
     * These are REGULAR EXPRESSIONS, not literals. Symfony wraps each one as
     * `{pattern}i` and preg_matches it unanchored (Request::setTrustedHosts),
     * so a bare hostname is a substring test: 'example.com' would accept
     * 'example.com.attacker.invalid' — a host-poisoning path straight into
     * emailed password-reset links — and 'evil.example.com', and its
     * unescaped dots would accept 'exampleXcom'. Hence the anchors and
     * preg_quote, matching what Laravel's own allSubdomainsOfApplicationUrl()
     * does for the pattern it builds.
     *
     * @return array
     */
    public function hosts()
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        // No parseable APP_URL: fall back to the framework default rather than
        // trusting nothing, which would reject every request.
        if (!is_string($host) || $host === '') {
            return [$this->allSubdomainsOfApplicationUrl()];
        }

        return ['^' . preg_quote($host) . '$'];
    }
}
