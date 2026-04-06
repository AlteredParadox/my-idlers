<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('prometheus_url')->nullable()->after('default_per_page');
            $table->integer('prometheus_check_interval')->default(20)->after('prometheus_url');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['prometheus_url', 'prometheus_check_interval']);
        });
    }
};
