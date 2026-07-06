@section("title", "{$server_data->hostname} server")
@section('css_links')
    @if(session('prometheus_enabled') && session('prometheus_url'))
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.49.1/dist/apexcharts.min.js"></script>
    @endif
@endsection
@section('style')
@if(session('prometheus_enabled') && session('prometheus_url'))
<style>
.stat-card {
    border-radius: 8px;
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--bs-border-color, #dee2e6);
    background: var(--bs-body-bg, #fff);
    border-top: 3px solid var(--stat-color, #6c757d);
}
.stat-card .stat-title {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--bs-secondary-color, #6c757d);
    margin-bottom: 0.3rem;
}
.stat-card .stat-values {
    display: flex;
    gap: 0.8rem;
    font-size: 0.8rem;
}
.stat-card .stat-values .stat-entry {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.stat-card .stat-values .stat-label {
    font-size: 0.6rem;
    text-transform: uppercase;
    color: var(--bs-secondary-color, #6c757d);
}
.stat-card .stat-values .stat-val {
    font-weight: 600;
    font-size: 0.85rem;
}
.prom-green { color: #4caf50; }
.prom-orange { color: #ff9800; }
.prom-red { color: #f44336; }
.disk-card-prom {
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: 8px;
    padding: 0.6rem 0.8rem;
    background: var(--bs-body-bg, #fff);
}
.disk-card-prom .disk-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
    font-weight: 600;
}
.disk-card-prom .disk-meta {
    font-size: 0.7rem;
    color: var(--bs-secondary-color, #6c757d);
    margin: 0.2rem 0 0.4rem;
}
.disk-bar-bg {
    height: 6px;
    background: var(--bs-secondary-bg, #e9ecef);
    border-radius: 3px;
    overflow: hidden;
}
.disk-bar-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s;
}
.disk-sizes {
    display: flex;
    justify-content: space-between;
    font-size: 0.7rem;
    color: var(--bs-secondary-color, #6c757d);
    margin-top: 0.2rem;
}
</style>
@endif
@endsection
@section('scripts')
    <script>
        function showYabsCode() {
            const el = document.querySelector('#yabs_code');
            el.classList.toggle("d-none");
        }
    </script>

    @if(session('prometheus_enabled') && session('prometheus_url'))
    <script>
    (function() {
        var hostname = @json($server_data->hostname);
        var currentPeriod = '24h';
        var currentBack = 0;
        var chartUsage = null, chartNetwork = null, chartDiskIO = null;
        var refreshTimer = null;

        var STAT_THRESHOLDS = {
            0: [65, 85],   // CPU
            1: [10, 30],   // IOwait
            2: [5, 10],    // Steal
            3: [65, 85],   // RAM
            4: [50, 80],   // Swap
            5: [75, 90],   // Disk
        };

        var METRIC_NAMES = ['CPU', 'IOwait', 'Steal', 'RAM', 'Swap', 'Disk', 'Net RX', 'Net TX', 'Disk Read', 'Disk Write'];
        var METRIC_COLORS = ['#1a73e8', '#f9a825', '#e53935', '#43a047', '#00897b', '#8e24aa', '#43a047', '#ff9800', '#43a047', '#ff9800'];

        function colorClass(v, warn, crit) {
            if (v >= crit) return 'prom-red';
            if (v >= warn) return 'prom-orange';
            return 'prom-green';
        }

        function fmtPct(v) { return v != null ? v.toFixed(1) + '%' : '-'; }

        function fmtBytes(v) {
            if (v == null || v === 0) return '0 B';
            var units = ['B', 'KB', 'MB', 'GB', 'TB'];
            var i = 0;
            while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
            return v.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
        }

        function fmtSpeed(v) {
            if (v == null || v === 0) return '0 B/s';
            var units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
            var i = 0;
            while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
            return v.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
        }

        function fmtBits(v) {
            if (v == null || v === 0) return '0 bps';
            v *= 8;
            var units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
            var i = 0;
            while (v >= 1000 && i < units.length - 1) { v /= 1000; i++; }
            return v.toFixed(1) + ' ' + units[i];
        }

        function diskBarColor(pct) {
            if (pct >= 90) return '#e53935';
            if (pct >= 75) return '#ff9800';
            return '#43a047';
        }

        function buildStatCards(stats) {
            var container = document.getElementById('stat-cards');
            container.innerHTML = '';
            METRIC_NAMES.forEach(function(name, i) {
                var isPct = i <= 5;
                var cur = stats.current[i];
                var avg = stats.avg[i];
                var mx = stats.max[i];
                var curText = isPct ? fmtPct(cur) : (i <= 7 ? fmtBits(cur) : fmtSpeed(cur));
                var avgText = isPct ? fmtPct(avg) : (i <= 7 ? fmtBits(avg) : fmtSpeed(avg));
                var maxText = isPct ? fmtPct(mx) : (i <= 7 ? fmtBits(mx) : fmtSpeed(mx));

                var curClass = '', maxClass = '';
                if (isPct && STAT_THRESHOLDS[i]) {
                    curClass = colorClass(cur, STAT_THRESHOLDS[i][0], STAT_THRESHOLDS[i][1]);
                    maxClass = colorClass(mx, STAT_THRESHOLDS[i][0], STAT_THRESHOLDS[i][1]);
                }

                var col = document.createElement('div');
                col.className = 'col-6 col-md-4 col-lg-2';
                col.innerHTML =
                    '<div class="stat-card" style="--stat-color:' + METRIC_COLORS[i] + '">' +
                    '<div class="stat-title">' + name + '</div>' +
                    '<div class="stat-values">' +
                    '<div class="stat-entry"><span class="stat-label">Cur</span><span class="stat-val ' + curClass + '">' + curText + '</span></div>' +
                    '<div class="stat-entry"><span class="stat-label">Avg</span><span class="stat-val">' + avgText + '</span></div>' +
                    '<div class="stat-entry"><span class="stat-label">Max</span><span class="stat-val ' + maxClass + '">' + maxText + '</span></div>' +
                    '</div></div>';
                container.appendChild(col);
            });
        }

        function buildDiskCards(disks) {
            var wrapper = document.getElementById('disk-cards-container');
            var container = document.getElementById('disk-cards');
            container.innerHTML = '';
            if (!disks || disks.length === 0) { wrapper.style.display = 'none'; return; }
            wrapper.style.display = '';

            disks.forEach(function(d) {
                var used = d.size - d.avail;
                var color = diskBarColor(d.used_pct);
                var col = document.createElement('div');
                col.className = 'col-12 col-md-6 col-lg-4';

                // Build with textContent, not innerHTML: mountpoint/device/fstype
                // are Prometheus node_exporter labels — trusted today, but an
                // injected filesystem label must never execute here.
                var card = document.createElement('div');
                card.className = 'disk-card-prom';

                var header = document.createElement('div');
                header.className = 'disk-header';
                var mount = document.createElement('span');
                mount.textContent = d.mountpoint;
                var pct = document.createElement('span');
                pct.style.color = color;
                pct.textContent = d.used_pct + '%';
                header.appendChild(mount);
                header.appendChild(pct);

                var meta = document.createElement('div');
                meta.className = 'disk-meta';
                meta.textContent = d.device + ' · ' + d.fstype;

                var barBg = document.createElement('div');
                barBg.className = 'disk-bar-bg';
                var barFill = document.createElement('div');
                barFill.className = 'disk-bar-fill';
                barFill.style.width = d.used_pct + '%';
                barFill.style.background = color;
                barBg.appendChild(barFill);

                var sizes = document.createElement('div');
                sizes.className = 'disk-sizes';
                var usedSpan = document.createElement('span');
                usedSpan.textContent = fmtBytes(used) + ' used';
                var totalSpan = document.createElement('span');
                totalSpan.textContent = fmtBytes(d.size) + ' total';
                sizes.appendChild(usedSpan);
                sizes.appendChild(totalSpan);

                card.appendChild(header);
                card.appendChild(meta);
                card.appendChild(barBg);
                card.appendChild(sizes);
                col.appendChild(card);
                container.appendChild(col);
            });
        }

        function isDark() {
            return document.querySelector('link[href*="dark"]') !== null;
        }

        function chartBaseOpts() {
            var dark = isDark();
            return {
                chart: { type: 'line', height: 220, background: 'transparent', toolbar: { show: true, tools: { download: false } }, zoom: { enabled: true }, animations: { enabled: false } },
                stroke: { width: 1.5, curve: 'straight' },
                xaxis: { type: 'datetime', labels: { style: { colors: dark ? '#999' : '#666', fontSize: '10px' } }, datetimeUTC: false },
                grid: { borderColor: dark ? '#333' : '#e0e0e0', strokeDashArray: 3 },
                legend: { position: 'top', horizontalAlign: 'left', labels: { colors: dark ? '#ccc' : '#333' }, fontSize: '11px' },
                tooltip: { theme: dark ? 'dark' : 'light', x: { format: 'MMM dd HH:mm' } },
            };
        }

        function buildCharts(data, metricOrder) {
            var timestamps = Object.keys(data).map(Number).sort(function(a, b) { return a - b; });

            // Usage chart (CPU, IOwait, Steal, RAM, Swap, Disk)
            var usageMetrics = [
                {idx: 0, name: 'CPU', color: '#1a73e8'},
                {idx: 1, name: 'IOwait', color: '#f9a825'},
                {idx: 2, name: 'Steal', color: '#e53935'},
                {idx: 3, name: 'RAM', color: '#43a047'},
                {idx: 4, name: 'Swap', color: '#00897b'},
                {idx: 5, name: 'Disk', color: '#8e24aa'},
            ];

            var usageSeries = usageMetrics.map(function(m) {
                return {
                    name: m.name,
                    color: m.color,
                    data: timestamps.map(function(t) { return { x: t * 1000, y: data[String(t)][m.idx] }; })
                };
            });

            var usageOpts = Object.assign({}, chartBaseOpts(), {
                series: usageSeries,
                yaxis: { min: 0, max: 100, labels: { formatter: function(v) { return v.toFixed(0) + '%'; }, style: { colors: isDark() ? '#999' : '#666', fontSize: '10px' } } },
            });
            usageOpts.chart.height = 220;

            if (chartUsage) chartUsage.destroy();
            chartUsage = new ApexCharts(document.getElementById('chart-usage'), usageOpts);
            chartUsage.render();

            // Network chart
            var netMetrics = [
                {idx: 6, name: 'Download (RX)', color: '#43a047'},
                {idx: 7, name: 'Upload (TX)', color: '#ff9800'},
            ];

            var netSeries = netMetrics.map(function(m) {
                return {
                    name: m.name,
                    color: m.color,
                    data: timestamps.map(function(t) { var v = data[String(t)][m.idx]; return { x: t * 1000, y: v != null ? v * 8 : null }; })
                };
            });

            var netOpts = Object.assign({}, chartBaseOpts(), {
                series: netSeries,
                yaxis: { min: 0, labels: { formatter: function(v) {
                    if (v >= 1e9) return (v/1e9).toFixed(1) + ' Gbps';
                    if (v >= 1e6) return (v/1e6).toFixed(1) + ' Mbps';
                    if (v >= 1e3) return (v/1e3).toFixed(0) + ' Kbps';
                    return v.toFixed(0) + ' bps';
                }, style: { colors: isDark() ? '#999' : '#666', fontSize: '10px' } } },
            });
            netOpts.chart.height = 220;

            if (chartNetwork) chartNetwork.destroy();
            chartNetwork = new ApexCharts(document.getElementById('chart-network'), netOpts);
            chartNetwork.render();

            // Disk IO chart
            var ioMetrics = [
                {idx: 8, name: 'Read', color: '#43a047'},
                {idx: 9, name: 'Write', color: '#ff9800'},
            ];

            var ioSeries = ioMetrics.map(function(m) {
                return {
                    name: m.name,
                    color: m.color,
                    data: timestamps.map(function(t) { return { x: t * 1000, y: data[String(t)][m.idx] }; })
                };
            });

            var ioOpts = Object.assign({}, chartBaseOpts(), {
                series: ioSeries,
                yaxis: { min: 0, labels: { formatter: function(v) {
                    if (v >= 1073741824) return (v/1073741824).toFixed(1) + ' GB/s';
                    if (v >= 1048576) return (v/1048576).toFixed(1) + ' MB/s';
                    if (v >= 1024) return (v/1024).toFixed(0) + ' KB/s';
                    return v.toFixed(0) + ' B/s';
                }, style: { colors: isDark() ? '#999' : '#666', fontSize: '10px' } } },
            });
            ioOpts.chart.height = 220;

            if (chartDiskIO) chartDiskIO.destroy();
            chartDiskIO = new ApexCharts(document.getElementById('chart-diskio'), ioOpts);
            chartDiskIO.render();
        }

        function fetchDetail() {
            var loading = document.getElementById('prom-loading');
            // Reset to the spinner each poll so a prior error doesn't linger/re-flash
            loading.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading monitoring data...';
            loading.style.display = '';

            fetch('/tools/prometheus/detail/' + encodeURIComponent(hostname) + '/' + currentPeriod + '/' + currentBack, {
                headers: {'Accept': 'application/json'}
            }).then(function(resp) {
                if (!resp.ok) throw new Error(resp.status);
                return resp.json();
            }).then(function(d) {
                loading.style.display = 'none';
                buildStatCards(d.stats);
                buildDiskCards(d.info.disks);
                buildCharts(d.data, d.metric_order);
            }).catch(function() {
                loading.innerHTML = '<span class="text-danger">Failed to load monitoring data</span>';
            });
        }

        // Period buttons
        document.querySelectorAll('.period-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.period-btn').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentPeriod = btn.getAttribute('data-period');
                currentBack = 0;
                document.getElementById('nav-newer').disabled = true;
                fetchDetail();
                resetRefresh();
            });
        });

        // Navigation buttons
        document.getElementById('nav-older').addEventListener('click', function() {
            currentBack++;
            document.getElementById('nav-newer').disabled = false;
            fetchDetail();
            resetRefresh();
        });
        document.getElementById('nav-newer').addEventListener('click', function() {
            if (currentBack > 0) {
                currentBack--;
                if (currentBack === 0) this.disabled = true;
                fetchDetail();
                resetRefresh();
            }
        });

        function resetRefresh() {
            if (refreshTimer) clearInterval(refreshTimer);
            if (currentBack === 0) {
                refreshTimer = setInterval(fetchDetail, 30000);
            }
        }

        fetchDetail();
        refreshTimer = setInterval(fetchDetail, 30000);
    })();
    </script>
    @endif
@endsection
<x-app-layout>
    <div class="container">
        <div class="page-header">
            <div>
                <h2 class="page-title">{{ $server_data->hostname }}</h2>
                <div class="mt-2">
                    @foreach($server_data->labels as $label)
                        <span class="badge bg-primary">{{$label->label?->label}}</span>
                    @endforeach
                    @if($server_data->active !== 1)
                        <span class="badge bg-danger">Not Active</span>
                    @endif
                </div>
            </div>
            <div class="page-actions">
                <a href="{{ route('servers.index') }}" class="btn btn-outline-secondary">Back to servers</a>
                <a href="{{ route('servers.edit', $server_data->id) }}" class="btn btn-primary">Edit</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <div class="detail-card">
            <!-- Server & Specs Section -->
            <div class="detail-section">
                <div class="detail-grid">
                    <div>
                        <div class="detail-section-header">
                            <h6 class="detail-section-title">Server Details</h6>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Type</span>
                                    <span class="detail-value">{{ $server_data->serviceServerType($server_data->server_type, false) }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">OS</span>
                                    <span class="detail-value">{{ $server_data->os->name ?? '-' }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Location</span>
                                    <span class="detail-value">{{ $server_data->location->name ?? '-' }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Provider</span>
                                    <span class="detail-value">{{ $server_data->provider->name ?? '-' }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Price</span>
                                    <span class="detail-value">{{ $server_data->price->price }} {{ $server_data->price->currency }} <span class="text-muted">{{ \App\Process::paymentTermIntToString($server_data->price->term) }}</span></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Next Due</span>
                                    <span class="detail-value">{{ $server_data->price->next_due_date ? Carbon\Carbon::parse($server_data->price->next_due_date)->diffForHumans() : '-' }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Was Promo</span>
                                    <span class="detail-value">{{ ($server_data->was_promo === 1) ? 'Yes' : 'No' }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Transferrable</span>
                                    <span class="detail-value">{{ is_null($server_data->transferrable) ? '-' : (($server_data->transferrable === 1) ? 'Yes' : 'No') }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Owned Since</span>
                                    <span class="detail-value">{{ $server_data->owned_since !== null ? date_format(new DateTime($server_data->owned_since), 'jS M Y') : '-' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="detail-section-header">
                            <h6 class="detail-section-title">Specifications</h6>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">CPU</span>
                                    <span class="detail-value">{{ $server_data->cpu }} @if($server_data->has_yabs)<span class="text-muted">@ {{ $server_data->yabs[0]->cpu_freq }} MHz</span>@endif</span>
                                </div>
                            </div>
                            @if($server_data->cpu_model)
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">CPU Model</span>
                                    <span class="detail-value">{{ $server_data->cpu_model }}</span>
                                </div>
                            </div>
                            @endif
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">RAM</span>
                                    <span class="detail-value">@if(isset($server_data->yabs[0]->ram)){{ $server_data->yabs[0]->ram }} {{ $server_data->yabs[0]->ram_type }}@else{{ $server_data->ram }} {{ $server_data->ram_type }}@endif</span>
                                </div>
                            </div>
                            @if($server_data->disks->count() > 0)
                                @foreach($server_data->disks as $d)
                                <div class="col-6">
                                    <div class="detail-item">
                                        <span class="detail-label">Disk ({{ $d->disk_media }})</span>
                                        <span class="detail-value">{{ $d->disk_size }} {{ $d->disk_unit }}</span>
                                    </div>
                                </div>
                                @endforeach
                            @else
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Disk</span>
                                    <span class="detail-value">@if(isset($server_data->yabs[0]->disk)){{ $server_data->yabs[0]->disk }} {{ $server_data->yabs[0]->disk_type }}@else{{ $server_data->disk }} {{ $server_data->disk_type }}@endif</span>
                                </div>
                            </div>
                            @endif
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Bandwidth</span>
                                    <span class="detail-value">@if($server_data->bandwidth == 0) Unlimited @else {{ $server_data->bandwidth }} GB @endif</span>
                                </div>
                            </div>
                            @if($server_data->link_speed)
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">Link Speed</span>
                                    <span class="detail-value">@if($server_data->link_speed >= 1000){{ rtrim(rtrim(number_format($server_data->link_speed / 1000, 1), '0'), '.') }} Gbps @else {{ $server_data->link_speed }} Mbps @endif</span>
                                </div>
                            </div>
                            @endif
                            @foreach($server_data->ips as $ip)
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">{{ $ip['is_ipv4'] ? 'IPv4' : 'IPv6' }}</span>
                                    <span class="detail-value"><code>{{ $ip['address'] }}</code></span>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            @if($server_data->has_yabs)
            <!-- YABS Section -->
            <div class="detail-section">
                <div class="detail-grid">
                    <div>
                        <div class="detail-section-header">
                            <h6 class="detail-section-title">YABS Benchmark</h6>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">GB6 Single</span>
                                    <span class="detail-value">{{ $server_data->yabs[0]->gb6_single ?? '-' }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">GB6 Multi</span>
                                    <span class="detail-value">{{ $server_data->yabs[0]->gb6_multi ?? '-' }}</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="detail-item">
                                    <span class="detail-label">CPU Model</span>
                                    <span class="detail-value">{{ $server_data->yabs[0]->cpu_model }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">AES</span>
                                    <span class="detail-value">{{ ($server_data->yabs[0]->aes === 1) ? 'Yes' : 'No' }}</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">VM</span>
                                    <span class="detail-value">{{ ($server_data->yabs[0]->vm === 1) ? 'Yes' : 'No' }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="detail-section-header">
                            <h6 class="detail-section-title">Disk Speed</h6>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">4K</span>
                                    <span class="detail-value">{{ $server_data->yabs[0]->disk_speed->d_4k ?? '—' }} <span class="text-muted">{{ $server_data->yabs[0]->disk_speed->d_4k_type ?? '' }}</span></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">64K</span>
                                    <span class="detail-value">{{ $server_data->yabs[0]->disk_speed->d_64k ?? '—' }} <span class="text-muted">{{ $server_data->yabs[0]->disk_speed->d_64k_type ?? '' }}</span></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">512K</span>
                                    <span class="detail-value">{{ $server_data->yabs[0]->disk_speed->d_512k ?? '—' }} <span class="text-muted">{{ $server_data->yabs[0]->disk_speed->d_512k_type ?? '' }}</span></span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="detail-item">
                                    <span class="detail-label">1M</span>
                                    <span class="detail-value">{{ $server_data->yabs[0]->disk_speed->d_1m ?? '—' }} <span class="text-muted">{{ $server_data->yabs[0]->disk_speed->d_1m_type ?? '' }}</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Network Speed Section -->
            <div class="detail-section">
                <div class="detail-section-header">
                    <h6 class="detail-section-title">Network Speed</h6>
                </div>
                <table class="network-table">
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Send</th>
                            <th>Receive</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($server_data->yabs[0]->network_speed as $ns)
                        <tr>
                            <td>{{ $ns->location }}</td>
                            <td>{{ $ns->send }} <span class="text-muted">{{ $ns->send_type }}</span></td>
                            <td>{{ $ns->receive }} <span class="text-muted">{{ $ns->receive_type }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <!-- Add YABS Section -->
            <div class="detail-section">
                <div class="detail-section-header">
                    <h6 class="detail-section-title">YABS Benchmark</h6>
                </div>
                <p class="mb-3">Run this command on your server to add YABS benchmark data:</p>
                <div class="yabs-command">
                    <code>curl -sL yabs.sh | bash -s -- -s "{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $server_data->id]) }}"</code>
                </div>
            </div>
            @endif

            @if(session('prometheus_enabled') && session('prometheus_url'))
            <!-- Prometheus Monitoring Section -->
            <div class="detail-section" id="prom-section">
                <div class="detail-section-header d-flex justify-content-between align-items-center">
                    <h6 class="detail-section-title mb-0">Live Monitoring</h6>
                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group btn-group-sm" id="period-buttons">
                            @foreach(['6h','12h','24h','3d','7d','14d','28d','3m','6m','1y'] as $p)
                            <button type="button" class="btn btn-outline-secondary period-btn {{ $p === '24h' ? 'active' : '' }}" data-period="{{ $p }}">{{ $p }}</button>
                            @endforeach
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" id="nav-older" title="Older">&larr;</button>
                            <button type="button" class="btn btn-outline-secondary" id="nav-newer" title="Newer" disabled>&rarr;</button>
                        </div>
                    </div>
                </div>

                <!-- Stat Cards -->
                <div class="row g-2 mt-2" id="stat-cards"></div>

                <!-- Disk Cards -->
                <div id="disk-cards-container" class="mt-3" style="display:none;">
                    <h6 class="text-muted mb-2" style="font-size:0.85rem;">Disks</h6>
                    <div class="row g-2" id="disk-cards"></div>
                </div>

                <!-- Charts -->
                <div class="mt-3">
                    <div id="chart-usage" style="min-height:220px;"></div>
                </div>
                <div class="mt-3">
                    <div id="chart-network" style="min-height:220px;"></div>
                </div>
                <div class="mt-3">
                    <div id="chart-diskio" style="min-height:220px;"></div>
                </div>

                <div class="text-center text-muted mt-2" id="prom-loading">
                    <i class="fas fa-spinner fa-spin"></i> Loading monitoring data...
                </div>
            </div>
            @endif

            @if(isset($server_data->note))
            <!-- Note Section -->
            <div class="detail-section">
                <div class="detail-section-header">
                    <h6 class="detail-section-title">Note</h6>
                </div>
                <div class="detail-note">{{ $server_data->note->note }}</div>
            </div>
            @endif

            <!-- Footer -->
            <div class="detail-footer">
                ID: <code>{{ $server_data->id }}</code> &middot;
                Created: {{ $server_data->created_at !== null ? date_format(new DateTime($server_data->created_at), 'jS M Y, g:i a') : '-' }} &middot;
                Updated: {{ $server_data->updated_at !== null ? date_format(new DateTime($server_data->updated_at), 'jS M Y, g:i a') : '-' }}
            </div>
        </div>

        @if($server_data->has_yabs)
        <div class="mt-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="showYabsCode()">Show YABS command</button>
            <div id="yabs_code" class="d-none mt-2 yabs-command">
                <code>curl -sL yabs.sh | bash -s -- -s "{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('api.store-yabs', now()->addHours(12), ['server' => $server_data->id]) }}"</code>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
