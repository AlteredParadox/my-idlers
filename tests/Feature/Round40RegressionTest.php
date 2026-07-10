<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Labels;
use App\Models\LabelsAssigned;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Services\PromQL;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 40 (audit of round 39's own fixes + ripple sweep):
 * the standalone IPs page was the one producer still writing verbatim
 * mixed-case addresses; legacy SQLite case-variant duplicate rows were
 * undeletable through the edit form; Prometheus host matching was
 * byte-exact so mixed-case hostnames never matched the lowercase
 * instance labels; the server export omitted every column added since
 * v4 (silent data loss for backup users); persisted table state froze
 * the page length, permanently shadowing the default_per_page setting.
 */
class Round40RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ips_page_stores_lowercase_with_derived_ip_version()
    {
        $user = User::factory()->create();
        Settings::create(['id' => 1]);
        Pricing::create([
            'service_id' => 'r40ipsp1', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'r40ipsp1', 'hostname' => 'r40.example.com', 'server_type' => 1,
            'os_id' => OS::create(['name' => 'Deb'])->id,
            'provider_id' => Providers::create(['name' => 'P'])->id,
            'location_id' => Locations::create(['name' => 'L'])->id,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);

        \Illuminate\Support\Facades\Http::fake(); // whois call after insert

        $this->actingAs($user)->post(route('IPs.store'), [
            'address' => '2001:DB8::5', 'ip_type' => 'ipv4', 'service_id' => 'r40ipsp1',
        ])->assertRedirect(route('IPs.index'));

        $this->assertDatabaseHas('ips', [
            'service_id' => 'r40ipsp1', 'address' => '2001:db8::5', 'is_ipv4' => 0,
        ]);
    }

    public function test_put_with_null_ip1_is_ignored_not_a_500()
    {
        // 'prohibited' passes null/empty fields into validated(); they must
        // not reach the servers UPDATE (no ip1/ip2 columns exist). A reused
        // POST payload commonly carries "ip1": null.
        $token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($token)]);
        Settings::firstOrCreate(['id' => 1]);
        Pricing::create([
            'service_id' => 'r40nullb', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'r40nullb', 'hostname' => 'null.example.com', 'server_type' => 1,
            'os_id' => OS::create(['name' => 'D'])->id,
            'provider_id' => Providers::create(['name' => 'P2'])->id,
            'location_id' => Locations::create(['name' => 'L2'])->id,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'active' => 1, 'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);

        $this->putJson('/api/servers/r40nullb', [
            'hostname' => 'renamed.example.com', 'ip1' => null, 'ip2' => '',
        ], ['Authorization' => 'Bearer ' . $token])->assertStatus(200);

        $this->assertDatabaseHas('servers', ['id' => 'r40nullb', 'hostname' => 'renamed.example.com']);
    }

    public function test_sync_deletes_legacy_case_variant_duplicate_rows()
    {
        // Pre-normalization SQLite installs could hold case-variants of one
        // address as two rows (its unique index is case-sensitive); the edit
        // form could never remove either. The sync must self-heal.
        // MySQL's ci unique index rejects the fixture itself — the legacy
        // shape can only exist on SQLite, so this scenario is SQLite-only.
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('legacy case-variant duplicates can only exist under SQLite');
        }
        foreach (['2001:db8::1', '2001:DB8::1'] as $variant) {
            IPs::create([
                'id' => Str::random(8), 'service_id' => 'r40dupe1',
                'address' => $variant, 'is_ipv4' => 0, 'active' => 1,
            ]);
        }

        IPs::syncForService('r40dupe1', ['2001:db8::1']);

        $this->assertSame(1, IPs::where('service_id', 'r40dupe1')->count());
    }

    public function test_prometheus_host_matching_is_case_insensitive()
    {
        $this->assertTrue(PromQL::hostMatches('2001:DB8::1', '2001:db8::1'));
        $this->assertTrue(PromQL::hostMatches('Web1.Example.COM', 'web1.example.com'));
        $this->assertTrue(PromQL::hostMatches('Web1.Example.COM', 'web1'));
        $this->assertFalse(PromQL::hostMatches('2001:db8::1', '2001:db8::2'));

        // The JS matcher must apply the same normalization (round-34 rule:
        // list and detail — and now case handling — share one truth table)
        $partial = file_get_contents(resource_path('views/servers/partials/status-js.blade.php'));
        $this->assertStringContainsString('hostname = hostname.toLowerCase();', $partial);
        $this->assertStringContainsString('promHost = promHost.toLowerCase();', $partial);
    }

    public function test_server_export_includes_post_v4_columns()
    {
        Providers::create(['name' => 'P']);
        Locations::create(['name' => 'L']);
        OS::create(['name' => 'Debian 12']);
        Pricing::create([
            'service_id' => 'r40expt1', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'r40expt1', 'hostname' => 'exp.example.com', 'server_type' => 1,
            'os_id' => OS::first()->id, 'provider_id' => Providers::first()->id, 'location_id' => Locations::first()->id,
            'ram' => 1024, 'ram_type' => 'MB', 'ram_as_mb' => 1024,
            'disk' => 10, 'disk_type' => 'GB', 'disk_as_gb' => 10,
            'cpu' => 1, 'cpu_model' => 'EPYC 7402P', 'active' => 1, 'was_promo' => 1,
            'owned_since' => '2024-01-01', 'ns1' => 'ns1.example.com',
            'network_type' => 'IPv4+IPv6', 'link_speed' => 1000, 'show_public' => 1,
        ]);
        \App\Models\Disk::insertDisk('r40expt1', 10, 'GB', 'NVMe');
        Labels::create(['id' => 'r40label', 'label' => 'prod']);
        LabelsAssigned::create(['label_id' => 'r40label', 'service_id' => 'r40expt1']);

        $export = (new \App\Services\ExportService())->exportServers('json');
        $row = json_decode($export['data'], true)[0];

        $this->assertSame('ns1.example.com', $row['ns1']);
        $this->assertSame('EPYC 7402P', $row['cpu_model']);
        $this->assertSame('IPv4+IPv6', $row['network_type']);
        $this->assertSame(1000, (int) $row['link_speed']);
        $this->assertSame(1, (int) $row['was_promo']);
        $this->assertSame(1, (int) $row['show_public']);
        $this->assertSame(['prod'], $row['labels']);
        $this->assertSame('NVMe', $row['disks'][0]['disk_media']);
    }

    public function test_persisted_table_state_does_not_freeze_page_length()
    {
        // A saved `length` would silently shadow the default_per_page
        // setting forever; the save must scrub it and restore must drop
        // legacy frozen values.
        $partial = file_get_contents(resource_path('views/partials/datatable-persist.blade.php'));
        $this->assertStringContainsString('delete data.length;', $partial);
        $this->assertStringContainsString('delete s.length;', $partial);
    }
}
