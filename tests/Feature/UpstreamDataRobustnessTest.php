<?php

namespace Tests\Feature;

use App\Models\Pricing;
use App\Models\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Exchange rates come from an external provider and are cached for a week, so
 * one bad response is used in pricing arithmetic for a long time.
 * usdEquivalent() DIVIDES by the rate: a zero is a DivisionByZeroError, and a
 * negative or non-numeric one silently writes a corrupted as_usd that outlives
 * the response that caused it.
 */
class UpstreamDataRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private function withCachedRate(mixed $rate): void
    {
        Cache::put('currency_rates', (object) ['EUR' => $rate, 'USD' => 1], now()->addWeek());
    }

    public static function hostileRates(): array
    {
        return [
            'zero'            => [0],
            'zero float'      => [0.0],
            'negative'        => [-1.5],
            'string word'     => ['not-a-rate'],
            'empty string'    => [''],
            'boolean'         => [true],
            'array'           => [[1.2]],
            'null'            => [null],
            'NAN'             => [NAN],
            'INF'             => [INF],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileRates')]
    public function test_an_unusable_rate_degrades_to_one_to_one_instead_of_crashing(mixed $rate)
    {
        $this->withCachedRate($rate);

        $usd = Pricing::usdEquivalent(100.0, 'EUR');

        $this->assertIsFloat($usd);
        $this->assertTrue(is_finite($usd), 'a non-finite amount would poison every total built on it');
        $this->assertSame(100.0, $usd, 'an unusable rate must fall back to 1:1');
    }

    public function test_a_usable_rate_is_still_applied()
    {
        $this->withCachedRate(2.0);

        // 100 EUR at 2.0 EUR-per-USD is 50 USD.
        $this->assertSame(50.0, Pricing::usdEquivalent(100.0, 'EUR'));
    }

    public function test_a_numeric_string_rate_is_accepted()
    {
        // JSON providers legitimately return numbers as strings.
        $this->withCachedRate('2.0');

        $this->assertSame(50.0, Pricing::usdEquivalent(100.0, 'EUR'));
    }

    public function test_conversion_out_of_usd_survives_an_unusable_rate()
    {
        $this->withCachedRate(0);

        $this->assertSame(100.0, Pricing::convertFromUSD('100', 'EUR'));
    }

    /**
     * The whole point of the guard: a zero rate used to reach a division.
     */
    public function test_a_zero_rate_does_not_raise_a_division_error()
    {
        $this->withCachedRate(0);

        try {
            Pricing::usdEquivalent(10.0, 'EUR');
        } catch (\DivisionByZeroError $e) {
            $this->fail('a zero exchange rate reached the division');
        }

        $this->assertTrue(true);
    }

    /**
     * Public server page: YABS-derived fields must honour the Show YABS
     * setting, like the Geekbench and disk-speed cells already did.
     */
    public function test_public_page_hides_yabs_derived_cpu_and_ram_when_show_yabs_is_off()
    {
        $view = file_get_contents(resource_path('views/servers/public-index.blade.php'));

        // Count the gate against the YABS-derived cells: cpu_freq, measured
        // RAM, and the Geekbench/disk cells that were already gated.
        $gated = substr_count($view, 'show_server_value_yabs === 1');

        $this->assertGreaterThanOrEqual(6, $gated, 'a YABS-derived public field lost its gate');

        // cpu_freq must not be rendered outside a gate.
        $this->assertStringNotContainsString(
            '<td class="text-center text-nowrap">{{ $s->yabs[0]->cpu_freq ?? \'—\' }}</td>',
            $view,
            'cpu_freq is rendered ungated on the public page'
        );
    }
}
