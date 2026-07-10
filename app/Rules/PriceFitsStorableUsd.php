<?php

namespace App\Rules;

use App\Models\Pricing;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The price cap (max:99999999) fits the raw value into decimal(10,2), but
 * the stored as_usd derivation (price / rate) exceeds that cap for any
 * currency stronger than USD: MySQL rejects the write mid-transaction
 * (1264 out-of-range -> 500 after a fully validated submit) and SQLite
 * silently stores an out-of-spec value. Reject at validation, where every
 * pricing writer (web, API, import) reports errors in its native format.
 */
class PriceFitsStorableUsd implements DataAwareRule, ValidationRule
{
    /** decimal(10,2) ceiling shared by as_usd and usd_per_month. */
    public const MAX_STORABLE_USD = 99999999.99;

    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $currency = $this->data['currency'] ?? null;
        if (!is_numeric($value) || !is_string($currency)) {
            return; // the numeric and currency rules own those failures
        }

        if (Pricing::usdEquivalent((float) $value, $currency) > self::MAX_STORABLE_USD) {
            $fail('The :attribute exceeds the maximum storable USD equivalent.');
        }
    }
}
