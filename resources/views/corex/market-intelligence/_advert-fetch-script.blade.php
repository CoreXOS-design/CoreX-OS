{{-- 13.16: loads the page script for "Get full details", the photo gallery and the full-ad window. Kept OUT of the card because the list is swapped in by the tick refresh and scripts inside a swapped fragment do not run. --}}
<script src="{{ asset('js/mic-advert-fetch.js') }}?v={{ @filemtime(public_path('js/mic-advert-fetch.js')) ?: 1 }}" defer></script>
<style>
    .mi-gallery-arrow { position:absolute; top:50%; transform:translateY(-50%); width:28px; height:28px; border:none; border-radius:50%; background: rgba(15,23,42,.72); color:#fff; font-size:1.25rem; line-height:1; cursor:pointer; opacity:0; transition: opacity .12s; }
    .mi-advert-photo:hover .mi-gallery-arrow, .mi-advert-photo:focus-within .mi-gallery-arrow, .mi-advert-photo:focus .mi-gallery-arrow { opacity:1; }
    @media (hover: none) { .mi-gallery-arrow { opacity:1; } }
    /* the full-ad window (13.16b item 5): strip without a visible scrollbar, and the copy of the card's panel at full size - the five buttons on ONE row, never cut */
    .mi-strip { scrollbar-width: none; -ms-overflow-style: none; }
    .mi-strip::-webkit-scrollbar { display: none; }
    .mi-strip-arrow { flex: none; width: 26px; height: 40px; border: 1px solid var(--border); border-radius: 5px; background: var(--surface); color: var(--text-primary); cursor: pointer; font-size: 1.125rem; line-height: 1; }
    .mi-fullad-right .mi-advert-right { height: auto; }
    .mi-fullad-right [data-qa="line-buttons"] { display: flex !important; flex-wrap: nowrap; gap: 6px; height: auto !important; }
    .mi-fullad-right [data-qa="line-buttons"] > *, .mi-fullad-right [data-qa="line-buttons"] > form { flex: 1 1 auto; width: auto !important; min-width: max-content; overflow: visible !important; text-overflow: clip !important; padding-left: 10px !important; padding-right: 10px !important; }
    .mi-fullad-right [data-qa="line-buttons"] button, .mi-fullad-right [data-qa="line-buttons"] a { overflow: visible !important; text-overflow: clip !important; white-space: nowrap !important; width: 100% !important; padding-left: 10px; padding-right: 10px; }
</style>
