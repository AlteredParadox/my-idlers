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
                                    <th>Price</th>
                                    <th class="text-center">Due</th>
                                    <th class="text-center">Since</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($servers))
                                @foreach($servers as $server)
                                <tr>
                                    <td class="fw-medium hostname-cell" data-full="{{ $server->hostname }}">{{ $server->hostname }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-type">{{ App\Models\Server::serviceServerType($server->server_type) }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if(isset($server->os))
                                            <span title="{{ $server->os->name }}">{!! App\Models\Server::osIntToIcon($server->os->id, $server->os->name) !!}</span>
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
                                    <td class="text-center text-nowrap" data-order="{{ $server->link_speed ?? 0 }}">
                                        @if($server->link_speed)
                                            @if($server->link_speed >= 1000)
                                                {{ rtrim(rtrim(number_format($server->link_speed / 1000, 1), '0'), '.') }}<small class="text-muted">Gbps</small>
                                            @else
                                                {{ $server->link_speed }}<small class="text-muted">Mbps</small>
                                            @endif
                                        @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->network_type ?? '-' }}</td>
                                    <td class="text-nowrap">{{ $server->location->name }}</td>
                                    <td class="text-nowrap">{{ $server->provider->name }}</td>
                                    <td class="text-nowrap" data-order="{{ $server->price->usd_per_month }}">
                                        {{ $server->price->price }} {{ $server->price->currency }}
                                        <small class="text-muted">{{ \App\Process::paymentTermIntToString($server->price->term) }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $server->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false) : -99999 }}">
                                        @if($server->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->owned_since }}</td>
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('servers.show', $server->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('servers.edit', $server->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-action status-check-btn" title="Check status"
                                                    data-hostname="{{ $server->hostname }}" @click="checkIfUp">
                                                <i class="fas fa-plug"></i>
                                            </button>
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
                                    <td colspan="16" class="text-center text-muted py-4">No active servers found</td>
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
                                    <th>Price</th>
                                    <th class="text-center">Expires In</th>
                                    <th class="text-center">Since</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($non_active_servers))
                                @foreach($non_active_servers as $server)
                                @php $expired = $server->price->next_due_date && Carbon\Carbon::parse($server->price->next_due_date)->isPast(); @endphp
                                <tr class="{{ $expired ? 'expired-row' : '' }}">
                                    <td class="fw-medium hostname-cell" data-full="{{ $server->hostname }}">{{ $server->hostname }}</td>
                                    <td class="text-center">
                                        <span class="badge badge-type">{{ App\Models\Server::serviceServerType($server->server_type) }}</span>
                                    </td>
                                    <td class="text-center">
                                        @if(isset($server->os))
                                            <span title="{{ $server->os->name }}">{!! App\Models\Server::osIntToIcon($server->os->id, $server->os->name) !!}</span>
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
                                    <td class="text-center text-nowrap" data-order="{{ $server->link_speed ?? 0 }}">
                                        @if($server->link_speed)
                                            @if($server->link_speed >= 1000)
                                                {{ rtrim(rtrim(number_format($server->link_speed / 1000, 1), '0'), '.') }}<small class="text-muted">Gbps</small>
                                            @else
                                                {{ $server->link_speed }}<small class="text-muted">Mbps</small>
                                            @endif
                                        @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->network_type ?? '-' }}</td>
                                    <td class="text-nowrap">{{ $server->location->name }}</td>
                                    <td class="text-nowrap">{{ $server->provider->name }}</td>
                                    <td class="text-nowrap" data-order="{{ $server->price->usd_per_month }}">
                                        {{ $server->price->price }} {{ $server->price->currency }}
                                        <small class="text-muted">{{ \App\Process::paymentTermIntToString($server->price->term) }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $server->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false) : -99999 }}">
                                        @if($server->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($server->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $server->owned_since }}</td>
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('servers.show', $server->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('servers.edit', $server->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-action status-check-btn" title="Check status"
                                                    data-hostname="{{ $server->hostname }}" @click="checkIfUp">
                                                <i class="fas fa-plug"></i>
                                            </button>
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
                                    <td colspan="16" class="text-center text-muted py-4">No inactive servers found</td>
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
    <script>
        window.addEventListener('load', function () {
            document.getElementById("confirmDeleteModal").classList.remove("d-none");
            axios.defaults.headers.common = {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            };

            var prometheusEnabled = {{ session('prometheus_enabled', 0) ? 'true' : 'false' }};
            var prometheusUrl = @json(session('prometheus_url', ''));
            var prometheusInterval = {{ session('prometheus_check_interval', 20) }};
            var authToken = document.querySelector('meta[name="api_token"]').getAttribute('content');

            function updateStatusIcons(statuses) {
                document.querySelectorAll('.status-check-btn').forEach(function(btn) {
                    var hostname = btn.getAttribute('data-hostname');
                    var icon = btn.querySelector('i');
                    if (!icon) return;

                    var matched = false;
                    var statusClasses = ['text-success', 'text-danger', 'text-warning', 'text-muted'];
                    for (var promHost in statuses) {
                        if (hostname === promHost || hostname.indexOf(promHost) === 0 || promHost.indexOf(hostname.split('.')[0]) === 0) {
                            icon.classList.remove(...statusClasses);
                            icon.classList.add(statuses[promHost] ? 'text-success' : 'text-danger');
                            matched = true;
                            break;
                        }
                    }
                    if (!matched) {
                        icon.classList.remove(...statusClasses);
                        icon.classList.add('text-warning');
                    }
                });
            }

            function ramColorClass(pct) {
                if (pct >= 85) return 'text-danger';
                if (pct >= 65) return 'text-warning';
                return 'text-success';
            }

            function diskColorClass(pct) {
                if (pct >= 90) return 'text-danger';
                if (pct >= 75) return 'text-warning';
                return 'text-success';
            }

            function matchHost(hostname, promHost) {
                return hostname === promHost || hostname.indexOf(promHost) === 0 || promHost.indexOf(hostname.split('.')[0]) === 0;
            }

            function updateRamUsage(metrics) {
                document.querySelectorAll('.ram-cell').forEach(function(cell) {
                    var hostname = cell.getAttribute('data-hostname');
                    var span = cell.querySelector('.ram-usage');
                    if (!span) return;

                    for (var promHost in metrics) {
                        if (matchHost(hostname, promHost) && metrics[promHost].ram_pct != null) {
                            var pct = metrics[promHost].ram_pct;
                            span.className = 'ram-usage ' + ramColorClass(pct);
                            span.textContent = ' (' + pct + '%)';
                            return;
                        }
                    }
                });
            }

            function updateDiskUsage(metrics) {
                document.querySelectorAll('.disk-cell').forEach(function(cell) {
                    var hostname = cell.getAttribute('data-hostname');
                    var span = cell.querySelector('.disk-usage');
                    if (!span) return;

                    for (var promHost in metrics) {
                        if (matchHost(hostname, promHost) && metrics[promHost].disk_pct != null) {
                            var pct = metrics[promHost].disk_pct;
                            span.className = 'disk-usage ' + diskColorClass(pct);
                            span.textContent = ' (' + pct + '%)';
                            return;
                        }
                    }
                });
            }

            function fetchPrometheusStatus() {
                axios.get('/api/prometheus/status', {
                    headers: {'Authorization': 'Bearer ' + authToken}
                }).then(function(response) {
                    if (response.data.statuses) {
                        updateStatusIcons(response.data.statuses);
                    }
                    if (response.data.metrics) {
                        updateRamUsage(response.data.metrics);
                        updateDiskUsage(response.data.metrics);
                    }
                }).catch(function() {});
            }

            var statusInterval = null;
            if (prometheusEnabled && prometheusUrl) {
                fetchPrometheusStatus();
                statusInterval = setInterval(fetchPrometheusStatus, prometheusInterval * 1000);
            }

            let app = new Vue({
                el: "#app",
                data: {
                    status: false,
                    modal_hostname: '',
                    modal_id: '',
                    delete_form_action: '',
                    showModal: false
                },
                methods: {
                    checkIfUp(event) {
                        if (prometheusEnabled && prometheusUrl) return; // Prometheus handles status automatically
                        var el = event.target.closest('button') || event.target;
                        var hostname = el.getAttribute('data-hostname') || el.id;
                        var icon = el.querySelector('i') || event.target;

                        if (hostname) {
                            axios
                                .get('/api/online/' + hostname, {
                                    headers: {'Authorization': 'Bearer ' + authToken}
                                })
                                .then(response => (this.status = response.data.is_online))
                                .finally(() => {
                                    icon.classList.remove('text-success', 'text-danger');
                                    icon.classList.add(this.status ? 'text-success' : 'text-danger');
                                });
                        }
                    },
                    confirmDeleteModal(event) {
                        var el = event.target.closest('button') || event.target;
                        this.showModal = true;
                        this.modal_hostname = el.dataset.title || el.title;
                        this.modal_id = el.id;
                        this.delete_form_action = 'servers/' + this.modal_id;
                    }
                }
            });

            $.fn.dataTable.ext.errMode = 'none';
            var dtConfig = {
                pageLength: {{ session('default_per_page', 100) }},
                lengthMenu: [10, 25, 50, 100, 250, 500],
                columnDefs: [
                    {orderable: false, targets: [2, 15]}
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
            $('#servers-table').DataTable(dtConfig);
            $('#inactive-servers-table').DataTable(dtConfig);

            // Add "Hide Domains" toggle next to each table's "Show" dropdown
            var domainHidden = false;
            document.querySelectorAll('.dataTables_length').forEach(function(el) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-outline-secondary ms-2 toggle-domains-btn';
                btn.innerHTML = '<i class="fas fa-eye-slash"></i> Hide Domains';
                btn.addEventListener('click', function() {
                    domainHidden = !domainHidden;
                    document.querySelectorAll('.toggle-domains-btn').forEach(function(b) {
                        b.innerHTML = domainHidden
                            ? '<i class="fas fa-eye"></i> Show Domains'
                            : '<i class="fas fa-eye-slash"></i> Hide Domains';
                    });
                    document.querySelectorAll('.hostname-cell').forEach(function(cell) {
                        var full = cell.getAttribute('data-full');
                        cell.textContent = domainHidden ? full.split('.')[0] : full;
                    });
                });
                el.appendChild(btn);
            });
        });
    </script>
    @endsection
</x-app-layout>
