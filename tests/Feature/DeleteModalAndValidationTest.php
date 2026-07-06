<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressions for the 2026-07 GPT review: the shared delete modal (XSS +
 * cancel-submits-delete) and DNS relationship validation.
 */
class DeleteModalAndValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_delete_modal_cancel_button_does_not_submit_and_title_is_escaped()
    {
        $modal = file_get_contents(resource_path('views/components/delete-confirm-modal.blade.php'));

        // The "No" button must not submit the DELETE form.
        $this->assertMatchesRegularExpression(
            '/type="button"[^>]*@click\.prevent="showModal=false"|@click\.prevent="showModal=false"[^>]*type="button"/s',
            $modal,
            'cancel button must be type=button with @click.prevent'
        );
        $this->assertStringNotContainsString('@click="showModal=false"', $modal);

        // The title must render as text, never v-html (stored XSS via data-title).
        $this->assertStringContainsString('v-text="modal_hostname"', $modal);
        $this->assertStringNotContainsString('v-html="modal_hostname"', $modal);
    }

    public function test_disk_cards_js_does_not_use_innerhtml_for_prometheus_labels()
    {
        $show = file_get_contents(resource_path('views/servers/show.blade.php'));

        // The buildDiskCards path must build nodes with textContent.
        $this->assertStringContainsString('mount.textContent = d.mountpoint', $show);
        $this->assertStringContainsString('meta.textContent = d.device', $show);
        $this->assertStringNotContainsString("'<div class=\"disk-card-prom\">'", $show);
    }

    public function test_dns_store_rejects_unknown_type_and_dangling_service_ids()
    {
        // Unsupported DNS type.
        $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'bad.example.com', 'address' => '10.0.0.1',
            'dns_type' => 'HACK',
            'server_id' => 'null', 'shared_id' => 'null', 'reseller_id' => 'null', 'domain_id' => 'null',
        ])->assertSessionHasErrors('dns_type');

        // Dangling server_id (not the 'null' sentinel, not a real row).
        $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'bad2.example.com', 'address' => '10.0.0.2',
            'dns_type' => 'A',
            'server_id' => 'ghost001', 'shared_id' => 'null', 'reseller_id' => 'null', 'domain_id' => 'null',
        ])->assertSessionHasErrors('server_id');

        $this->assertSame(0, \App\Models\DNS::count());
    }

    public function test_dns_store_accepts_null_sentinel_and_valid_type()
    {
        $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'good.example.com', 'address' => '10.0.0.3',
            'dns_type' => 'AAAA',
            'server_id' => 'null', 'shared_id' => 'null', 'reseller_id' => 'null', 'domain_id' => 'null',
        ])->assertRedirect(route('dns.index'));

        $this->assertDatabaseHas('d_n_s', ['hostname' => 'good.example.com', 'server_id' => null]);
    }
}
