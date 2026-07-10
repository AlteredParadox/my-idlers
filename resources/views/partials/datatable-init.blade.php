{{-- Shared DataTables init used by every index page.
     Params: $tables (array of selectors), $noSort (array of column indexes),
     $empty (emptyTable text), optional $placeholder (default "Search..."). --}}
@include('partials.datatable-persist')
<script>
    window.addEventListener('load', function () {
        $.fn.dataTable.ext.errMode = 'none';
        var dtConfig = {
            pageLength: {{ session('default_per_page', 100) }},
            lengthMenu: [10, 25, 50, 100, 250, 500],
            columnDefs: [
                {orderable: false, targets: @json($noSort)}
            ],
            language: {
                search: "",
                searchPlaceholder: "{{ $placeholder ?? 'Search...' }}",
                lengthMenu: "Show _MENU_",
                info: "Showing _START_ to _END_ of _TOTAL_",
                paginate: { previous: "Prev", next: "Next" },
                emptyTable: "{{ $empty }}"
            }
        };
        @foreach($tables as $table)
        window.idlersDataTable('{{ $table }}', dtConfig);
        @endforeach
    });
</script>
