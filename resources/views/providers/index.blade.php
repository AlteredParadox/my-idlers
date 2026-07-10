@section("title", "Providers")
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">Providers</h2>
            <div class="page-actions">
                <a href="{{ route('providers.create') }}" class="btn btn-primary">Add provider</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="content-card">
            <div class="table-responsive">
                <table class="table data-table" id="providers-table">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th class="text-center" style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @if(!empty($providers))
                        @foreach($providers as $provider)
                        <tr>
                            <td class="fw-medium">{{ $provider['name'] }}</td>
                            <td class="text-center text-nowrap">
                                <div class="action-buttons">
                                    <a href="{{ route('providers.show', $provider['id']) }}" class="btn btn-sm btn-action" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                            @click="confirmDeleteModal" id="{{ $provider['id'] }}" data-title="{{ $provider['name'] }}">
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

        <x-details-footer></x-details-footer>
        <x-delete-confirm-modal></x-delete-confirm-modal>
    </div>

    <x-modal-delete-script>
        <x-slot name="uri">providers</x-slot>
    </x-modal-delete-script>

    @section('scripts')
    @include('partials.datatable-init', ['tables' => ['#providers-table'], 'noSort' => [1], 'empty' => 'No providers found'])
    @endsection
</x-app-layout>
