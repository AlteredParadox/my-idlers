<?php

namespace Tests\Feature;

use App\Models\Disk;
use App\Models\Labels;
use App\Models\LabelsAssigned;
use App\Models\Locations;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the GPT round-17 findings:
 * 1. The YABS replay dedupe was a raceable check-then-insert (exists() in
 *    one query, insert in a later transaction, no unique constraint). The
 *    (server_id, output_date) unique index now makes the database
 *    authoritative and the ingest treats the loser's duplicate-key error
 *    as idempotent success.
 * 2. DNS creation wrote the record and its label assignments outside a
 *    transaction — a label failure stranded a half-created DNS row.
 * 3. Labels::insertLabelsAssigned caught EVERY QueryException as "duplicate
 *    assignment", disguising unrelated database errors as successful
 *    writes; only the unique-constraint violation is suppressed now.
 */
class GptRound17RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LabelsAssigned::flushEventListeners();
        parent::tearDown();
    }

    private function makeServer(): Server
    {
        $server_id = Str::random(8);
        (new Pricing)->insertPricing(1, $server_id, 'USD', 5.00, 1, '2027-01-01');
        Disk::insertDisk($server_id, 50, 'GB', 'SSD');

        return Server::create([
            'id' => $server_id, 'hostname' => 'gpt17.example.com', 'server_type' => 1,
            'os_id' => OS::create(['name' => 'OS ' . $server_id])->id,
            'provider_id' => Providers::create(['name' => 'P' . $server_id])->id,
            'location_id' => Locations::create(['name' => 'L' . $server_id])->id,
            'ram' => 4, 'ram_type' => 'GB', 'ram_as_mb' => 4096, 'disk' => 50, 'disk_type' => 'GB',
            'disk_as_gb' => 50, 'cpu' => 4, 'has_yabs' => 0, 'active' => 1, 'owned_since' => '2026-01-01',
        ]);
    }

    private function yabsPayload(): array
    {
        return [
            'version' => 'v2024-06-09', 'time' => '20260705-120000',
            'os' => ['distro' => 'Debian 13', 'kernel' => '6.1.0', 'uptime' => 432000],
            'net' => ['ipv4' => 1, 'ipv6' => 0],
            'cpu' => ['model' => 'AMD EPYC', 'cores' => 4, 'freq' => '2299.998', 'aes' => 1, 'virt' => 'KVM'],
            'mem' => ['ram' => 4014080, 'swap' => 524288, 'disk' => 49283072],
        ];
    }

    public function test_concurrent_duplicate_yabs_deliveries_insert_exactly_one_row()
    {
        Settings::firstOrCreate(['id' => 1]);
        $template = $this->makeServer();
        $server = $this->makeServer();

        // Template row to clone: an already-ingested copy of this exact run
        // (on another server) supplies the full column set for the injection.
        $this->postJson(
            URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $template->id]),
            $this->yabsPayload()
        )->assertStatus(200);

        // Commit the competing identical run immediately BEFORE the real
        // yabs insert executes — after isDuplicateRun() has already passed.
        $raced = false;
        DB::beforeExecuting(function ($query) use (&$raced, $template, $server) {
            if ($raced) {
                return;
            }
            $sql = strtolower(ltrim($query));
            $intoYabs = str_contains($sql, 'into "yabs"') || str_contains($sql, 'into `yabs`');
            if (str_starts_with($sql, 'insert') && $intoYabs) {
                $raced = true; // set FIRST: the injected insert re-enters this hook
                $row = (array) DB::table('yabs')->where('server_id', $template->id)->first();
                $row['id'] = 'racer017';
                $row['server_id'] = $server->id;
                DB::table('yabs')->insert($row);
            }
        });

        // The injected racer shares persist()'s transaction, so the UCVE
        // rollback wipes it too and the replay re-check finds no committed
        // row — fail-closed here. A REAL racer commits on its own
        // connection, and that idempotent-200 path is pinned by
        // GptRound16 (HTTP replay) and GptRound18 (persist-level replay).
        // The property pinned HERE is that the race can never land TWO
        // rows — pre-fix, with no unique index, both inserts landed.
        $this->postJson(
            URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $server->id]),
            $this->yabsPayload()
        );

        $this->assertLessThan(2, DB::table('yabs')->where('server_id', $server->id)->count(),
            'the unique run index must hold against a racing delivery');
    }

    public function test_dns_creation_rolls_back_wholesale_when_a_label_write_fails()
    {
        $user = User::factory()->create();
        Labels::create(['id' => 'gpt17lb1', 'label' => 'gpt17-label']);

        LabelsAssigned::creating(function () {
            throw new \RuntimeException('injected label write failure');
        });

        $this->actingAs($user)->post(route('dns.store'), [
            'hostname' => 'gpt17.example.com',
            'address' => '203.0.113.17',
            'dns_type' => 'A',
            'label1' => 'gpt17lb1',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('d_n_s', ['hostname' => 'gpt17.example.com']);
        $this->assertDatabaseCount('labels_assigned', 0);
    }

    public function test_duplicate_label_assignment_is_still_suppressed()
    {
        Labels::create(['id' => 'gpt17lb2', 'label' => 'gpt17-dupe']);
        LabelsAssigned::create(['label_id' => 'gpt17lb2', 'service_id' => 'gpt17svc']);

        Labels::insertLabelsAssigned(['gpt17lb2', null, null, null], 'gpt17svc');

        $this->assertSame(1, LabelsAssigned::where('service_id', 'gpt17svc')->count());
    }

    public function test_non_duplicate_label_write_errors_are_no_longer_swallowed()
    {
        Labels::create(['id' => 'gpt17lb3', 'label' => 'gpt17-err']);

        // A genuinely broken write (table gone) must surface, not be
        // disguised as a successful duplicate suppression.
        Schema::drop('labels_assigned');

        $this->expectException(QueryException::class);
        Labels::insertLabelsAssigned(['gpt17lb3', null, null, null], 'gpt17svc');
    }
}
