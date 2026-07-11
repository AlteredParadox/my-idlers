<?php

namespace Tests\Feature;

use App\Models\DNS;
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
use Tests\TestCase;

/**
 * Regressions for the GPT round-19 findings — the two integrity gaps the
 * catalog FK program left open:
 * 1. settings.default_server_os had no constraint: an OS used solely as
 *    the configured default could be deleted, silently unselecting the
 *    server-create form's default. Restrictive FK + the OS destroy's
 *    in-use scan now covers the settings row.
 * 2. The d_n_s service links validated existence at write time but
 *    nothing kept them true — deleting a linked service left a dangling
 *    id rendered as a dead link. Nullable FKs with ON DELETE SET NULL:
 *    deleting a service detaches its DNS records.
 */
class GptRound19RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_foreign_keys_are_declared()
    {
        $settings = collect(Schema::getForeignKeys('settings'))
            ->mapWithKeys(fn ($fk) => [$fk['columns'][0] => $fk['foreign_table']]);
        $this->assertSame('os', $settings->get('default_server_os'));

        $dns = collect(Schema::getForeignKeys('d_n_s'))
            ->mapWithKeys(fn ($fk) => [$fk['columns'][0] => $fk['foreign_table']]);
        foreach (['server_id' => 'servers', 'shared_id' => 'shared_hosting',
                     'reseller_id' => 'reseller_hosting', 'domain_id' => 'domains'] as $column => $table) {
            $this->assertSame($table, $dns->get($column), "d_n_s.$column must reference $table");
        }
    }

    public function test_an_os_used_only_as_the_default_cannot_be_deleted()
    {
        $os = OS::create(['name' => 'default-only-os']);
        Settings::firstOrCreate(['id' => 1])->update(['default_server_os' => $os->id]);

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('os.destroy', $os));

        $response->assertSessionHas('error', 'Cannot delete an OS that is assigned to servers or set as the default OS.');
        $this->assertDatabaseHas('os', ['id' => $os->id]);
        $this->assertDatabaseHas('settings', ['id' => 1, 'default_server_os' => $os->id]);
    }

    public function test_a_dangling_default_os_is_rejected_by_the_database()
    {
        Settings::firstOrCreate(['id' => 1]);

        $this->expectException(QueryException::class);
        DB::table('settings')->where('id', 1)->update(['default_server_os' => 424242]);
    }

    public function test_deleting_a_linked_service_detaches_its_dns_records()
    {
        Settings::firstOrCreate(['id' => 1]);
        $user = User::factory()->create();

        (new Pricing)->insertPricing(1, 'g19srv01', 'USD', 5, 1, '2027-01-01');
        Server::create([
            'id' => 'g19srv01', 'hostname' => 'dns-linked.example.com', 'server_type' => 1,
            'os_id' => OS::create(['name' => 'g19-os'])->id,
            'provider_id' => Providers::create(['name' => 'g19-p'])->id,
            'location_id' => Locations::create(['name' => 'g19-l'])->id,
            'ram' => 1, 'ram_type' => 'GB', 'ram_as_mb' => 1024, 'disk' => 10,
            'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1, 'active' => 1,
            'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);
        $dns = DNS::create([
            'id' => 'g19dns01', 'hostname' => 'a.example.com', 'dns_type' => 'A',
            'address' => '203.0.113.19', 'server_id' => 'g19srv01',
        ]);

        $this->actingAs($user)->delete(route('servers.destroy', 'g19srv01'));
        $this->assertDatabaseMissing('servers', ['id' => 'g19srv01']);

        // Pre-fix: the link survived as a dangling id and the detail page
        // rendered a dead /servers/g19srv01 link (404 on click).
        $this->assertDatabaseHas('d_n_s', ['id' => 'g19dns01', 'server_id' => null]);
        $this->actingAs($user)->get(route('dns.show', $dns))
            ->assertStatus(200)
            ->assertDontSee('servers/g19srv01');
    }
}
