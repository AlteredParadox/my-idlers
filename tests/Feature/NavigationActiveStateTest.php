<?php

namespace Tests\Feature;

use App\Models\Pricing;
use App\Models\Server;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The nav bar's active states originally matched only *.index routes,
 * so "Servers" lost its highlight on server show/edit/create pages
 * (GPT nav-flattening review). The links now match whole sections via
 * routeIs('section.*'); these tests pin that subordinate pages still
 * light up their section — and only their section.
 */
class NavigationActiveStateTest extends TestCase
{
    use RefreshDatabase;

    private function activeNavLabels(string $html): array
    {
        preg_match_all('/class="nav-link\s+active"[^>]*>([^<]+)</', $html, $m);

        return array_map('trim', $m[1]);
    }

    public function test_a_show_page_marks_its_section_active()
    {
        Settings::firstOrCreate(['id' => 1]);
        (new Pricing)->insertPricing(1, 'nav00001', 'USD', 5, 1, '2027-01-01');
        Server::create([
            'id' => 'nav00001', 'hostname' => 'nav.example.com', 'server_type' => 1,
            'os_id' => null, 'provider_id' => null, 'location_id' => null,
            'ram' => 1, 'ram_type' => 'GB', 'ram_as_mb' => 1024, 'disk' => 10,
            'disk_type' => 'GB', 'disk_as_gb' => 10, 'cpu' => 1, 'active' => 1,
            'was_promo' => 0, 'owned_since' => '2024-01-01',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('servers.show', 'nav00001'))
            ->assertStatus(200);

        $this->assertSame(['Servers'], $this->activeNavLabels($response->getContent()));
    }

    public function test_a_create_page_marks_its_section_active()
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('domains.create'))
            ->assertStatus(200);

        $this->assertSame(['Domains'], $this->activeNavLabels($response->getContent()));
    }

    public function test_index_pages_still_mark_exactly_one_section_active()
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.index'))
            ->assertStatus(200);

        $this->assertSame(['Settings'], $this->activeNavLabels($response->getContent()));
    }
}
