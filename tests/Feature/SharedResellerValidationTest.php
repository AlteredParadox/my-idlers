<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared/Reseller pricing validation: price/currency omitted used to pass
 * validation and then TypeError (500) in Pricing::insertPricing. Validation
 * runs before any DB write, so no service scaffolding is needed here.
 */
class SharedResellerValidationTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(): array
    {
        return [
            'domain' => 'x.example.com',
            'shared_type' => 'cPanel',
            'reseller_type' => 'WHM',
            'provider_id' => 1,
            'location_id' => 1,
            'payment_term' => 1,
            // price + currency intentionally omitted
        ];
    }

    public function test_shared_store_requires_price_and_currency()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('shared.store'), $this->basePayload())
            ->assertSessionHasErrors(['price', 'currency']);
    }

    public function test_reseller_store_requires_price_and_currency()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('reseller.store'), $this->basePayload())
            ->assertSessionHasErrors(['price', 'currency']);
    }

    public function test_server_store_requires_currency_and_payment_term()
    {
        // Server was the last pricing controller missing these rules; omitting
        // them used to TypeError (500) in Pricing::insertPricing.
        $this->actingAs(User::factory()->create())
            ->post(route('servers.store'), ['hostname' => 'crafted.example.com'])
            ->assertSessionHasErrors(['currency', 'payment_term']);
    }
}
