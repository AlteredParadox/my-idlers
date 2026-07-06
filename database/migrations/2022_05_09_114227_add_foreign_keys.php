<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddForeignKeys extends Migration
{
    public function up()
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->foreign('id', 'servers_fk_pricing')->references('service_id')->on('pricings')->onDelete('cascade');
        });

        Schema::table('shared_hosting', function (Blueprint $table) {
            $table->foreign('id', 'shared_fk_pricing')->references('service_id')->on('pricings')->onDelete('cascade');
        });

        Schema::table('reseller_hosting', function (Blueprint $table) {
            $table->foreign('id', 'reseller_fk_pricing')->references('service_id')->on('pricings')->onDelete('cascade');
        });

        Schema::table('domains', function (Blueprint $table) {
            $table->foreign('id', 'domains_fk_pricing')->references('service_id')->on('pricings')->onDelete('cascade');
        });

        Schema::table('misc_services', function (Blueprint $table) {
            $table->foreign('id', 'misc_fk_pricing')->references('service_id')->on('pricings')->onDelete('cascade');
        });

        Schema::table('yabs', function (Blueprint $table) {
            $table->foreign('server_id', 'yabs_fk_servers')->references('id')->on('servers');
        });

        Schema::table('disk_speed', function (Blueprint $table) {
            $table->foreign('id', 'ds_fk_yabs')->references('id')->on('yabs');
        });

        Schema::table('network_speed', function (Blueprint $table) {
            $table->foreign('id', 'ns_fk_yabs')->references('id')->on('yabs');
        });
    }

    public function down()
    {
        // Irreversible: schema changes from up() are retained on rollback.
    }
}
