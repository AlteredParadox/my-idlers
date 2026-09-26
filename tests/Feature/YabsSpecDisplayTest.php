<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use App\Models\Yabs;
use App\Services\YabsIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #74: the three places a server's specs appear disagreed about which
 * value they show. The list and the edit form used the entered (as
 * provisioned) values; the detail page swapped in the latest YABS
 * measurement for RAM and disk; and the form claimed "YABS output will
 * overwrite these values", which the ingest stopped doing in July 2026
 * (server_disks is the disk source of truth and YABS sees usable RAM and
 * the root filesystem only). Every surface now shows the entered values,
 * and the measurements live in the YABS section, labelled as measurements.
 */
class YabsSpecDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Server $server;
    private Yabs $yabs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $provider = Providers::create(['name' => 'P'])->id;
        $location = Locations::create(['name' => 'L'])->id;
        $os = OS::create(['name' => 'Ubuntu 22.04'])->id;
        Settings::create(['id' => 1]);

        Pricing::create([
            'service_id' => 'spec0074', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        $this->server = Server::create([
            'id' => 'spec0074', 'hostname' => 'spec.example.com', 'server_type' => 1,
            'os_id' => $os, 'provider_id' => $provider, 'location_id' => $location,
            'ram' => 4, 'ram_type' => 'GB', 'ram_as_mb' => 4096,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50,
            'cpu' => 2, 'active' => 1,
        ]);

        // A run that measures LESS than was provisioned: ~3.8 GB usable RAM
        // and a ~47 GB root filesystem on the 4 GB / 50 GB plan above.
        $ok = app(YabsIngestService::class)->ingest([
            'version' => 'v2025-04-20', 'time' => '20260705-120000',
            'os' => ['arch' => 'x86_64', 'distro' => 'Ubuntu', 'kernel' => '5.15', 'uptime' => 93784.55],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'CPU', 'cores' => 2, 'freq' => '2400', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 4014080, 'swap' => 524288, 'disk' => 49283072],
            'geekbench' => [['version' => 6, 'single' => 1600, 'multi' => 5000, 'url' => 'https://browser.geekbench.com/v6/cpu/7']],
        ], 'spec0074');
        $this->assertTrue($ok);
        $this->yabs = Yabs::where('server_id', 'spec0074')->firstOrFail();
        $this->assertNotEquals('4 GB', $this->measuredRam(), 'the fixture must measure something other than the entered value');
    }

    private function measuredRam(): string
    {
        return $this->yabs->ram . ' ' . $this->yabs->ram_type;
    }

    private function measuredDisk(): string
    {
        return $this->yabs->disk . ' ' . $this->yabs->disk_type;
    }

    public function test_the_detail_page_shows_entered_specs_and_labels_the_measurements()
    {
        $html = $this->actingAs($this->user)->get(route('servers.show', 'spec0074'))
            ->assertOk()->getContent();

        $specs = substr($html, strpos($html, 'Specifications'), strpos($html, 'YABS Benchmark') - strpos($html, 'Specifications'));
        $this->assertStringContainsString('4 GB', $specs, 'the Specifications block must show the entered RAM');
        $this->assertStringContainsString('50 GB', $specs, 'the Specifications block must show the entered disk');
        $this->assertStringNotContainsString($this->measuredRam(), $specs,
            'the Specifications block swapped in the measured RAM (issue #74)');
        $this->assertStringNotContainsString($this->measuredDisk(), $specs,
            'the Specifications block swapped in the measured disk (issue #74)');

        $bench = substr($html, strpos($html, 'YABS Benchmark'));
        $this->assertStringContainsString('Usable RAM', $bench);
        $this->assertStringContainsString($this->measuredRam(), $bench);
        $this->assertStringContainsString('Root Filesystem', $bench);
        $this->assertStringContainsString($this->measuredDisk(), $bench);
    }

    public function test_the_forms_no_longer_claim_yabs_overwrites_the_specs()
    {
        $this->actingAs($this->user)->get(route('servers.edit', 'spec0074'))
            ->assertOk()
            ->assertDontSee('will overwrite')
            ->assertSee('never changes these');
        $this->actingAs($this->user)->get(route('servers.create'))
            ->assertOk()
            ->assertDontSee('will overwrite');
    }

    public function test_the_ingest_left_the_entered_specs_alone()
    {
        $this->assertDatabaseHas('servers', [
            'id' => 'spec0074', 'ram' => 4, 'ram_type' => 'GB', 'disk' => 50, 'disk_type' => 'GB',
        ]);
    }
}
