{{-- MIC Adverts card photo tile (mic-work-tab.md 13.16b): "N photos" in the top-right corner (opposite the Listed-by pill) and, with full details, left / right arrows on hover. The
     page script (public/js/mic-advert-fetch.js) swaps the picture; keyboard arrows work while the tile has focus; no autoplay. Included once, inside .mi-advert-photo, after the picture link. --}}
@php
    $gd = $adv['details'] ?? [];
    $gFull = ($gd['level'] ?? 'search') === 'full';
    $gUrls = $gFull && is_array($adv['photo_urls'] ?? null) ? array_values(array_filter($adv['photo_urls'], 'is_string')) : [];
    $gN = $gUrls ? count($gUrls) : ((!empty($thumbSrc) || !empty($adv['thumbnail_source_url'])) ? 1 : 0);
@endphp
@if($gN > 0)
    <span data-qa="photo-count" data-total="{{ $gN }}" style="position:absolute; top:8px; right:8px; padding:1px 7px; font-size:.6875rem; font-weight:700; color:#fff; background: rgba(15,23,42,.82); border-radius:3px; pointer-events:none;">{{ $gN }} {{ $gN === 1 ? 'photo' : 'photos' }}</span>
@endif
@if(count($gUrls) > 1)
    <script type="application/json" data-gallery-data>@json($gUrls)</script>
    <button type="button" data-gallery-prev aria-label="Previous photo" class="mi-gallery-arrow" style="left:6px;">‹</button>
    <button type="button" data-gallery-next aria-label="Next photo" class="mi-gallery-arrow" style="right:6px;">›</button>
@endif
