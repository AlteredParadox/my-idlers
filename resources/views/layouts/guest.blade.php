<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @include('partials.theme-attrs')>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title') - @if (config()->has('app.name')) {{ config('app.name') }} @else My idlers @endif</title>
    <link rel="icon" type="image" href="{{asset(\App\Models\Settings::getSettings()->favicon ?? 'favicon.ico')}}"/>

    @vite(['resources/js/app.js'])

    <link rel="preload" href="{{ asset('webfonts/fa-solid-900.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('webfonts/fa-regular-400.woff2') }}" as="font" type="font/woff2" crossorigin>

    @yield('css_links')
    @yield('style')

</head>
<body class="auth-page">
    <div class="auth-wrapper">
        {{ $slot }}
    </div>
    <script>
    (function() {
        if (!document.fonts) return;
        document.fonts.ready.then(function() {
            // Read the family from FontAwesome's own CSS variable rather than
            // hardcoding it. The name carries the major version ("Font Awesome
            // 7 Free"), so a hardcoded one silently stops matching on upgrade:
            // the check always fails and we register a face nothing references.
            var family = (getComputedStyle(document.documentElement)
                    .getPropertyValue('--fa-family-classic') || 'Font Awesome 7 Free')
                .trim().replace(/^["']|["']$/g, '');
            var needed = [
                {weight: '900', url: '{{ asset("webfonts/fa-solid-900.woff2") }}'},
                {weight: '400', url: '{{ asset("webfonts/fa-regular-400.woff2") }}'}
            ];
            needed.forEach(function(f) {
                if (!document.fonts.check(f.weight + ' 1em "' + family + '"', '\uf007')) {
                    var face = new FontFace(family,
                        'url(' + f.url + ') format("woff2")',
                        {weight: f.weight, style: 'normal', display: 'swap'});
                    face.load().then(function(loaded) { document.fonts.add(loaded); });
                }
            });
        });
    })();
    </script>
    @yield('scripts')
</body>
</html>
