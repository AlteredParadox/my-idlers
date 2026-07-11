<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The os/providers/locations catalogs were guarded only by application
 * checks: `exists:` rules on service writes and an in-use scan before a
 * catalog delete. Both are check-then-write and therefore raceable — a
 * service created between the in-use scan and the delete kept a dangling
 * catalog id. These restrictive foreign keys make the database
 * authoritative; the application checks remain for friendly errors.
 *
 * Reconciliation: the reference columns carried magic sentinel defaults
 * (9999, and 0 for servers.os_id) instead of NULL, so legacy rows can
 * reference catalog ids that never existed or were since deleted — the
 * UI already renders '-' for those. Dangling references are nulled
 * (loudly), the columns become nullable, and the sentinels are dropped.
 */
return new class extends Migration {
    private const CATALOG_REFS = [
        'servers' => ['os_id' => 'os', 'provider_id' => 'providers', 'location_id' => 'locations'],
        'shared_hosting' => ['provider_id' => 'providers', 'location_id' => 'locations'],
        'reseller_hosting' => ['provider_id' => 'providers', 'location_id' => 'locations'],
        'domains' => ['provider_id' => 'providers'],
        'seedboxes' => ['provider_id' => 'providers', 'location_id' => 'locations'],
    ];

    /** Restore point for down(): the legacy sentinel each column shipped with. */
    private function legacySentinel(string $column): int
    {
        return $column === 'os_id' ? 0 : 9999;
    }

    public function up()
    {
        foreach (self::CATALOG_REFS as $table => $refs) {
            // Columns must be nullable BEFORE the danglers can be nulled
            // (one rebuild for all of a table's columns — SQLite rewrites
            // the table per Schema::table call).
            Schema::table($table, function (Blueprint $t) use ($refs) {
                foreach (array_keys($refs) as $column) {
                    $t->unsignedBigInteger($column)->nullable()->default(null)->change();
                }
            });

            // Null the dangling references or the constraint cannot be
            // created over legacy data.
            foreach ($refs as $column => $catalog) {
                $nulled = DB::table($table)
                    ->whereNotNull($column)
                    ->whereNotIn($column, fn ($query) => $query->select('id')->from($catalog))
                    ->update([$column => null]);
                if ($nulled > 0) {
                    Log::warning("catalog FK migration: nulled $nulled dangling $table.$column reference(s) — the UI renders these as '-'");
                }
            }

            Schema::table($table, function (Blueprint $t) use ($table, $refs) {
                foreach ($refs as $column => $catalog) {
                    // RESTRICT (the default): an in-use catalog row cannot
                    // be deleted; a dangling reference cannot be written.
                    $t->foreign($column, "{$table}_fk_{$column}")->references('id')->on($catalog);
                }
            });
        }
    }

    public function down()
    {
        // SQLite cannot drop foreign keys (no ALTER support; Laravel throws
        // "does not support dropping foreign keys by name"), and reverting
        // the columns to NOT NULL sentinels while the constraints remain
        // would leave a schema that rejects its own defaults. Fail loudly
        // instead of half-reverting.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            throw new \App\Exceptions\IrreversibleMigrationException(
                'add_catalog_foreign_keys cannot be rolled back on SQLite — restore from a backup or use migrate:fresh.'
            );
        }

        foreach (self::CATALOG_REFS as $table => $refs) {
            Schema::table($table, function (Blueprint $t) use ($table, $refs) {
                foreach (array_keys($refs) as $column) {
                    $t->dropForeign("{$table}_fk_{$column}");
                }
            });
            foreach (array_keys($refs) as $column) {
                DB::table($table)->whereNull($column)->update([$column => $this->legacySentinel($column)]);
            }
            Schema::table($table, function (Blueprint $t) use ($refs) {
                foreach (array_keys($refs) as $column) {
                    $t->unsignedBigInteger($column)->nullable(false)->default($this->legacySentinel($column))->change();
                }
            });
        }
    }
};
