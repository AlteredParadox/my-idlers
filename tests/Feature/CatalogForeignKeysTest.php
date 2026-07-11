<?php

namespace Tests\Feature;

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
 * The catalog foreign-key program (deferred from GPT round 17): the
 * os/providers/locations catalogs were guarded only by raceable
 * application checks — `exists:` rules on service writes and an in-use
 * scan before catalog deletes. A service created between the scan and
 * the delete kept a dangling catalog id. Restrictive FKs now make the
 * database authoritative on both drivers (empirically proven: Laravel 13
 * adds enforced FKs on SQLite via table rebuild); dangling legacy
 * references were reconciled to NULL by the migration, and the destroy
 * paths absorb ONLY the FK refusal into the friendly in-use error.
 */
class CatalogForeignKeysTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_FKS = [
        'servers' => ['os_id' => 'os', 'provider_id' => 'providers', 'location_id' => 'locations'],
        'shared_hosting' => ['provider_id' => 'providers', 'location_id' => 'locations'],
        'reseller_hosting' => ['provider_id' => 'providers', 'location_id' => 'locations'],
        'domains' => ['provider_id' => 'providers'],
        'seedboxes' => ['provider_id' => 'providers', 'location_id' => 'locations'],
    ];

    public function test_every_catalog_reference_carries_an_enforced_foreign_key()
    {
        foreach (self::EXPECTED_FKS as $table => $refs) {
            $declared = collect(Schema::getForeignKeys($table))
                ->mapWithKeys(fn ($fk) => [$fk['columns'][0] => $fk['foreign_table']]);

            foreach ($refs as $column => $catalog) {
                $this->assertSame($catalog, $declared->get($column),
                    "$table.$column must carry a foreign key to $catalog");
            }
        }
    }

    public function test_a_dangling_catalog_reference_is_rejected_by_the_database()
    {
        (new Pricing)->insertPricing(1, 'fkt00001', 'USD', 5, 1, '2027-01-01');
        // Real rows for the OTHER references: only os_id dangles, so the
        // failure can come from nothing but its foreign key (nulls would
        // trip the pre-FK schema's NOT NULL and pass this test vacuously).
        $provider = Providers::create(['name' => 'fk-real-p']);
        $location = Locations::create(['name' => 'fk-real-l']);

        $this->expectException(QueryException::class);
        DB::table('servers')->insert([
            'id' => 'fkt00001', 'hostname' => 'h', 'server_type' => 1,
            'os_id' => 424242, 'provider_id' => $provider->id, 'location_id' => $location->id,
            'ram' => 1, 'ram_type' => 'GB', 'ram_as_mb' => 1024, 'disk' => 10,
            'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1, 'active' => 1,
            'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);
    }

    public function test_destroy_race_loser_gets_the_friendly_error_not_a_500()
    {
        Settings::firstOrCreate(['id' => 1]);
        $user = User::factory()->create();
        $provider = Providers::create(['name' => 'raced-provider']);

        // Claim the provider immediately BEFORE its delete executes — after
        // the controller's in-use scan has already passed. The FK refuses
        // the delete; the controller must map that to the in-use error.
        $raced = false;
        DB::beforeExecuting(function ($query) use (&$raced, $provider) {
            if ($raced) {
                return;
            }
            $sql = strtolower(ltrim($query));
            $onProviders = str_contains($sql, 'from "providers"') || str_contains($sql, 'from `providers`');
            if (str_starts_with($sql, 'delete') && $onProviders) {
                $raced = true; // set FIRST: the inserts below re-enter this hook
                (new Pricing)->insertPricing(4, 'fkrace01', 'USD', 5, 4, '2027-01-01');
                DB::table('domains')->insert([
                    'id' => 'fkrace01', 'domain' => 'raced', 'extension' => '.com',
                    'provider_id' => $provider->id, 'owned_since' => '2024-01-01',
                ]);
            }
        });

        $response = $this->actingAs($user)->delete(route('providers.destroy', $provider));

        $response->assertRedirect(route('providers.index'));
        $response->assertSessionHas('error', 'Cannot delete a provider that is assigned to services.');
        $this->assertDatabaseHas('providers', ['id' => $provider->id]);
        $this->assertDatabaseHas('domains', ['id' => 'fkrace01', 'provider_id' => $provider->id]);
    }

    public function test_unreferenced_catalog_rows_still_delete_normally()
    {
        Settings::firstOrCreate(['id' => 1]);
        $user = User::factory()->create();
        $os = OS::create(['name' => 'deletable-os']);
        $location = Locations::create(['name' => 'deletable-loc']);

        $this->actingAs($user)->delete(route('os.destroy', $os))
            ->assertSessionHas('success');
        $this->actingAs($user)->delete(route('locations.destroy', $location))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('os', ['id' => $os->id]);
        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_null_catalog_references_remain_writable_and_renderable()
    {
        // The migration nulls legacy danglers — the app must accept and
        // render that shape (the index shows '-' for missing relations).
        Settings::firstOrCreate(['id' => 1]);
        (new Pricing)->insertPricing(1, 'fkt00002', 'USD', 5, 1, '2027-01-01');
        Server::create([
            'id' => 'fkt00002', 'hostname' => 'nulls.example.com', 'server_type' => 1,
            'os_id' => null, 'provider_id' => null, 'location_id' => null,
            'ram' => 1, 'ram_type' => 'GB', 'ram_as_mb' => 1024, 'disk' => 10,
            'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1, 'active' => 1,
            'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('servers.index'))
            ->assertStatus(200)
            ->assertSee('nulls.example.com');
    }
}
