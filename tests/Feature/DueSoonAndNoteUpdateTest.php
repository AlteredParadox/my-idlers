<?php

namespace Tests\Feature;

use App\Models\Home;
use App\Models\Note;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DueSoonAndNoteUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::create(['id' => 1]);
    }

    public function test_due_soon_advance_invalidates_non_server_caches()
    {
        // A past-due shared (type 2) service; doDueSoon advances its date and
        // must clear the shared caches (previously only servers were cleared).
        $row = (object) [
            'service_type' => 2,
            'service_id' => 'shd00001',
            'term' => 1,
            'next_due_date' => '2020-01-01',
        ];
        Cache::put('all_shared', 'stale');
        Cache::put('shared_hosting.shd00001', 'stale');

        Home::doDueSoon(collect([$row]));

        $this->assertFalse(Cache::has('all_shared'));
        $this->assertFalse(Cache::has('shared_hosting.shd00001'));
    }

    public function test_note_update_rejects_pointing_at_a_service_that_already_has_a_note()
    {
        Note::create(['id' => 'note0001', 'service_id' => 'svcaaaa1', 'note' => 'A']);
        $noteB = Note::create(['id' => 'note0002', 'service_id' => 'svcbbbb2', 'note' => 'B']);

        // Re-pointing B at A's service used to hit the unique index -> 500
        $response = $this->actingAs(User::factory()->create())->put(route('notes.update', $noteB), [
            'service_id' => 'svcaaaa1',
            'note' => 'B moved',
        ]);

        $response->assertSessionHasErrors('service_id');
        $this->assertDatabaseHas('notes', ['id' => 'note0002', 'service_id' => 'svcbbbb2']);
    }
}
