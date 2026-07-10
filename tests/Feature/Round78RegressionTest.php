<?php

namespace Tests\Feature;

use App\Models\Pricing;
use App\Rules\PriceFitsStorableUsd;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Review round 78, two findings of the SQLite-silent/MySQL-strict-500 class:
 *
 * 1. price was capped to fit decimal(10,2), but the derived as_usd
 *    (price / rate) exceeds the cap for any currency stronger than USD —
 *    MySQL 1264 500 mid-transaction after a fully validated submit, SQLite
 *    silently out-of-spec (and unrounded). Now rejected at validation by
 *    PriceFitsStorableUsd (web, API, and import all carry the rule), and
 *    the derivations are rounded to column precision on both drivers.
 *
 * 2. web rules used bare `date` (any strtotime string, e.g. "May 2030")
 *    where the API already required date_format:Y-m-d — MySQL 1292 500;
 *    SQLite persisted the raw string, which then crashed the home page in
 *    doDueSoon's createFromFormat once the row entered the due-soon window.
 */
class Round78RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('currency_rates', (object) ['USD' => 1.0, 'GBP' => 0.79, 'EUR' => 0.92], now()->addDay());
    }

    private function validate(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, Pricing::webValidationRules());
    }

    public function test_price_overflowing_the_usd_column_is_a_validation_error()
    {
        // 99999999 GBP / 0.79 = 126,582,277.22 USD > decimal(10,2) ceiling
        $v = $this->validate(['price' => 99999999, 'currency' => 'GBP', 'payment_term' => 1]);

        $this->assertTrue($v->fails());
        $this->assertSame(
            'The price exceeds the maximum storable USD equivalent.',
            $v->errors()->first('price')
        );
    }

    public function test_price_at_the_cap_in_usd_still_validates()
    {
        $v = $this->validate(['price' => 99999999, 'currency' => 'USD', 'payment_term' => 1]);

        $this->assertFalse($v->fails());
    }

    public function test_stored_usd_derivations_are_rounded_to_column_precision()
    {
        // SQLite has no column rounding: pre-fix it stored the full float,
        // diverging from MySQL and corrupting cross-driver totals.
        (new Pricing())->insertPricing(1, 'r78prc01', 'EUR', 10.55, 2, null);

        $row = Pricing::where('service_id', 'r78prc01')->first();
        $this->assertSame(11.47, (float) $row->as_usd);     // 10.55 / 0.92
        $this->assertSame(3.82, (float) $row->usd_per_month); // 11.47 / 3
    }

    public function test_non_ymd_parseable_due_date_is_rejected()
    {
        // Bare `date` accepted this; MySQL then 500ed and SQLite stored the
        // literal string, bricking the dashboard in doDueSoon.
        $v = $this->validate(['price' => 5, 'currency' => 'USD', 'payment_term' => 1,
            'next_due_date' => 'May 2030']);

        $this->assertTrue($v->fails());
        $this->assertTrue($v->errors()->has('next_due_date'));

        $ok = $this->validate(['price' => 5, 'currency' => 'USD', 'payment_term' => 1,
            'next_due_date' => '2030-05-01']);
        $this->assertFalse($ok->fails());
    }

    public function test_owned_since_and_api_price_rules_carry_the_hardening()
    {
        // The six web owned_since rules are per-controller strings; pin the
        // exact rule so a revert to bare `date` fails here.
        foreach (['ServerController', 'DomainsController', 'SharedController',
                     'ResellerController', 'MiscController', 'SeedBoxesController'] as $controller) {
            $this->assertStringContainsString(
                "'owned_since' => 'sometimes|nullable|date_format:Y-m-d'",
                file_get_contents(app_path("Http/Controllers/$controller.php")),
                "$controller lost the owned_since date_format hardening"
            );
        }

        // Both API price rules must carry the overflow rule too.
        $api = file_get_contents(app_path('Http/Controllers/Api/ServerManagementController.php'));
        $this->assertSame(2, substr_count($api, 'new \App\Rules\PriceFitsStorableUsd()'),
            'both API price rules (create/update pricing) must carry PriceFitsStorableUsd');
    }

    public function test_overflow_rule_defers_when_other_rules_own_the_failure()
    {
        // Non-numeric price / missing currency: the numeric and required
        // rules must report, not the overflow rule (no division attempted).
        $v = $this->validate(['price' => 'abc', 'currency' => 'GBP', 'payment_term' => 1]);
        $this->assertStringNotContainsString('USD equivalent', $v->errors()->first('price'));

        $rule = new PriceFitsStorableUsd();
        $rule->setData(['price' => 5]); // no currency key at all
        $failed = false;
        $rule->validate('price', 5, function () use (&$failed) {
            $failed = true;
        });
        $this->assertFalse($failed);
    }
}
