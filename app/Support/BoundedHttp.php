<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GET a JSON document with a ceiling on how many bytes are read.
 *
 * A timeout bounds how LONG a response takes, not how BIG it is. Both callers
 * here run on a synchronous web request against a third party -- the
 * exchange-rate provider and ipwhois.app -- and a fast endpoint returning an
 * enormous body is materialised into a PHP string and then into arrays before
 * any application limit applies, exhausting the worker.
 *
 * Reading through a stream and stopping at the cap means a hostile or broken
 * upstream costs a bounded amount of memory regardless of what it sends, and
 * regardless of whether it declares Content-Length (a chunked response does
 * not, so a header check alone would not hold).
 */
class BoundedHttp
{
    private const CHUNK = 8192;

    /**
     * @return array|null decoded JSON, or null on failure/oversize
     */
    public static function json(string $url, int $maxBytes, int $timeout = 5, int $connectTimeout = 3): ?array
    {
        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('bounded fetch failed', ['url' => $url, 'err' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (!$stream->eof()) {
            $body .= $stream->read(self::CHUNK);

            if (strlen($body) > $maxBytes) {
                Log::warning('upstream response exceeded its size cap; discarding', [
                    'url' => $url,
                    'limit_bytes' => $maxBytes,
                ]);

                return null;
            }
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
