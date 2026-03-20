<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->integer('link_speed')->nullable()->after('bandwidth');
        });

        Schema::table('shared_hosting', function (Blueprint $table) {
            $table->integer('link_speed')->nullable()->after('bandwidth');
        });

        Schema::table('reseller_hosting', function (Blueprint $table) {
            $table->integer('link_speed')->nullable()->after('bandwidth');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('link_speed');
        });

        Schema::table('shared_hosting', function (Blueprint $table) {
            $table->dropColumn('link_speed');
        });

        Schema::table('reseller_hosting', function (Blueprint $table) {
            $table->dropColumn('link_speed');
        });
    }
};
