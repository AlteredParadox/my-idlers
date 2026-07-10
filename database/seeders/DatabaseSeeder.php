<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Core seeders and the table each populates. Each is skipped when its
     * table already has rows: an accidental `db:seed` on an installed DB
     * used to duplicate every provider/location/OS dropdown entry and then
     * abort mid-run on the labels unique index, with no rollback.
     */
    private const CORE_SEEDERS = [
        SettingsSeeder::class => 'settings',
        ProvidersSeeder::class => 'providers',
        LocationsSeeder::class => 'locations',
        OsSeeder::class => 'os',
        LabelsSeeder::class => 'labels',
    ];

    public function run()
    {
        foreach (self::CORE_SEEDERS as $seeder => $table) {
            if (DB::table($table)->count() === 0) {
                $this->call($seeder);
            } else {
                $this->command?->warn("Skipping $seeder: '$table' already has rows.");
            }
        }

        // Optional: Demo user and sample data (set SEED_DEMO_DATA=true in .env)
        if ($this->shouldSeedDemoData()) {
            // Same already-seeded guard as the core seeders: a re-run with
            // the flag still set (it survives in the cached config) would
            // otherwise crash on the demo user's unique email — or, with
            // that user renamed/deleted, silently duplicate the entire demo
            // set under fresh random ids.
            if (DB::table('users')->count() > 0 || DB::table('servers')->count() > 0) {
                $this->command?->warn('Skipping demo data: users/servers already have rows.');

                return;
            }
            $this->call(UsersSeeder::class);
            $this->call(ServersSeeder::class);
            $this->call(SharedSeeder::class);
            $this->call(ResellerSeeder::class);
            $this->call(DomainsSeeder::class);
            $this->call(MiscSeeder::class);
            $this->call(SeedBoxesSeeder::class);
            $this->call(DNSSeeder::class);
            $this->call(YabsSeeder::class);
        } else {
            $this->command?->warn('Skipping demo data (SEED_DEMO_DATA is not true).');
        }
    }

    private function shouldSeedDemoData(): bool
    {
        // config(), not env(): under a cached config (standard production
        // step) env() returns null at runtime and the demo set silently
        // never seeded — the CLI residual of the max_users class.
        return filter_var(config('custom.seed_demo_data'), FILTER_VALIDATE_BOOL);
    }
}
