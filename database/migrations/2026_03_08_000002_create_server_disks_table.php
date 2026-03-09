<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up()
    {
        Schema::create('server_disks', function (Blueprint $table) {
            $table->char('id', 8)->primary();
            $table->char('server_id', 8);
            $table->integer('disk_size')->default(0);
            $table->char('disk_unit', 2)->default('GB');
            $table->integer('disk_as_gb')->default(0);
            $table->string('disk_media', 4)->default('SSD');
            $table->timestamps();
        });

        // Migrate existing disk data from servers table
        $servers = DB::table('servers')->where('disk', '>', 0)->get();
        foreach ($servers as $server) {
            DB::table('server_disks')->insert([
                'id' => Str::random(8),
                'server_id' => $server->id,
                'disk_size' => $server->disk,
                'disk_unit' => $server->disk_type,
                'disk_as_gb' => $server->disk_as_gb,
                'disk_media' => 'SSD',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down()
    {
        Schema::dropIfExists('server_disks');
    }
};
