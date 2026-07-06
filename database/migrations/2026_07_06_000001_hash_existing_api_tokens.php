<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_token', 64)->change();
        });

        foreach (DB::table('users')
            ->whereRaw('LENGTH(api_token) != 64')
            ->orderBy('id')
            ->cursor() as $user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['api_token' => User::hashApiToken($user->api_token)]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_token', 60)->change();
        });
    }
};
