@section('title', 'Resellers')
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">Reseller Hosting</h2>
            <div class="page-actions">
                <x-export-buttons route="export.reseller" />
                <a href="{{ route('reseller.create') }}" class="btn btn-primary">Add reseller</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="content-card">
            <div class="card-tabs">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-resellers"
                                type="button" role="tab" aria-selected="true">
                            Active <span class="badge bg-secondary ms-1">{{ count($resellers ?? []) }}</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link @if(!isset($non_active_resellers[0])) disabled @endif" id="inactive-tab"
                                data-bs-toggle="tab" data-bs-target="#inactive-resellers" type="button" role="tab" aria-selected="false">
                            Inactive <span class="badge bg-secondary ms-1">{{ count($non_active_resellers ?? []) }}</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="active-resellers" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table data-table" id="reseller-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th class="text-center">Accounts</th>
                                    <th>Location</th>
                                    <th>Provider</th>
                                    <th class="text-center">Transferrable</th>
                                    <th class="text-center">Disk</th>
                                    <th class="text-center">Domains</th>
                                    <th class="text-center">Link</th>
                                    <th>Price</th>
                                    <th class="text-center">Due</th>
                                    <th class="text-center">Since</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($resellers))
                                @foreach($resellers as $row)
                                <tr>
                                    <td class="fw-medium">{{ $row->main_domain }}</td>
                                    <td><span class="badge badge-type">{{ $row->reseller_type }}</span></td>
                                    <td class="text-center">{{ $row->accounts }}</td>
                                    <td class="text-nowrap">{{ $row->location->name }}</td>
                                    <td class="text-nowrap">{{ $row->provider->name }}</td>
                                    <td class="text-center">{{ is_null($row->transferrable) ? '-' : (($row->transferrable === 1) ? 'Yes' : 'No') }}</td>
                                    <td class="text-center text-nowrap">{{ $row->disk_as_gb }}<small class="text-muted">GB</small></td>
                                    <td class="text-center">{{ $row->domains_limit }}</td>
                                    <td class="text-center text-nowrap" data-order="{{ $row->link_speed ?? 0 }}">
                                        @if($row->link_speed)
                                            @if($row->link_speed >= 1000)
                                                {{ rtrim(rtrim(number_format($row->link_speed / 1000, 1), '0'), '.') }}<small class="text-muted">Gbps</small>
                                            @else
                                                {{ $row->link_speed }}<small class="text-muted">Mbps</small>
                                            @endif
                                        @else - @endif
                                    </td>
                                    <td class="text-nowrap">
                                        {{ $row->price->price }} {{ $row->price->currency }}
                                        <small class="text-muted">{{ \App\Process::paymentTermIntToString($row->price->term) }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $row->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($row->price->next_due_date), false) : -99999 }}">
                                        @if($row->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($row->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $row->owned_since }}</td>
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('reseller.show', $row->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('reseller.edit', $row->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                                    @click="confirmDeleteModal" id="{{ $row->id }}" data-title="{{ $row->main_domain }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td colspan="13" class="text-center text-muted py-4">No active reseller hosting found</td>
                                </tr>
                            @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="inactive-resellers" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table data-table" id="inactive-reseller-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th class="text-center">Accounts</th>
                                    <th>Location</th>
                                    <th>Provider</th>
                                    <th class="text-center">Transferrable</th>
                                    <th class="text-center">Disk</th>
                                    <th class="text-center">Domains</th>
                                    <th class="text-center">Link</th>
                                    <th>Price</th>
                                    <th class="text-center">Expires In</th>
                                    <th class="text-center">Since</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($non_active_resellers))
                                @foreach($non_active_resellers as $row)
                                @php $expired = $row->price->next_due_date && Carbon\Carbon::parse($row->price->next_due_date)->isPast(); @endphp
                                <tr class="{{ $expired ? 'expired-row' : '' }}">
                                    <td class="fw-medium">{{ $row->main_domain }}</td>
                                    <td><span class="badge badge-type">{{ $row->reseller_type }}</span></td>
                                    <td class="text-center">{{ $row->accounts }}</td>
                                    <td class="text-nowrap">{{ $row->location->name }}</td>
                                    <td class="text-nowrap">{{ $row->provider->name }}</td>
                                    <td class="text-center">{{ is_null($row->transferrable) ? '-' : (($row->transferrable === 1) ? 'Yes' : 'No') }}</td>
                                    <td class="text-center text-nowrap">{{ $row->disk_as_gb }}<small class="text-muted">GB</small></td>
                                    <td class="text-center">{{ $row->domains_limit }}</td>
                                    <td class="text-center text-nowrap" data-order="{{ $row->link_speed ?? 0 }}">
                                        @if($row->link_speed)
                                            @if($row->link_speed >= 1000)
                                                {{ rtrim(rtrim(number_format($row->link_speed / 1000, 1), '0'), '.') }}<small class="text-muted">Gbps</small>
                                            @else
                                                {{ $row->link_speed }}<small class="text-muted">Mbps</small>
                                            @endif
                                        @else - @endif
                                    </td>
                                    <td class="text-nowrap">
                                        {{ $row->price->price }} {{ $row->price->currency }}
                                        <small class="text-muted">{{ \App\Process::paymentTermIntToString($row->price->term) }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $row->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($row->price->next_due_date), false) : -99999 }}">
                                        @if($row->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($row->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $row->owned_since }}</td>
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('reseller.show', $row->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('reseller.edit', $row->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                                    @click="confirmDeleteModal" id="{{ $row->id }}" data-title="{{ $row->main_domain }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td colspan="13" class="text-center text-muted py-4">No inactive reseller hosting found</td>
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

    <x-modal-delete-script>
        <x-slot name="uri">reseller</x-slot>
    </x-modal-delete-script>

    @section('scripts')
    <script>
        window.addEventListener('load', function () {
            $.fn.dataTable.ext.errMode = 'none';
            var dtConfig = {
                pageLength: {{ session('default_per_page', 100) }},
                lengthMenu: [10, 25, 50, 100, 250, 500],
                columnDefs: [
                    {orderable: false, targets: [12]}
                ],
                language: {
                    search: "",
                    searchPlaceholder: "Search...",
                    lengthMenu: "Show _MENU_",
                    info: "Showing _START_ to _END_ of _TOTAL_",
                    paginate: { previous: "Prev", next: "Next" },
                    emptyTable: "No reseller hosting found"
                }
            };
            $('#reseller-table').DataTable(dtConfig);
            $('#inactive-reseller-table').DataTable(dtConfig);
        });
    </script>
    @endsection
</x-app-layout>
