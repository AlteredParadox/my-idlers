<?php

namespace Tests\Feature;

use App\Models\DNS;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_access_dns_index()
    {
        $response = $this->get(route('dns.index'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_dns_index()
    {
        $response = $this->actingAs($this->user)->get(route('dns.index'));
        $response->assertStatus(200);
        $response->assertViewIs('dns.index');
    }

    public function test_authenticated_user_can_view_create_dns_form()
    {
        $response = $this->actingAs($this->user)->get(route('dns.create'));
        $response->assertStatus(200);
        $response->assertViewIs('dns.create');
    }

    public function test_dns_form_shows_shared_reseller_by_main_domain()
    {
        // shared_hosting / reseller_hosting have FKs to pricings
        (new \App\Models\Pricing)->insertPricing(2, 'shd00001', 'USD', 3, 1, '2027-01-01');
        (new \App\Models\Pricing)->insertPricing(3, 'rsl00001', 'USD', 3, 1, '2027-01-01');
        \App\Models\Shared::create(['id' => 'shd00001', 'main_domain' => 'shared.example.com', 'active' => 1]);
        \App\Models\Reseller::create(['id' => 'rsl00001', 'main_domain' => 'reseller.example.com', 'active' => 1]);

        // The option labels used ['hostname'] (no such column) -> blank options
        $response = $this->actingAs($this->user)->get(route('dns.create'));

        $response->assertSee('shared.example.com');
        $response->assertSee('reseller.example.com');
    }

    public function test_authenticated_user_can_create_dns_record()
    {
        $response = $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'example.com',
            'address' => '192.168.1.1',
            'dns_type' => 'A',
            'server_id' => 'null',
            'shared_id' => 'null',
            'reseller_id' => 'null',
            'domain_id' => 'null'
        ]);

        $response->assertRedirect(route('dns.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('d_n_s', [
            'hostname' => 'example.com',
            'address' => '192.168.1.1',
            'dns_type' => 'A'
        ]);
    }

    public function test_dns_record_persists_shared_and_reseller_associations()
    {
        // shared_id/reseller_id were missing from $fillable, so mass assignment
        // silently dropped them and the association never saved.
        \App\Models\Pricing::create([
            'service_id' => 'shared01', 'service_type' => 2, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        \App\Models\Shared::create(['id' => 'shared01', 'main_domain' => 'assoc-host.example.com', 'shared_type' => 'cPanel']);

        $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'assoc.example.com',
            'address' => '10.0.0.5',
            'dns_type' => 'A',
            'server_id' => 'null',
            'shared_id' => 'shared01',
            'reseller_id' => 'null',
            'domain_id' => 'null',
        ])->assertRedirect(route('dns.index'));

        $this->assertDatabaseHas('d_n_s', [
            'hostname' => 'assoc.example.com',
            'shared_id' => 'shared01',
        ]);
    }

    public function test_dns_hostname_is_required()
    {
        $response = $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => '',
            'address' => '192.168.1.1',
            'dns_type' => 'A'
        ]);

        $response->assertSessionHasErrors('hostname');
    }

    public function test_dns_address_is_required()
    {
        $response = $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'example.com',
            'address' => '',
            'dns_type' => 'A'
        ]);

        $response->assertSessionHasErrors('address');
    }

    public function test_dns_type_is_required()
    {
        $response = $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'example.com',
            'address' => '192.168.1.1',
            'dns_type' => ''
        ]);

        $response->assertSessionHasErrors('dns_type');
    }

    public function test_authenticated_user_can_view_dns_details()
    {
        $dns = DNS::create([
            'id' => 'testdns1',
            'hostname' => 'example.com',
            'address' => '192.168.1.1',
            'dns_type' => 'A'
        ]);

        $response = $this->actingAs($this->user)->get(route('dns.show', $dns));
        $response->assertStatus(200);
        $response->assertViewIs('dns.show');
    }

    public function test_authenticated_user_can_update_dns_record()
    {
        $dns = DNS::create([
            'id' => 'testdns2',
            'hostname' => 'example.com',
            'address' => '192.168.1.1',
            'dns_type' => 'A'
        ]);

        $response = $this->actingAs($this->user)->put(route('dns.update', $dns), [
            'hostname' => 'updated.example.com',
            'address' => '192.168.1.2',
            'dns_type' => 'AAAA',
            'server_id' => 'null',
            'shared_id' => 'null',
            'reseller_id' => 'null',
            'domain_id' => 'null'
        ]);

        $response->assertRedirect(route('dns.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('d_n_s', [
            'hostname' => 'updated.example.com',
            'address' => '192.168.1.2',
            'dns_type' => 'AAAA'
        ]);
    }

    public function test_authenticated_user_can_delete_dns_record()
    {
        $dns = DNS::create([
            'id' => 'testdns3',
            'hostname' => 'example.com',
            'address' => '192.168.1.1',
            'dns_type' => 'A'
        ]);

        $response = $this->actingAs($this->user)->delete(route('dns.destroy', $dns));

        $response->assertRedirect(route('dns.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('d_n_s', ['hostname' => 'example.com']);
    }
}
