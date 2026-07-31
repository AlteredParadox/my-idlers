<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UsersSeeder extends Seeder
{
    /** Published in the README, so the production guard names it explicitly. */
    public const DEMO_EMAIL = 'admin@admin.com';

    public function run()
    {
        DB::table('users')->insert([
            'name' => 'Admin',
            'email' => self::DEMO_EMAIL,
            'password' => Hash::make('password'),
            'api_token' => \App\Models\User::hashApiToken(Str::random(60)),
            'email_verified_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }
}
