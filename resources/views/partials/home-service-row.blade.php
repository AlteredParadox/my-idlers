{{-- One row of the Due Soon / Recently Added tables. Params: $row (joined
     pricing row carrying every type's name column), $date (display string). --}}
@php
    $home_row_names = [
        1 => $row->hostname,
        2 => $row->main_domain,
        3 => $row->reseller,
        4 => $row->domain . '.' . $row->extension,
        5 => $row->name,
        6 => $row->title,
    ];
    $home_row_types = [1 => 'VPS', 2 => 'Shared', 3 => 'Reseller', 4 => 'Domain', 5 => 'Misc', 6 => 'Seedbox'];
    $home_row_routes = [
        1 => 'servers.show',
        2 => 'shared.show',
        3 => 'reseller.show',
        4 => 'domains.show',
        5 => 'misc.show',
        6 => 'seedboxes.show'
    ];
@endphp
<tr>
    <td>{{ $home_row_names[$row->service_type] ?? '' }}</td>
    <td>
        <span class="badge bg-secondary">{{ $home_row_types[$row->service_type] ?? '' }}</span>
    </td>
    <td>{{ $date }}</td>
    <td>{{ $row->price }} {{ $row->currency }} {{ \App\Process::paymentTermIntToString($row->term) }}</td>
    <td class="text-center">
        <a href="{{ route($home_row_routes[$row->service_type], $row->service_id) }}" class="btn btn-sm btn-outline-primary">View</a>
    </td>
</tr>
