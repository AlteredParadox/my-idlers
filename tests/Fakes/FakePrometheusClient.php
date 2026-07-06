<?php

namespace Tests\Fakes;

use App\Services\PrometheusClient;

/**
 * Canned-response Prometheus client. Instant and range results are
 * keyed by a substring of the PromQL query (first match wins);
 * a null value simulates an unreachable Prometheus for that query.
 */
class FakePrometheusClient extends PrometheusClient
{
    public function __construct(
        private array $instant = [],
        private array $range = [],
        private bool $enabled = true,
        private int $interval = 30,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function checkInterval(): int
    {
        return $this->interval;
    }

    private function match(array $map, string $query)
    {
        foreach ($map as $needle => $rows) {
            if (str_contains($query, $needle)) {
                return $rows;
            }
        }

        return [];
    }

    public function rawQuery(string $query): ?array
    {
        $rows = $this->match($this->instant, $query);

        return $rows === null ? null : ['status' => 'success', 'data' => ['result' => $rows]];
    }

    public function query(string $query): array
    {
        $body = $this->rawQuery($query);

        return $body['data']['result'] ?? [];
    }

    public function rangeQuery(string $query, float $start, float $end, int $step): array
    {
        return $this->match($this->range, $query) ?? [];
    }
}
