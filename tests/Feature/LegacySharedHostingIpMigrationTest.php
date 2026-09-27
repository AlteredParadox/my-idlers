<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacySharedHostingIpMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_legacy_shared_hosting_ips_without_creating_duplicates(): void
    {
        Schema::table('shared_hosting', function (Blueprint $table): void {
            $table->boolean('has_dedicated_ip')->default(false);
            $table->string('ip')->nullable();
        });

        DB::table('pricings')->insert([
            ['service_id' => 'legacy01', 'service_type' => 3, 'currency' => 'USD', 'price' => 1, 'term' => 1, 'as_usd' => 1, 'usd_per_month' => 1],
            ['service_id' => 'legacy02', 'service_type' => 3, 'currency' => 'USD', 'price' => 1, 'term' => 1, 'as_usd' => 1, 'usd_per_month' => 1],
            ['service_id' => 'legacy03', 'service_type' => 3, 'currency' => 'USD', 'price' => 1, 'term' => 1, 'as_usd' => 1, 'usd_per_month' => 1],
        ]);
        DB::table('shared_hosting')->insert([
            ['id' => 'legacy01', 'main_domain' => 'one.example', 'ip' => '203.0.113.42'],
            ['id' => 'legacy02', 'main_domain' => 'two.example', 'ip' => '2001:db8::42'],
            ['id' => 'legacy03', 'main_domain' => 'three.example', 'ip' => null],
        ]);
        DB::table('ips')->insert([
            'id' => 'oldip001',
            'service_id' => 'legacy01',
            'address' => '203.0.113.42',
            'is_ipv4' => 1,
            'active' => 1,
        ]);

        $migration = require database_path('migrations/2026_09_26_000001_backfill_legacy_shared_hosting_ips.php');
        $migration->up();

        $this->assertSame(1, DB::table('ips')->where('service_id', 'legacy01')->count());
        $this->assertDatabaseHas('ips', [
            'service_id' => 'legacy02',
            'address' => '2001:db8::42',
            'is_ipv4' => 0,
            'active' => 1,
        ]);
        $this->assertDatabaseMissing('ips', ['service_id' => 'legacy03']);
    }
}
