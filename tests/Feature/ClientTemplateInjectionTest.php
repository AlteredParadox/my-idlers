<?php

namespace Tests\Feature;

use App\Models\Locations;
use App\Models\OS;
use App\Models\Providers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The app used to ship vue.esm-bundler.js -- the Vue build that INCLUDES the
 * browser template compiler -- and mount it on each page's whole container.
 * Vue then compiled the server-rendered markup as a template. Blade's escaping
 * does nothing to `{{ }}`, so a stored hostname, provider name, ipwhois.app
 * field or YABS-reported CPU model containing a mustache became an expression
 * the runtime evaluated:
 *
 *     {{ $options.constructor.constructor('...')() }}   -> arbitrary JS
 *
 * The fix is structural: no template compiler is shipped at all. These tests
 * guard the pieces of that, because re-adding Vue would silently restore the
 * sink on every index page at once.
 */
class ClientTemplateInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function bundle(): string
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $entry = $manifest['resources/js/app.js']['file'] ?? null;

        $this->assertNotNull($entry, 'no built app.js entry in the Vite manifest');

        return file_get_contents(public_path('build/' . $entry));
    }

    public function test_the_shipped_bundle_contains_no_template_compiler()
    {
        $bundle = $this->bundle();

        foreach (['compileToFunction', 'createCompilerError', 'resolveComponent'] as $marker) {
            $this->assertStringNotContainsString($marker, $bundle, "Vue's compiler marker $marker is in the bundle");
        }
    }

    /**
     * The compiler is only half of it: the gadget is `new Function(expr)`. With
     * no dynamic-code path in the bundle the CSP can withhold 'unsafe-eval',
     * which turns a future regression into a blocked script rather than a
     * working exploit.
     */
    public function test_the_shipped_bundle_never_builds_code_from_strings()
    {
        $bundle = $this->bundle();

        $this->assertSame(0, preg_match_all('/\bFunction\s*\(/', $bundle), 'bundle constructs functions from strings');
        $this->assertSame(0, preg_match_all('/\beval\s*\(/', $bundle), 'bundle calls eval()');
    }

    public function test_vue_is_not_a_dependency()
    {
        $pkg = json_decode(file_get_contents(base_path('package.json')), true);

        $this->assertArrayNotHasKey('vue', $pkg['dependencies'] ?? []);
        $this->assertArrayNotHasKey('vue', $pkg['devDependencies'] ?? []);
    }

    /**
     * Nothing may re-mount a client-side template over server-rendered data.
     */
    public function test_no_view_mounts_a_client_side_template_over_rendered_data()
    {
        $offenders = [];

        foreach (glob(resource_path('views') . '/{,*/,*/*/}*.blade.php', GLOB_BRACE) as $view) {
            $source = file_get_contents($view);
            $relative = str_replace(resource_path('views') . '/', '', $view);

            foreach (['createApp', 'id="app"', 'v-model', 'v-text', 'v-html', 'v-bind:', '@click="', '@change="'] as $marker) {
                if (str_contains($source, $marker)) {
                    $offenders[] = "$relative uses $marker";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * End to end: a stored mustache reaches the browser as inert text. It is
     * still present verbatim -- escaping it would corrupt the stored value --
     * but there is no longer anything on the page that would evaluate it.
     */
    public function test_a_stored_mustache_renders_as_inert_text()
    {
        $payload = "{{ 7*6 }}";

        Providers::create(['name' => $payload]);
        Locations::create(['name' => $payload]);
        OS::create(['name' => $payload]);

        foreach (['/providers', '/locations', '/os'] as $page) {
            $response = $this->actingAs(User::factory()->create())->get($page)->assertStatus(200);

            $html = $response->getContent();

            $this->assertStringContainsString($payload, $html, "$page did not render the stored value");
            $this->assertStringNotContainsString('42', $html, "$page evaluated the stored expression");
            $this->assertStringNotContainsString('id="app"', $html, "$page still declares a Vue mount point");
        }
    }
}
