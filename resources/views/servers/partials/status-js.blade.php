{{-- Shared Prometheus/status JS for the two server index variants. Emitted
     INSIDE each page's window load listener, so page-specific code in the
     same closure (toggles, DataTables config) can call these functions.
     Params: $withLinkUsage (table page tracks .link-cell usage),
     $roundedUptime (cards round the uptime pill corners). --}}
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
function updateStatusIcons(statuses) {
    document.querySelectorAll('.status-check-btn').forEach(function(btn) {
        var hostname = btn.getAttribute('data-hostname');
        var icon = btn.querySelector('i');
        if (!icon) return;

        var matched = false;
        var statusClasses = ['text-success', 'text-danger', 'text-warning', 'text-muted'];
        for (var promHost in statuses) {
            if (matchHost(hostname, promHost)) {
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

function isIpAddress(s) {
    // IPv4 or bracketless IPv6 (Prometheus strips :port before keying)
    return /^\d{1,3}(\.\d{1,3}){3}$/.test(s) || s.indexOf(':') !== -1;
}

function matchHost(hostname, promHost) {
    // Case-insensitive like DNS (and PromQL::hostMatches) — a mixed-case
    // hostname must still match Prometheus's lowercase instance labels.
    hostname = hostname.toLowerCase();
    promHost = promHost.toLowerCase();
    // If either side is an IP, only exact equality counts — mirrors
    // PromQL::hostMatches so the list and detail agree.
    if (isIpAddress(hostname) || isIpAddress(promHost)) {
        return hostname === promHost;
    }
    return hostname === promHost || promHost === hostname.split('.')[0]
        || hostname === promHost.split('.')[0] || hostname.indexOf(promHost + '.') === 0;
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

@if($withLinkUsage)
function linkColorClass(pct) {
    if (pct >= 90) return 'text-danger';
    if (pct >= 75) return 'text-warning';
    return 'text-success';
}

function updateLinkUsage(metrics) {
    document.querySelectorAll('.link-cell').forEach(function(cell) {
        var hostname = cell.getAttribute('data-hostname');
        var linkSpeedMbps = parseFloat(cell.getAttribute('data-link-speed'));
        var span = cell.querySelector('.link-usage');
        if (!span || !linkSpeedMbps) return;

        var linkBytesPerSec = linkSpeedMbps * 1000000 / 8;

        for (var promHost in metrics) {
            if (matchHost(hostname, promHost) && (metrics[promHost].net_rx != null || metrics[promHost].net_tx != null)) {
                var rx = metrics[promHost].net_rx || 0;
                var tx = metrics[promHost].net_tx || 0;
                var peak = Math.max(rx, tx);
                var pct = Math.round(peak / linkBytesPerSec * 1000) / 10;
                span.className = 'link-usage ' + linkColorClass(pct);
                span.textContent = ' (' + pct + '%)';
                return;
            }
        }
    });
}
@endif

function fmtDuration(secs) {
    var d = Math.floor(secs / 86400);
    var h = Math.floor((secs % 86400) / 3600);
    var m = Math.floor((secs % 3600) / 60);
    var s = Math.floor(secs) % 60;
    if (d > 0) return d + 'd ' + h + 'h ' + m + 'm';
    if (h > 0) return h + 'h ' + m + 'm ' + s + 's';
    if (m > 0) return m + 'm ' + s + 's';
    return s + 's';
}

// Store per-cell data for live ticking
var uptimeData = {};

function updateUptimeCells(statuses, metrics) {
    document.querySelectorAll('.uptime-cell').forEach(function(cell) {
        var hostname = cell.getAttribute('data-hostname');

        for (var promHost in statuses) {
            if (!matchHost(hostname, promHost)) continue;

            var isUp = statuses[promHost];
            var m = metrics[promHost] || {};

            if (isUp && m.uptime != null) {
                uptimeData[hostname] = {type: 'uptime', base: m.uptime, fetched: Date.now()};
                cell.textContent = fmtDuration(m.uptime);
                cell.style.background = '';
                @if($roundedUptime)
                cell.style.borderRadius = '';
                @endif
                cell.classList.remove('text-white');
            } else if (!isUp && m.offline_since != null) {
                uptimeData[hostname] = {type: 'downtime', since: m.offline_since};
                var elapsed = Date.now() / 1000 - m.offline_since;
                cell.textContent = '-' + fmtDuration(elapsed);
                cell.style.background = 'var(--bs-danger, #dc3545)';
                @if($roundedUptime)
                cell.style.borderRadius = '4px';
                @endif
                cell.classList.add('text-white');
            } else if (!isUp) {
                uptimeData[hostname] = {type: 'down_unknown'};
                cell.textContent = '-Down';
                cell.style.background = 'var(--bs-danger, #dc3545)';
                @if($roundedUptime)
                cell.style.borderRadius = '4px';
                @endif
                cell.classList.add('text-white');
            }
            return;
        }
    });
}

// Tick uptime/downtime counters every second
setInterval(function() {
    document.querySelectorAll('.uptime-cell').forEach(function(cell) {
        var hostname = cell.getAttribute('data-hostname');
        var data = uptimeData[hostname];
        if (!data) return;

        if (data.type === 'uptime') {
            var elapsed = data.base + (Date.now() - data.fetched) / 1000;
            cell.textContent = fmtDuration(elapsed);
        } else if (data.type === 'downtime') {
            var elapsed = Date.now() / 1000 - data.since;
            cell.textContent = '-' + fmtDuration(elapsed);
        }
    });
}, 1000);

function fetchPrometheusStatus() {
    axios.get('/tools/prometheus/status').then(function(response) {
        if (response.data.statuses) {
            updateStatusIcons(response.data.statuses);
        }
        if (response.data.metrics) {
            updateRamUsage(response.data.metrics);
            updateDiskUsage(response.data.metrics);
            @if($withLinkUsage)
            updateLinkUsage(response.data.metrics);
            @endif
        }
        if (response.data.statuses && response.data.metrics) {
            updateUptimeCells(response.data.statuses, response.data.metrics);
        }
        if (statsHidden) applyStatsToggle();
    }).catch(function() {});
}

if (prometheusEnabled && prometheusUrl) {
    fetchPrometheusStatus();
    setInterval(fetchPrometheusStatus, prometheusInterval * 1000);
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
                    .get('/tools/online/' + encodeURIComponent(hostname))
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
            // Absolute like the shared modal component: a relative action on
            // a page loaded as /servers/ (trailing slash matches without a
            // redirect) would POST the DELETE to /servers/servers/{id} → 404
            this.delete_form_action = '/servers/' + this.modal_id;
        }
    }
});
