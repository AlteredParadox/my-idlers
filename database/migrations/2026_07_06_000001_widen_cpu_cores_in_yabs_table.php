<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * yabs.sh reports cpu.cores as the THREAD count (nproc). Signed TINYINT
     * caps at 127, so ingest hard-failed on MySQL strict for 128+ thread
     * dedis (dual-socket / EPYC) — surfaced as "not valid YABS".
     */
    public function up(): void
    {
        Schema::table('yabs', function (Blueprint $table) {
            $table->smallInteger('cpu_cores')->change();
        });
    }

    public function down(): void
    {
        Schema::table('yabs', function (Blueprint $table) {
            $table->tinyInteger('cpu_cores')->change();
        });
    }
};
