<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Configured via TRUSTED_PROXIES env: '*' to trust any upstream proxy,
     * or a comma-separated list of proxy IPs/CIDRs. Required when TLS
     * terminates at a reverse proxy, otherwise signed URLs (YABS ingest)
     * fail validation because the request scheme is seen as http.
     *
     * @var array|string|null
     */
    protected $proxies;

    public function __construct()
    {
        $trusted = config('app.trusted_proxies');

        if ($trusted) {
            $this->proxies = $trusted === '*' ? '*' : array_map('trim', explode(',', $trusted));
        }
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
