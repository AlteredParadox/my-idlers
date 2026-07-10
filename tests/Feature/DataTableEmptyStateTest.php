<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DataTables hard-crashes on a tbody row whose single td spans the table
 * (`Cannot set properties of undefined (setting '_DT_CellIndex')`): on any
 * page with an empty table the init aborted, killing sorting, search and
 * the Columns menu for the whole page. The blade empty-state fallbacks are
 * gone — DataTables' own language.emptyTable message renders instead — so
 * no managed table may ever emit a colspan row again.
 */
class DataTableEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_pages_render_no_colspan_rows_when_empty()
    {
        $user = User::factory()->create();

        foreach (['/servers', '/domains', '/shared', '/reseller', '/seedboxes',
                  '/misc', '/dns', '/IPs', '/labels', '/os', '/providers',
                  '/locations', '/yabs', '/notes'] as $page) {
            $response = $this->actingAs($user)->get($page);
            $response->assertStatus(200);
            $this->assertStringNotContainsString(
                'colspan',
                $response->getContent(),
                "$page still renders a colspan row — it crashes DataTables init on empty tables"
            );
        }
    }
}
