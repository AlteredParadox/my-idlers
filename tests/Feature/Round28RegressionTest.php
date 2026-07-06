<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the round-28 review findings: GB6-only YABS on the
 * server compare page, null owned_since, type-field lengths, is_ipv4
 * derived from the address.
 */
class Round28RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $providerId;
    private int $locationId;
    private int $osId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->providerId = Providers::create(['name' => 'P'])->id;
        $this->locationId = Locations::create(['name' => 'L'])->id;
        $this->osId = OS::create(['name' => 'Ubuntu 22.04'])->id;
        Settings::create(['id' => 1]);
    }

    /** Server with a YABS run from modern yabs.sh: Geekbench 6 only, no GB5. */
    private function makeServerWithGb6OnlyYabs(string $hostname, ?string $ownedSince = '2024-01-01'): string
    {
        $server_id = Str::random(8);
        (new Pricing)->insertPricing(1, $server_id, 'USD', 5.00, 1, '2027-01-01');

        Server::create([
            'id' => $server_id, 'hostname' => $hostname, 'server_type' => 1, 'os_id' => $this->osId,
            'provider_id' => $this->providerId, 'location_id' => $this->locationId, 'ram' => 2, 'ram_type' => 'GB',
            'ram_as_mb' => 2048, 'disk' => 40, 'disk_type' => 'GB', 'disk_as_gb' => 40, 'cpu' => 2, 'has_yabs' => 0,
            'was_promo' => 0, 'active' => 1, 'show_public' => 0, 'bandwidth' => 1000, 'owned_since' => $ownedSince,
        ]);

        app(YabsIngestService::class)->ingest([
            'version' => 'v', 'time' => '20260705-120000',
            'os' => ['arch' => 'x86_64', 'distro' => 'Ubuntu', 'kernel' => '5.15', 'uptime' => '1 day'],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'CPU', 'cores' => 2, 'freq' => '2400', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 2048000, 'swap' => 0, 'disk' => 41943040],
            'geekbench' => [['version' => 6, 'single' => 1500, 'multi' => 4500, 'url' => 'https://browser.geekbench.com/v6/cpu/1']],
            'fio' => [
                ['bs' => '4k', 'speed_rw' => 150000], ['bs' => '64k', 'speed_rw' => 500000],
                ['bs' => '512k', 'speed_rw' => 800000], ['bs' => '1m', 'speed_rw' => 900000],
            ],
            'iperf' => [['mode' => 'IPv4', 'loc' => 'London', 'send' => '1.00 Gbits/sec', 'recv' => '1.00 Gbits/sec']],
        ], $server_id);

        return $server_id;
    }

    public function test_compare_page_survives_gb6_only_yabs_and_null_owned_since()
    {
        // Modern yabs.sh runs GB6 by default: gb5_single/gb5_multi are NULL,
        // which the non-nullable tableRowCompare/safeDivide signatures turned
        // into a TypeError 500. Null owned_since also rendered as "now".
        $s1 = $this->makeServerWithGb6OnlyYabs('gb6a.example.com');
        $s2 = $this->makeServerWithGb6OnlyYabs('gb6b.example.com', null);

        $this->actingAs($this->user)
            ->get(route('servers.compare', ['server1' => $s1, 'server2' => $s2]))
            ->assertOk();
    }

    public function test_overlong_type_fields_are_validation_errors()
    {
        $this->actingAs($this->user)->post(route('shared.store'), [
            'domain' => 'longtype.example.com',
            'shared_type' => str_repeat('x', 300),
            'provider_id' => $this->providerId, 'location_id' => $this->locationId,
            'price' => 5.00, 'currency' => 'USD', 'payment_term' => 1,
        ])->assertSessionHasErrors('shared_type');

        $this->assertSame(0, Pricing::count());
    }

    public function test_ip_type_is_derived_from_address_not_dropdown()
    {
        Pricing::create([
            'service_id' => 'ipv6drop', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        Server::create([
            'id' => 'ipv6drop', 'hostname' => 'ipv6drop.example.com', 'server_type' => 1,
            'os_id' => $this->osId, 'provider_id' => $this->providerId,
            'location_id' => $this->locationId, 'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);

        // IPv6 address pasted with the pre-selected "IPv4" dropdown left as-is.
        $this->actingAs($this->user)->post(route('IPs.store'), [
            'address' => '2001:db8::77', 'ip_type' => 'ipv4', 'service_id' => 'ipv6drop',
        ]);

        $this->assertDatabaseHas('ips', ['address' => '2001:db8::77', 'is_ipv4' => 0]);
    }
}
