@section('title', 'Domain names')
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">Domains</h2>
            <div class="page-actions">
                <x-export-buttons route="export.domains" />
                <a href="{{ route('domains.create') }}" class="btn btn-primary">Add domain</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="content-card">
            <div class="card-tabs">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="active-tab" data-bs-toggle="tab" data-bs-target="#active-domains"
                                type="button" role="tab" aria-selected="true">
                            Active <span class="badge bg-secondary ms-1">{{ count($domains ?? []) }}</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link @if(!isset($non_active_domains[0])) disabled @endif" id="inactive-tab"
                                data-bs-toggle="tab" data-bs-target="#inactive-domains" type="button" role="tab" aria-selected="false">
                            Inactive <span class="badge bg-secondary ms-1">{{ count($non_active_domains ?? []) }}</span>
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="active-domains" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table data-table" id="domain-table">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>Provider</th>
                                    <th class="text-center">Transferrable</th>
                                    <th>Price</th>
                                    <th class="text-center">Price/yr (USD)</th>
                                    <th class="text-center">Due In</th>
                                    <th class="text-center">Since</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($domains))
                                @foreach($domains as $domain)
                                <tr>
                                    <td class="fw-medium">
                                        <a href="https://{{ $domain->domain }}.{{ $domain->extension }}" class="text-decoration-none" target="_blank">
                                            {{ $domain->domain }}.{{ $domain->extension }}
                                        </a>
                                    </td>
                                    <td class="text-nowrap">{{ $domain->provider->name ?? '-' }}</td>
                                    <td class="text-center">{{ is_null($domain->transferrable) ? '-' : (($domain->transferrable === 1) ? 'Yes' : 'No') }}</td>
                                    <td class="text-nowrap">
                                        {{ $domain->price->price }}
                                        <small class="text-muted">{{ $domain->price->currency }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $domain->price->usd_per_month * 12 }}">@if($domain->price->usd_per_month > 0)${{ number_format($domain->price->usd_per_month * 12, 2) }}@else - @endif</td>
                                    <td class="text-center text-nowrap" data-order="{{ $domain->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($domain->price->next_due_date), false) : -99999 }}">
                                        @if($domain->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($domain->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $domain->owned_since }}</td>
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('domains.show', $domain->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('domains.edit', $domain->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                                    @click="confirmDeleteModal" id="{{ $domain->id }}" data-title="{{ $domain->domain }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="inactive-domains" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table data-table" id="inactive-domain-table">
                            <thead>
                                <tr>
                                    <th>Domain</th>
                                    <th>Provider</th>
                                    <th class="text-center">Transferrable</th>
                                    <th>Price</th>
                                    <th class="text-center">Price/yr (USD)</th>
                                    <th class="text-center">Expires In</th>
                                    <th class="text-center">Since</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            @if(!empty($non_active_domains))
                                @foreach($non_active_domains as $domain)
                                @php $expired = $domain->price->next_due_date && Carbon\Carbon::parse($domain->price->next_due_date)->isPast(); @endphp
                                <tr class="{{ $expired ? 'expired-row' : '' }}">
                                    <td class="fw-medium">
                                        {{ $domain->domain }}.{{ $domain->extension }}
                                    </td>
                                    <td class="text-nowrap">{{ $domain->provider->name ?? '-' }}</td>
                                    <td class="text-center">{{ is_null($domain->transferrable) ? '-' : (($domain->transferrable === 1) ? 'Yes' : 'No') }}</td>
                                    <td class="text-nowrap">
                                        {{ $domain->price->price }}
                                        <small class="text-muted">{{ $domain->price->currency }}</small>
                                    </td>
                                    <td class="text-center text-nowrap" data-order="{{ $domain->price->usd_per_month * 12 }}">@if($domain->price->usd_per_month > 0)${{ number_format($domain->price->usd_per_month * 12, 2) }}@else - @endif</td>
                                    <td class="text-center text-nowrap" data-order="{{ $domain->price->next_due_date ? now()->diffInDays(Carbon\Carbon::parse($domain->price->next_due_date), false) : -99999 }}">
                                        @if($domain->price->next_due_date) {{ number_format(now()->diffInDays(Carbon\Carbon::parse($domain->price->next_due_date), false), 0) }}d @else - @endif
                                    </td>
                                    <td class="text-center text-nowrap">{{ $domain->owned_since }}</td>
                                    <td class="text-center text-nowrap">
                                        <div class="action-buttons">
                                            <a href="{{ route('domains.show', $domain->id) }}" class="btn btn-sm btn-action" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="{{ route('domains.edit', $domain->id) }}" class="btn btn-sm btn-action" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                                    @click="confirmDeleteModal" id="{{ $domain->id }}" data-title="{{ $domain->domain }}">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
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
        <x-slot name="uri">domains</x-slot>
    </x-modal-delete-script>

    @section('scripts')
    @include('partials.datatable-init', ['tables' => ['#domain-table', '#inactive-domain-table'], 'noSort' => [7], 'empty' => 'No domains found'])
    @endsection
</x-app-layout>
