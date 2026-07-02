<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->tinyInteger('transferrable')->nullable()->after('was_promo');
        });

        Schema::table('shared_hosting', function (Blueprint $table) {
            $table->tinyInteger('transferrable')->nullable()->after('was_promo');
        });

        Schema::table('reseller_hosting', function (Blueprint $table) {
            $table->tinyInteger('transferrable')->nullable()->after('was_promo');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->tinyInteger('transferrable')->nullable()->after('active');
        });

        Schema::table('seedboxes', function (Blueprint $table) {
            $table->tinyInteger('transferrable')->nullable()->after('was_promo');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('transferrable');
        });

        Schema::table('shared_hosting', function (Blueprint $table) {
            $table->dropColumn('transferrable');
        });

        Schema::table('reseller_hosting', function (Blueprint $table) {
            $table->dropColumn('transferrable');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('transferrable');
        });

        Schema::table('seedboxes', function (Blueprint $table) {
            $table->dropColumn('transferrable');
        });
    }
};
