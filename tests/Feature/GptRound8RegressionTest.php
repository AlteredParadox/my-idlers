<?php

namespace Tests\Feature;

use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 GPT review (8th batch): CSV import
 * bypassing the hardened invariants, and catalog get-by-id endpoints
 * returning successful empty arrays for missing ids.
 */
class GptRound8RegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
    }

    private function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function importCsv(string $row): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($csv,
            "COMPANY,LOCATION,HOSTNAME,RAM,VCPU,SSD DISK,HDD DISK,BANDWIDTH,PERIOD,COST,CURRENCY,Renews,Cancelled\n"
            . $row . "\n");
        $this->artisan('import:servers', ['file' => $csv]);
        unlink($csv);
    }

    public function test_import_rejects_rows_violating_pricing_and_capacity_invariants()
    {
        // Unconvertible currency: previously silently normalized/stored 1:1.
        $this->importCsv('C1,L1,badcur01.invalid,4 GB,2,80 GB,,10TB,1M,$5.00,ZZZ,12/01/26,');
        // Negative price.
        $this->importCsv('C1,L1,badprc01.invalid,4 GB,2,80 GB,,10TB,1M,-5.00,USD,12/01/26,');
        // Zero CPUs (VCPU column empty -> intval 0).
        $this->importCsv('C1,L1,badcpu01.invalid,4 GB,,80 GB,,10TB,1M,$5.00,USD,12/01/26,');

        $this->assertSame(0, Server::count());
        $this->assertSame(0, Pricing::count());
        // GPT round 12: rejected rows must not strand provider/location rows.
        $this->assertSame(0, \App\Models\Providers::count());
        $this->assertSame(0, \App\Models\Locations::count());

        // A valid row still imports (empty currency defaults to USD).
        $this->importCsv('C1,L1,goodrow1.invalid,4 GB,2,80 GB,,10TB,1M,$5.00,,12/01/26,');
        $this->assertSame(1, Server::count());
        $this->assertDatabaseHas('pricings', ['currency' => 'USD', 'price' => 5.00]);
    }

    public function test_import_rejects_lossy_parsed_cells()
    {
        // GPT round 9: validation ran AFTER lossy casts, so these all
        // previously imported as plausible-looking zero/partial values.
        $bad = [
            'C1,L1,lossy001.invalid,4 GB,2,80 GB,,10TB,1M,abc,USD,12/01/26,',        // floatval('abc') = 0
            'C1,L1,lossy002.invalid,4 GB,2 cores,80 GB,,10TB,1M,$5.00,USD,12/01/26,',// intval('2 cores') = 2
            'C1,L1,lossy003.invalid,lots,2,80 GB,,10TB,1M,$5.00,USD,12/01/26,',      // intval('lots') = 0 MB
            'C1,L1,lossy004.invalid,4 GB,2,big,,10TB,1M,$5.00,USD,12/01/26,',        // dropped disk -> 0-disk server
        ];
        foreach ($bad as $row) {
            $this->importCsv($row);
        }

        $this->assertSame(0, Server::count());
        $this->assertSame(0, Pricing::count());

        // Plain-numeric RAM (MB) is a legitimate shape and still imports.
        $this->importCsv('C1,L1,plainram.invalid,2048,2,80 GB,,10TB,1M,$5.00,USD,12/01/26,');
        $this->assertDatabaseHas('servers', ['hostname' => 'plainram.invalid', 'ram_as_mb' => 2048]);
    }

    public function test_import_rejects_lossy_bandwidth_period_and_renews()
    {
        // GPT round 10: the last silently-normalized cells.
        $bad = [
            'C1,L1,lossy005.invalid,4 GB,2,80 GB,,oops,1M,$5.00,USD,12/01/26,',      // 'oops' -> 0 = unlimited
            'C1,L1,lossy006.invalid,4 GB,2,80 GB,,10TB,12M,$5.00,USD,12/01/26,',     // '12M' typo -> monthly
            'C1,L1,lossy007.invalid,4 GB,2,80 GB,,10TB,1M,$5.00,USD,13/45/26,',      // overflow date -> null
        ];
        foreach ($bad as $row) {
            $this->importCsv($row);
        }

        $this->assertSame(0, Server::count());

        // Unmetered variants and N/A renewals remain legitimate shapes.
        $this->importCsv('C1,L1,unmeter1.invalid,4 GB,2,80 GB,,Unmetered,1 TIME,$5.00,USD,N/A,');
        $this->assertDatabaseHas('servers', ['hostname' => 'unmeter1.invalid', 'bandwidth' => 0]);
        $this->assertDatabaseHas('pricings', ['term' => 7, 'next_due_date' => null]);
    }

    public function test_seedbox_ips_included_in_reads_and_exports()
    {
        // IPs were assignable to seedboxes but omitted from every read and
        // export — attach-then-silently-drop data.
        \App\Models\Pricing::create([
            'service_id' => 'sbips001', 'service_type' => 6, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        \App\Models\SeedBoxes::create(['id' => 'sbips001', 'title' => 'IP Seedbox', 'active' => 1]);
        \App\Models\IPs::insertIP('sbips001', '192.0.2.80');

        $seedbox = \App\Models\SeedBoxes::seedbox('sbips001');
        $this->assertSame('192.0.2.80', $seedbox->ips[0]->address);

        $exported = (new \App\Services\ExportTransformer())
            ->transformSeedboxForExport($seedbox);
        $this->assertSame('192.0.2.80', $exported['ips'][0]['address']);
        $this->assertContains('ips', (new \App\Services\ExportTransformer())->getSeedboxCsvHeaders());

        // The combined export has its OWN eager-load list (GPT round 11:
        // sibling site) — the JSON must carry the seedbox IPs too.
        $all = json_decode((new \App\Services\ExportService())->exportAll('json')['data'], true);
        $this->assertSame('192.0.2.80', $all['seedboxes'][0]['ips'][0]['address']);
    }

    public function test_catalog_get_by_id_returns_404_for_missing_rows()
    {
        foreach (['/api/pricing/999999', '/api/labels/zzzzzz99', '/api/dns/zzzzzz99',
                  '/api/locations/999999', '/api/providers/999999', '/api/os/999999',
                  '/api/IPs/zzzzzz99'] as $url) {
            $this->getJson($url, $this->apiHeaders())->assertStatus(404);
        }
    }

    public function test_catalog_get_by_id_keeps_array_shape_on_hit()
    {
        $provider = Providers::create(['name' => 'Hit Provider']);

        $response = $this->getJson("/api/providers/{$provider->id}", $this->apiHeaders())
            ->assertOk();

        // Historical contract: a hit returns an ARRAY of rows.
        $body = $response->json();
        $this->assertIsArray($body);
        $this->assertSame('Hit Provider', $body[0]['name']);
    }
}
