{{-- Ad typography — the ONE font stylesheet every ad surface loads.

     The Ad Builder offers these families in its font picker; the single-property
     generator and the bulk Ad Manager must load exactly the same set, or a family
     a designer picked would silently fall back to Figtree in the rendered ad and
     the downloaded PNG. Adding a family means adding it HERE and to FONTS in
     public/js/corex-ad-render.js — nowhere else. Spec: ad-manager.md §12. --}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900|inter:400,500,600,700,800,900|poppins:400,600,700,800|montserrat:400,600,700,800,900|oswald:400,500,600,700|bebas-neue:400|playfair-display:400,600,700,800,900|lora:400,600,700&display=swap">
