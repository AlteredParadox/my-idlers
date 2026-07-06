<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\Misc;
use App\Models\Note;
use App\Models\OS;
use App\Models\Pricing;
use App\Models\Providers;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regressions for the round-23 review findings: DNS tool warnings,
 * max-length validation, seeder idempotency, note service integrity,
 * API strict date formats.
 */
class Round23RegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Str::random(60);
        $this->user = User::factory()->create(['api_token' => User::hashApiToken($this->token)]);
        Providers::create(['name' => 'Test Provider']);
        Locations::create(['name' => 'Test Location']);
        OS::create(['name' => 'Ubuntu 22.04']);
        Settings::create(['id' => 1]);
    }

    private function makeServer(string $id): Server
    {
        Pricing::create([
            'service_id' => $id, 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        return Server::create([
            'id' => $id, 'hostname' => "host-$id.example.com", 'server_type' => 1,
            'os_id' => OS::first()->id, 'provider_id' => Providers::first()->id,
            'location_id' => Locations::first()->id, 'ram' => 2048, 'disk' => 50, 'cpu' => 2,
        ]);
    }

    public function test_dns_tool_returns_null_ip_for_unresolvable_domain()
    {
        // dns_get_record failures (SERVFAIL raises a warning → ErrorException
        // 500 before the @-suppression) must come back as {ip: null}.
        $this->actingAs($this->user)
            ->get(route('tools.dns', ['domainName' => 'no-such-host-r23.invalid', 'type' => 'A']))
            ->assertOk()
            ->assertJson(['ip' => null]);
    }

    public function test_overlong_name_is_validation_error_and_leaves_no_orphan_pricing()
    {
        // varchar(255) + MySQL strict: >255 chars was a "Data too long" 500
        // thrown AFTER the pricing insert, orphaning an active pricing row.
        $this->actingAs($this->user)->post(route('misc.store'), [
            'name' => str_repeat('x', 300),
            'price' => 5.00,
            'payment_term' => 1,
            'currency' => 'USD',
        ])->assertSessionHasErrors('name');

        $this->assertSame(0, Pricing::count());
        $this->assertSame(0, Misc::count());
    }

    public function test_misc_store_creates_service_with_pricing()
    {
        $this->actingAs($this->user)->post(route('misc.store'), [
            'name' => 'Backup Storage',
            'price' => 5.00,
            'payment_term' => 1,
            'currency' => 'USD',
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ])->assertRedirect(route('misc.index'));

        $misc = Misc::where('name', 'Backup Storage')->first();
        $this->assertNotNull($misc);
        $this->assertDatabaseHas('pricings', ['service_id' => $misc->id, 'service_type' => 5]);
    }

    public function test_dns_service_id_over_8_chars_is_validation_error()
    {
        $this->actingAs($this->user)->post(route('dns.store'), [
            'hostname' => 'toolong.example.com',
            'address' => '192.0.2.20',
            'dns_type' => 'A',
            'server_id' => 'waytoolongid',
            'shared_id' => 'null', 'reseller_id' => 'null', 'domain_id' => 'null',
        ])->assertSessionHasErrors('server_id');
    }

    public function test_core_seeders_are_idempotent()
    {
        $this->seed();
        $providers = \DB::table('providers')->count();
        $labels = \DB::table('labels')->count();

        // Second run must skip populated tables instead of duplicating every
        // dropdown and dying on the labels unique index mid-way.
        $this->seed();

        $this->assertSame($providers, \DB::table('providers')->count());
        $this->assertSame($labels, \DB::table('labels')->count());
    }

    public function test_note_for_nonexistent_service_is_validation_error()
    {
        $this->actingAs($this->user)->post(route('notes.store'), [
            'service_id' => 'zzzzzzz1',
            'note' => 'ghost note',
        ])->assertSessionHasErrors('service_id');

        // Sanity: a real server still accepts notes.
        $server = $this->makeServer('notesrv1');
        $this->actingAs($this->user)->post(route('notes.store'), [
            'service_id' => $server->id,
            'note' => 'real note',
        ])->assertRedirect(route('notes.index'));
    }

    public function test_misc_destroy_cleans_legacy_notes()
    {
        Pricing::create([
            'service_id' => 'miscnote', 'service_type' => 5, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);
        $misc = Misc::create(['id' => 'miscnote', 'name' => 'With Ghost Note']);
        Note::create(['id' => Str::random(8), 'service_id' => 'miscnote', 'note' => 'ghost']);

        $this->actingAs($this->user)->delete(route('misc.destroy', $misc));

        $this->assertDatabaseMissing('notes', ['service_id' => 'miscnote']);
    }

    public function test_api_pricing_update_rejects_non_ymd_dates()
    {
        $pricing = Pricing::create([
            'service_id' => 'datesrv1', 'service_type' => 1, 'currency' => 'USD',
            'price' => 5.00, 'term' => 1, 'as_usd' => 5.00, 'usd_per_month' => 5.00,
            'next_due_date' => now()->addMonth()->format('Y-m-d'),
        ]);

        // 'date' accepted "07/01/2026", which MySQL strict then rejected → 500.
        $this->putJson("/api/pricing/{$pricing->id}", [
            'price' => 5.00, 'currency' => 'USD', 'term' => 1,
            'next_due_date' => '07/01/2026',
        ], ['Authorization' => 'Bearer ' . $this->token])->assertStatus(400);
    }
}
