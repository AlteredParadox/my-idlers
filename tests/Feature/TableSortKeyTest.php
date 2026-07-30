<?php

namespace Tests\Feature;

use App\Models\User;
use App\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Index tables are sorted in the browser by DataTables, which orders on the
 * cell's *text* unless the cell carries a `data-order` sort key. Every column
 * that renders a number therefore has to supply one, because the text is
 * formatted for humans:
 *
 *   "1000" vs "300"    -> lexicographic, so 1000 sorts before 300
 *   "512MB" vs "32GB"  -> the unit is ignored entirely
 *   "12.99 GBP p/y"    -> currency and billing term differ per row
 *
 * The keys come from the normalised columns the schema already carries
 * (ram_mb, disk_gb, disk_as_gb, *_as_mbps, usd_per_month), so display and
 * ordering cannot drift apart.
 */
class TableSortKeyTest extends TestCase
{
    use RefreshDatabase;

    /** Index pages whose tables carry formatted numbers. */
    private const PAGES = [
        '/servers', '/shared', '/reseller', '/seedboxes',
        '/domains', '/misc', '/yabs', '/IPs', '/dns',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['custom.seed_demo_data' => true]);
        $this->seed();
    }

    private function tablesIn(string $html): \DOMNodeList
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        return (new \DOMXPath($dom))->query("//table[contains(@class,'data-table')]//tbody/tr/td");
    }

    /**
     * The generalisable rule, so a column added later cannot quietly regress:
     * a cell that prints a unit suffix (<small class="text-muted">GB</small>
     * and friends) is a formatted number, and must carry a sort key.
     */
    public function test_every_unit_suffixed_cell_carries_a_sort_key()
    {
        $user = User::factory()->create();
        $offenders = [];

        foreach (self::PAGES as $page) {
            $html = $this->actingAs($user)->get($page)->assertStatus(200)->getContent();

            foreach ($this->tablesIn($html) as $td) {
                $inner = '';
                foreach ($td->childNodes as $child) {
                    $inner .= $td->ownerDocument->saveHTML($child);
                }

                $hasUnit = str_contains($inner, '<small class="text-muted">')
                    && trim(strip_tags($inner)) !== '';

                if ($hasUnit && !$td->hasAttribute('data-order')) {
                    $offenders[] = $page . ': ' . preg_replace('/\s+/', ' ', trim(strip_tags($inner)));
                }
            }
        }

        $this->assertSame([], array_unique($offenders));
    }

    /**
     * A sort key is only useful if every row in the column has one -- DataTables
     * types a column from its cells, so one bare cell drops the whole column
     * back to text ordering.
     */
    public function test_sort_keys_are_present_on_every_row_of_a_column()
    {
        $user = User::factory()->create();
        $offenders = [];

        foreach (self::PAGES as $page) {
            $html = $this->actingAs($user)->get($page)->assertStatus(200)->getContent();

            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();
            $xpath = new \DOMXPath($dom);

            foreach ($xpath->query("//table[contains(@class,'data-table')]") as $table) {
                $counts = [];
                $rows = 0;

                foreach ($xpath->query('.//tbody/tr', $table) as $tr) {
                    $rows++;
                    $col = 0;
                    foreach ($xpath->query('./td', $tr) as $td) {
                        if ($td->hasAttribute('data-order')) {
                            $counts[$col] = ($counts[$col] ?? 0) + 1;
                        }
                        $col++;
                    }
                }

                foreach ($counts as $col => $withKey) {
                    if ($rows > 1 && $withKey !== $rows) {
                        $offenders[] = "{$page} table#{$table->getAttribute('id')} col {$col}: {$withKey}/{$rows}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * The key must be sortable as a value, not as prose: numeric for numbers,
     * and for the address columns a fixed-width key (see addressSortKey).
     */
    public function test_sort_keys_are_numeric_or_fixed_width_address_keys()
    {
        $user = User::factory()->create();
        $bad = [];

        foreach (self::PAGES as $page) {
            $html = $this->actingAs($user)->get($page)->assertStatus(200)->getContent();

            foreach ($this->tablesIn($html) as $td) {
                if (!$td->hasAttribute('data-order')) {
                    continue;
                }
                $key = $td->getAttribute('data-order');

                if (is_numeric($key)) {
                    continue;
                }
                // Address key: family digit + packed hex for a real address,
                // or the '9' + raw-text fallback the DNS column relies on for
                // hostnames and MX values.
                if (preg_match('/^4[0-9a-f]{8}$|^6[0-9a-f]{32}$|^9/', $key)) {
                    continue;
                }
                $bad[] = "{$page}: " . $key;
            }
        }

        $this->assertSame([], array_unique($bad));
    }

    public function test_yabs_numeric_columns_sort_on_the_normalised_columns()
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get('/yabs')->assertStatus(200)->getContent();

        $yabs = \App\Models\Yabs::with('disk_speed')->first();
        $this->assertNotNull($yabs, 'demo seed produced no YABS rows');

        // RAM shows "8GB" but must sort on megabytes, disk likewise on GB.
        $this->assertStringContainsString('data-order="' . $yabs->ram_mb . '"', $html);
        $this->assertStringContainsString('data-order="' . $yabs->disk_gb . '"', $html);

        // Disk speeds print MB/s or GB/s per row; *_as_mbps normalises them.
        if ($yabs->disk_speed) {
            $this->assertStringContainsString('data-order="' . $yabs->disk_speed->d_4k_as_mbps . '"', $html);
        }
    }

    /**
     * The Geekbench cell shows the v5 score when a v5 run exists and the v6
     * score otherwise, so the key has to track whichever is on screen -- a key
     * hardcoded to gb5_single would sort every v6-only row as blank.
     */
    public function test_geekbench_sort_key_follows_the_displayed_score()
    {
        $user = User::factory()->create();
        $html = $this->actingAs($user)->get('/yabs')->assertStatus(200)->getContent();

        foreach (\App\Models\Yabs::all() as $yabs) {
            $expected = $yabs->gb5_id
                ? (int) $yabs->gb5_single
                : ($yabs->gb6_id ? (int) $yabs->gb6_single : 0);

            $this->assertStringContainsString(
                'data-order="' . $expected . '"',
                $html,
                "no Geekbench sort key of {$expected} for yabs {$yabs->id}"
            );
        }
    }

    /**
     * DataTables styles a column by the type it detects: a numeric or date
     * column gets `text-align: right` and a header `flex-direction:
     * row-reverse`, which puts the sort arrow on the LEFT of the title. Giving
     * columns proper sort keys is what makes that detection fire, so the arrows
     * moved side on exactly the columns that were fixed -- and stayed put on
     * the rest, leaving the header row inconsistent.
     *
     * The stylesheet switches both off. This asserts the override is still
     * there and still specific enough to win: DataTables' own rules are
     * (0,3,3) and (0,2,2), and naming thead/tbody/tr clears them on element
     * count regardless of import order.
     */
    public function test_datatables_type_styling_is_neutralised()
    {
        $css = file_get_contents(resource_path('css/style.css'));

        $this->assertStringContainsString(
            'table.dataTable > thead > tr > th.dt-type-numeric > div.dt-column-header',
            $css,
            'the sort-arrow side override is gone -- numeric columns will flip their arrow to the left'
        );
        $this->assertStringContainsString('flex-direction: row;', $css);

        $this->assertStringContainsString(
            'table.dataTable > tbody > tr > td.dt-type-numeric',
            $css,
            'the alignment override is gone -- typed columns will right-align themselves'
        );
        $this->assertStringContainsString('text-align: inherit;', $css);
    }

    /**
     * The built stylesheet is committed and served directly, so an override
     * that only exists in source would never reach a browser.
     */
    public function test_the_type_styling_override_is_in_the_built_stylesheet()
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $cssFiles = $manifest['resources/js/app.js']['css'] ?? [];

        $this->assertNotEmpty($cssFiles, 'no CSS asset in the Vite manifest');

        $built = file_get_contents(public_path('build/' . $cssFiles[0]));

        $this->assertStringContainsString('th.dt-type-numeric>div.dt-column-header', str_replace(' ', '', $built));
        $this->assertStringContainsString('flex-direction:row}', str_replace(' ', '', $built));
    }

    public function test_address_sort_key_orders_by_address_not_text()
    {
        // The bug this key exists for: '45...' sorts after '205...' as text.
        $addresses = ['205.185.120.45', '45.77.120.80', '65.108.90.120', '9.9.9.9'];
        usort($addresses, fn($a, $b) => strcmp(Process::addressSortKey($a), Process::addressSortKey($b)));

        $this->assertSame(['9.9.9.9', '45.77.120.80', '65.108.90.120', '205.185.120.45'], $addresses);
    }

    public function test_address_sort_key_groups_families_and_tolerates_non_addresses()
    {
        $mixed = ['mail.example.com', '2001:db8::1', '10.0.0.1', '', '10 mail.example.com'];
        usort($mixed, fn($a, $b) => strcmp(Process::addressSortKey($a), Process::addressSortKey($b)));

        // IPv4, then IPv6, then everything unparseable in text order.
        $this->assertSame('10.0.0.1', $mixed[0]);
        $this->assertSame('2001:db8::1', $mixed[1]);
        $this->assertSame(['', '10 mail.example.com', 'mail.example.com'], array_slice($mixed, 2));
    }

    public function test_address_sort_keys_are_fixed_width_per_family()
    {
        // Equal width is what makes a lexicographic compare match numeric order.
        $this->assertSame(9, strlen(Process::addressSortKey('1.2.3.4')));
        $this->assertSame(9, strlen(Process::addressSortKey('255.255.255.255')));
        $this->assertSame(33, strlen(Process::addressSortKey('2001:db8::1')));
        $this->assertSame(33, strlen(Process::addressSortKey('::1')));
    }
}
