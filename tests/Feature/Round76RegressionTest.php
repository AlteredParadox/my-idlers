<?php

namespace Tests\Feature;

use App\Models\IPs;
use App\Models\Labels;
use App\Models\Note;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Review round 76: round 75's "class swept" claim was incomplete — three
 * more unique-validated writes ran unguarded, so the loser of a concurrent
 * duplicate race hit the index raw (500): the labels store (the fourth
 * catalog store), the note update (ignore-self unique on service_id), and
 * the account update (ignore-self unique on email, reachable with
 * MAX_USERS >= 2). All three now route through Controller::createUniquely.
 */
class Round76RegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::firstOrCreate(['id' => 1]);
    }

    /**
     * Commit a competing write immediately BEFORE the first $type query on
     * $table executes — after the request's unique rule has already passed.
     */
    private function injectBefore(string $type, string $table, callable $inject): void
    {
        $raced = false;
        DB::beforeExecuting(function ($query) use (&$raced, $type, $table, $inject) {
            if ($raced) {
                return;
            }
            $sql = strtolower(ltrim($query));
            $onTable = str_contains($sql, "\"$table\"") || str_contains($sql, "`$table`");
            if (str_starts_with($sql, $type) && $onTable) {
                $raced = true; // set FIRST: the injected write re-enters this hook
                $inject();
            }
        });
    }

    public function test_label_duplicate_race_yields_a_validation_error_not_a_500()
    {
        $this->injectBefore('insert', 'labels', fn() => DB::table('labels')->insert([
            'id' => 'zzzzzzzz', 'label' => 'Contested',
        ]));

        $response = $this->actingAs(User::factory()->create())
            ->from('/labels/create')
            ->post('/labels', ['label' => 'Contested']);

        $response->assertRedirect('/labels/create');
        $response->assertSessionHasErrors(['label' => 'The label has already been taken.']);
        $this->assertSame(1, Labels::where('label', 'Contested')->count());
    }

    public function test_note_repoint_race_yields_a_validation_error_not_a_500()
    {
        // The note-service existence rule resolves service ids against the
        // ips table's id column (among others).
        IPs::create(['id' => 'svctgt01', 'service_id' => 'anysvc01',
            'address' => '203.0.113.5', 'is_ipv4' => 1, 'active' => 1]);
        $note = Note::create(['id' => 'note0001', 'service_id' => 'svcold01', 'note' => 'mine']);

        $this->injectBefore('update', 'notes', fn() => Note::create([
            'id' => 'racer001', 'service_id' => 'svctgt01', 'note' => 'theirs',
        ]));

        $response = $this->actingAs(User::factory()->create())
            ->from(route('notes.edit', $note))
            ->put(route('notes.update', $note), [
                'service_id' => 'svctgt01', 'note' => 'mine moved',
            ]);

        $response->assertSessionHasErrors(['service_id' => 'The service id has already been taken.']);
        $this->assertDatabaseHas('notes', ['id' => 'note0001', 'service_id' => 'svcold01']);
        $this->assertDatabaseHas('notes', ['id' => 'racer001', 'service_id' => 'svctgt01']);
    }

    public function test_account_email_race_yields_a_validation_error_not_a_500()
    {
        config(['custom.max_users' => 2]);
        $me = User::factory()->create();
        $other = User::factory()->create();
        // The controller mutates Auth::user() — this same instance — in
        // memory before the save throws, so snapshot the pre-race email.
        $myEmail = $me->email;

        $this->injectBefore('update', 'users', fn() => DB::table('users')
            ->where('id', $other->id)->update(['email' => 'contested@example.com']));

        $response = $this->actingAs($me)
            ->from(route('account.index'))
            ->put(route('account.update', $me), [
                'name' => $me->name, 'email' => 'contested@example.com',
            ]);

        $response->assertSessionHasErrors(['email' => 'The email has already been taken.']);
        $this->assertDatabaseHas('users', ['id' => $me->id, 'email' => $myEmail]);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'email' => 'contested@example.com']);
    }
}
