<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\Shared;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Regressions for the GPT round-14 findings (first cross-model round after
 * the 4.2 simplification pass):
 * 1. make:database interpolated its name argument and the DB_CHARSET /
 *    DB_COLLATION env values straight into CREATE DATABASE — identifiers
 *    can't be parameter-bound, so they must be allowlisted.
 * 2. Service update paths wrote model/pricing/labels/IPs in separate
 *    non-transactional steps: a failure in a late write left a partially
 *    updated service.
 * 3. The signed YABS API answered a malformed (but signed) payload with a
 *    generic 500; that's client input and must be a 422, distinct from a
 *    genuine persistence failure.
 */
class GptRound14RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Model-event listeners registered in tests are process-global.
        IPs::flushEventListeners();
        parent::tearDown();
    }

    public function test_make_database_rejects_non_identifier_names()
    {
        $this->artisan('make:database', ['name' => 'my_idlers;DROP DATABASE mysql'])
            ->assertExitCode(1);

        $this->artisan('make:database', ['name' => 'my`idlers'])
            ->assertExitCode(1);

        $this->artisan('make:database', ['name' => 'my idlers'])
            ->assertExitCode(1);
    }

    public function test_make_database_rejects_non_identifier_charset_and_collation_from_config()
    {
        // These come from DB_CHARSET/DB_COLLATION env values via config.
        config(['database.connections.mysql.charset' => 'utf8mb4`;DROP DATABASE mysql;--']);
        $this->artisan('make:database', ['name' => 'my_idlers'])->assertExitCode(1);

        config(['database.connections.mysql.charset' => 'utf8mb4']);
        config(['database.connections.mysql.collation' => 'utf8mb4_unicode_ci; --']);
        $this->artisan('make:database', ['name' => 'my_idlers'])->assertExitCode(1);
    }

    public function test_shared_update_rolls_back_every_write_when_a_late_write_fails()
    {
        $user = User::factory()->create();
        $provider = Providers::create(['name' => 'GPT14 Provider']);
        $location = Locations::create(['name' => 'GPT14 Location']);
        (new Pricing)->insertPricing(2, 'gpt14shr', 'USD', 5.00, 1, '2027-01-01');
        $shared = Shared::create(['id' => 'gpt14shr', 'main_domain' => 'before.example.com', 'shared_type' => 'cPanel']);

        // Fail at the LAST write step (the new IP's insert): before the
        // update sequence was transactional, the model update and pricing
        // rewrite had already been committed by this point.
        IPs::creating(function () {
            throw new \RuntimeException('injected write failure');
        });

        $this->actingAs($user)->put(route('shared.update', $shared), [
            'domain' => 'after.example.com',
            'shared_type' => 'cPanel',
            'provider_id' => $provider->id,
            'location_id' => $location->id,
            'price' => 99.00, 'currency' => 'USD', 'payment_term' => 1,
            'disk' => 50, 'domains' => 1, 'sub_domains' => 1, 'bandwidth' => 100,
            'email' => 1, 'ftp' => 1, 'db' => 1, 'was_promo' => 0,
            'dedicated_ip' => '198.51.100.9',
        ])->assertStatus(500);

        // Everything must have rolled back with the failed IP write.
        $this->assertDatabaseHas('shared_hosting', ['id' => 'gpt14shr', 'main_domain' => 'before.example.com']);
        $this->assertDatabaseHas('pricings', ['service_id' => 'gpt14shr', 'price' => 5.00]);
        $this->assertDatabaseMissing('ips', ['service_id' => 'gpt14shr']);
    }

    public function test_signed_yabs_with_malformed_payload_returns_422_not_500()
    {
        $provider = Providers::create(['name' => 'GPT14 Provider']);
        $location = Locations::create(['name' => 'GPT14 Location']);
        $os = OS::create(['name' => 'Debian 13']);
        Settings::create(['id' => 1]);
        (new Pricing)->insertPricing(1, 'gpt14srv', 'USD', 5.00, 1, '2027-01-01');
        $server = Server::create([
            'id' => 'gpt14srv', 'hostname' => 'gpt14.example.com', 'server_type' => 1,
            'os_id' => $os->id, 'provider_id' => $provider->id, 'location_id' => $location->id,
            'ram' => 4, 'ram_type' => 'GB', 'ram_as_mb' => 4096, 'disk' => 50, 'disk_type' => 'GB',
            'disk_as_gb' => 50, 'cpu' => 4, 'has_yabs' => 0, 'active' => 1, 'owned_since' => '2026-01-01',
        ]);

        // Passes the top-level validation (all required arrays present) but
        // the timestamp is not yabs.sh's Ymd-His shape — a parse failure,
        // i.e. client input.
        $payload = [
            'version' => 'v2024-06-09',
            'time' => 'not-a-yabs-timestamp',
            'os' => ['distro' => 'Debian 13', 'kernel' => '6.1.0', 'uptime' => '5 days'],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'AMD EPYC', 'cores' => 4, 'freq' => '2299.998', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 4014080, 'swap' => 524288, 'disk' => 49283072],
        ];

        $url = URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $server->id]);
        $this->postJson($url, $payload)->assertStatus(422);

        // Nothing was persisted and the server wasn't flagged.
        $this->assertDatabaseCount('yabs', 0);
        $this->assertDatabaseHas('servers', ['id' => 'gpt14srv', 'has_yabs' => 0]);
    }
}
