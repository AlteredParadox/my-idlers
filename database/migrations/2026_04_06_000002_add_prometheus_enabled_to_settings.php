<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('prometheus_enabled')->default(false)->after('default_per_page');
        });

        DB::table('settings')
            ->whereNotNull('prometheus_url')
            ->where('prometheus_url', '!=', '')
            ->update(['prometheus_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('prometheus_enabled');
        });
    }
};
