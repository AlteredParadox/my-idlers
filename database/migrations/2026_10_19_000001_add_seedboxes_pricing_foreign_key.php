<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * add_foreign_keys (2022-05-09) gave servers, shared_hosting,
 * reseller_hosting, domains and misc_services an `id -> pricings.service_id`
 * constraint. seedboxes was created six days later and never got one, so for
 * five of six service types the database guarantees a pricing row and for
 * seedboxes nothing does -- while the seedbox views dereference `$row->price`
 * unguarded, and one seedbox without a pricing row takes /seedboxes down with
 * a 500. Every current write path is transactional and pricing-first; the
 * gap is legacy data (an install upgraded from upstream) and manual edits.
 * Same principle as add_catalog_foreign_keys: make the database authoritative.
 *
 * Reconciliation: an orphaned seedbox cannot be nulled (the reference is its
 * primary key), so it gets a zero-price placeholder pricing row, loudly; the
 * operator corrects it in the edit form. Nothing is deleted.
 */
return new class extends Migration {
    public function up()
    {
        $orphans = DB::table('seedboxes')
            ->whereNotIn('id', fn ($query) => $query->select('service_id')->from('pricings'))
            ->pluck('id');

        foreach ($orphans as $id) {
            DB::table('pricings')->insert([
                'service_id' => $id, 'service_type' => 6, 'active' => 1,
                'currency' => 'USD', 'price' => 0, 'term' => 1, 'as_usd' => 0, 'usd_per_month' => 0,
                'next_due_date' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($orphans->isNotEmpty()) {
            Log::warning('seedboxes pricing FK migration: ' . $orphans->count()
                . ' seedbox(es) had no pricing row and were given a zero-price USD placeholder: '
                . $orphans->implode(', '));
        }

        Schema::table('seedboxes', function (Blueprint $table) {
            $table->foreign('id', 'seedboxes_fk_pricing')->references('service_id')->on('pricings')->onDelete('cascade');
        });
    }

    public function down()
    {
        // SQLite cannot drop a foreign key by name (see add_catalog_foreign_keys).
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            throw new \App\Exceptions\IrreversibleMigrationException(
                'add_seedboxes_pricing_foreign_key cannot be rolled back on SQLite — restore from a backup or use migrate:fresh.'
            );
        }

        Schema::table('seedboxes', function (Blueprint $table) {
            $table->dropForeign('seedboxes_fk_pricing');
        });
        // The placeholder rows are left in place: they are valid pricing rows
        // and the operator may have edited them since.
    }
};
