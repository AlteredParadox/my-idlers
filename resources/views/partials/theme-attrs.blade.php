{{-- Theme selection, as attributes for the <html> tag.

     `dark_mode` is a global setting (Settings row, cached into the session by
     the LoadSettings middleware): 0 = light, 1 = dark, 2 = neutral dark.

     data-theme drives this app's own custom properties in theme.css.
     data-bs-theme puts Bootstrap into its native dark mode for the two dark
     themes, so components this stylesheet does not style itself -- dropdown
     menus, select chevrons, file inputs -- follow the theme instead of
     rendering light on a dark page. The retired bootstrap-dark-5 package left
     Bootstrap's own values at their LIGHT defaults, which is why a white
     dropdown menu used to open on the dark themes.

     The handful of base values worth keeping from that old behaviour are
     pinned explicitly in theme.css rather than inherited from Bootstrap. --}}
@php($__theme = [0 => 'light', 1 => 'dark', 2 => 'neutral-dark'][(int) session('dark_mode', 0)] ?? 'light')
data-theme="{{ $__theme }}"@if($__theme !== 'light') data-bs-theme="dark"@endif
