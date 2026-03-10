<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title') - @if (config()->has('app.name')) {{ config('app.name') }} @else My idlers @endif</title>
    <link rel="icon" type="image" href="{{asset(Session::get('favicon') ?? 'favicon.ico')}}"/>

    @if(Session::get('dark_mode'))
        <link rel="stylesheet" href="{{ asset('css/dark.css') }}">
    @else
        <link rel="stylesheet" href="{{ asset('css/light.css') }}">
    @endif

    <link rel="preload" href="{{ asset('webfonts/fa-solid-900.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('webfonts/fa-regular-400.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">

    @yield('css_links')
    @yield('style')

</head>
<body class="auth-page">
    <div class="auth-wrapper">
        {{ $slot }}
    </div>
    <script src="{{ asset('js/app.js') }}" defer></script>
    <script>
    (function() {
        if (!document.fonts) return;
        document.fonts.ready.then(function() {
            var needed = [
                {weight: '900', url: '{{ asset("webfonts/fa-solid-900.woff2") }}'},
                {weight: '400', url: '{{ asset("webfonts/fa-regular-400.woff2") }}'}
            ];
            needed.forEach(function(f) {
                if (!document.fonts.check(f.weight + ' 1em "Font Awesome 6 Free"', '\uf007')) {
                    var face = new FontFace('Font Awesome 6 Free',
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
