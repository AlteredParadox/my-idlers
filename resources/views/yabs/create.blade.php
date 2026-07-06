@section("title", "Add a YABS")
<x-app-layout>
    <div class="container">
        <div class="page-header">
            <h2 class="page-title">Add YABS Result</h2>
            <div class="page-actions">
                <a href="{{ route('yabs.index') }}" class="btn btn-outline-secondary">Back to YABS</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <form action="{{ route('yabs.store') }}" method="POST">
            @csrf

            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Paste YABS JSON</h5>
                    <span class="text-muted small">For servers that cannot reach this instance directly</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-lg-4">
                            <label class="form-label">Server</label>
                            <select class="form-select" name="server_id" required>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}" {{ old('server_id') == $server->id ? 'selected' : '' }}>{{ $server->hostname }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">YABS JSON output</label>
                            <textarea class="form-control font-monospace" name="yabs_json" rows="14" required
                                      placeholder='Run on the server: curl -sL yabs.sh | bash -s -- -j
Then paste the JSON block printed at the end here.'>{{ old('yabs_json') }}</textarea>
                            <small class="text-muted">Run <code>curl -sL yabs.sh | bash -s -- -j</code> on the server and paste the JSON it prints. Alternatively <code>-w yabs.json</code> writes it to a file.</small>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Add YABS</button>
        </form>
    </div>
</x-app-layout>
