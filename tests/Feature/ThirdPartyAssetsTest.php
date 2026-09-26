<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Every script and stylesheet ships in the Vite bundle. A third-party URL in a
 * view is a supply-chain trust the app does not otherwise take (jsDelivr with
 * no integrity attribute served Bootstrap on the error pages and ApexCharts on
 * the server page), and the container's Content-Security-Policy is
 * script-src/style-src 'self', which blocked both -- the Prometheus charts
 * were broken in the image without anyone noticing.
 */
class ThirdPartyAssetsTest extends TestCase
{
    public function test_no_view_loads_a_script_or_stylesheet_from_another_origin()
    {
        $views = __DIR__ . '/../../resources/views';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views, \FilesystemIterator::SKIP_DOTS));

        $offenders = [];
        foreach ($files as $file) {
            $html = file_get_contents($file->getPathname());
            if (preg_match_all('/<(?:script|link)\b[^>]*\b(?:src|href)\s*=\s*["\']https?:\/\//i', $html, $m)) {
                $offenders[] = substr($file->getPathname(), strlen($views) + 1) . ': ' . implode(' | ', $m[0]);
            }
        }

        $this->assertSame([], $offenders, 'views must load scripts and styles from the Vite bundle only');
    }

    public function test_the_chart_library_is_a_bundled_vite_entry()
    {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../../public/build/manifest.json'), true);

        $this->assertArrayHasKey('resources/js/charts.js', $manifest, 'charts.js must be built as its own entry');
        $this->assertTrue($manifest['resources/js/charts.js']['isEntry'] ?? false);
        $this->assertStringContainsString("@vite(['resources/js/charts.js'])",
            file_get_contents(__DIR__ . '/../../resources/views/servers/show.blade.php'));
    }
}
