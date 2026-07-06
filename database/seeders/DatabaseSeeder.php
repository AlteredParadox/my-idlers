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
            $this->call(UsersSeeder::class);
            $this->call(ServersSeeder::class);
            $this->call(SharedSeeder::class);
            $this->call(ResellerSeeder::class);
            $this->call(DomainsSeeder::class);
            $this->call(MiscSeeder::class);
            $this->call(SeedBoxesSeeder::class);
            $this->call(DNSSeeder::class);
            $this->call(YabsSeeder::class);
        }
    }

    private function shouldSeedDemoData(): bool
    {
        return env('SEED_DEMO_DATA', false) === true;
    }
}
