<?php

namespace Tests\Unit;

use App\Models\Labels;
use App\Models\LabelsAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabelsModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_labels_assigned_creates_label_assignments()
    {
        $label1 = Labels::create(['id' => 'label001', 'label' => 'Production']);
        $label2 = Labels::create(['id' => 'label002', 'label' => 'Web']);

        Labels::insertLabelsAssigned([$label1->id, $label2->id, null, null], 'svc00001');

        $this->assertDatabaseHas('labels_assigned', [
            'label_id' => 'label001',
            'service_id' => 'svc00001'
        ]);
        $this->assertDatabaseHas('labels_assigned', [
            'label_id' => 'label002',
            'service_id' => 'svc00001'
        ]);
    }

    public function test_delete_labels_assigned_to_removes_all_labels_for_service()
    {
        $label = Labels::create(['id' => 'label001', 'label' => 'Production']);
        Labels::insertLabelsAssigned([$label->id, null, null, null], 'svc00001');

        Labels::deleteLabelsAssignedTo('svc00001');

        $this->assertDatabaseMissing('labels_assigned', ['service_id' => 'svc00001']);
    }

    public function test_delete_label_assigned_as_removes_all_assignments_for_label()
    {
        $label = Labels::create(['id' => 'label001', 'label' => 'Production']);
        Labels::insertLabelsAssigned([$label->id, null, null, null], 'svc00001');
        Labels::insertLabelsAssigned([$label->id, null, null, null], 'svc00002');

        Labels::deleteLabelAssignedAs($label->id);

        $this->assertDatabaseMissing('labels_assigned', ['label_id' => 'label001']);
    }

    public function test_labels_count_returns_correct_count()
    {
        Labels::create(['id' => 'label001', 'label' => 'Production']);
        Labels::create(['id' => 'label002', 'label' => 'Web']);
        Labels::create(['id' => 'label003', 'label' => 'Database']);

        $count = Labels::labelsCount();

        $this->assertEquals(3, $count);
    }

    public function test_duplicate_label_in_two_slots_does_not_throw_and_inserts_once()
    {
        $label = Labels::create(['id' => 'label001', 'label' => 'Production']);

        // The create forms let a user pick the same label in two slots; this
        // used to hit the unique index and throw an uncaught QueryException.
        Labels::insertLabelsAssigned([$label->id, $label->id, null, null], 'svc00001');

        $this->assertSame(1, LabelsAssigned::where('service_id', 'svc00001')->count());
    }
}
