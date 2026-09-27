<?php

namespace Tests\Feature;

use Tests\TestCase;

class StartupMigrationGuardTest extends TestCase
{
    public function test_manual_migration_guard_uses_the_boolean_pending_option(): void
    {
        $entrypoint = file_get_contents(base_path('run.sh'));

        $this->assertStringContainsString(
            'until php artisan migrate:status --pending > /dev/null 2>&1; do',
            $entrypoint
        );
        $this->assertStringNotContainsString('--pending=', $entrypoint);
    }
}
