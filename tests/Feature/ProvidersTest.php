<?php

namespace Tests\Feature;

use App\Models\Providers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvidersTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_access_providers_index()
    {
        $response = $this->get(route('providers.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_providers_index()
    {
        $response = $this->actingAs($this->user)->get(route('providers.index'));
        $response->assertStatus(200);
        $response->assertViewIs('providers.index');
    }

    public function test_authenticated_user_can_view_create_provider_form()
    {
        $response = $this->actingAs($this->user)->get(route('providers.create'));
        $response->assertStatus(200);
        $response->assertViewIs('providers.create');
    }

    public function test_authenticated_user_can_create_provider()
    {
        $response = $this->actingAs($this->user)->post(route('providers.store'), [
            'provider_name' => 'Test Provider'
        ]);

        $response->assertRedirect(route('providers.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('providers', ['name' => 'Test Provider']);
    }

    public function test_provider_name_is_required()
    {
        $response = $this->actingAs($this->user)->post(route('providers.store'), [
            'provider_name' => ''
        ]);

        $response->assertSessionHasErrors('provider_name');
    }

    public function test_provider_name_must_be_at_least_2_characters()
    {
        $response = $this->actingAs($this->user)->post(route('providers.store'), [
            'provider_name' => 'A'
        ]);

        $response->assertSessionHasErrors('provider_name');
    }

    public function test_authenticated_user_can_view_provider_details()
    {
        $provider = Providers::create(['name' => 'Test Provider']);

        $response = $this->actingAs($this->user)->get(route('providers.show', $provider));
        $response->assertStatus(200);
        $response->assertViewIs('providers.show');
    }

    public function test_authenticated_user_can_delete_provider()
    {
        $provider = Providers::create(['name' => 'Test Provider']);

        $response = $this->actingAs($this->user)->delete(route('providers.destroy', $provider));

        $response->assertRedirect(route('providers.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('providers', ['name' => 'Test Provider']);
    }

    public function test_cannot_delete_a_provider_assigned_to_a_service()
    {
        $provider = Providers::create(['name' => 'In-Use Provider']);
        $location = \App\Models\Locations::create(['name' => 'L']);
        $os = \App\Models\OS::create(['name' => 'Ubuntu']);
        \App\Models\Settings::create(['id' => 1]);
        (new \App\Models\Pricing)->insertPricing(1, 'srv00001', 'USD', 5, 1, '2027-01-01');
        \App\Models\Server::create([
            'id' => 'srv00001', 'hostname' => 'h', 'server_type' => 1, 'os_id' => $os->id,
            'provider_id' => $provider->id, 'location_id' => $location->id, 'ram' => 1, 'ram_type' => 'GB',
            'ram_as_mb' => 1024, 'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1,
            'has_yabs' => 0, 'was_promo' => 0, 'active' => 1, 'show_public' => 0, 'bandwidth' => 1, 'owned_since' => '2024-01-01',
        ]);

        $response = $this->actingAs($this->user)->delete(route('providers.destroy', $provider));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('providers', ['id' => $provider->id]);
    }
}
