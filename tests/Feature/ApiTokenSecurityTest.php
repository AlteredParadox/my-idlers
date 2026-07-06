<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiTokenSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeServer(): Server
    {
        $provider = Providers::create(['name' => 'Test Provider']);
        $location = Locations::create(['name' => 'Test Location']);
        $os = OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);

        $server_id = Str::random(8);
        (new \App\Models\Pricing)->insertPricing(1, $server_id, 'USD', 5.00, 1, '2027-01-01');

        return Server::create([
            'id' => $server_id,
            'hostname' => 'trial.example.com',
            'server_type' => 1,
            'os_id' => $os->id,
            'provider_id' => $provider->id,
            'location_id' => $location->id,
            'ram' => 2048,
            'ram_type' => 'MB',
            'ram_as_mb' => 2048,
            'disk' => 50,
            'disk_type' => 'GB',
            'disk_as_gb' => 50,
            'cpu' => 2,
            'has_yabs' => 0,
            'was_promo' => 0,
            'active' => 1,
            'show_public' => 0,
            'bandwidth' => 1000,
            'owned_since' => '2024-01-01',
        ]);
    }

    public function test_hashed_bearer_token_authenticates_api_request()
    {
        $plain = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($plain)]);

        $this->getJson('/api/servers/', ['Authorization' => 'Bearer ' . $plain])
            ->assertStatus(200);
    }

    public function test_plaintext_stored_token_no_longer_authenticates()
    {
        $plain = Str::random(60);
        User::factory()->create(['api_token' => $plain]);

        $this->getJson('/api/servers/', ['Authorization' => 'Bearer ' . $plain])
            ->assertStatus(401);
    }

    public function test_yabs_endpoint_rejects_unsigned_request()
    {
        $server = $this->makeServer();

        $this->postJson('/api/yabs/' . $server->id)->assertStatus(403);
    }

    public function test_yabs_endpoint_accepts_signed_url()
    {
        $server = $this->makeServer();

        $url = URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $server->id]);

        // 422 (validation) rather than 403 proves the signature was accepted
        $this->postJson($url)->assertStatus(422);
    }
}
