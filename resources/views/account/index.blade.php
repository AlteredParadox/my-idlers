@section("title", "Edit account")
<x-app-layout>
    <div class="container">
        <div class="page-header">
            <h2 class="page-title">Account Settings</h2>
        </div>

        <x-response-alerts></x-response-alerts>

        <form action="{{ route('account.update', Auth::user()->id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">Profile Information</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" name="name" maxlength="255" 
                                   value="{{ Auth::user()->name }}" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" maxlength="255" 
                                   value="{{ Auth::user()->email }}" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card content-card mb-4">
                <div class="card-header card-section-header">
                    <h5 class="card-section-title mb-0">API Access</h5>
                </div>
                <div class="card-body">
                    @if(session('new_api_token'))
                        <label class="form-label">New API Token</label>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control font-monospace" value="{{ session('new_api_token') }}" readonly>
                            <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(@json(session('new_api_token')))">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    @endif

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" name="rotate_api_token" id="rotate_api_token">
                        <label class="form-check-label" for="rotate_api_token">
                            Generate a new API token
                        </label>
                    </div>
                    <small class="text-muted">Stored API tokens are hashed and can only be viewed immediately after rotation.</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary mb-4">Update Account</button>
        </form>

        <x-details-footer></x-details-footer>
    </div>
</x-app-layout>
