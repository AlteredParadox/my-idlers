{{-- DB-backed per-user UI preferences: DataTables state (sort order, column
     visibility, page length) and misc view toggles persist via the
     preferences endpoint. Guests get plain, non-persistent tables. --}}
@php($userPrefs = auth()->check() ? \App\Models\UserPreference::valuesFor(auth()->id()) : [])
<script>
    window.idlersPrefs = @json((object) $userPrefs);

    window.idlersSavePref = function (key, value) {
        fetch('{{ url('preferences') }}/' + key, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(value)
        });
    };

    // Shared DataTable init: persists state per table for logged-in users
    // and adds the column show/hide dropdown to the table toolbar.
    window.idlersDataTable = function (selector, config) {
        var key = 'dt.' + selector.replace('#', '');
        @if(auth()->check())
        var timer = null;
        config.stateSave = true;
        config.stateDuration = 0;
        config.stateSaveCallback = function (settings, data) {
            data.search.search = ''; // don't resurrect old searches on reload
            clearTimeout(timer);
            timer = setTimeout(function () { window.idlersSavePref(key, data); }, 500);
        };
        config.stateLoadCallback = function () {
            return window.idlersPrefs[key] || null;
        };
        @endif
        var dt = $(selector).DataTable(config);
        idlersColumnMenu(dt, selector);
        return dt;
    };

    // Hand-rolled dropdown (position:fixed) so the menu isn't clipped by
    // the .table-responsive overflow container bootstrap dropdowns sit in.
    function idlersColumnMenu(dt, selector) {
        var menu = $('<ul class="dropdown-menu idlers-colvis-menu" style="max-height: 60vh; overflow-y: auto;"></ul>');
        dt.columns().every(function (i) {
            var col = this;
            var title = $(col.header()).text().trim() || 'Column ' + (i + 1);
            var label = $('<label class="dropdown-item mb-0" style="cursor: pointer;"></label>');
            var box = $('<input type="checkbox" class="form-check-input me-2">')
                .prop('checked', col.visible())
                .on('change', function () { col.visible(this.checked); });
            label.append(box).append(document.createTextNode(title));
            menu.append($('<li></li>').append(label));
        });

        var btn = $('<button type="button" class="btn btn-sm btn-outline-secondary ms-2" title="Show/hide columns">Columns</button>');
        btn.on('click', function (e) {
            e.stopPropagation();
            var wasOpen = menu.hasClass('show');
            $('.idlers-colvis-menu.show').removeClass('show');
            if (!wasOpen) {
                menu.addClass('show');
                var r = this.getBoundingClientRect();
                menu.css({position: 'fixed', top: (r.bottom + 4) + 'px', left: Math.max(8, r.right - menu.outerWidth()) + 'px'});
            }
        });
        menu.on('click', function (e) { e.stopPropagation(); });
        $(selector).closest('.dataTables_wrapper').find('.dataTables_filter').append(btn).append(menu);
    }

    $(document).on('click', function () { $('.idlers-colvis-menu.show').removeClass('show'); });
    window.addEventListener('scroll', function (e) {
        if (!$(e.target).closest('.idlers-colvis-menu').length) {
            $('.idlers-colvis-menu.show').removeClass('show');
        }
    }, true);
</script>
