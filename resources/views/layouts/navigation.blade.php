<nav class="navbar navbar-expand-xl main-navbar" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="{{ route('/') }}">
            @if (config()->has('app.name')) {{ config('app.name') }} @else My Idlers @endif
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar"
                aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-xl-0">
                @if(Auth::check())
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('servers.index') ? 'active' : '' }}" href="{{ route('servers.index') }}">Servers</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('shared.index') ? 'active' : '' }}" href="{{ route('shared.index') }}">Shared</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('reseller.index') ? 'active' : '' }}" href="{{ route('reseller.index') }}">Reseller</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('seedboxes.index') ? 'active' : '' }}" href="{{ route('seedboxes.index') }}">Seedboxes</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('domains.index') ? 'active' : '' }}" href="{{ route('domains.index') }}">Domains</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('dns.index') ? 'active' : '' }}" href="{{ route('dns.index') }}">DNS</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('IPs.index') ? 'active' : '' }}" href="{{ route('IPs.index') }}">IPs</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('locations.index') ? 'active' : '' }}" href="{{ route('locations.index') }}">Locations</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('os.index') ? 'active' : '' }}" href="{{ route('os.index') }}">OS</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('providers.index') ? 'active' : '' }}" href="{{ route('providers.index') }}">Providers</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('yabs.index') ? 'active' : '' }}" href="{{ route('yabs.index') }}">YABS</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('labels.index') ? 'active' : '' }}" href="{{ route('labels.index') }}">Labels</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('notes.index') ? 'active' : '' }}" href="{{ route('notes.index') }}">Notes</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('misc.index') ? 'active' : '' }}" href="{{ route('misc.index') }}">Misc</a>
                </li>
                @else
                <li class="nav-item">
                    <a class="nav-link" href="https://github.com/cp6/my-idlers">View on GitHub</a>
                </li>
                @endif
            </ul>
            @if(Auth::check())
            <ul class="navbar-nav mb-2 mb-xl-0">
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('settings.index') ? 'active' : '' }}" href="{{ route('settings.index') }}">Settings</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs('account.index') ? 'active' : '' }}" href="{{ route('account.index') }}">Account</a>
                </li>
                <li class="nav-item">
                    <form method="POST" action="{{ route('logout') }}" class="d-flex">
                        @csrf
                        <button type="submit" class="btn btn-link nav-link logout-link">Log Out</button>
                    </form>
                </li>
            </ul>
            @else
            <a href="{{ route('login') }}" class="btn btn-link nav-link">Log in</a>
            @endif
        </div>
    </div>
</nav>
