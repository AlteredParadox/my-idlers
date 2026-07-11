<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the catalog FK program (GPT round 19):
 *
 * - settings.default_server_os referenced os.id with no constraint, so
 *   an OS used solely as the configured default could be deleted and
 *   the server-create form silently lost its preselection. Restrictive
 *   FK, matching the service-table policy; the column loses its magic
 *   default (20 — a positional guess at "Ubuntu 20.04") and becomes
 *   nullable so legacy danglers reconcile to NULL.
 *
 * - The d_n_s service links (server/shared/reseller/domain) validated
 *   existence at write time but nothing kept them true: deleting a
 *   linked service left a dangling id the DNS detail page rendered as
 *   a dead link. The associations are optional, so nullable FKs with
 *   ON DELETE SET NULL — deleting a service now detaches its DNS
 *   records instead of orphaning them.
 */
return new class extends Migration {
    private const DNS_REFS = [
        'server_id' => 'servers',
        'shared_id' => 'shared_hosting',
        'reseller_id' => 'reseller_hosting',
        'domain_id' => 'domains',
    ];

    public function up()
    {
        // settings.default_server_os: signed int with default 20 → matches
        // os.id's unsigned bigint, nullable, no sentinel.
        Schema::table('settings', function (Blueprint $t) {
            $t->unsignedBigInteger('default_server_os')->nullable()->default(null)->change();
        });
        $nulled = DB::table('settings')
            ->whereNotNull('default_server_os')
            ->whereNotIn('default_server_os', fn ($q) => $q->select('id')->from('os'))
            ->update(['default_server_os' => null]);
        if ($nulled > 0) {
            Log::warning("default-os FK migration: nulled a dangling settings.default_server_os — pick a new default OS in Settings");
        }
        Schema::table('settings', function (Blueprint $t) {
            $t->foreign('default_server_os', 'settings_fk_default_server_os')->references('id')->on('os');
        });

        // d_n_s links: already char(8) nullable — reconcile then constrain.
        foreach (self::DNS_REFS as $column => $service_table) {
            $nulled = DB::table('d_n_s')
                ->whereNotNull($column)
                ->whereNotIn($column, fn ($q) => $q->select('id')->from($service_table))
                ->update([$column => null]);
            if ($nulled > 0) {
                Log::warning("dns FK migration: nulled $nulled dangling d_n_s.$column link(s) to deleted services");
            }
        }
        Schema::table('d_n_s', function (Blueprint $t) {
            foreach (self::DNS_REFS as $column => $service_table) {
                $t->foreign($column, "d_n_s_fk_$column")->references('id')->on($service_table)->nullOnDelete();
            }
        });
    }

    public function down()
    {
        // Same constraint as the catalog FK migration: SQLite cannot drop
        // foreign keys — fail loudly instead of half-reverting.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            throw new RuntimeException(
                'add_default_os_and_dns_foreign_keys cannot be rolled back on SQLite — restore from a backup or use migrate:fresh.'
            );
        }

        Schema::table('d_n_s', function (Blueprint $t) {
            foreach (array_keys(self::DNS_REFS) as $column) {
                $t->dropForeign("d_n_s_fk_$column");
            }
        });

        Schema::table('settings', function (Blueprint $t) {
            $t->dropForeign('settings_fk_default_server_os');
        });
        DB::table('settings')->whereNull('default_server_os')->update(['default_server_os' => 20]);
        Schema::table('settings', function (Blueprint $t) {
            $t->integer('default_server_os')->nullable(false)->default(20)->change();
        });
    }
};
