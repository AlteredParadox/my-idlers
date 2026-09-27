<?php

namespace Tests\Feature;

use App\Models\Home;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Settings::create(['id' => 1]);
    }

    public function test_guests_cannot_access_home_page()
    {
        $response = $this->get(route('/'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_home_page()
    {
        $response = $this->actingAs($this->user)->get(route('/'));
        $response->assertStatus(200);
        $response->assertViewIs('home');
    }

    public function test_home_page_ignores_legacy_services_count_cache_format()
    {
        Cache::forget('services_count_v2');
        Cache::put('services_count', collect([(object) ['service_type' => 1]]));

        $counts = Home::doServicesCount(Home::servicesCount());

        $this->assertIsArray($counts);
        $this->assertSame(0, $counts['total']);
        $this->assertTrue(Cache::has('services_count_v2'));
    }

    public function test_home_page_contains_information_array()
    {
        $response = $this->actingAs($this->user)->get(route('/'));
        $response->assertViewHas('information');
    }

    public function test_home_page_information_contains_expected_keys()
    {
        $response = $this->actingAs($this->user)->get(route('/'));
        
        $information = $response->viewData('information');
        
        $this->assertArrayHasKey('servers', $information);
        $this->assertArrayHasKey('domains', $information);
        $this->assertArrayHasKey('shared', $information);
        $this->assertArrayHasKey('reseller', $information);
        $this->assertArrayHasKey('misc', $information);
        $this->assertArrayHasKey('seedbox', $information);
        $this->assertArrayHasKey('labels', $information);
        $this->assertArrayHasKey('dns', $information);
        $this->assertArrayHasKey('total_services', $information);
        $this->assertArrayHasKey('total_cost_monthly', $information);
        $this->assertArrayHasKey('total_cost_yearly', $information);
    }
}
