@section("title", "Add a server")
<x-app-layout>
    <div class="container" id="app">
        <div class="page-header">
            <h2 class="page-title">Add Server</h2>
            <div class="page-actions">
                <a href="{{ route('servers.index') }}" class="btn btn-outline-secondary">Back to servers</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <form action="{{ route('servers.store') }}" method="POST">
            @csrf

            <!-- Basic Information -->
            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Basic Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <label class="form-label">Hostname</label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="hostname" id="hostname"
                                       value="{{ old('hostname') }}" placeholder="server.example.com">
                                <button type="button" class="btn btn-outline-secondary" @click="fetchDnsRecords" title="Auto fill IPs from DNS">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                            @error('hostname') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Server Type</label>
                            <select class="form-select" name="server_type">
                                <option value="1" {{ old('server_type', 1) == 1 ? 'selected' : '' }}>KVM</option>
                                <option value="2" {{ old('server_type') == 2 ? 'selected' : '' }}>OVZ</option>
                                <option value="3" {{ old('server_type') == 3 ? 'selected' : '' }}>DEDI</option>
                                <option value="4" {{ old('server_type') == 4 ? 'selected' : '' }}>LXC</option>
                                <option value="5" {{ old('server_type') == 5 ? 'selected' : '' }}>SEMI-DEDI</option>
                                <option value="6" {{ old('server_type') == 6 ? 'selected' : '' }}>VMware</option>
                                <option value="7" {{ old('server_type') == 7 ? 'selected' : '' }}>NAT</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Operating System</label>
                            <select class="form-select" name="os_id">
                                @foreach (App\Models\OS::all() as $os)
                                    <option value="{{ $os->id }}" {{ old('os_id', Session::get('default_server_os')) == $os->id ? 'selected' : '' }}>
                                        {{ $os->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Network -->
            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Network</h5>
                    <span class="text-muted small">Additional IPs can be added after creation</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">IP Address 1</label>
                            <input type="text" class="form-control" name="ip1" minlength="4" maxlength="255"
                                   v-model="ipv4_in" placeholder="IPv4 or IPv6">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">IP Address 2</label>
                            <input type="text" class="form-control" name="ip2" minlength="4" maxlength="255"
                                   v-model="ipv6_in" placeholder="IPv4 or IPv6">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">NS1</label>
                            <input type="text" class="form-control" name="ns1" value="{{ old('ns1') }}" maxlength="255">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">NS2</label>
                            <input type="text" class="form-control" name="ns2" value="{{ old('ns2') }}" maxlength="255">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">SSH Port</label>
                            <input type="number" class="form-control" name="ssh_port" value="{{ old('ssh_port', 22) }}" min="1" max="65535">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Bandwidth (GB)</label>
                            <input type="number" class="form-control" name="bandwidth" id="bandwidth" value="{{ old('bandwidth', 1000) }}" min="0" max="999999">
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="bandwidth_unlimited" onchange="var el=document.getElementById('bandwidth');if(this.checked){el.value=0;el.readOnly=true;}else{el.readOnly=false;el.value=1000;}">
                                <label class="form-check-label small" for="bandwidth_unlimited">Unlimited</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Link Speed</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="link_speed" value="{{ old('link_speed') }}" min="0" step="any">
                                <select class="form-select" name="link_speed_type" style="max-width: 90px;">
                                    <option value="Mbps" {{ old('link_speed_type') == 'Mbps' ? 'selected' : '' }}>Mbps</option>
                                    <option value="Gbps" {{ old('link_speed_type', 'Gbps') == 'Gbps' ? 'selected' : '' }}>Gbps</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Specifications -->
            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Specifications</h5>
                    <span class="text-muted small">YABS output will overwrite these values</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label">CPU Cores</label>
                            <input type="number" class="form-control" name="cpu" value="{{ old('cpu', 2) }}" min="1" max="128">
                        </div>
                        <div class="col-12 col-md-4 col-lg-4">
                            <label class="form-label">CPU Model</label>
                            <input type="text" class="form-control" name="cpu_model" value="{{ old('cpu_model') }}" placeholder="e.g. AMD EPYC 7502">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label">RAM (MB)</label>
                            <input type="number" class="form-control" name="ram" value="{{ old('ram', 2048) }}" min="1" max="999999">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label">RAM Type</label>
                            <select class="form-select" name="ram_type">
                                <option value="MB" {{ old('ram_type', 'MB') == 'MB' ? 'selected' : '' }}>MB</option>
                                <option value="GB" {{ old('ram_type') == 'GB' ? 'selected' : '' }}>GB</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4 col-lg-2">
                            <label class="form-label">Location</label>
                            <select class="form-select" name="location_id">
                                @foreach (App\Models\Locations::all() as $location)
                                    <option value="{{ $location->id }}" {{ old('location_id') == $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Disks</label>
                        <div id="disks-container">
                            <div class="disk-row row g-2 mb-2 align-items-end">
                                <div class="col-3">
                                    <input type="number" class="form-control" name="disk[]" value="{{ old('disk.0', 20) }}" min="0" max="999999" placeholder="Size">
                                </div>
                                <div class="col-3">
                                    <select class="form-select" name="disk_type[]">
                                        <option value="GB" {{ old('disk_type.0', 'GB') == 'GB' ? 'selected' : '' }}>GB</option>
                                        <option value="TB" {{ old('disk_type.0') == 'TB' ? 'selected' : '' }}>TB</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <select class="form-select" name="disk_media[]">
                                        <option value="SSD" {{ old('disk_media.0', 'SSD') == 'SSD' ? 'selected' : '' }}>SSD</option>
                                        <option value="NVMe" {{ old('disk_media.0') == 'NVMe' ? 'selected' : '' }}>NVMe</option>
                                        <option value="HDD" {{ old('disk_media.0') == 'HDD' ? 'selected' : '' }}>HDD</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <button type="button" class="btn btn-sm btn-outline-danger remove-disk" style="display:none" onclick="this.closest('.disk-row').remove();toggleRemoveButtons();">Remove</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addDiskRow()">+ Add Disk</button>
                    </div>
                </div>
            </div>

            <!-- Billing -->
            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Billing</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Provider</label>
                            <select class="form-select" name="provider_id">
                                @foreach (App\Models\Providers::all() as $provider)
                                    <option value="{{ $provider->id }}" {{ old('provider_id') == $provider->id ? 'selected' : '' }}>{{ $provider->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-6 col-lg-2">
                            <label class="form-label">Price</label>
                            <input type="number" class="form-control" name="price" value="{{ old('price', '5.00') }}" min="0" max="99999" step="0.01" required>
                        </div>
                        <div class="col-6 col-md-6 col-lg-2">
                            <label class="form-label">Currency</label>
                            <select class="form-select" name="currency">
                                @foreach (App\Models\Pricing::getCurrencyList() as $currency)
                                    <option value="{{ $currency }}" {{ old('currency', Session::get('default_currency')) == $currency ? 'selected' : '' }}>
                                        {{ $currency }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <label class="form-label">Term</label>
                            <select class="form-select" name="payment_term">
                                <option value="1" {{ old('payment_term', 1) == 1 ? 'selected' : '' }}>Monthly</option>
                                <option value="2" {{ old('payment_term') == 2 ? 'selected' : '' }}>Quarterly</option>
                                <option value="3" {{ old('payment_term') == 3 ? 'selected' : '' }}>Half annual</option>
                                <option value="4" {{ old('payment_term') == 4 ? 'selected' : '' }}>Annual</option>
                                <option value="5" {{ old('payment_term') == 5 ? 'selected' : '' }}>Biennial</option>
                                <option value="6" {{ old('payment_term') == 6 ? 'selected' : '' }}>Triennial</option>
                                <option value="7" {{ old('payment_term') == 7 ? 'selected' : '' }}>One time</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Promo Price</label>
                            <select class="form-select" name="was_promo">
                                <option value="0" {{ old('was_promo', 0) == 0 ? 'selected' : '' }}>No</option>
                                <option value="1" {{ old('was_promo') == 1 ? 'selected' : '' }}>Yes</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Owned Since</label>
                            <input type="date" class="form-control" name="owned_since" value="{{ old('owned_since', Carbon\Carbon::now()->format('Y-m-d')) }}">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Next Due Date</label>
                            <input type="date" class="form-control" name="next_due_date" value="{{ old('next_due_date', Carbon\Carbon::now()->addMonth()->format('Y-m-d')) }}">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Labels -->
            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Labels</h5>
                    <span class="text-muted small">Optional</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        @php $labels = App\Models\Labels::all(); @endphp
                        @for ($i = 1; $i <= 4; $i++)
                        <div class="col-6 col-md-3">
                            <label class="form-label">Label {{ $i }}</label>
                            <select class="form-select" name="label{{ $i }}">
                                <option value="">None</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}" {{ old("label{$i}") == $label->id ? 'selected' : '' }}>{{ $label->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endfor
                    </div>
                </div>
            </div>

            <!-- Options & Submit -->
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="show_public" id="show_public" value="1" {{ old('show_public') ? 'checked' : '' }}>
                <label class="form-check-label" for="show_public">
                    Allow this server to be shown publicly (configure visible fields in settings)
                </label>
            </div>
            <button type="submit" class="btn btn-primary mb-4">Add Server</button>
        </form>
    </div>

    @section('scripts')
    <script>
        function addDiskRow() {
            var container = document.getElementById('disks-container');
            var row = container.querySelector('.disk-row').cloneNode(true);
            row.querySelector('input[name="disk[]"]').value = '20';
            row.querySelector('select[name="disk_type[]"]').value = 'GB';
            row.querySelector('select[name="disk_media[]"]').value = 'SSD';
            row.querySelector('.remove-disk').style.display = '';
            container.appendChild(row);
            toggleRemoveButtons();
        }
        function toggleRemoveButtons() {
            var rows = document.querySelectorAll('#disks-container .disk-row');
            rows.forEach(function(row, i) {
                var btn = row.querySelector('.remove-disk');
                btn.style.display = rows.length > 1 ? '' : 'none';
            });
        }

        window.addEventListener('load', function () {
            axios.defaults.headers.common = {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            };

            let app = new Vue({
                el: "#app",
                data: {
                    ipv4_in: '{{ old("ip1") }}',
                    ipv6_in: '{{ old("ip2") }}'
                },
                methods: {
                    fetchDnsRecords(event) {
                        var hostname = document.getElementById('hostname').value;
                        if (hostname) {
                            axios
                                .get('/api/dns/' + hostname + '/A', {
                                    headers: {'Authorization': 'Bearer ' + document.querySelector('meta[name="api_token"]').getAttribute('content')}
                                })
                                .then(response => (this.ipv4_in = response.data.ip))
                                .catch(error => {});
                            axios
                                .get('/api/dns/' + hostname + '/AAAA', {
                                    headers: {'Authorization': 'Bearer ' + document.querySelector('meta[name="api_token"]').getAttribute('content')}
                                })
                                .then(response => (this.ipv6_in = response.data.ip))
                                .catch(error => {});
                        }
                    }
                }
            });
        });
    </script>
    @endsection
</x-app-layout>
