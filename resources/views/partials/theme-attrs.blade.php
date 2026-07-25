{{-- Theme selection, as attributes for the <html> tag.

     `dark_mode` is a global setting (Settings row, cached into the session by
     the LoadSettings middleware): 0 = light, 1 = dark, 2 = neutral dark.

     Only data-theme is emitted, driving this app's own custom properties in
     theme.css. Bootstrap is deliberately NOT switched into its native dark mode:
     measuring the old build showed the bootstrap-dark-5 package left Bootstrap's
     own base values at their light defaults (body #212529, .text-muted #6c757d,
     table borders #dee2e6) and let this app's stylesheet do all the theming.
     Turning on data-bs-theme="dark" would therefore change the dark themes'
     appearance rather than preserve it. --}}
@php($__theme = [0 => 'light', 1 => 'dark', 2 => 'neutral-dark'][(int) session('dark_mode', 0)] ?? 'light')
data-theme="{{ $__theme }}"
