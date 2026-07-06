<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression for the round-27 review finding: API ram_type/disk_type must
 * be strict enums — the case-sensitive === derivations turned 'gb' into a
 * silent 1024x disk_as_gb corruption with a 200 response.
 */
class Round27RegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        Providers::create(['name' => 'Test Provider']);
        Locations::create(['name' => 'Test Location']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    public function test_api_rejects_lowercase_ram_and_disk_units()
    {
        Pricing::create([
            'service_id' => 'enumapi1', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'enumapi1', 'hostname' => 'enum-api.example.com', 'server_type' => 1,
            'os_id' => OS::first()->id, 'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id, 'ram' => 2048, 'ram_type' => 'MB',
            'ram_as_mb' => 2048, 'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50, 'cpu' => 2,
        ]);

        $headers = ['Authorization' => 'Bearer ' . $this->token];

        // 'gb' !== 'GB' in the derivation: this used to return 200 and set
        // disk_as_gb = 512000 (500 TB) while server_disks said 500 GB.
        $this->putJson('/api/servers/enumapi1', ['disk' => 500, 'disk_type' => 'gb'], $headers)
            ->assertStatus(400);
        $this->putJson('/api/servers/enumapi1', ['ram' => 2048, 'ram_type' => 'mb'], $headers)
            ->assertStatus(400);

        $this->assertDatabaseHas('servers', ['id' => 'enumapi1', 'disk_as_gb' => 50, 'ram_as_mb' => 2048]);
    }
}
