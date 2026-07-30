@section("title", "Choose servers")
<x-app-layout>
    <div class="container">
        <div class="page-header">
            <h2 class="page-title">Compare Servers</h2>
            <div class="page-actions">
                <a href="{{ route('servers.index') }}" class="btn btn-outline-secondary">Back to servers</a>
            </div>
        </div>

        <div class="card content-card">
            <div class="card-header card-section-header">
                <h5 class="card-section-title mb-0">Select Servers to Compare</h5>
            </div>
            <div class="card-body">
                @if(count($all_servers) >= 2)
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <label class="form-label">Server 1</label>
                            <select class="form-select" name="server1" id="compare-select-1">
                                @foreach ($all_servers as $server)
                                    <option value="{{ $server['id'] }}">{{ $server['hostname'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label">Server 2</label>
                            <select class="form-select" name="server2" id="compare-select-2">
                                @foreach ($all_servers as $server)
                                    <option value="{{ $server['id'] }}" {{ $loop->index === 1 ? 'selected' : '' }}>
                                        {{ $server['hostname'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="{{ route('servers.compare', ['server1' => $all_servers[0]->id, 'server2' => $all_servers[1]->id]) }}"
                           id="compare-link" class="btn btn-primary">View Comparison</a>
                    </div>
                @else
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        You need to have added a YABS to at least 2 servers to use this feature.
                    </div>
                @endif
            </div>
        </div>

        <x-details-footer></x-details-footer>
    </div>

    @if(count($all_servers) >= 2)
    <script type="application/javascript">
        // The two selects just recompute the comparison link's href. This was a
        // Vue app mounted on #app, which put the browser template compiler over
        // the whole page including the option labels (stored hostnames).
        window.addEventListener('load', function() {
            var base = "{{ url('servers-compare') }}/";
            var link = document.getElementById('compare-link');
            var first = document.getElementById('compare-select-1');
            var second = document.getElementById('compare-select-2');

            function sync() {
                link.href = base + first.value + '/' + second.value;
            }

            first.addEventListener('change', sync);
            second.addEventListener('change', sync);
            sync();
        });
    </script>
    @endif
</x-app-layout>
