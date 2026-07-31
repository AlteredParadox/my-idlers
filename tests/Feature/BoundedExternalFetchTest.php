<?php

namespace Tests\Feature;

use App\Models\Pricing;
use App\Services\PrometheusClient;
use App\Services\PrometheusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Support\BoundedHttp;
use Tests\Fakes\FakePrometheusClient;
use Tests\TestCase;

/**
 * A timeout bounds how LONG an upstream reply takes, not how BIG it is. All
 * three of these callers run synchronously on a web request, so a fast
 * endpoint returning an enormous body would be materialized in full -- and,
 * for the JSON ones, decoded into PHP arrays -- before any application limit
 * could apply.
 */
class BoundedExternalFetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_response_within_the_cap_is_decoded()
    {
        Http::fake(['*' => Http::response(json_encode(['ok' => true, 'n' => 1]), 200)]);

        $this->assertSame(['ok' => true, 'n' => 1], BoundedHttp::json('https://example.test/x', 1024));
    }

    public function test_a_response_over_the_cap_is_discarded()
    {
        Http::fake(['*' => Http::response(json_encode(['pad' => str_repeat('A', 5000)]), 200)]);

        $this->assertNull(BoundedHttp::json('https://example.test/x', 1024));
    }

    public function test_a_non_success_status_is_discarded()
    {
        Http::fake(['*' => Http::response('{}', 503)]);

        $this->assertNull(BoundedHttp::json('https://example.test/x', 1024));
    }

    public function test_a_connection_failure_is_swallowed()
    {
        Http::fake(fn() => throw new ConnectionException('dns'));

        $this->assertNull(BoundedHttp::json('https://example.test/x', 1024));
    }

    public function test_a_non_json_body_is_discarded()
    {
        Http::fake(['*' => Http::response('<html>not json</html>', 200)]);

        $this->assertNull(BoundedHttp::json('https://example.test/x', 1024));
    }

    public function test_a_json_scalar_is_not_accepted_as_a_document()
    {
        Http::fake(['*' => Http::response('"just a string"', 200)]);

        $this->assertNull(BoundedHttp::json('https://example.test/x', 1024));
    }

    // ---- exchange rates ---------------------------------------------------

    private function ratesUrl(): void
    {
        config(['services.exchange_rates.url' => 'https://rates.test/latest']);
        Cache::forget('currency_rates');
    }

    public function test_a_successful_rates_response_is_cached_and_used()
    {
        $this->ratesUrl();
        Http::fake(['*' => Http::response(json_encode(['result' => 'success', 'rates' => ['EUR' => 2.0]]), 200)]);

        $this->assertSame(50.0, Pricing::usdEquivalent(100.0, 'EUR'));
        $this->assertNotNull(Cache::get('currency_rates'), 'rates were not cached');
    }

    public function test_an_oversized_rates_response_degrades_instead_of_being_parsed()
    {
        $this->ratesUrl();
        Http::fake(['*' => Http::response(json_encode([
            'result' => 'success',
            'rates' => ['EUR' => 2.0],
            'pad' => str_repeat('A', 4 * 1024 * 1024),
        ]), 200)]);

        // Falls back to 1:1 rather than decoding a body of that size.
        $this->assertSame(100.0, Pricing::usdEquivalent(100.0, 'EUR'));
    }

    public function test_a_rates_response_that_is_not_success_is_rejected()
    {
        $this->ratesUrl();
        Http::fake(['*' => Http::response(json_encode(['result' => 'error']), 200)]);

        $this->assertSame(100.0, Pricing::usdEquivalent(100.0, 'EUR'));
    }

    public function test_no_configured_rates_url_short_circuits()
    {
        config(['services.exchange_rates.url' => '']);
        Cache::forget('currency_rates');
        Http::fake(fn() => throw new \RuntimeException('must not be called'));

        $this->assertSame(100.0, Pricing::usdEquivalent(100.0, 'EUR'));
    }

    // ---- Prometheus -------------------------------------------------------

    public function test_the_prometheus_size_guard_aborts_once_the_cap_is_passed()
    {
        $body = '';
        $overflowed = false;
        $writer = PrometheusClient::sizeCappedWriter($body, $overflowed, 10);

        // Under the cap: cURL is told the whole chunk was consumed.
        $this->assertSame(5, $writer(null, 'AAAAA'));
        $this->assertFalse($overflowed);

        // Over it: a short write, which is cURL's abort signal.
        $this->assertSame(0, $writer(null, 'BBBBBBBBBB'));
        $this->assertTrue($overflowed);
    }

    public function test_the_prometheus_size_guard_allows_a_body_exactly_at_the_cap()
    {
        $body = '';
        $overflowed = false;
        $writer = PrometheusClient::sizeCappedWriter($body, $overflowed, 4);

        $this->assertSame(4, $writer(null, 'ABCD'));
        $this->assertFalse($overflowed, 'the cap is a ceiling, not an exclusive bound');
    }

    /**
     * offlineSince() issues range queries per DOWN target, sequentially. That
     * count is not something the app controls, and it spikes during exactly
     * the outage that makes someone load the status page.
     */
    public function test_offline_since_lookups_are_capped()
    {
        $down = [];
        for ($i = 0; $i < 60; $i++) {
            $down[] = ['metric' => ['instance' => "10.0.0.$i:9100"], 'value' => [0, '0']];
        }

        $client = new FakePrometheusClient(
            instant: ['node_uname_info' => [], 'up{job="node"}' => $down],
            range: ['up{job="node"' => [['values' => [[1, '1']]]]],
        );

        (new PrometheusService($client))->statusPayload();

        $this->assertLessThanOrEqual(25, $client->rangeQueryCount,
            'one status request fanned out past the cap');
        $this->assertGreaterThan(0, $client->rangeQueryCount, 'no lookups happened at all');
    }
}
