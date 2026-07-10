@section("title", "Dashboard")
<x-app-layout>
    <div class="dashboard-container">
        <!-- Stats Overview -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4 col-lg-2">
                <a href="{{ route('servers.index') }}" class="text-decoration-none">
                    <div class="stat-card">
                        <div class="stat-value">{{ $information['servers'] }}</div>
                        <div class="stat-label">Servers</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="{{ route('shared.index') }}" class="text-decoration-none">
                    <div class="stat-card">
                        <div class="stat-value">{{ $information['shared'] }}</div>
                        <div class="stat-label">Shared</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="{{ route('reseller.index') }}" class="text-decoration-none">
                    <div class="stat-card">
                        <div class="stat-value">{{ $information['reseller'] }}</div>
                        <div class="stat-label">Reseller</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="{{ route('domains.index') }}" class="text-decoration-none">
                    <div class="stat-card">
                        <div class="stat-value">{{ $information['domains'] }}</div>
                        <div class="stat-label">Domains</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="{{ route('misc.index') }}" class="text-decoration-none">
                    <div class="stat-card">
                        <div class="stat-value">{{ $information['misc'] }}</div>
                        <div class="stat-label">Misc</div>
                    </div>
                </a>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <a href="{{ route('dns.index') }}" class="text-decoration-none">
                    <div class="stat-card">
                        <div class="stat-value">{{ $information['dns'] }}</div>
                        <div class="stat-label">DNS</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Costs & Resources Row -->
        <div class="row g-3 mb-4">
            <!-- Costs Card -->
            <div class="col-12 col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <h5 class="card-title-custom">Costs</h5>
                        <span class="badge bg-secondary">{{ $information['currency'] }}</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <div class="cost-item">
                                    <div class="cost-value">{{ $information['total_cost_weekly'] }}</div>
                                    <div class="cost-label">Weekly</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="cost-item">
                                    <div class="cost-value">{{ $information['total_cost_monthly'] }}</div>
                                    <div class="cost-label">Monthly</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="cost-item">
                                    <div class="cost-value">{{ $information['total_cost_yearly'] }}</div>
                                    <div class="cost-label">Yearly</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="cost-item">
                                    <div class="cost-value">{{ $information['total_cost_2_yearly'] }}</div>
                                    <div class="cost-label">2 Years</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resources Card -->
            <div class="col-12 col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <h5 class="card-title-custom">Server Resources</h5>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-3">
                            <div class="col-4 col-md-2">
                                <div class="resource-item">
                                    <div class="resource-value">{{ $information['servers_summary']['cpu_sum'] }}</div>
                                    <div class="resource-label">CPU</div>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="resource-item">
                                    <div class="resource-value">{{ number_format($information['servers_summary']['ram_mb_sum'] / 1024, 1) }}</div>
                                    <div class="resource-label">RAM GB</div>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="resource-item">
                                    @if($information['servers_summary']['disk_gb_sum'] >= 1000)
                                        <div class="resource-value">{{ number_format($information['servers_summary']['disk_gb_sum'] / 1024, 1) }}</div>
                                        <div class="resource-label">Disk TB</div>
                                    @else
                                        <div class="resource-value">{{ $information['servers_summary']['disk_gb_sum'] }}</div>
                                        <div class="resource-label">Disk GB</div>
                                    @endif
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="resource-item">
                                    <div class="resource-value">{{ number_format($information['servers_summary']['bandwidth_sum'] / 1024, 1) }}</div>
                                    <div class="resource-label">BW TB</div>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="resource-item">
                                    <div class="resource-value">{{ $information['servers_summary']['locations_sum'] }}</div>
                                    <div class="resource-label">Locations</div>
                                </div>
                            </div>
                            <div class="col-4 col-md-2">
                                <div class="resource-item">
                                    <div class="resource-value">{{ $information['servers_summary']['providers_sum'] }}</div>
                                    <div class="resource-label">Providers</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Due Soon Section -->
        @if((\App\Models\Settings::getSettings()->due_soon_amount ?? 6) > 0 && !empty($information['due_soon']))
        <div class="row mb-4">
            <div class="col-12">
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <h5 class="card-title-custom">Due Soon</h5>
                        <span class="badge bg-warning text-dark">{{ count($information['due_soon']) }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Due</th>
                                    <th>Price</th>
                                    <th class="text-center" style="width: 60px;">View</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($information['due_soon'] as $due_soon)
                                    @include('partials.home-service-row', [
                                        'row' => $due_soon,
                                        'date' => $due_soon->next_due_date ? Carbon\Carbon::parse($due_soon->next_due_date)->diffForHumans() : '-',
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Recently Added Section -->
        @if((\App\Models\Settings::getSettings()->recently_added_amount ?? 6) > 0 && !empty($information['newest']))
        <div class="row mb-4">
            <div class="col-12">
                <div class="dashboard-card">
                    <div class="card-header-custom">
                        <h5 class="card-title-custom">Recently Added</h5>
                        <span class="badge bg-success">{{ count($information['newest']) }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>Added</th>
                                    <th>Price</th>
                                    <th class="text-center" style="width: 60px;">View</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($information['newest'] as $new)
                                    @include('partials.home-service-row', [
                                        'row' => $new,
                                        'date' => Carbon\Carbon::parse($new->created_at)->diffForHumans(),
                                    ])
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif

        <!-- Footer -->
        @if(Session::get('timer_version_footer', 0) === 1)
        <div class="row">
            <div class="col-12">
                <p class="text-muted small text-end mb-4">
                    Page loaded in {{ $information['execution_time'] }}s &middot;
                    Laravel v{{ Illuminate\Foundation\Application::VERSION }} &middot;
                    PHP v{{ PHP_VERSION }} &middot;
                    Rates by <a href="https://www.exchangerate-api.com" class="text-muted">Exchange Rate API</a>
                </p>
            </div>
        </div>
        @endif
    </div>
</x-app-layout>
