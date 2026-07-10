<?php

namespace Tests\Feature;

use App\Models\DNS;
use App\Models\Domains;
use App\Models\IPs;
use App\Models\Locations;
use App\Models\Misc;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Reseller;
use App\Models\SeedBoxes;
use App\Models\Server;
use App\Models\Shared;
use App\Services\CsvFormatter;
use App\Services\ExportCsvHeaders;
use App\Services\ExportTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the per-section contract between each transform method
 * (ExportTransformer) and its CSV column list (ExportCsvHeaders), which live
 * in separate classes since the S1448 split. CsvFormatter::toCsv only emits
 * columns present in the header list ($row[$header] ?? ''), so a field added
 * to a transform without its header — or vice versa — silently drops or
 * blanks a CSV column while the JSON export keeps it. Rows here are fully
 * populated (every relation present) so every column a transform can emit
 * is exercised, and assertSame pins order as well as names.
 */
class ExportHeaderParityTest extends TestCase
{
    use RefreshDatabase;

    protected Providers $provider;
    protected Locations $location;
    protected OS $os;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = Providers::create(['name' => 'Parity Provider']);
        $this->location = Locations::create(['name' => 'Parity Location']);
        $this->os = OS::create(['name' => 'Debian 13']);
    }

    /** Flattened column names exactly as CsvFormatter derives them per row. */
    private function flattenedKeys(array $transformed): array
    {
        $flattener = new class extends CsvFormatter {
            public function keys(array $item): array
            {
                return array_keys($this->flattenForCsv($item));
            }
        };

        return $flattener->keys($transformed);
    }

    private function createPricing(string $service_id, int $type): void
    {
        Pricing::create([
            'service_id' => $service_id,
            'service_type' => $type,
            'currency' => 'USD',
            'price' => 10.00,
            'term' => 1,
            'as_usd' => 10.00,
            'usd_per_month' => 10.00,
            'next_due_date' => '2027-02-15'
        ]);
    }

    public function test_every_section_header_list_matches_its_transform_output()
    {
        $transformer = new ExportTransformer();
        $headers = new ExportCsvHeaders();

        $this->createPricing('parity01', 1);
        $server = Server::create([
            'id' => 'parity01', 'hostname' => 'parity.example.com', 'server_type' => 1,
            'os_id' => $this->os->id, 'provider_id' => $this->provider->id, 'location_id' => $this->location->id,
            'ram' => 8, 'ram_type' => 'GB', 'ram_as_mb' => 8192, 'disk' => 100, 'disk_type' => 'GB',
            'disk_as_gb' => 100, 'bandwidth' => 1000, 'ssh' => 22, 'cpu' => 4, 'active' => 1,
            'owned_since' => '2026-01-15'
        ]);
        IPs::insertIP($server->id, '192.0.2.10');
        // Populate the nested relations too — parity must hold for the
        // populated shapes, not just empty lists
        \App\Models\Disk::insertDisk($server->id, 100, 'GB', 'NVMe');
        \App\Models\Labels::create(['id' => 'paritylb', 'label' => 'parity-label']);
        \App\Models\LabelsAssigned::create(['label_id' => 'paritylb', 'service_id' => $server->id]);
        $this->assertSame(
            $headers->getServerCsvHeaders(),
            $this->flattenedKeys($transformer->transformServerForExport($server->fresh())),
            'servers: header list drifted from transformServerForExport output'
        );

        $this->createPricing('parity02', 4);
        $domain = Domains::create([
            'id' => 'parity02', 'domain' => 'parity', 'extension' => 'com',
            'ns1' => 'ns1.example.com', 'ns2' => 'ns2.example.com', 'ns3' => 'ns3.example.com',
            'provider_id' => $this->provider->id, 'owned_since' => '2026-01-01'
        ]);
        $this->assertSame(
            $headers->getDomainCsvHeaders(),
            $this->flattenedKeys($transformer->transformDomainForExport($domain->fresh())),
            'domains: header list drifted from transformDomainForExport output'
        );

        $this->createPricing('parity03', 2);
        $shared = Shared::create([
            'id' => 'parity03', 'main_domain' => 'parity-shared.com', 'shared_type' => 'cPanel',
            'provider_id' => $this->provider->id, 'location_id' => $this->location->id,
            'disk' => 50, 'disk_type' => 'GB', 'disk_as_gb' => 50, 'bandwidth' => 500,
            'domains_limit' => 10, 'subdomains_limit' => 50, 'ftp_limit' => 10, 'email_limit' => 100,
            'db_limit' => 10, 'active' => 1, 'owned_since' => '2026-01-01'
        ]);
        IPs::insertIP($shared->id, '192.0.2.11');
        \App\Models\LabelsAssigned::create(['label_id' => 'paritylb', 'service_id' => $shared->id]);
        $this->assertSame(
            $headers->getSharedCsvHeaders(),
            $this->flattenedKeys($transformer->transformSharedForExport($shared->fresh())),
            'shared: header list drifted from transformSharedForExport output'
        );

        $this->createPricing('parity04', 3);
        $reseller = Reseller::create([
            'id' => 'parity04', 'main_domain' => 'parity-reseller.com', 'reseller_type' => 'WHM',
            'accounts' => 15, 'provider_id' => $this->provider->id, 'location_id' => $this->location->id,
            'disk' => 200, 'disk_type' => 'GB', 'disk_as_gb' => 200, 'bandwidth' => 2000,
            'domains_limit' => 100, 'subdomains_limit' => 500, 'ftp_limit' => 100, 'email_limit' => 1000,
            'db_limit' => 100, 'active' => 1, 'owned_since' => '2026-01-01'
        ]);
        IPs::insertIP($reseller->id, '192.0.2.12');
        $this->assertSame(
            $headers->getResellerCsvHeaders(),
            $this->flattenedKeys($transformer->transformResellerForExport($reseller->fresh())),
            'reseller: header list drifted from transformResellerForExport output'
        );

        $this->createPricing('parity05', 6);
        $seedbox = SeedBoxes::create([
            'id' => 'parity05', 'title' => 'Parity Box', 'hostname' => 'seed.parity.com',
            'seed_box_type' => 'Dedicated', 'provider_id' => $this->provider->id,
            'location_id' => $this->location->id, 'disk' => 2000, 'disk_type' => 'GB',
            'disk_as_gb' => 2000, 'bandwidth' => 10000, 'port_speed' => 1000, 'active' => 1,
            'owned_since' => '2026-01-01'
        ]);
        IPs::insertIP($seedbox->id, '192.0.2.13');
        $this->assertSame(
            $headers->getSeedboxCsvHeaders(),
            $this->flattenedKeys($transformer->transformSeedboxForExport($seedbox->fresh())),
            'seedboxes: header list drifted from transformSeedboxForExport output'
        );

        $dns = DNS::create([
            'id' => 'parity06', 'hostname' => 'parity.example.com', 'dns_type' => 'A',
            'address' => '192.0.2.14'
        ]);
        $this->assertSame(
            $headers->getDnsCsvHeaders(),
            $this->flattenedKeys($transformer->transformDnsForExport($dns->fresh())),
            'dns: header list drifted from transformDnsForExport output'
        );

        $this->createPricing('parity07', 5);
        $misc = Misc::create(['id' => 'parity07', 'name' => 'Parity Service', 'owned_since' => '2026-01-01']);
        $this->assertSame(
            $headers->getMiscCsvHeaders(),
            $this->flattenedKeys($transformer->transformMiscForExport($misc->fresh())),
            'misc: header list drifted from transformMiscForExport output'
        );
    }
}
