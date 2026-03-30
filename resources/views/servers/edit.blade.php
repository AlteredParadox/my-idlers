@section("title", "{$server_data->hostname} edit")
<x-app-layout>
    <div class="container">
        <div class="page-header">
            <h2 class="page-title">Edit {{ $server_data->hostname }}</h2>
            <div class="page-actions">
                <a href="{{ route('servers.index') }}" class="btn btn-outline-secondary">Back to servers</a>
            </div>
        </div>

        <x-response-alerts></x-response-alerts>

        <form action="{{ route('servers.update', $server_data->id) }}" method="POST">
            @csrf
            @method('PUT')

            <!-- Basic Information -->
            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Basic Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <label class="form-label">Hostname</label>
                            <input type="text" class="form-control" name="hostname" value="{{ $server_data->hostname }}">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Server Type</label>
                            <select class="form-select" name="server_type">
                                <option value="1" {{ $server_data->server_type === 1 ? 'selected' : '' }}>KVM</option>
                                <option value="2" {{ $server_data->server_type === 2 ? 'selected' : '' }}>OVZ</option>
                                <option value="3" {{ $server_data->server_type === 3 ? 'selected' : '' }}>DEDI</option>
                                <option value="4" {{ $server_data->server_type === 4 ? 'selected' : '' }}>LXC</option>
                                <option value="5" {{ $server_data->server_type === 5 ? 'selected' : '' }}>SEMI-DEDI</option>
                                <option value="6" {{ $server_data->server_type === 6 ? 'selected' : '' }}>VMware</option>
                                <option value="7" {{ $server_data->server_type === 7 ? 'selected' : '' }}>NAT</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Operating System</label>
                            <select class="form-select" name="os_id">
                                @foreach (App\Models\OS::all() as $os)
                                    <option value="{{ $os->id }}" {{ $server_data->os_id == $os->id ? 'selected' : '' }}>{{ $os->name }}</option>
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
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">NS1</label>
                            <input type="text" class="form-control" name="ns1" value="{{ $server_data->ns1 }}" maxlength="255">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">NS2</label>
                            <input type="text" class="form-control" name="ns2" value="{{ $server_data->ns2 }}" maxlength="255">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">SSH Port</label>
                            <input type="number" class="form-control" name="ssh_port" value="{{ $server_data->ssh }}" min="1" max="65535">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Bandwidth (GB)</label>
                            <input type="number" class="form-control" name="bandwidth" id="bandwidth" value="{{ $server_data->bandwidth }}" min="0" max="999999" {{ $server_data->bandwidth == 0 ? 'readonly' : '' }}>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="bandwidth_unlimited" {{ $server_data->bandwidth == 0 ? 'checked' : '' }} onchange="var el=document.getElementById('bandwidth');if(this.checked){el.value=0;el.readOnly=true;}else{el.readOnly=false;el.value=1000;}">
                                <label class="form-check-label small" for="bandwidth_unlimited">Unlimited</label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Link Speed</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="link_speed" value="{{ $server_data->link_speed ? ($server_data->link_speed >= 1000 ? $server_data->link_speed / 1000 : $server_data->link_speed) : '' }}" min="0" step="any">
                                <select class="form-select" name="link_speed_type" style="max-width: 90px;">
                                    <option value="Mbps" {{ $server_data->link_speed && $server_data->link_speed < 1000 ? 'selected' : '' }}>Mbps</option>
                                    <option value="Gbps" {{ !$server_data->link_speed || $server_data->link_speed >= 1000 ? 'selected' : '' }}>Gbps</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Network Type</label>
                            <select class="form-select" name="network_type">
                                <option value="">None</option>
                                <option value="IPv4" {{ $server_data->network_type == 'IPv4' ? 'selected' : '' }}>IPv4</option>
                                <option value="IPv6" {{ $server_data->network_type == 'IPv6' ? 'selected' : '' }}>IPv6</option>
                                <option value="IPv4+IPv6" {{ $server_data->network_type == 'IPv4+IPv6' ? 'selected' : '' }}>IPv4+IPv6</option>
                                <option value="IPv4 NAT" {{ $server_data->network_type == 'IPv4 NAT' ? 'selected' : '' }}>IPv4 NAT</option>
                                <option value="IPv4 NAT + IPv6" {{ $server_data->network_type == 'IPv4 NAT + IPv6' ? 'selected' : '' }}>IPv4 NAT + IPv6</option>
                            </select>
                        </div>
                    </div>
                    @if(count($server_data->ips) > 0)
                    <div class="row g-3 mt-1">
                        @foreach($server_data->ips as $ip)
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">IP {{ $loop->iteration }}</label>
                            <input type="text" class="form-control" name="ip{{ $loop->iteration }}" value="{{ $ip['address'] }}" maxlength="255">
                        </div>
                        @endforeach
                    </div>
                    @endif
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
                            <input type="number" class="form-control" name="cpu" value="{{ $server_data->cpu }}" min="1" max="128">
                        </div>
                        <div class="col-12 col-md-4 col-lg-4">
                            <label class="form-label">CPU Model</label>
                            <input type="text" class="form-control" name="cpu_model" value="{{ $server_data->cpu_model }}" placeholder="e.g. AMD EPYC 7502">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label">RAM</label>
                            <input type="number" class="form-control" name="ram" value="{{ $server_data->ram }}" min="1" max="999999">
                        </div>
                        <div class="col-6 col-md-4 col-lg-2">
                            <label class="form-label">RAM Type</label>
                            <select class="form-select" name="ram_type">
                                <option value="MB" {{ $server_data->ram_type === 'MB' ? 'selected' : '' }}>MB</option>
                                <option value="GB" {{ $server_data->ram_type === 'GB' ? 'selected' : '' }}>GB</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4 col-lg-2">
                            <label class="form-label">Location</label>
                            <select class="form-select" name="location_id">
                                @foreach (App\Models\Locations::all() as $location)
                                    <option value="{{ $location->id }}" {{ $server_data->location_id == $location->id ? 'selected' : '' }}>{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Disks</label>
                        <div id="disks-container">
                            @if($server_data->disks->count() > 0)
                                @foreach($server_data->disks as $d)
                                <div class="disk-row row g-2 mb-2 align-items-end">
                                    <div class="col-3">
                                        <input type="number" class="form-control" name="disk[]" value="{{ $d->disk_size }}" min="0" max="999999" placeholder="Size">
                                    </div>
                                    <div class="col-3">
                                        <select class="form-select" name="disk_type[]">
                                            <option value="GB" {{ $d->disk_unit === 'GB' ? 'selected' : '' }}>GB</option>
                                            <option value="TB" {{ $d->disk_unit === 'TB' ? 'selected' : '' }}>TB</option>
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <select class="form-select" name="disk_media[]">
                                            <option value="SSD" {{ $d->disk_media === 'SSD' ? 'selected' : '' }}>SSD</option>
                                            <option value="NVMe" {{ $d->disk_media === 'NVMe' ? 'selected' : '' }}>NVMe</option>
                                            <option value="HDD" {{ $d->disk_media === 'HDD' ? 'selected' : '' }}>HDD</option>
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-disk" style="{{ $server_data->disks->count() <= 1 ? 'display:none' : '' }}" onclick="this.closest('.disk-row').remove();toggleRemoveButtons();">Remove</button>
                                    </div>
                                </div>
                                @endforeach
                            @else
                                <div class="disk-row row g-2 mb-2 align-items-end">
                                    <div class="col-3">
                                        <input type="number" class="form-control" name="disk[]" value="{{ $server_data->disk }}" min="0" max="999999" placeholder="Size">
                                    </div>
                                    <div class="col-3">
                                        <select class="form-select" name="disk_type[]">
                                            <option value="GB" {{ $server_data->disk_type === 'GB' ? 'selected' : '' }}>GB</option>
                                            <option value="TB" {{ $server_data->disk_type === 'TB' ? 'selected' : '' }}>TB</option>
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <select class="form-select" name="disk_media[]">
                                            <option value="SSD" selected>SSD</option>
                                            <option value="NVMe">NVMe</option>
                                            <option value="HDD">HDD</option>
                                        </select>
                                    </div>
                                    <div class="col-3">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-disk" style="display:none" onclick="this.closest('.disk-row').remove();toggleRemoveButtons();">Remove</button>
                                    </div>
                                </div>
                            @endif
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
                                    <option value="{{ $provider->id }}" {{ $server_data->provider_id == $provider->id ? 'selected' : '' }}>{{ $provider->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-6 col-lg-2">
                            <label class="form-label">Price</label>
                            <input type="number" class="form-control" name="price" value="{{ $server_data->price->price }}" min="0" max="99999" step="0.01" required>
                        </div>
                        <div class="col-6 col-md-6 col-lg-2">
                            <label class="form-label">Currency</label>
                            <select class="form-select" name="currency">
                                @foreach (App\Models\Pricing::getCurrencyList() as $currency)
                                    <option value="{{ $currency }}" {{ $server_data->price->currency == $currency ? 'selected' : '' }}>{{ $currency }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <label class="form-label">Term</label>
                            <select class="form-select" name="payment_term">
                                <option value="1" {{ $server_data->price->term == 1 ? 'selected' : '' }}>Monthly</option>
                                <option value="2" {{ $server_data->price->term == 2 ? 'selected' : '' }}>Quarterly</option>
                                <option value="3" {{ $server_data->price->term == 3 ? 'selected' : '' }}>Half annual</option>
                                <option value="4" {{ $server_data->price->term == 4 ? 'selected' : '' }}>Annual</option>
                                <option value="5" {{ $server_data->price->term == 5 ? 'selected' : '' }}>Biennial</option>
                                <option value="6" {{ $server_data->price->term == 6 ? 'selected' : '' }}>Triennial</option>
                                <option value="7" {{ $server_data->price->term == 7 ? 'selected' : '' }}>One time</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Promo Price</label>
                            <select class="form-select" name="was_promo">
                                <option value="0" {{ $server_data->was_promo == 0 ? 'selected' : '' }}>No</option>
                                <option value="1" {{ $server_data->was_promo == 1 ? 'selected' : '' }}>Yes</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Owned Since</label>
                            <input type="date" class="form-control" name="owned_since" value="{{ $server_data->owned_since }}">
                        </div>
                        <div class="col-12 col-md-6 col-lg-3">
                            <label class="form-label">Next Due Date</label>
                            <input type="date" class="form-control" name="next_due_date" value="{{ $server_data->price->next_due_date ?? Carbon\Carbon::now()->addMonth()->format('Y-m-d') }}">
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
                        <div class="col-6 col-md-3">
                            <label class="form-label">Label 1</label>
                            <select class="form-select" name="label1">
                                <option value="">None</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}" {{ isset($server_data->labels[0]->label->id) && $server_data->labels[0]->label->id == $label->id ? 'selected' : '' }}>{{ $label->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Label 2</label>
                            <select class="form-select" name="label2">
                                <option value="">None</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}" {{ isset($server_data->labels[1]->label->id) && $server_data->labels[1]->label->id == $label->id ? 'selected' : '' }}>{{ $label->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Label 3</label>
                            <select class="form-select" name="label3">
                                <option value="">None</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}" {{ isset($server_data->labels[2]->label->id) && $server_data->labels[2]->label->id == $label->id ? 'selected' : '' }}>{{ $label->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Label 4</label>
                            <select class="form-select" name="label4">
                                <option value="">None</option>
                                @foreach ($labels as $label)
                                    <option value="{{ $label->id }}" {{ isset($server_data->labels[3]->label->id) && $server_data->labels[3]->label->id == $label->id ? 'selected' : '' }}>{{ $label->label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Options & Submit -->
            <div class="row mb-3">
                <div class="col-12 col-lg-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1" {{ $server_data->active === 1 ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">I still have this server</label>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_public" id="show_public" value="1" {{ $server_data->show_public === 1 ? 'checked' : '' }}>
                        <label class="form-check-label" for="show_public">Allow some of this data to be public</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mb-4">Update Server</button>
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
            rows.forEach(function(row) {
                var btn = row.querySelector('.remove-disk');
                btn.style.display = rows.length > 1 ? '' : 'none';
            });
        }
    </script>
    @endsection
</x-app-layout>
