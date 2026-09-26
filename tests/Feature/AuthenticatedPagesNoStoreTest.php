<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * After logout, Back on the same browser must not bring the last inventory
 * page up again from the HTTP cache or the back-forward cache. Nothing in the
 * app forced a revalidation (an earlier pageshow/persisted reload handler was
 * removed with the Vue build), so the authenticated group now answers with
 * Cache-Control: no-store, which browsers honour for both caches.
 */
class AuthenticatedPagesNoStoreTest extends TestCase
{
    use RefreshDatabase;

    public static function authenticatedPages(): array
    {
        return [
            'servers'  => ['servers.index'],
            'domains'  => ['domains.index'],
            'account'  => ['account.index'],
            'settings' => ['settings.index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('authenticatedPages')]
    public function test_authenticated_pages_are_not_cacheable(string $route)
    {
        $response = $this->actingAs(User::factory()->create())->get(route($route))->assertOk();

        $cacheControl = $response->headers->get('Cache-Control', '');
        $this->assertStringContainsString('no-store', $cacheControl, "$route is cacheable after logout");
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_the_login_page_is_unaffected()
    {
        // Guest pages carry nothing sensitive; the directive is scoped to the
        // authenticated group rather than sprayed over every response.
        User::factory()->create(); // an empty install redirects /login to /register

        $this->assertStringNotContainsString('no-store',
            $this->get('/login')->assertOk()->headers->get('Cache-Control', ''));
    }
}
