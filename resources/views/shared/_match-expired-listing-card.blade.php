{{--
    One listing card for the expired-link page's "My newest stock" /
    "Latest from {agency}" sections. Pure public-stock data — nothing here
    is derived from the closed wishlist. $property->display_image_url is
    resolved server-side by SharedMatchController::listingImageUrl().

    Required: $property  App\Models\Property
--}}
<a href="{{ route('corex.properties.preview', $property) }}?agent=none" target="_blank" rel="noopener" class="listing-card">
    <div class="listing-img">
        {{-- A stored path doesn't guarantee the file is actually there (storage
             sync gaps, a deleted file the DB row still points at) — onerror
             swaps in the same placeholder the no-photo case already uses,
             rather than the browser's broken-image icon. --}}
        @if($property->display_image_url)
            <img src="{{ $property->display_image_url }}" alt="{{ $property->title }}" loading="lazy"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        @endif
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" style="{{ $property->display_image_url ? 'display:none;' : '' }}" fill="none" viewBox="0 0 24 24" stroke="var(--text-muted)" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3 8.25V18a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 18V8.25m-18 0V6a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 6v2.25m-18 0h18" /></svg>
    </div>
    <div class="listing-body">
        @if($property->suburb)<div class="listing-suburb">{{ $property->suburb }}</div>@endif
        <div class="listing-price">{{ $property->formattedPrice() }}</div>
        <div class="listing-addr">{{ $property->buildDisplayAddress() ?: $property->title }}</div>
        <div class="listing-specs">
            @if($property->beds !== null)<span>{{ (int) $property->beds }} beds</span>@endif
            @if($property->baths !== null)<span>{{ rtrim(rtrim((string) $property->baths, '0'), '.') }} baths</span>@endif
            @if($property->size_m2)<span>{{ (int) $property->size_m2 }} m&sup2;</span>@endif
        </div>
    </div>
</a>
