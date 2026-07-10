<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Review round 58: DataTables fires stateSaveCallback on the INIT draw
 * with zero user interaction. When a stored state was discarded for a
 * column-count mismatch (prometheus toggle, a deploy adding a column),
 * that save permanently overwrote the user's preference with pristine
 * defaults on the first page view. The partial now suppresses saves until
 * init completes (verified in jsdom against the app's real DataTables
 * 1.13.11: zero PUTs on load in matching/mismatched/first-visit
 * scenarios; a genuine sort interaction still saves).
 *
 * CI has no node runtime, so the load-bearing source shapes are pinned:
 * the ready guard must gate the save callback, start false, and flip only
 * after the DataTable constructor (whose init draw runs synchronously).
 */
class Round58RegressionTest extends TestCase
{
    public function test_state_saves_are_gated_on_init_completion()
    {
        $partial = file_get_contents(resource_path('views/partials/datatable-persist.blade.php'));

        // Contiguous needles, not isolated fragments (round 59: a dropped
        // `return;` and a flip relocated into the catch both survived the
        // fragment pins) — the guard must contain its bail, and the flip
        // must be the constructor's immediate successor on the success path.
        $declare = strpos($partial, 'var ready = false;');
        $guard = strpos($partial, "if (!ready) {\n                return;");
        $initThenFlip = strpos($partial, "\$(selector).DataTable(config);\n            ready = true;");

        $this->assertNotFalse($declare, 'the ready flag must exist');
        $this->assertNotFalse($guard, 'stateSaveCallback must bail (return) before init completes');
        $this->assertNotFalse($initThenFlip, 'ready must flip immediately after the constructor on the success path');
        $this->assertLessThan($guard, $declare, 'flag declared before the guard');
    }
}
