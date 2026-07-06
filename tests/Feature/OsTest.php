<?php

namespace Tests\Feature;

use App\Models\OS;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_access_os_index()
    {
        $response = $this->get(route('os.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_os_index()
    {
        $response = $this->actingAs($this->user)->get(route('os.index'));
        $response->assertStatus(200);
        $response->assertViewIs('os.index');
    }

    public function test_authenticated_user_can_view_create_os_form()
    {
        $response = $this->actingAs($this->user)->get(route('os.create'));
        $response->assertStatus(200);
        $response->assertViewIs('os.create');
    }

    public function test_authenticated_user_can_create_os()
    {
        $response = $this->actingAs($this->user)->post(route('os.store'), [
            'os_name' => 'Ubuntu 22.04'
        ]);

        $response->assertRedirect(route('os.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('os', ['name' => 'Ubuntu 22.04']);
    }

    public function test_os_name_is_required()
    {
        $response = $this->actingAs($this->user)->post(route('os.store'), [
            'os_name' => ''
        ]);

        $response->assertSessionHasErrors('os_name');
    }

    public function test_os_name_must_be_at_least_2_characters()
    {
        $response = $this->actingAs($this->user)->post(route('os.store'), [
            'os_name' => 'A'
        ]);

        $response->assertSessionHasErrors('os_name');
    }

    public function test_authenticated_user_can_delete_os()
    {
        $os = OS::create(['name' => 'Test OS']);

        // The route parameter is 'o' not 'os' based on the controller
        $response = $this->actingAs($this->user)->delete(route('os.destroy', ['o' => $os->id]));

        $response->assertRedirect(route('os.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('os', ['name' => 'Test OS']);
    }

    public function test_cannot_delete_an_os_assigned_to_a_server()
    {
        $provider = \App\Models\Providers::create(['name' => 'P']);
        $location = \App\Models\Locations::create(['name' => 'L']);
        $os = OS::create(['name' => 'In-Use OS']);
        \App\Models\Settings::create(['id' => 1]);
        (new \App\Models\Pricing)->insertPricing(1, 'srv00001', 'USD', 5, 1, '2027-01-01');
        \App\Models\Server::create([
            'id' => 'srv00001', 'hostname' => 'h', 'server_type' => 1, 'os_id' => $os->id,
            'provider_id' => $provider->id, 'location_id' => $location->id, 'ram' => 1, 'ram_type' => 'GB',
            'ram_as_mb' => 1024, 'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1,
            'has_yabs' => 0, 'was_promo' => 0, 'active' => 1, 'show_public' => 0, 'bandwidth' => 1, 'owned_since' => '2024-01-01',
        ]);

        $response = $this->actingAs($this->user)->delete(route('os.destroy', ['o' => $os->id]));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('os', ['id' => $os->id]);
    }
}
