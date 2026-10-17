{{-- Ad typography — the ONE font stylesheet every ad surface loads.

     The Ad Builder offers these families in its font picker; the single-property
     generator and the bulk Ad Manager must load exactly the same set, or a family
     a designer picked would silently fall back to Figtree in the rendered ad and
     the downloaded PNG. Adding a family means adding it HERE and to FONTS in
     public/js/corex-ad-render.js — nowhere else. Spec: ad-manager.md §12. --}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900|inter:400,500,600,700,800,900|poppins:400,600,700,800|montserrat:400,600,700,800,900|oswald:400,500,600,700|bebas-neue:400|playfair-display:400,600,700,800,900|lora:400,600,700&display=swap">
{{-- AT-456 §B.9-1 — the twelve extra families are NOT in the stylesheet above. The render kernel adds the
     stylesheet for one the first time a template draws text in it (CoreXAd.requestFont), and a capture waits
     for it (CoreXAd.fontsReady, inside prepareImagesForCapture). A template that does not use them costs nothing.
     Same CDN, same Open Font Licence families. Keep this list and FONTS (lazy: true) in the kernel in step. --}}
<script>
    window.CoreXAdLazyFonts = {
        'Cormorant Garamond': 'cormorant-garamond:400,600,700',
        'DM Serif Display':   'dm-serif-display:400',
        'Libre Baskerville':  'libre-baskerville:400,700',
        'Great Vibes':        'great-vibes:400',
        'Dancing Script':     'dancing-script:400,600,700',
        'Barlow Condensed':   'barlow-condensed:400,600,700',
        'Anton':              'anton:400',
        'Nunito':             'nunito:400,600,700,800',
        'Quicksand':          'quicksand:400,600,700',
        'Raleway':            'raleway:400,600,700,800',
        'Work Sans':          'work-sans:400,600,700,800',
        'Space Grotesk':      'space-grotesk:400,500,600,700'
    };
</script>
