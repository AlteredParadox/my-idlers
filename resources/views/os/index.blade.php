@section('title', 'Operating systems')
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">Operating Systems</h2>
            <div class="page-actions">
                <a href="{{ route('os.create') }}" class="btn btn-primary">Add OS</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="content-card">
            <div class="table-responsive">
                <table class="table data-table" id="os-table">
                    <thead>
                        <tr>
                            <th>Operating System</th>
                            <th class="text-center" style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @if(!empty($os))
                        @foreach($os as $o)
                        <tr>
                            <td class="fw-medium">{{ $o['name'] }}</td>
                            <td class="text-center text-nowrap">
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                            @click="confirmDeleteModal" id="{{ $o['id'] }}" data-title="{{ $o['name'] }}">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="2" class="text-center text-muted py-4">No operating systems found</td>
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
        <x-slot name="uri">os</x-slot>
    </x-modal-delete-script>

    @section('scripts')
    @include('partials.datatable-init', ['tables' => ['#os-table'], 'noSort' => [1], 'empty' => 'No operating systems found'])
    @endsection
</x-app-layout>
