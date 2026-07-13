<?php

namespace Tests\Unit;

use App\Models\Pricing;
use PHPUnit\Framework\TestCase;

class PricingModelTest extends TestCase
{
    protected Pricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new Pricing();
    }

    public function test_cost_as_per_month_returns_same_for_monthly_term()
    {
        $this->assertEquals(10.00, $this->pricing->costAsPerMonth('10.00', 1));
    }

    public function test_cost_as_per_month_divides_by_3_for_quarterly_term()
    {
        $result = $this->pricing->costAsPerMonth('30.00', 2);
        $this->assertEquals(10.00, $result);
    }

    public function test_cost_as_per_month_divides_by_6_for_semi_annual_term()
    {
        $result = $this->pricing->costAsPerMonth('60.00', 3);
        $this->assertEquals(10.00, $result);
    }

    public function test_cost_as_per_month_divides_by_12_for_annual_term()
    {
        $result = $this->pricing->costAsPerMonth('120.00', 4);
        $this->assertEquals(10.00, $result);
    }

    public function test_cost_as_per_month_divides_by_24_for_biennial_term()
    {
        $result = $this->pricing->costAsPerMonth('240.00', 5);
        $this->assertEquals(10.00, $result);
    }

    public function test_cost_as_per_month_divides_by_36_for_triennial_term()
    {
        $result = $this->pricing->costAsPerMonth('360.00', 6);
        $this->assertEquals(10.00, $result);
    }

    public function test_term_as_months_returns_1_for_monthly()
    {
        $this->assertEquals(1, $this->pricing->termAsMonths(1));
    }

    public function test_term_as_months_returns_3_for_quarterly()
    {
        $this->assertEquals(3, $this->pricing->termAsMonths(2));
    }

    public function test_term_as_months_returns_6_for_semi_annual()
    {
        $this->assertEquals(6, $this->pricing->termAsMonths(3));
    }

    public function test_term_as_months_returns_12_for_annual()
    {
        $this->assertEquals(12, $this->pricing->termAsMonths(4));
    }

    public function test_term_as_months_returns_24_for_biennial()
    {
        $this->assertEquals(24, $this->pricing->termAsMonths(5));
    }

    public function test_term_as_months_returns_36_for_triennial()
    {
        $this->assertEquals(36, $this->pricing->termAsMonths(6));
    }

    public function test_cost_as_per_month_returns_zero_for_one_time_term()
    {
        $this->assertEquals(0, $this->pricing->costAsPerMonth('50.00', 7));
    }

    public function test_term_as_months_returns_0_for_one_time()
    {
        $this->assertEquals(0, $this->pricing->termAsMonths(7));
    }

    public function test_term_as_months_returns_0_for_unknown_term()
    {
        // Unknown terms must NOT advance a due date (was 62 months — a
        // forged/legacy payment_term=99 would jump the date 5+ years).
        $this->assertEquals(0, $this->pricing->termAsMonths(99));
    }

    public function test_usd_per_year_returns_exact_stored_price_for_annual_term()
    {
        // Regression: views computed usd_per_month * 12, so a 44.46/yr
        // service displayed 44.52 (round(44.46/12) = 3.71, * 12).
        $pricing = new Pricing(['as_usd' => 44.46, 'term' => 4]);

        $this->assertSame(44.46, $pricing->usdPerYear());
    }

    public function test_usd_per_year_multiplies_by_12_for_monthly_term()
    {
        $pricing = new Pricing(['as_usd' => 5.00, 'term' => 1]);

        $this->assertSame(60.00, $pricing->usdPerYear());
    }

    public function test_usd_per_year_multiplies_by_4_for_quarterly_term()
    {
        $pricing = new Pricing(['as_usd' => 30.00, 'term' => 2]);

        $this->assertSame(120.00, $pricing->usdPerYear());
    }

    public function test_usd_per_year_multiplies_by_2_for_semi_annual_term()
    {
        $pricing = new Pricing(['as_usd' => 60.00, 'term' => 3]);

        $this->assertSame(120.00, $pricing->usdPerYear());
    }

    public function test_usd_per_year_halves_for_biennial_term()
    {
        $pricing = new Pricing(['as_usd' => 100.99, 'term' => 5]);

        $this->assertSame(50.50, $pricing->usdPerYear());
    }

    public function test_usd_per_year_divides_by_3_for_triennial_term()
    {
        $pricing = new Pricing(['as_usd' => 100.00, 'term' => 6]);

        $this->assertSame(33.33, $pricing->usdPerYear());
    }

    public function test_usd_per_year_returns_zero_for_one_time_term()
    {
        $pricing = new Pricing(['as_usd' => 50.00, 'term' => 7]);

        $this->assertSame(0.0, $pricing->usdPerYear());
    }

    public function test_usd_per_year_treats_unknown_term_as_monthly()
    {
        // Mirrors costAsPerMonth's default branch for legacy rows.
        $pricing = new Pricing(['as_usd' => 5.00, 'term' => 99]);

        $this->assertSame(60.00, $pricing->usdPerYear());
    }

    public function test_active_is_mass_assignable()
    {
        // 'active' was missing from $fillable, so insertPricing's $is_active=0
        // (used by the cancelled-server import) was silently dropped -> default 1.
        $pricing = new Pricing(['active' => 0]);

        $this->assertSame(0, $pricing->active);
    }
}
