<?php

namespace App\Http\Controllers;

use App\Models\DNS;
use App\Models\Domains;
use App\Models\IPs;
use App\Models\Note;
use App\Models\Reseller;
use App\Models\Server;
use App\Models\Shared;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class NoteController extends Controller
{
    public function index()
    {
        $notes = Note::allNotes();
        return view('notes.index', compact('notes'));
    }

    public function create()
    {
        $servers = Server::all();
        $shareds = Shared::all();
        $resellers = Reseller::all();
        $domains = Domains::all();
        $dns = DNS::all();
        $ips = IPs::all();

        return view('notes.create', compact(['servers', 'shareds', 'resellers', 'domains', 'dns', 'ips']));
    }

    /**
     * The six note-capable tables (the Note model's relations). Notes for any
     * other id render as ghost rows with blank Service/Type cells.
     */
    private function noteServiceExistsRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            $tables = ['servers', 'shared_hosting', 'reseller_hosting', 'domains', 'd_n_s', 'ips'];
            foreach ($tables as $table) {
                if (\DB::table($table)->where('id', $value)->exists()) {
                    return;
                }
            }
            $fail("The selected {$attribute} does not exist or cannot have notes.");
        };
    }

    public function store(Request $request)
    {
        $request->validate([
            'service_id' => ['required', 'string', 'size:8', $this->noteServiceExistsRule()],
            'note' => 'required|string',
        ]);

        try {
            $note_id = Str::random(8);

            Note::create([
                'id' => $note_id,
                'service_id' => $request->service_id,
                'note' => $request->note
            ]);

        } catch (\Exception $e) {

            if ($e->getCode() === "23000") {
                $message = "A note already exists for this service";
            } else {
                $message = "Error inserting note";
            }

            return redirect()->route('notes.create')
                ->withInput($request->input())->with('error', $message);
        }

        Cache::forget('all_notes');

        return redirect()->route('notes.index')
            ->with('success', 'Note created successfully.');
    }

    public function edit(Note $note)
    {
        $note = Note::note($note->service_id);
        $servers = Server::all();
        $shareds = Shared::all();
        $resellers = Reseller::all();
        $domains = Domains::all();
        $dns = DNS::all();
        $ips = IPs::all();

        return view('notes.edit', compact(['note', 'servers', 'shareds', 'resellers', 'domains', 'dns', 'ips']));
    }

    public function update(Request $request, Note $note)
    {
        $request->validate([
            // notes.service_id is unique; without this, re-pointing a note at a
            // service that already has one throws an uncaught QueryException (500)
            'service_id' => ['required', 'string', 'size:8', Rule::unique('notes', 'service_id')->ignore($note->id), $this->noteServiceExistsRule()],
            'note' => 'required|string'
        ]);

        $old_service_id = $note->service_id;

        $note->update([
            'service_id' => $request->service_id,
            'note' => $request->note
        ]);

        Cache::forget('all_notes');
        Cache::forget("note.$old_service_id");
        Cache::forget("note.$note->service_id");

        return redirect()->route('notes.index')
            ->with('success', 'Note was updated successfully.');
    }

    public function show(Note $note)
    {
        $note = Note::note($note->service_id);
        return view('notes.show', compact(['note']));
    }

    public function destroy(Note $note)
    {
        if ($note->delete()) {
            Cache::forget("all_notes");
            Cache::forget("note.$note->service_id");

            return redirect()->route('notes.index')
                ->with('success', 'Note was deleted successfully.');
        }

        return redirect()->route('notes.index')
            ->with('error', 'Note was not deleted.');

    }

}
