<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DynamicPageTitleEscapingTest extends TestCase
{
    public function test_dynamic_title_sections_do_not_use_unescaped_inline_values(): void
    {
        $offenders = [];

        foreach (glob(resource_path('views') . '/{,*/,*/*/}*.blade.php', GLOB_BRACE) as $view) {
            $source = file_get_contents($view);

            if (preg_match('/@section\([\'\"]title[\'\"],\s*[\'\"][^\r\n]*\$/', $source)) {
                $offenders[] = str_replace(resource_path('views') . '/', '', $view);
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_block_title_sections_escape_html(): void
    {
        $html = Blade::render(
            '<title>@section(\'title\'){{ $title }} server@endsection @yield(\'title\')</title>',
            ['title' => 'node</title><script>alert(1)</script><title>'],
        );

        $this->assertStringContainsString('&lt;/title&gt;&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
