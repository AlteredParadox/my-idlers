<?php

namespace Tests\Feature;

use App\Models\OS;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Review round 51: an .ico upload targets the SHIPPED favicon.ico's exact
 * name, which the hardened container keeps root-owned — in-place
 * truncation needs FILE write, so every .ico upload failed with the
 * round-50 error. The upload path now unlinks an existing unwritable
 * target first (directory write covers unlink + create).
 */
class Round51RegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ico_upload_replaces_an_unwritable_shipped_favicon()
    {
        $user = User::factory()->create();
        Settings::firstOrCreate(['id' => 1]);

        // Real filesystem: a read-only favicon.ico in a writable dir mirrors
        // the container's root-owned shipped file + www-data-owned webroot
        $root = sys_get_temp_dir() . '/r51-' . uniqid();
        mkdir($root, 0755, true);
        file_put_contents("$root/favicon.ico", 'shipped');
        chmod("$root/favicon.ico", 0444);
        config(['filesystems.disks.public_uploads.root' => $root]);

        try {
            $response = $this->actingAs($user)->put(route('settings.update', 1), [
                'dark_mode' => 0, 'show_versions_footer' => 1, 'show_servers_public' => 0,
                'show_server_value_ip' => 0, 'show_server_value_hostname' => 1,
                'show_server_value_provider' => 1, 'show_server_value_location' => 1,
                'show_server_value_price' => 1, 'show_server_value_yabs' => 1,
                'default_currency' => 'USD', 'default_server_os' => OS::firstOrCreate(['name' => 'TestOS'])->id,
                'due_soon_amount' => 6, 'recently_added_amount' => 6,
                'dashboard_currency' => 'USD', 'sort_on' => 1,
                'servers_index_cards' => 0, 'default_per_page' => 100,
                'prometheus_enabled' => 0, 'prometheus_url' => '',
                'prometheus_check_interval' => 20,
                'favicon' => UploadedFile::fake()->create('icon.ico', 8, 'image/vnd.microsoft.icon'),
            ]);

            $response->assertSessionMissing('error');
            $this->assertFileExists("$root/favicon.ico");
            $this->assertNotSame('shipped', file_get_contents("$root/favicon.ico"));
            $this->assertDatabaseHas('settings', ['id' => 1, 'favicon' => 'favicon.ico']);
        } finally {
            @chmod("$root/favicon.ico", 0644);
            @unlink("$root/favicon.ico");
            @rmdir($root);
        }
    }
}
