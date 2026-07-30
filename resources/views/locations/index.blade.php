@section('title', 'Locations')
<x-app-layout>
    <div class="container">
        <div class="page-header">
            <h2 class="page-title">Locations</h2>
            <div class="page-actions">
                <a href="{{ route('locations.create') }}" class="btn btn-primary">Add location</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="content-card">
            <div class="table-responsive">
                <table class="table data-table" id="locations-table">
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th class="text-center" style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @if(!empty($locations))
                        @foreach($locations as $location)
                        <tr>
                            <td class="fw-medium">{{ $location['name'] }}</td>
                            <td class="text-center text-nowrap">
                                <div class="action-buttons">
                                    <a href="{{ route('locations.show', $location['id']) }}" class="btn btn-sm btn-action" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button type="button" class="btn btn-sm btn-action btn-delete" title="Delete"
                                            data-id="{{ $location['id'] }}" data-title="{{ $location['name'] }}">
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
        <x-delete-confirm-modal uri="locations" />
    </div>


    @section('scripts')
    @include('partials.datatable-init', ['tables' => ['#locations-table'], 'noSort' => [1], 'empty' => 'No locations found'])
    @endsection
</x-app-layout>
