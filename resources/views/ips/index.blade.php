@section('title', 'IP addresses')
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">IP Addresses</h2>
            <div class="page-actions">
                <a href="{{ route('IPs.create') }}" class="btn btn-primary">Add IP</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="content-card">
            <div class="table-responsive">
                <table class="table data-table" id="ips-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Address</th>
                            <th>Country</th>
                            <th>City</th>
                            <th>ORG</th>
                            <th>ASN</th>
                            <th>ISP</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @if(!empty($ips))
                        @foreach($ips as $ip)
                        <tr>
                            <td>
                                <span class="badge badge-type">
                                    @if($ip->is_ipv4 === 1) IPv4 @else IPv6 @endif
                                </span>
                            </td>
                            <td class="fw-medium text-nowrap">{{ $ip->address }}</td>
                            <td class="text-nowrap">{{ $ip->country }}</td>
                            <td class="text-nowrap">{{ $ip->city }}</td>
                            <td class="text-nowrap">{{ $ip->org }}</td>
                            <td class="text-nowrap">{{ $ip->asn }}</td>
                            <td class="text-nowrap">{{ $ip->isp }}</td>
                            <td class="text-center text-nowrap">
                                <div class="action-buttons">
                                    <a href="{{ route('ip.pull.info', $ip->id) }}" class="btn btn-sm btn-action" title="Pull IP info">
                                        <i class="fa-solid fa-arrows-rotate"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                            @click="confirmDeleteModal" id="{{ $ip->id }}" data-title="{{ $ip->address }}">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No IP addresses found</td>
                        </tr>
                    @endif
                    </tbody>
                </table>
            </div>
        </div>

        <x-details-footer></x-details-footer>
        <x-delete-confirm-modal></x-delete-confirm-modal>
    </div>

    <x-modal-delete-script>
        <x-slot name="uri">IPs</x-slot>
    </x-modal-delete-script>

    @section('scripts')
    @include('partials.datatable-init', ['tables' => ['#ips-table'], 'noSort' => [7], 'empty' => 'No IP addresses found'])
    @endsection
</x-app-layout>
