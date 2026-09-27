<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrometheusEnabledMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_prometheus_configuration_remains_enabled(): void
    {
        $migration = require database_path('migrations/2026_04_06_000002_add_prometheus_enabled_to_settings.php');
        $migration->down();

        DB::table('settings')->insert([
            ['id' => 10, 'prometheus_url' => 'http://prometheus:9090'],
            ['id' => 11, 'prometheus_url' => null],
        ]);

        $migration->up();

        $this->assertDatabaseHas('settings', [
            'id' => 10,
            'prometheus_enabled' => true,
        ]);
        $this->assertDatabaseHas('settings', [
            'id' => 11,
            'prometheus_enabled' => false,
        ]);
    }
}
