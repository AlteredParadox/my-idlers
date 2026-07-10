<?php

namespace Tests\Feature;

use App\Models\OS;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Review round 50: favicon uploads were silently dead in the fpm
 * container — /app/public stayed root-owned through the www-data switch,
 * the storeAs return went unchecked, and settings.favicon was repointed
 * at a file that was never written, 404ing the favicon site-wide behind
 * a "Settings Updated Successfully" flash. The write is now verified
 * before anything else changes (and the image chowns /app/public).
 */
class Round50RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function settingsPayload(): array
    {
        return [
            'dark_mode' => 0, 'show_versions_footer' => 1, 'show_servers_public' => 0,
            'show_server_value_ip' => 0, 'show_server_value_hostname' => 1,
            'show_server_value_provider' => 1, 'show_server_value_location' => 1,
            'show_server_value_price' => 1, 'show_server_value_yabs' => 1,
            'default_currency' => 'USD',
            // real id: MySQL auto-increment survives the test transaction
            'default_server_os' => OS::firstOrCreate(['name' => 'TestOS'])->id,
            'due_soon_amount' => 6, 'recently_added_amount' => 6,
            'dashboard_currency' => 'USD', 'sort_on' => 1,
            'servers_index_cards' => 0, 'default_per_page' => 100,
            'prometheus_enabled' => 0, 'prometheus_url' => '',
            'prometheus_check_interval' => 20,
        ];
    }

    public function test_failed_favicon_write_errors_and_leaves_settings_untouched()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1]);

        // Simulate the unwritable-webroot case: the disk's write reports
        // failure (Flysystem swallows UnableToWriteFile into `false`)
        Storage::shouldReceive('disk')->with('public_uploads')->andReturn(
            \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class, function ($mock) {
                $mock->shouldReceive('putFileAs')->andReturn(false);
                // Only the temp-file cleanup may be deleted — any delete of
                // the real favicon here would fail this expectation
                $mock->shouldReceive('delete')->once()->with('favicon.png.tmp')->andReturn(true);
            })
        );

        $response = $this->actingAs($user)->put(route('settings.update', 1), array_merge(
            $this->settingsPayload(),
            ['favicon' => UploadedFile::fake()->image('icon.png', 32, 32)]
        ));

        $response->assertSessionHas('error');
        // The row must NOT point at a file that was never written
        $this->assertDatabaseHas('settings', ['id' => 1, 'favicon' => 'favicon.ico']);
    }

    public function test_failed_favicon_move_errors_and_leaves_settings_untouched()
    {
        // Round 68: the atomic replace's SECOND leg — write succeeds, the
        // rename over the target fails (I/O error, disk-full copy fallback;
        // Laravel swallows UnableToMoveFile into false). Dropping that
        // operand left every test green while the controller flashed
        // success over a favicon that was never created.
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1]);

        Storage::shouldReceive('disk')->with('public_uploads')->andReturn(
            \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class, function ($mock) {
                $mock->shouldReceive('putFileAs')->andReturn('favicon.png.tmp');
                $mock->shouldReceive('move')->with('favicon.png.tmp', 'favicon.png')->andReturn(false);
                $mock->shouldReceive('delete')->once()->with('favicon.png.tmp')->andReturn(true);
            })
        );

        $response = $this->actingAs($user)->put(route('settings.update', 1), array_merge(
            $this->settingsPayload(),
            ['favicon' => UploadedFile::fake()->image('icon.png', 32, 32)]
        ));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('settings', ['id' => 1, 'favicon' => 'favicon.ico']);
    }

    public function test_successful_favicon_upload_updates_settings()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1]);
        Storage::fake('public_uploads');

        $this->actingAs($user)->put(route('settings.update', 1), array_merge(
            $this->settingsPayload(),
            ['favicon' => UploadedFile::fake()->image('icon.png', 32, 32)]
        ))->assertSessionMissing('error');

        Storage::disk('public_uploads')->assertExists('favicon.png');
        $this->assertDatabaseHas('settings', ['id' => 1, 'favicon' => 'favicon.png']);
    }
}
