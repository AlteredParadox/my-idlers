<?php

namespace Tests\Feature;

use App\Models\DNS;
use App\Models\Labels;
use App\Models\LabelsAssigned;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review round 43: the lock-free read-modify-write lens. Update paths
 * read their row before the write transaction, so a concurrent destroy
 * could turn the transaction's label/IP re-inserts into ghost rows for a
 * deleted service (no FK stops them), and the API's derived columns were
 * computed from a pre-transaction snapshot. Every update now re-reads its
 * row LOCKED inside the transaction and bails when it is gone. The DNS
 * update additionally predated the round-14 atomicity pass entirely —
 * its model write and label re-sync ran unwrapped.
 */
class Round43RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dns_update_is_atomic()
    {
        $user = User::factory()->create();
        DNS::create(['id' => 'r43dns01', 'hostname' => 'old.example.com', 'dns_type' => 'A', 'address' => '192.0.2.1']);
        Labels::create(['id' => 'r43labl1', 'label' => 'dns-l']);

        // Fail the label re-insert — the hostname change must roll back
        // with it (pre-fix the update had already committed, unwrapped).
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'insert') && str_contains($query->sql, 'labels_assigned')) {
                throw new \RuntimeException('injected insert failure');
            }
        });

        $this->actingAs($user)->put(route('dns.update', 'r43dns01'), [
            'hostname' => 'new.example.com', 'dns_type' => 'A', 'address' => '192.0.2.2',
            'label1' => 'r43labl1',
        ])->assertStatus(500);

        $this->assertDatabaseHas('d_n_s', ['id' => 'r43dns01', 'hostname' => 'old.example.com', 'address' => '192.0.2.1']);
        $this->assertDatabaseMissing('labels_assigned', ['service_id' => 'r43dns01']);
    }

    public function test_web_update_bails_when_the_row_is_deleted_after_binding()
    {
        // Round 56: mutating lockedRowStillExists() to `return true` survived
        // the suite — the seven web guard sites were unpinned. Simulate the
        // concurrent destroy by deleting the bound row from a DB::listen hook
        // once the request's first select on the table has completed (the
        // route binding), so the locked in-transaction re-read finds it gone.
        $user = User::factory()->create();
        DNS::create(['id' => 'r43race1', 'hostname' => 'old.example.com', 'dns_type' => 'A', 'address' => '192.0.2.9']);
        Labels::create(['id' => 'r43labl9', 'label' => 'race-l']);

        $deleted = false;
        DB::listen(function ($query) use (&$deleted) {
            if ($deleted) {
                return;
            }
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'select') && str_contains($sql, 'd_n_s')) {
                $deleted = true;
                DB::table('d_n_s')->where('id', 'r43race1')->delete();
            }
        });

        $response = $this->actingAs($user)->put(route('dns.update', 'r43race1'), [
            'hostname' => 'new.example.com', 'dns_type' => 'A', 'address' => '192.0.2.10',
            'label1' => 'r43labl9',
        ]);

        $response->assertRedirect(route('dns.index'));
        $response->assertSessionHas('error', 'DNS record no longer exists.');
        // The bail must leave no ghost rows behind for the deleted record
        $this->assertDatabaseMissing('labels_assigned', ['service_id' => 'r43race1']);
        $this->assertDatabaseMissing('d_n_s', ['id' => 'r43race1']);
    }

    public function test_api_update_of_missing_server_writes_no_ghost_child_rows()
    {
        $token = Str::random(60);
        User::factory()->create(['api_token' => User::hashApiToken($token)]);
        Labels::create(['id' => 'r43labl2', 'label' => 'ghost-l']);

        // The locked in-transaction existence check must 404 without any
        // of the label/IP/disk inserts the transaction would otherwise run.
        $this->putJson('/api/servers/r43ghost', [
            'ips' => ['10.43.0.1'],
            'labels' => ['r43labl2'],
            'disk' => 100, 'disk_type' => 'GB',
        ], ['Authorization' => 'Bearer ' . $token])->assertStatus(404);

        $this->assertDatabaseMissing('ips', ['service_id' => 'r43ghost']);
        $this->assertDatabaseMissing('labels_assigned', ['service_id' => 'r43ghost']);
        $this->assertDatabaseMissing('server_disks', ['server_id' => 'r43ghost']);
    }
}
