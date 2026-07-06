@section("title", "Compare servers")
@section('style')
    <style>
        .compare-table th, .compare-table td {
            white-space: nowrap;
            vertical-align: middle;
        }
        .compare-table th:first-child, .compare-table td:first-child {
            position: sticky;
            left: 0;
            z-index: 1;
        }
        .plus-td { background: rgba(40, 167, 69, 0.15) !important; }
        .neg-td { background: rgba(220, 53, 69, 0.15) !important; }
        .equal-td { background: rgba(108, 117, 125, 0.1) !important; }
        .data-type { color: var(--text-muted); font-size: 0.85em; margin-left: 2px; }
    </style>
@endsection
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">Server Comparison</h2>
            <div class="page-actions">
                <a href="{{ route('servers-compare-choose') }}" class="btn btn-outline-secondary">Choose Others</a>
            </div>
        </div>

        <div class="card content-card">
            <div class="card-header card-section-header">
                <h5 class="card-section-title mb-0">
                    {{ $server1_data[0]->hostname }} vs {{ $server2_data[0]->hostname }}
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table data-table compare-table mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Metric</th>
                                <th class="text-center">{{ $server1_data[0]->hostname }}</th>
                                <th class="text-center">Difference</th>
                                <th class="text-center">{{ $server2_data[0]->hostname }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="ps-3 fw-medium">CPU Cores</td>
                                <td class="text-center">{{ $server1_data[0]->yabs[0]->cpu_cores }}</td>
                                {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->cpu_cores, $server2_data[0]->yabs[0]->cpu_cores, ' cores') !!}
                                <td class="text-center">{{ $server2_data[0]->yabs[0]->cpu_cores }}</td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">CPU Frequency</td>
                                <td class="text-center">{{ $server1_data[0]->yabs[0]->cpu_freq }}<span class="data-type">MHz</span></td>
                                {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->cpu_freq, $server2_data[0]->yabs[0]->cpu_freq, 'MHz') !!}
                                <td class="text-center">{{ $server2_data[0]->yabs[0]->cpu_freq }}<span class="data-type">MHz</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">RAM</td>
                                <td class="text-center">{{ $server1_data[0]->yabs[0]->ram_mb }}<span class="data-type">MB</span></td>
                                {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->ram_mb, $server2_data[0]->yabs[0]->ram_mb, 'MB') !!}
                                <td class="text-center">{{ $server2_data[0]->yabs[0]->ram_mb }}<span class="data-type">MB</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">Disk</td>
                                <td class="text-center">{{ $server1_data[0]->yabs[0]->disk_gb }}<span class="data-type">GB</span></td>
                                {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->disk_gb, $server2_data[0]->yabs[0]->disk_gb, 'GB') !!}
                                <td class="text-center">{{ $server2_data[0]->yabs[0]->disk_gb }}<span class="data-type">GB</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">GB5 Single</td>
                                @php
                                    // version-paired: the displayed values, the diff, and the
                                    // per-USD rows below must all come from the SAME GB version
                                    if ($server1_data[0]->yabs[0]->gb5_single !== null && $server2_data[0]->yabs[0]->gb5_single !== null) {
                                        $cmp1_single = $server1_data[0]->yabs[0]->gb5_single; $cmp2_single = $server2_data[0]->yabs[0]->gb5_single; $cmpv6_single = false;
                                    } elseif ($server1_data[0]->yabs[0]->gb6_single !== null && $server2_data[0]->yabs[0]->gb6_single !== null) {
                                        $cmp1_single = $server1_data[0]->yabs[0]->gb6_single; $cmp2_single = $server2_data[0]->yabs[0]->gb6_single; $cmpv6_single = true;
                                    } else {
                                        $cmp1_single = $cmp2_single = null; $cmpv6_single = false;
                                    }
                                    $v6tag_single = $cmpv6_single ? ' (v6)' : '';
                                @endphp
                                @if($cmp1_single !== null && $cmp2_single !== null)
                                    <td class="text-center">{{ $cmp1_single }}{{ $v6tag_single }}</td>
                                    {!! \App\Process::tableRowCompare($cmp1_single, $cmp2_single, '') !!}
                                    <td class="text-center">{{ $cmp2_single }}{{ $v6tag_single }}</td>
                                @else
                                    <td class="text-center">{{ $server1_data[0]->yabs[0]->gb5_single ?? ($server1_data[0]->yabs[0]->gb6_single !== null ? $server1_data[0]->yabs[0]->gb6_single . ' (v6)' : '—') }}</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">{{ $server2_data[0]->yabs[0]->gb5_single ?? ($server2_data[0]->yabs[0]->gb6_single !== null ? $server2_data[0]->yabs[0]->gb6_single . ' (v6)' : '—') }}</td>
                                @endif
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">GB5 Multi</td>
                                @php
                                    // version-paired: the displayed values, the diff, and the
                                    // per-USD rows below must all come from the SAME GB version
                                    if ($server1_data[0]->yabs[0]->gb5_multi !== null && $server2_data[0]->yabs[0]->gb5_multi !== null) {
                                        $cmp1_multi = $server1_data[0]->yabs[0]->gb5_multi; $cmp2_multi = $server2_data[0]->yabs[0]->gb5_multi; $cmpv6_multi = false;
                                    } elseif ($server1_data[0]->yabs[0]->gb6_multi !== null && $server2_data[0]->yabs[0]->gb6_multi !== null) {
                                        $cmp1_multi = $server1_data[0]->yabs[0]->gb6_multi; $cmp2_multi = $server2_data[0]->yabs[0]->gb6_multi; $cmpv6_multi = true;
                                    } else {
                                        $cmp1_multi = $cmp2_multi = null; $cmpv6_multi = false;
                                    }
                                    $v6tag_multi = $cmpv6_multi ? ' (v6)' : '';
                                @endphp
                                @if($cmp1_multi !== null && $cmp2_multi !== null)
                                    <td class="text-center">{{ $cmp1_multi }}{{ $v6tag_multi }}</td>
                                    {!! \App\Process::tableRowCompare($cmp1_multi, $cmp2_multi, '') !!}
                                    <td class="text-center">{{ $cmp2_multi }}{{ $v6tag_multi }}</td>
                                @else
                                    <td class="text-center">{{ $server1_data[0]->yabs[0]->gb5_multi ?? ($server1_data[0]->yabs[0]->gb6_multi !== null ? $server1_data[0]->yabs[0]->gb6_multi . ' (v6)' : '—') }}</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">{{ $server2_data[0]->yabs[0]->gb5_multi ?? ($server2_data[0]->yabs[0]->gb6_multi !== null ? $server2_data[0]->yabs[0]->gb6_multi . ' (v6)' : '—') }}</td>
                                @endif
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">4K Disk Speed</td>
                                @if($server1_data[0]->yabs[0]->disk_speed !== null && $server2_data[0]->yabs[0]->disk_speed !== null)
                                    <td class="text-center">{{ $server1_data[0]->yabs[0]->disk_speed->d_4k_as_mbps }}<span class="data-type">MB/s</span></td>
                                    {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->disk_speed->d_4k_as_mbps, $server2_data[0]->yabs[0]->disk_speed->d_4k_as_mbps, 'MB/s') !!}
                                    <td class="text-center">{{ $server2_data[0]->yabs[0]->disk_speed->d_4k_as_mbps }}<span class="data-type">MB/s</span></td>
                                @else
                                    <td class="text-center">—</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">—</td>
                                @endif
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">64K Disk Speed</td>
                                @if($server1_data[0]->yabs[0]->disk_speed !== null && $server2_data[0]->yabs[0]->disk_speed !== null)
                                    <td class="text-center">{{ $server1_data[0]->yabs[0]->disk_speed->d_64k_as_mbps }}<span class="data-type">MB/s</span></td>
                                    {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->disk_speed->d_64k_as_mbps, $server2_data[0]->yabs[0]->disk_speed->d_64k_as_mbps, 'MB/s') !!}
                                    <td class="text-center">{{ $server2_data[0]->yabs[0]->disk_speed->d_64k_as_mbps }}<span class="data-type">MB/s</span></td>
                                @else
                                    <td class="text-center">—</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">—</td>
                                @endif
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">512K Disk Speed</td>
                                @if($server1_data[0]->yabs[0]->disk_speed !== null && $server2_data[0]->yabs[0]->disk_speed !== null)
                                    <td class="text-center">{{ $server1_data[0]->yabs[0]->disk_speed->d_512k_as_mbps }}<span class="data-type">MB/s</span></td>
                                    {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->disk_speed->d_512k_as_mbps, $server2_data[0]->yabs[0]->disk_speed->d_512k_as_mbps, 'MB/s') !!}
                                    <td class="text-center">{{ $server2_data[0]->yabs[0]->disk_speed->d_512k_as_mbps }}<span class="data-type">MB/s</span></td>
                                @else
                                    <td class="text-center">—</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">—</td>
                                @endif
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">1M Disk Speed</td>
                                @if($server1_data[0]->yabs[0]->disk_speed !== null && $server2_data[0]->yabs[0]->disk_speed !== null)
                                    <td class="text-center">{{ $server1_data[0]->yabs[0]->disk_speed->d_1m_as_mbps }}<span class="data-type">MB/s</span></td>
                                    {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->disk_speed->d_1m_as_mbps, $server2_data[0]->yabs[0]->disk_speed->d_1m_as_mbps, 'MB/s') !!}
                                    <td class="text-center">{{ $server2_data[0]->yabs[0]->disk_speed->d_1m_as_mbps }}<span class="data-type">MB/s</span></td>
                                @else
                                    <td class="text-center">—</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">—</td>
                                @endif
                            </tr>
                            @for($i = 0; $i < 5; $i++)
                                @if(isset($server1_data[0]->yabs[0]->network_speed[$i]) && isset($server2_data[0]->yabs[0]->network_speed[$i]) && $server1_data[0]->yabs[0]->network_speed[$i]->location === $server2_data[0]->yabs[0]->network_speed[$i]->location)
                                    <tr>
                                        <td class="ps-3 fw-medium">{{ $server1_data[0]->yabs[0]->network_speed[$i]->location }} Send</td>
                                        <td class="text-center">{{ $server1_data[0]->yabs[0]->network_speed[$i]->send_as_mbps }}<span class="data-type">MB/s</span></td>
                                        {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->network_speed[$i]->send_as_mbps, $server2_data[0]->yabs[0]->network_speed[$i]->send_as_mbps, 'MB/s') !!}
                                        <td class="text-center">{{ $server2_data[0]->yabs[0]->network_speed[$i]->send_as_mbps }}<span class="data-type">MB/s</span></td>
                                    </tr>
                                    <tr>
                                        <td class="ps-3 fw-medium">{{ $server1_data[0]->yabs[0]->network_speed[$i]->location }} Receive</td>
                                        <td class="text-center">{{ $server1_data[0]->yabs[0]->network_speed[$i]->receive_as_mbps }}<span class="data-type">MB/s</span></td>
                                        {!! \App\Process::tableRowCompare($server1_data[0]->yabs[0]->network_speed[$i]->receive_as_mbps, $server2_data[0]->yabs[0]->network_speed[$i]->receive_as_mbps, 'MB/s') !!}
                                        <td class="text-center">{{ $server2_data[0]->yabs[0]->network_speed[$i]->receive_as_mbps }}<span class="data-type">MB/s</span></td>
                                    </tr>
                                @endif
                            @endfor
                            <tr>
                                <td class="ps-3 fw-medium">USD per Month</td>
                                <td class="text-center">{{ $server1_data[0]->price->usd_per_month }}<span class="data-type">/mo</span></td>
                                {!! \App\Process::tableRowCompare($server1_data[0]->price->usd_per_month, $server2_data[0]->price->usd_per_month, '/mo', false) !!}
                                <td class="text-center">{{ $server2_data[0]->price->usd_per_month }}<span class="data-type">/mo</span></td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">Actual Price</td>
                                <td class="text-center">{{ $server1_data[0]->price->price }}<span class="data-type">{{ $server1_data[0]->price->currency }}</span> {{ \App\Process::paymentTermIntToString($server1_data[0]->price->term) }}</td>
                                <td class="text-center equal-td">—</td>
                                <td class="text-center">{{ $server2_data[0]->price->price }}<span class="data-type">{{ $server2_data[0]->price->currency }}</span> {{ \App\Process::paymentTermIntToString($server2_data[0]->price->term) }}</td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">CPU per USD</td>
                                <td class="text-center">{{ number_format(\App\Process::safeDivide($server1_data[0]->yabs[0]->cpu_cores, $server1_data[0]->price->usd_per_month), 2) }}</td>
                                {!! \App\Process::tableRowCompare(\App\Process::safeDivide($server1_data[0]->yabs[0]->cpu_cores, $server1_data[0]->price->usd_per_month), \App\Process::safeDivide($server2_data[0]->yabs[0]->cpu_cores, $server2_data[0]->price->usd_per_month), '', false) !!}
                                <td class="text-center">{{ number_format(\App\Process::safeDivide($server2_data[0]->yabs[0]->cpu_cores, $server2_data[0]->price->usd_per_month), 2) }}</td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">Disk GB per USD</td>
                                <td class="text-center">{{ number_format(\App\Process::safeDivide($server1_data[0]->yabs[0]->disk_gb, $server1_data[0]->price->usd_per_month), 2) }}</td>
                                {!! \App\Process::tableRowCompare(\App\Process::safeDivide($server1_data[0]->yabs[0]->disk_gb, $server1_data[0]->price->usd_per_month), \App\Process::safeDivide($server2_data[0]->yabs[0]->disk_gb, $server2_data[0]->price->usd_per_month), '', false) !!}
                                <td class="text-center">{{ number_format(\App\Process::safeDivide($server2_data[0]->yabs[0]->disk_gb, $server2_data[0]->price->usd_per_month), 2) }}</td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">RAM MB per USD</td>
                                <td class="text-center">{{ number_format(\App\Process::safeDivide($server1_data[0]->yabs[0]->ram_mb, $server1_data[0]->price->usd_per_month), 2) }}</td>
                                {!! \App\Process::tableRowCompare(\App\Process::safeDivide($server1_data[0]->yabs[0]->ram_mb, $server1_data[0]->price->usd_per_month), \App\Process::safeDivide($server2_data[0]->yabs[0]->ram_mb, $server2_data[0]->price->usd_per_month), '', false) !!}
                                <td class="text-center">{{ number_format(\App\Process::safeDivide($server2_data[0]->yabs[0]->ram_mb, $server2_data[0]->price->usd_per_month), 2) }}</td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">GB{{ $cmpv6_single ? '6' : '5' }} Single per USD</td>
                                @if($cmp1_single !== null && $cmp2_single !== null)
                                    <td class="text-center">{{ number_format(\App\Process::safeDivide($cmp1_single, $server1_data[0]->price->usd_per_month), 2) }}</td>
                                    {!! \App\Process::tableRowCompare(\App\Process::safeDivide($cmp1_single, $server1_data[0]->price->usd_per_month), \App\Process::safeDivide($cmp2_single, $server2_data[0]->price->usd_per_month), '', false) !!}
                                    <td class="text-center">{{ number_format(\App\Process::safeDivide($cmp2_single, $server2_data[0]->price->usd_per_month), 2) }}</td>
                                @else
                                    <td class="text-center">—</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">—</td>
                                @endif
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">GB{{ $cmpv6_multi ? '6' : '5' }} Multi per USD</td>
                                @if($cmp1_multi !== null && $cmp2_multi !== null)
                                    <td class="text-center">{{ number_format(\App\Process::safeDivide($cmp1_multi, $server1_data[0]->price->usd_per_month), 2) }}</td>
                                    {!! \App\Process::tableRowCompare(\App\Process::safeDivide($cmp1_multi, $server1_data[0]->price->usd_per_month), \App\Process::safeDivide($cmp2_multi, $server2_data[0]->price->usd_per_month), '', false) !!}
                                    <td class="text-center">{{ number_format(\App\Process::safeDivide($cmp2_multi, $server2_data[0]->price->usd_per_month), 2) }}</td>
                                @else
                                    <td class="text-center">—</td>
                                    <td class="text-center equal-td">—</td>
                                    <td class="text-center">—</td>
                                @endif
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">Location</td>
                                <td class="text-center">{{ $server1_data[0]->location->name }}</td>
                                <td class="text-center equal-td">—</td>
                                <td class="text-center">{{ $server2_data[0]->location->name }}</td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">Provider</td>
                                <td class="text-center">{{ $server1_data[0]->provider->name }}</td>
                                <td class="text-center equal-td">—</td>
                                <td class="text-center">{{ $server2_data[0]->provider->name }}</td>
                            </tr>
                            <tr>
                                <td class="ps-3 fw-medium">Owned Since</td>
                                <td class="text-center">{{ $server1_data[0]->owned_since !== null ? date_format(new DateTime($server1_data[0]->owned_since), 'F Y') : '-' }}</td>
                                @if($server1_data[0]->owned_since !== null && $server2_data[0]->owned_since !== null)
                                    <td class="text-center equal-td">{{ \Carbon\Carbon::parse($server1_data[0]->owned_since)->diffForHumans(\Carbon\Carbon::parse($server2_data[0]->owned_since)) }}</td>
                                @else
                                    <td class="text-center equal-td">—</td>
                                @endif
                                <td class="text-center">{{ $server2_data[0]->owned_since !== null ? date_format(new DateTime($server2_data[0]->owned_since), 'F Y') : '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <x-details-footer></x-details-footer>
    </div>
</x-app-layout>
