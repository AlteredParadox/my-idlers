<?php

namespace Tests\Feature;

use App\Models\Labels;
use App\Models\LabelsAssigned;
use App\Models\Note;
use App\Models\Pricing;
use App\Models\Shared;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The destroy paths clean up child rows (pricing, labels, IPs, notes, ...)
 * with no DB cascades. Follow-up to the GPT round-14 update-atomicity fix:
 * the whole delete sequence must be one transaction, or a failure
 * mid-cleanup leaves orphans behind an already-deleted service (ghost
 * notes/labels, and a leftover pricings.service_id that a future service
 * could collide with).
 */
class DestroyAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_destroy_rolls_back_when_a_child_delete_fails()
    {
        $user = User::factory()->create();
        (new Pricing)->insertPricing(2, 'atomshr1', 'USD', 5.00, 1, '2027-01-01');
        $shared = Shared::create(['id' => 'atomshr1', 'main_domain' => 'atomic.example.com', 'shared_type' => 'cPanel']);
        $label = Labels::create(['id' => 'atomlbl1', 'label' => 'atomic-label']);
        LabelsAssigned::create(['label_id' => $label->id, 'service_id' => 'atomshr1']);
        Note::create(['id' => 'atomnte1', 'service_id' => 'atomshr1', 'note' => 'still here']);

        // Fail on the LAST cleanup step (the notes delete — a query-builder
        // delete, so model events can't hook it): before the sequence was
        // transactional, the service/pricing/label deletes had already
        // committed by this point.
        DB::listen(function ($query) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete') && str_contains($query->sql, 'notes')) {
                throw new \RuntimeException('injected delete failure');
            }
        });

        $this->actingAs($user)->delete(route('shared.destroy', $shared))->assertStatus(500);

        // Every delete must have rolled back together.
        $this->assertDatabaseHas('shared_hosting', ['id' => 'atomshr1']);
        $this->assertDatabaseHas('pricings', ['service_id' => 'atomshr1']);
        $this->assertDatabaseHas('labels_assigned', ['service_id' => 'atomshr1']);
        $this->assertDatabaseHas('notes', ['service_id' => 'atomshr1']);
    }
}
