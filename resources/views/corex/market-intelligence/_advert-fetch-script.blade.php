{{-- 13.16: loads the page script for "Get full details", the photo gallery and the full-ad window. Kept OUT of the card because the list is swapped in by the tick refresh and scripts inside a swapped fragment do not run. --}}
<script src="{{ asset('js/mic-advert-fetch.js') }}?v={{ @filemtime(public_path('js/mic-advert-fetch.js')) ?: 1 }}" defer></script>
<style>
    .mi-gallery-arrow { position:absolute; top:50%; transform:translateY(-50%); width:28px; height:28px; border:none; border-radius:50%; background: rgba(15,23,42,.72); color:#fff; font-size:1.25rem; line-height:1; cursor:pointer; opacity:0; transition: opacity .12s; }
    .mi-advert-photo:hover .mi-gallery-arrow, .mi-advert-photo:focus-within .mi-gallery-arrow, .mi-advert-photo:focus .mi-gallery-arrow { opacity:1; }
    @media (hover: none) { .mi-gallery-arrow { opacity:1; } }
</style>
