<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Review round 75: the round-74 duplicate-race class (unique rule validates
 * before the insert; the loser of a concurrent same-value race hits the
 * unique index raw and renders a 500) existed on all three catalog stores —
 * locations, os, providers. All creates now route through
 * Controller::createUniquely(), which turns the lost race into the standard
 * validation error, byte-identical to the validator's own message.
 */
class Round75RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function raceStore(string $uri, string $field, string $table, string $expectedMessage): void
    {
        // Commit the competing same-name row immediately BEFORE the store's
        // insert executes — after its unique rule has already passed.
        $raced = false;
        DB::beforeExecuting(function ($query) use (&$raced, $table) {
            if ($raced) {
                return;
            }
            $sql = strtolower(ltrim($query));
            $intoTable = str_contains($sql, "into \"$table\"") || str_contains($sql, "into `$table`");
            if (str_starts_with($sql, 'insert') && $intoTable) {
                $raced = true; // set FIRST: the injected insert re-enters this hook
                DB::table($table)->insert(['name' => 'Contested']);
            }
        });

        $response = $this->actingAs(User::factory()->create())
            ->from("/$uri/create")
            ->post("/$uri", [$field => 'Contested']);

        $response->assertRedirect("/$uri/create");
        $response->assertSessionHasErrors([$field => $expectedMessage]);
        $this->assertSame(1, DB::table($table)->where('name', 'Contested')->count());
    }

    public function test_location_duplicate_race_yields_a_validation_error_not_a_500()
    {
        $this->raceStore('locations', 'location_name', 'locations',
            'The location name has already been taken.');
    }

    public function test_os_duplicate_race_yields_a_validation_error_not_a_500()
    {
        $this->raceStore('os', 'os_name', 'os',
            'The os name has already been taken.');
    }

    public function test_provider_duplicate_race_yields_a_validation_error_not_a_500()
    {
        $this->raceStore('providers', 'provider_name', 'providers',
            'The provider name has already been taken.');
    }
}
