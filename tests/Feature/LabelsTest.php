<?php

namespace Tests\Feature;

use App\Models\Labels;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_access_labels_index()
    {
        $response = $this->get(route('labels.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_labels_index()
    {
        $response = $this->actingAs($this->user)->get(route('labels.index'));
        $response->assertStatus(200);
        $response->assertViewIs('labels.index');
    }

    public function test_authenticated_user_can_view_create_label_form()
    {
        $response = $this->actingAs($this->user)->get(route('labels.create'));
        $response->assertStatus(200);
        $response->assertViewIs('labels.create');
    }

    public function test_authenticated_user_can_create_label()
    {
        $response = $this->actingAs($this->user)->post(route('labels.store'), [
            'label' => 'Production'
        ]);

        $response->assertRedirect(route('labels.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('labels', ['label' => 'Production']);
    }

    public function test_label_is_required()
    {
        $response = $this->actingAs($this->user)->post(route('labels.store'), [
            'label' => ''
        ]);

        $response->assertSessionHasErrors('label');
    }

    public function test_label_must_be_at_least_2_characters()
    {
        $response = $this->actingAs($this->user)->post(route('labels.store'), [
            'label' => 'A'
        ]);

        $response->assertSessionHasErrors('label');
    }

    public function test_authenticated_user_can_view_label_details()
    {
        $label = Labels::create(['id' => 'testlbl1', 'label' => 'Test Label']);

        $response = $this->actingAs($this->user)->get(route('labels.show', $label));
        $response->assertStatus(200);
        $response->assertViewIs('labels.show');
    }

    public function test_authenticated_user_can_delete_label()
    {
        $label = Labels::create(['id' => 'testlbl2', 'label' => 'Test Label']);

        $response = $this->actingAs($this->user)->delete(route('labels.destroy', $label));

        $response->assertRedirect(route('labels.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('labels', ['label' => 'Test Label']);
    }

    public function test_deleting_a_label_clears_the_assigned_server_cache()
    {
        $label = Labels::create(['id' => 'lbldel01', 'label' => 'To Delete']);
        $provider = \App\Models\Providers::create(['name' => 'P']);
        $location = \App\Models\Locations::create(['name' => 'L']);
        $os = \App\Models\OS::create(['name' => 'Ubuntu']);
        \App\Models\Settings::create(['id' => 1]);
        (new \App\Models\Pricing)->insertPricing(1, 'srv00001', 'USD', 5, 1, '2027-01-01');
        \App\Models\Server::create([
            'id' => 'srv00001', 'hostname' => 'h', 'server_type' => 1, 'os_id' => $os->id, 'provider_id' => $provider->id,
            'location_id' => $location->id, 'ram' => 1, 'ram_type' => 'GB', 'ram_as_mb' => 1024, 'disk' => 10,
            'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1, 'has_yabs' => 0, 'was_promo' => 0,
            'active' => 1, 'show_public' => 0, 'bandwidth' => 1, 'owned_since' => '2024-01-01',
        ]);
        Labels::insertLabelsAssigned([$label->id, null, null, null], 'srv00001');
        \Illuminate\Support\Facades\Cache::put('server.srv00001', 'stale');

        $this->actingAs($this->user)->delete(route('labels.destroy', $label));

        // The server show cache embeds the labels relation; a stale one would
        // lazy-load the deleted label and 500 the show page
        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('server.srv00001'));
    }

    public function test_label_page_shows_seedbox_assignment()
    {
        $label = Labels::create(['id' => 'sblabel1', 'label' => 'Seedbox Label']);
        \App\Models\SeedBoxes::create(['id' => 'sbx00001', 'title' => 'My Seedbox', 'active' => 1]);
        (new \App\Models\Pricing)->insertPricing(6, 'sbx00001', 'USD', 5, 1, '2027-01-01');
        Labels::insertLabelsAssigned([$label->id, null, null, null], 'sbx00001');

        $response = $this->actingAs($this->user)->get(route('labels.show', $label));

        $response->assertStatus(200);
        // Previously rendered a blank card (no seedbox/type-6 branch)
        $response->assertSee('My Seedbox');
        $response->assertSee('Seedbox');
    }
}
