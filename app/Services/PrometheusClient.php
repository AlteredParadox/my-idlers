<?php

namespace App\Services;

use App\Models\Settings;
use Illuminate\Support\Facades\Log;

class PrometheusClient
{
    public const PERIODS = [
        '6h'  => ['seconds' => 21600,    'step' => 60],
        '12h' => ['seconds' => 43200,    'step' => 120],
        '24h' => ['seconds' => 86400,    'step' => 240],
        '3d'  => ['seconds' => 259200,   'step' => 720],
        '7d'  => ['seconds' => 604800,   'step' => 1680],
        '14d' => ['seconds' => 1209600,  'step' => 3360],
        '28d' => ['seconds' => 2419200,  'step' => 6720],
        '3m'  => ['seconds' => 7776000,  'step' => 21600],
        '6m'  => ['seconds' => 15552000, 'step' => 43200],
        '1y'  => ['seconds' => 31536000, 'step' => 86400],
    ];

    private ?object $settings = null;

    private function settings(): object
    {
        return $this->settings ??= Settings::getSettings();
    }

    public function isEnabled(): bool
    {
        $settings = $this->settings();

        return (bool)($settings->prometheus_enabled ?? false) && !empty($settings->prometheus_url);
    }

    public function isValidPeriod(string $period): bool
    {
        return isset(self::PERIODS[$period]);
    }

    public function checkInterval(): int
    {
        return $this->settings()->prometheus_check_interval ?? 20;
    }

    private function baseUrl(): string
    {
        return rtrim($this->settings()->prometheus_url, '/');
    }

    /**
     * Ceiling on one Prometheus response body.
     *
     * A timeout bounds how LONG a response takes, not how BIG it is: a fast
     * upstream returning a huge result set is decoded into PHP arrays in one
     * go, and json_decode of a multi-hundred-megabyte body exhausts the worker
     * before any application limit applies. 8 MB is far above a normal reply
     * from this app's queries (a few hundred series at most).
     */
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /**
     * The size guard itself, as a cURL write callback.
     *
     * Extracted so the control can be exercised without a network: it is the
     * piece that decides when to abandon a response, and "returned a short
     * count so cURL aborts" is not something a mocked client would ever show.
     *
     * @param string $body        accumulates the bytes seen so far
     * @param bool   $overflowed  set once the cap is passed
     */
    public static function sizeCappedWriter(string &$body, bool &$overflowed, int $limit): \Closure
    {
        return function ($ch, string $chunk) use (&$body, &$overflowed, $limit): int {
            $body .= $chunk;

            if (strlen($body) > $limit) {
                $overflowed = true;

                // A write count that differs from the chunk length is cURL's
                // documented signal to abort the transfer.
                return 0;
            }

            return strlen($chunk);
        };
    }

    private function fetch(string $url, int $timeout = 5): ?array
    {
        $body = '';
        $overflowed = false;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            // Collected through a write callback rather than RETURNTRANSFER so
            // the transfer can be aborted mid-body once it exceeds the cap,
            // instead of buffering all of it and checking afterwards.
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_WRITEFUNCTION => self::sizeCappedWriter($body, $overflowed, self::MAX_RESPONSE_BYTES),
            // Restrict to HTTP(S) so a crafted prometheus_url can't reach
            // file://, gopher://, dict:// etc. Private/internal addresses are
            // intentionally allowed: a self-hosted Prometheus normally lives
            // on a private network (the documented default is prometheus:9090).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($overflowed) {
            Log::warning('Prometheus response exceeded the response-size cap; discarding', [
                'limit_bytes' => self::MAX_RESPONSE_BYTES,
            ]);

            return null;
        }

        if ($httpCode !== 200 || $ok === false) {
            return null;
        }

        return json_decode($body, true);
    }

    /** Full response body for an instant query, or null on failure */
    public function rawQuery(string $query): ?array
    {
        return $this->fetch($this->baseUrl() . '/api/v1/query?' . http_build_query(['query' => $query]));
    }

    /** Result rows for an instant query; empty array on failure */
    public function query(string $query): array
    {
        $body = $this->rawQuery($query);

        return $body['data']['result'] ?? [];
    }

    /** Result rows for a range query; empty array on failure */
    public function rangeQuery(string $query, float $start, float $end, int $step): array
    {
        $body = $this->fetch($this->baseUrl() . '/api/v1/query_range?' . http_build_query([
            'query' => $query, 'start' => $start, 'end' => $end, 'step' => $step,
        ]), 10);

        return $body['data']['result'] ?? [];
    }
}
