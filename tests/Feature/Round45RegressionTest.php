<?php

namespace Tests\Feature;

use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Review round 45/46: Note::deleteForService forgot its cache keys INSIDE
 * the caller's still-open transaction — a concurrent read could re-prime
 * note.{id}/all_notes from the pre-commit snapshot for a month. The
 * forgets now defer via DB::afterCommit at the model boundary: they fire
 * at commit, are discarded on rollback (cache stays consistent with the
 * rolled-back rows), and run immediately outside a transaction. The
 * deferral originally shipped unpinned — reverting it left both suites
 * green — so all three semantics are pinned here.
 */
class Round45RegressionTest extends TestCase
{
    use RefreshDatabase;

    private function seedAndPrimeNote(): void
    {
        Note::create(['id' => 'r45note1', 'service_id' => 'r45svc01', 'note' => 'text']);
        Note::note('r45svc01');
        Note::allNotes();
        $this->assertTrue(Cache::has('note.r45svc01'));
        $this->assertTrue(Cache::has('all_notes'));
    }

    public function test_forgets_are_deferred_until_commit()
    {
        $this->seedAndPrimeNote();

        DB::transaction(function () {
            Note::deleteForService('r45svc01');
            // Still primed inside the transaction — a pre-commit forget is
            // re-primeable with the pre-delete snapshot by a concurrent read
            $this->assertTrue(Cache::has('note.r45svc01'), 'forgot before commit');
            $this->assertTrue(Cache::has('all_notes'), 'forgot before commit');
        });

        $this->assertFalse(Cache::has('note.r45svc01'), 'not forgotten after commit');
        $this->assertFalse(Cache::has('all_notes'), 'not forgotten after commit');
    }

    public function test_rollback_discards_the_forgets()
    {
        $this->seedAndPrimeNote();

        try {
            DB::transaction(function () {
                Note::deleteForService('r45svc01');
                throw new \RuntimeException('injected rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // Row restored by rollback; cache still primed — consistent
        $this->assertSame(1, Note::where('service_id', 'r45svc01')->count());
        $this->assertTrue(Cache::has('note.r45svc01'), 'rollback lost the cache entry');
        $this->assertTrue(Cache::has('all_notes'), 'rollback lost the cache entry');
    }

    public function test_outside_a_transaction_forgets_run_immediately()
    {
        $this->seedAndPrimeNote();

        Note::deleteForService('r45svc01');

        $this->assertFalse(Cache::has('note.r45svc01'));
        $this->assertFalse(Cache::has('all_notes'));
    }
}
