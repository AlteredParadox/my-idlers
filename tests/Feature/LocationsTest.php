<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_access_locations_index()
    {
        $response = $this->get(route('locations.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_locations_index()
    {
        $response = $this->actingAs($this->user)->get(route('locations.index'));
        $response->assertStatus(200);
        $response->assertViewIs('locations.index');
    }

    public function test_authenticated_user_can_view_create_location_form()
    {
        $response = $this->actingAs($this->user)->get(route('locations.create'));
        $response->assertStatus(200);
        $response->assertViewIs('locations.create');
    }

    public function test_authenticated_user_can_create_location()
    {
        $response = $this->actingAs($this->user)->post(route('locations.store'), [
            'location_name' => 'New York, USA'
        ]);

        $response->assertRedirect(route('locations.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('locations', ['name' => 'New York, USA']);
    }

    public function test_location_name_is_required()
    {
        $response = $this->actingAs($this->user)->post(route('locations.store'), [
            'location_name' => ''
        ]);

        $response->assertSessionHasErrors('location_name');
    }

    public function test_location_name_must_be_at_least_2_characters()
    {
        $response = $this->actingAs($this->user)->post(route('locations.store'), [
            'location_name' => 'A'
        ]);

        $response->assertSessionHasErrors('location_name');
    }

    public function test_authenticated_user_can_view_location_details()
    {
        $location = Locations::create(['name' => 'Test Location']);

        $response = $this->actingAs($this->user)->get(route('locations.show', $location));
        $response->assertStatus(200);
        $response->assertViewIs('locations.show');
    }

    public function test_authenticated_user_can_delete_location()
    {
        $location = Locations::create(['name' => 'Test Location']);

        $response = $this->actingAs($this->user)->delete(route('locations.destroy', $location));

        $response->assertRedirect(route('locations.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('locations', ['name' => 'Test Location']);
    }

    public function test_cannot_delete_a_location_assigned_to_a_service()
    {
        $location = Locations::create(['name' => 'In-Use Location']);
        $provider = \App\Models\Providers::create(['name' => 'P']);
        $os = \App\Models\OS::create(['name' => 'Ubuntu']);
        \App\Models\Settings::create(['id' => 1]);
        (new \App\Models\Pricing)->insertPricing(1, 'srv00001', 'USD', 5, 1, '2027-01-01');
        \App\Models\Server::create([
            'id' => 'srv00001', 'hostname' => 'h', 'server_type' => 1, 'os_id' => $os->id,
            'provider_id' => $provider->id, 'location_id' => $location->id, 'ram' => 1, 'ram_type' => 'GB',
            'ram_as_mb' => 1024, 'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1,
            'has_yabs' => 0, 'was_promo' => 0, 'active' => 1, 'show_public' => 0, 'bandwidth' => 1, 'owned_since' => '2024-01-01',
        ]);

        $response = $this->actingAs($this->user)->delete(route('locations.destroy', $location));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }
}
