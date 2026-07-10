<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A YABS run is identified by (server_id, output_date). The application
 * dedupe is a check-then-insert and therefore raceable: two simultaneous
 * webhook deliveries can both pass the exists() check and both insert.
 * The unique index makes the database authoritative; the ingest treats
 * the loser's duplicate-key error as idempotent success.
 */
return new class extends Migration {
    public function up()
    {
        // Reconcile replay artifacts first: duplicate (server_id,
        // output_date) rows are the same benchmark output ingested more
        // than once before the dedupe existed. Keep one deterministically
        // (MIN(id)); drop the others with their child rows (keyed on the
        // duplicate yabs ids), or the index creation fails on legacy data.
        $groups = DB::table('yabs')
            ->select('server_id', 'output_date', DB::raw('MIN(id) as keep_id'))
            ->groupBy('server_id', 'output_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $loser_ids = DB::table('yabs')
                ->where('server_id', $group->server_id)
                ->where('output_date', $group->output_date)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id');

            DB::table('disk_speed')->whereIn('id', $loser_ids)->delete();
            DB::table('network_speed')->whereIn('id', $loser_ids)->delete();
            DB::table('yabs')->whereIn('id', $loser_ids)->delete();
        }

        Schema::table('yabs', function (Blueprint $table) {
            $table->unique(['server_id', 'output_date'], 'yabs_server_run_unique');
        });
    }

    public function down()
    {
        Schema::table('yabs', function (Blueprint $table) {
            $table->dropUnique('yabs_server_run_unique');
        });
    }
};
