@section('title', 'Servers')
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">Servers</h2>
            <div class="page-actions">
                <x-export-buttons route="export.servers" />
                <a href="{{ route('servers.create') }}" class="btn btn-primary">Add server</a>
                <a href="{{ route('servers-compare-choose') }}" class="btn btn-outline-secondary">Compare</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="content-card">
            <div class="card-tabs">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-servers" 
                                type="button" role="tab" aria-selected="true">
                            Active <span class="badge bg-secondary ms-1">{{ count($servers ?? []) }}</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link @if(!isset($non_active_servers[0])) disabled @endif" id="inactive-tab" 
                                data-bs-toggle="tab" data-bs-target="#inactive-servers" type="button" role="tab" aria-selected="false">
                            Inactive <span class="badge bg-secondary ms-1">{{ count($non_active_servers ?? []) }}</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="active-servers" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table data-table" id="servers-table">
                            <thead>
                                <tr>
                                    <th>Hostname</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">OS</th>
                                    <th class="text-center">CPU</th>
                                    <th>CPU Model</th>
                                    <th class="text-center">RAM</th>
                                    <th class="text-center">Disk</th>
                                    <th class="text-center">BW</th>
                                    <th class="text-center">Link</th>
                                    <th class="text-center">Net</th>
                                    <th>Location</th>
                                    <th>Provider</th>
                                    <th class="text-center">Transferrable</th>
                                    <th>Price</th>
                                    <th class="text-center">Due</th>
                                    <th class="text-center">Since</th>
                                    @if(session('prometheus_enabled') && session('prometheus_url'))
                                    <th class="text-center">Uptime</th>
                                    @endif
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($servers))
                                @foreach($servers as $server)
                                <tr>
                                    <td class="fw-medium hostname-cell" data-full="{{ $server->hostname }}"><a href="{{ route('servers.show', $server->id) }}" class="text-reset text-decoration-none">{{ $server->hostname }}</a></td>
                                    <td class="text-center">
                                        <span class="badge badge-type">{{ App\Models\Server::serviceServerType($server->server_type) }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if(isset($server->os))
                                            <span title="{{ $server->os->name }}">{!! App\Models\Server::osIntToIcon($server->os->name) !!}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $server->cpu }}</td>
                                    <td class="text-nowrap">{{ $server->cpu_model }}</td>
                                    <td class="text-center text-nowrap ram-cell" data-order="{{ $server->ram_as_mb }}" data-hostname="{{ $server->hostname }}">
                                        @if($server->ram_as_mb >= 1024)
                                            {{ number_format($server->ram_as_mb / 1024, $server->ram_as_mb % 1024 === 0 ? 0 : 1) }}<small class="text-muted">GB</small>
                                        @else
                                            {{ $server->ram_as_mb }}<small class="text-muted">MB</small>
                                        @endif
                                        <span class="ram-usage"></span>
                                    </td>
                                    @php $total_disk_gb = $server->disks->count() > 0 ? $server->disks->sum('disk_as_gb') : $server->disk_as_gb; @endphp
                                    <td class="text-center text-nowrap disk-cell" data-order="{{ $total_disk_gb }}" data-hostname="{{ $server->hostname }}">
                                        @if($total_disk_gb >= 1024)
                                            {{ number_format($total_disk_gb / 1024, 1) }}<small class="text-muted">TB</small>
                                        @else
                                            {{ $total_disk_gb }}<small class="text-muted">GB</small>
                                        @endif
                                        <span class="disk-usage"></span>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $server->bandwidth == 0 ? 999999 : $server->bandwidth }}">
                                        @if($server->bandwidth == 0)
                                            <span title="Unlimited">&infin;</span>
                                        @elseif($server->bandwidth >= 1000)
                                            {{ number_format($server->bandwidth / 1000, $server->bandwidth % 1000 === 0 ? 0 : 1) }}<small class="text-muted">TB</small>
                                        @else
                                            {{ $server->bandwidth }}<small class="text-muted">GB</small>
                                        @endif
                                    </td>
                                    <td class="text-center text-nowrap link-cell" data-order="{{ $server->link_speed ?? 0 }}" data-hostname="{{ $server->hostname }}" data-link-speed="{{ $server->link_speed ?? 0 }}">
                                        @if($server->link_speed)
                                            @if($server->link_speed >= 1000)
                                                {{ rtrim(rtrim(number_format($server->link_speed / 1000, 1), '0'), '.') }}<small class="text-muted">Gbps</small>
                                            @else
                                                {{ $server->link_speed }}<small class="text-muted">Mbps</small>
                                            @endif
                                        @else - @endif
                                        <span class="link-usage"></span>
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->network_type ?? '-' }}</td>
                                    <td class="text-nowrap">{{ $server->location->name ?? '-' }}</td>
                                    <td class="text-nowrap">{{ $server->provider->name ?? '-' }}</td>
                                    <td class="text-center">{{ is_null($server->transferrable) ? '-' : (($server->transferrable === 1) ? 'Yes' : 'No') }}</td>
                                    <td class="text-nowrap" data-order="{{ $server->price->usd_per_month }}">
                                        {{ $server->price->price }} {{ $server->price->currency }}
                                        <small class="text-muted">{{ \App\Process::paymentTermIntToString($server->price->term) }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $server->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false) : -99999 }}">
                                        @if($server->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->owned_since }}</td>
                                    @if(session('prometheus_enabled') && session('prometheus_url'))
                                    <td class="text-center text-nowrap uptime-cell" data-hostname="{{ $server->hostname }}">-</td>
                                    @endif
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('servers.show', $server->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('servers.edit', $server->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            @if(session('prometheus_enabled') && session('prometheus_url'))
                                            <span class="btn btn-sm btn-action status-check-btn" title="Live status" data-hostname="{{ $server->hostname }}" style="cursor: default;">
                                                <i class="fas fa-plug"></i>
                                            </span>
                                            @else
                                            <button type="button" class="btn btn-sm btn-action status-check-btn" title="Check status"
                                                    data-hostname="{{ $server->hostname }}" @click="checkIfUp">
                                                <i class="fas fa-plug"></i>
                                            </button>
                                            @endif
                                            <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                                    @click="confirmDeleteModal" id="{{ $server->id }}" data-title="{{ $server->hostname }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td colspan="{{ (session('prometheus_enabled') && session('prometheus_url')) ? 18 : 17 }}" class="text-center text-muted py-4">No active servers found</td>
                                </tr>
                            @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="inactive-servers" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table data-table" id="inactive-servers-table">
                            <thead>
                                <tr>
                                    <th>Hostname</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">OS</th>
                                    <th class="text-center">CPU</th>
                                    <th>CPU Model</th>
                                    <th class="text-center">RAM</th>
                                    <th class="text-center">Disk</th>
                                    <th class="text-center">BW</th>
                                    <th class="text-center">Link</th>
                                    <th class="text-center">Net</th>
                                    <th>Location</th>
                                    <th>Provider</th>
                                    <th class="text-center">Transferrable</th>
                                    <th>Price</th>
                                    <th class="text-center">Expires In</th>
                                    <th class="text-center">Since</th>
                                    @if(session('prometheus_enabled') && session('prometheus_url'))
                                    <th class="text-center">Uptime</th>
                                    @endif
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($non_active_servers))
                                @foreach($non_active_servers as $server)
                                @php $expired = $server->price->next_due_date && Carbon\Carbon::parse($server->price->next_due_date)->isPast(); @endphp
                                <tr class="{{ $expired ? 'expired-row' : '' }}">
                                    <td class="fw-medium hostname-cell" data-full="{{ $server->hostname }}"><a href="{{ route('servers.show', $server->id) }}" class="text-reset text-decoration-none">{{ $server->hostname }}</a></td>
                                    <td class="text-center">
                                        <span class="badge badge-type">{{ App\Models\Server::serviceServerType($server->server_type) }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if(isset($server->os))
                                            <span title="{{ $server->os->name }}">{!! App\Models\Server::osIntToIcon($server->os->name) !!}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $server->cpu }}</td>
                                    <td class="text-nowrap">{{ $server->cpu_model }}</td>
                                    <td class="text-center text-nowrap ram-cell" data-order="{{ $server->ram_as_mb }}" data-hostname="{{ $server->hostname }}">
                                        @if($server->ram_as_mb >= 1024)
                                            {{ number_format($server->ram_as_mb / 1024, $server->ram_as_mb % 1024 === 0 ? 0 : 1) }}<small class="text-muted">GB</small>
                                        @else
                                            {{ $server->ram_as_mb }}<small class="text-muted">MB</small>
                                        @endif
                                        <span class="ram-usage"></span>
                                    </td>
                                    @php $total_disk_gb = $server->disks->count() > 0 ? $server->disks->sum('disk_as_gb') : $server->disk_as_gb; @endphp
                                    <td class="text-center text-nowrap disk-cell" data-order="{{ $total_disk_gb }}" data-hostname="{{ $server->hostname }}">
                                        @if($total_disk_gb >= 1024)
                                            {{ number_format($total_disk_gb / 1024, 1) }}<small class="text-muted">TB</small>
                                        @else
                                            {{ $total_disk_gb }}<small class="text-muted">GB</small>
                                        @endif
                                        <span class="disk-usage"></span>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $server->bandwidth == 0 ? 999999 : $server->bandwidth }}">
                                        @if($server->bandwidth == 0)
                                            <span title="Unlimited">&infin;</span>
                                        @elseif($server->bandwidth >= 1000)
                                            {{ number_format($server->bandwidth / 1000, $server->bandwidth % 1000 === 0 ? 0 : 1) }}<small class="text-muted">TB</small>
                                        @else
                                            {{ $server->bandwidth }}<small class="text-muted">GB</small>
                                        @endif
                                    </td>
                                    <td class="text-center text-nowrap link-cell" data-order="{{ $server->link_speed ?? 0 }}" data-hostname="{{ $server->hostname }}" data-link-speed="{{ $server->link_speed ?? 0 }}">
                                        @if($server->link_speed)
                                            @if($server->link_speed >= 1000)
                                                {{ rtrim(rtrim(number_format($server->link_speed / 1000, 1), '0'), '.') }}<small class="text-muted">Gbps</small>
                                            @else
                                                {{ $server->link_speed }}<small class="text-muted">Mbps</small>
                                            @endif
                                        @else - @endif
                                        <span class="link-usage"></span>
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->network_type ?? '-' }}</td>
                                    <td class="text-nowrap">{{ $server->location->name ?? '-' }}</td>
                                    <td class="text-nowrap">{{ $server->provider->name ?? '-' }}</td>
                                    <td class="text-center">{{ is_null($server->transferrable) ? '-' : (($server->transferrable === 1) ? 'Yes' : 'No') }}</td>
                                    <td class="text-nowrap" data-order="{{ $server->price->usd_per_month }}">
                                        {{ $server->price->price }} {{ $server->price->currency }}
                                        <small class="text-muted">{{ \App\Process::paymentTermIntToString($server->price->term) }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $server->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false) : -99999 }}">
                                        @if($server->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->owned_since }}</td>
                                    @if(session('prometheus_enabled') && session('prometheus_url'))
                                    <td class="text-center text-nowrap uptime-cell" data-hostname="{{ $server->hostname }}">-</td>
                                    @endif
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('servers.show', $server->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('servers.edit', $server->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            @if(session('prometheus_enabled') && session('prometheus_url'))
                                            <span class="btn btn-sm btn-action status-check-btn" title="Live status" data-hostname="{{ $server->hostname }}" style="cursor: default;">
                                                <i class="fas fa-plug"></i>
                                            </span>
                                            @else
                                            <button type="button" class="btn btn-sm btn-action status-check-btn" title="Check status"
                                                    data-hostname="{{ $server->hostname }}" @click="checkIfUp">
                                                <i class="fas fa-plug"></i>
                                            </button>
                                            @endif
                                            <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                                    @click="confirmDeleteModal" id="{{ $server->id }}" data-title="{{ $server->hostname }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td colspan="{{ (session('prometheus_enabled') && session('prometheus_url')) ? 18 : 17 }}" class="text-center text-muted py-4">No inactive servers found</td>
                                </tr>
                            @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <x-details-footer></x-details-footer>
        <x-delete-confirm-modal></x-delete-confirm-modal>
    </div>

    @section('scripts')
    @include('partials.datatable-persist')
    <script>
        window.addEventListener('load', function () {
            @include('servers.partials.status-js', ['withLinkUsage' => true, 'roundedUptime' => false])

            $.fn.dataTable.ext.errMode = 'none';
            var dtConfig = {
                pageLength: {{ session('default_per_page', 100) }},
                lengthMenu: [10, 25, 50, 100, 250, 500],
                columnDefs: [
                    {orderable: false, targets: [2, {{ (session('prometheus_enabled') && session('prometheus_url')) ? 17 : 16 }}]}
                ],
                language: {
                    search: "",
                    searchPlaceholder: "Search servers...",
                    lengthMenu: "Show _MENU_",
                    info: "Showing _START_ to _END_ of _TOTAL_",
                    paginate: {
                        previous: "Prev",
                        next: "Next"
                    },
                    emptyTable: "No servers found"
                }
            };
            window.idlersDataTable('#servers-table', dtConfig);
            window.idlersDataTable('#inactive-servers-table', dtConfig);

            // Toggle state from the user's DB-backed preferences
            var uiPrefs = window.idlersPrefs['ui.servers'] || {};
            var domainHidden = uiPrefs.hide_domains === 1;
            var statsHidden = uiPrefs.hide_stats === 1;

            function saveToggles() {
                window.idlersSavePref('ui.servers', {
                    hide_domains: domainHidden ? 1 : 0,
                    hide_stats: statsHidden ? 1 : 0
                });
            }

            function applyDomainToggle() {
                document.querySelectorAll('.toggle-domains-btn').forEach(function(b) {
                    b.innerHTML = domainHidden
                        ? '<i class="fas fa-eye"></i> Show Domains'
                        : '<i class="fas fa-eye-slash"></i> Hide Domains';
                });
                document.querySelectorAll('.hostname-cell').forEach(function(cell) {
                    var full = cell.getAttribute('data-full');
                    var link = cell.querySelector('a');
                    if (link) {
                        link.textContent = domainHidden ? full.split('.')[0] : full;
                    } else {
                        cell.textContent = domainHidden ? full.split('.')[0] : full;
                    }
                });
            }

            function applyStatsToggle() {
                var display = statsHidden ? 'none' : '';
                document.querySelectorAll('.ram-usage, .disk-usage, .link-usage').forEach(function(el) {
                    el.style.display = display;
                });
                document.querySelectorAll('.toggle-stats-btn').forEach(function(b) {
                    b.innerHTML = statsHidden
                        ? '<i class="fas fa-chart-bar"></i> Show Stats'
                        : '<i class="fas fa-chart-bar"></i> Hide Stats';
                });
            }

            // Apply saved state on load
            if (domainHidden) applyDomainToggle();
            if (statsHidden) applyStatsToggle();

            // Add toggle buttons next to each table's "Show" dropdown
            document.querySelectorAll('.dataTables_length').forEach(function(el) {
                var domainBtn = document.createElement('button');
                domainBtn.type = 'button';
                domainBtn.className = 'btn btn-sm btn-outline-secondary ms-2 toggle-domains-btn';
                domainBtn.addEventListener('click', function() {
                    domainHidden = !domainHidden;
                    saveToggles();
                    applyDomainToggle();
                });
                el.appendChild(domainBtn);

                @if(session('prometheus_enabled') && session('prometheus_url'))
                var statsBtn = document.createElement('button');
                statsBtn.type = 'button';
                statsBtn.className = 'btn btn-sm btn-outline-secondary ms-2 toggle-stats-btn';
                statsBtn.addEventListener('click', function() {
                    statsHidden = !statsHidden;
                    saveToggles();
                    applyStatsToggle();
                });
                el.appendChild(statsBtn);
                @endif
            });

            // Set initial button text
            applyDomainToggle();
            applyStatsToggle();
        });
    </script>
    @endsection
</x-app-layout>
