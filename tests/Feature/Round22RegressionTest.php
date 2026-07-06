<?php

namespace Tests\Feature;

use App\Models\Domains;
use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Services\ExportTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressions for the round-22 review findings: duplicate-IP 500s,
 * dedicated_ip / ipN validation, export completeness, unimplemented
 * resource routes, provider/location blocker visibility.
 */
class Round22RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Providers $provider;
    private Locations $location;
    private OS $os;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->provider = Providers::create(['name' => 'Test Provider']);
        $this->location = Locations::create(['name' => 'Test Location']);
        $this->os = OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function serverPayload(array $overrides = []): array
    {
        return array_merge([
            'hostname' => 'round22.example.com',
            'server_type' => 1,
            'os_id' => $this->os->id,
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'ram' => 2048,
            'ram_type' => 'MB',
            'disk' => [50],
            'disk_type' => ['GB'],
            'disk_media' => ['SSD'],
            'cpu' => 2,
            'bandwidth' => 1000,
            'ssh_port' => 22,
            'was_promo' => 0,
            'currency' => 'USD',
            'price' => 5.00,
            'payment_term' => 1,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ], $overrides);
    }

    public function test_duplicate_ip_on_server_store_is_validation_error_not_500()
    {
        // (service_id, address) is unique; the old code 500ed on the second
        // insert AFTER writing the pricing row, orphaning it forever.
        $this->actingAs($this->user)->post(route('servers.store'), $this->serverPayload([
            'ip1' => '192.0.2.5',
            'ip2' => '192.0.2.5',
        ]))->assertSessionHasErrors('ip2');

        $this->assertSame(0, Pricing::count());
        $this->assertSame(0, Server::count());
    }

    public function test_ip_sync_dedupes_addresses()
    {
        Pricing::create([
            'service_id' => 'dupip001', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        IPs::syncForService('dupip001', ['192.0.2.7', '192.0.2.7']);

        $this->assertSame(1, IPs::where('service_id', 'dupip001')->count());
    }

    public function test_dedicated_ip_is_validated_on_shared_store()
    {
        // The field was entirely unvalidated: any pasted string became an
        // "IPv4" row feeding the IPs index and whois pulls.
        $this->actingAs($this->user)->post(route('shared.store'), [
            'domain' => 'sharedip.example.com',
            'shared_type' => 'cPanel',
            'provider_id' => $this->provider->id,
            'location_id' => $this->location->id,
            'price' => 5.00,
            'currency' => 'USD',
            'payment_term' => 1,
            'dedicated_ip' => 'not an ip at all',
        ])->assertSessionHasErrors('dedicated_ip');
    }

    public function test_all_ip_fields_are_validated_on_server_update()
    {
        // Only ip1/ip2 had the ip rule while the edit form renders a field
        // per assigned IP — garbage in ip3+ was stored unvalidated.
        Pricing::create([
            'service_id' => 'updipsrv', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        $server = Server::create([
            'id' => 'updipsrv', 'hostname' => 'updip.example.com', 'server_type' => 1,
            'os_id' => $this->os->id, 'provider_id' => $this->provider->id,
            'location_id' => $this->location->id, 'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);

        $this->actingAs($this->user)->put(route('servers.update', $server), $this->serverPayload([
            'ip1' => '192.0.2.1',
            'ip2' => '192.0.2.2',
            'ip3' => 'garbage-not-an-ip',
        ]))->assertSessionHasErrors('ip3');
    }

    private function makeDomain(string $id, string $name): Domains
    {
        // domains.id has an FK to pricings.service_id
        Pricing::create([
            'service_id' => $id, 'service_type' => 4, 'currency' => 'USD',
            'price' => 10.00, 'term' => 4, 'as_usd' => 10.00, 'usd_per_month' => 0.83,
            'next_due_date' => now()->addYear()->format('Y-m-d'),
        ]);

        return Domains::create([
            'id' => $id, 'domain' => $name, 'extension' => 'com',
            'provider_id' => $this->provider->id,
        ]);
    }

    public function test_domain_and_misc_exports_include_active()
    {
        $domain = $this->makeDomain('expdom01', 'exported');
        $domain->update(['active' => 0]);

        $transformer = new ExportTransformer();
        $exported = $transformer->transformDomainForExport($domain);

        $this->assertArrayHasKey('active', $exported);
        $this->assertContains('active', $transformer->getDomainCsvHeaders());
        $this->assertContains('active', $transformer->getMiscCsvHeaders());
    }

    public function test_unimplemented_settings_and_account_routes_are_not_500()
    {
        // Previously BadMethodCallException 500s; with ->only() they resolve
        // to 404 (no route) or 405 (path matches update's URI, wrong verb).
        foreach (['/settings/create', '/account/create', '/settings/1'] as $url) {
            $status = $this->actingAs($this->user)->get($url)->status();
            $this->assertContains($status, [404, 405], "$url returned $status");
        }
    }

    public function test_provider_show_lists_domains_and_seedboxes_blocking_deletion()
    {
        $this->makeDomain('provdom1', 'blockingdomain');
        SeedBoxes::create([
            'id' => 'provsbx1', 'title' => 'Blocking Seedbox',
            'provider_id' => $this->provider->id, 'location_id' => $this->location->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('providers.show', $this->provider));
        $response->assertOk();
        $response->assertSee('blockingdomain');
        $response->assertSee('Blocking Seedbox');

        $locResponse = $this->actingAs($this->user)->get(route('locations.show', $this->location));
        $locResponse->assertOk();
        $locResponse->assertSee('Blocking Seedbox');
    }
}
