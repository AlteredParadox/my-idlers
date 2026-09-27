<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Some fresh installs already have cpu_model because the original
        // migration was edited after release. Only legacy schemas need repair.
        if (Schema::hasColumn('yabs', 'cpu') && ! Schema::hasColumn('yabs', 'cpu_model')) {
            Schema::table('yabs', function (Blueprint $table) {
                $table->renameColumn('cpu', 'cpu_model');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('yabs', 'cpu_model') && ! Schema::hasColumn('yabs', 'cpu')) {
            Schema::table('yabs', function (Blueprint $table) {
                $table->renameColumn('cpu_model', 'cpu');
            });
        }
    }
};
