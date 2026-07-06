<?php

namespace App\Services;

use App\Models\Settings;

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

    private function fetch(string $url, int $timeout = 5): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            // Restrict to HTTP(S) so a crafted prometheus_url can't reach
            // file://, gopher://, dict:// etc. Private/internal addresses are
            // intentionally allowed: a self-hosted Prometheus normally lives
            // on a private network (the documented default is prometheus:9090).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return null;
        }

        return json_decode($response, true);
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
