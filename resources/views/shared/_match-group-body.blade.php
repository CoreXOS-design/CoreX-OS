{{--
    Body of one wishlist's section: refine bar, property grid, feedback controls,
    and the "change your search criteria" form. Pulled out of match-group.blade.php
    purely so it can render either bare (single-wishlist contact, unchanged
    behaviour) or inside a <details> accordion (multi-wishlist contact) without
    duplicating this markup. All element scoping is done with classes + a
    `.match-group-root` ancestor (see the enclosing partial) — never with raw
    ids — so the same markup can repeat once per wishlist on the page.
--}}
<section>
    <div class="flex items-end justify-between gap-3 mb-3 flex-wrap">
        <div>
            <h3 class="text-base font-bold" style="color: var(--text-primary);">
                Properties found
                <span class="ds-badge ds-badge-info ml-1.5" style="vertical-align: middle;">{{ number_format($totalCount) }}</span>
            </h3>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">Tap a card to see full listing details. Tell us what you think with the reactions below each one.</p>
        </div>
        @if($totalCount > 0)
        <span class="js-shown-count text-xs font-semibold" style="color: var(--text-muted);"></span>
        @endif
    </div>

    {{-- Refine filter bar (client-side, instant) --}}
    @if($totalCount > 0)
    <div class="js-refine-bar surface-card p-4 lg:p-5 mb-4" style="display:none;">
        <div class="flex items-center justify-between gap-2 mb-4">
            <div class="flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" /></svg>
                <h4 class="text-sm font-bold" style="color: var(--text-primary);">Refine these results</h4>
            </div>
            <button type="button" class="js-refine-clear text-xs font-semibold" style="color: var(--brand-icon);">Clear</button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">

            {{-- Location --}}
            @if($suburbList->isNotEmpty())
            <div>
                <label class="field-label">Location</label>
                <select class="js-f-location refine-select">
                    <option value="">All areas</option>
                    @foreach($suburbList as $sub)
                        <option value="{{ $sub }}">{{ $sub }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Bedrooms --}}
            <div>
                <label class="field-label">Bedrooms</label>
                <select class="js-f-beds refine-select">
                    <option value="0">Any</option>
                    @foreach([1,2,3,4,5] as $b)
                        <option value="{{ $b }}">{{ $b }}+ beds</option>
                    @endforeach
                </select>
            </div>

            {{-- Minimum match % --}}
            @if($hasScores)
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="field-label" style="margin:0;">Minimum match</label>
                    <span class="text-xs font-bold" style="color: var(--brand-icon);"><span class="js-f-match-val">0</span>%</span>
                </div>
                <input type="range" class="js-f-match range" min="0" max="100" step="5" value="0">
            </div>
            @endif

            {{-- Price range --}}
            @if($priceFloor !== null)
            <div class="{{ $hasScores ? '' : 'sm:col-span-2' }}">
                <div class="flex items-center justify-between mb-1.5">
                    <label class="field-label" style="margin:0;">Price range</label>
                    <span class="text-xs font-semibold" style="color: var(--text-secondary);">
                        R <span class="js-f-price-min-val"></span> – R <span class="js-f-price-max-val"></span>
                    </span>
                </div>
                <div class="space-y-2 pt-1">
                    <input type="range" class="js-f-price-min range" min="{{ $priceFloor }}" max="{{ $priceCeil }}" step="{{ $priceStep }}" value="{{ $priceFloor }}">
                    <input type="range" class="js-f-price-max range" min="{{ $priceFloor }}" max="{{ $priceCeil }}" step="{{ $priceStep }}" value="{{ $priceCeil }}">
                </div>
            </div>
            @endif
        </div>
    </div>
    @endif

    @if($properties->isEmpty())
    <div class="rounded-xl py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No properties match your current filters</h3>
        <p class="text-sm" style="color: var(--text-muted);">Try adjusting the search criteria below — broaden the price range, suburb, or rooms.</p>
    </div>
    @else
    <div class="js-match-list space-y-3">
        @foreach($properties as $property)
        @php
            $thumb = $property->thumbFor(
                $property->gallery_images_json[0]
                ?? $property->dawn_images_json[0]
                ?? $property->noon_images_json[0]
                ?? $property->dusk_images_json[0]
                ?? null
            );
            $reaction = $feedback[$property->id]->reaction ?? null;
            $score = (int) ($property->match_score ?? 0);
            $scoreVariant = $score >= 80 ? 'ds-badge-success' : ($score >= 60 ? 'ds-badge-info' : 'ds-badge-warning');
            $statusVariant = match($property->status) {
                'active'    => 'ds-badge-success',
                'sold'      => 'ds-badge-info',
                'withdrawn' => 'ds-badge-warning',
                default     => 'ds-badge-default',
            };
            $statusLabel = $property->status === 'active' ? 'For Sale' : ucfirst($property->status);
        @endphp
        <article class="match-card surface-card overflow-hidden"
                 data-price="{{ (int) $property->price > 0 ? (int) $property->price : '' }}"
                 data-score="{{ $score }}"
                 data-suburb="{{ $property->suburb }}"
                 data-beds="{{ (int) $property->beds }}">
            {{-- agent=none: the client already has their own agent, so the
                 listing agent's identity/contact is hidden on this preview. --}}
            <a href="{{ route('corex.properties.preview', $property) }}?agent=none"
               target="_blank"
               data-record-view="{{ route('shared.match.view', [$groupToken, $property->id]) }}"
               class="property-card-link flex flex-col sm:flex-row gap-0 group"
               style="color: inherit;">

                {{-- Image --}}
                <div class="relative flex-shrink-0 overflow-hidden sm:w-[220px] sm:min-h-[160px]"
                     style="background: var(--surface-2); aspect-ratio: 16/10;">
                    @if($thumb)
                    <img src="{{ $thumb }}" alt="{{ $property->title }}"
                         class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                    @else
                    <div class="absolute inset-0 flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-10 h-10" style="color: var(--text-muted); opacity: 0.4;"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
                    </div>
                    @endif
                    @if($score > 0)
                    <div class="absolute top-2 left-2 ds-badge {{ $scoreVariant }}" style="backdrop-filter: blur(6px);">{{ $score }}% match</div>
                    @endif
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0 p-4 flex flex-col justify-between gap-3">
                    <div>
                        <div class="flex items-start justify-between gap-3 mb-1.5">
                            <span class="ds-badge {{ $statusVariant }}">{{ $statusLabel }}</span>
                        </div>
                        <div class="text-xl font-extrabold leading-tight" style="color: var(--brand-default);">
                            {{ $property->formattedPrice() }}
                        </div>
                        <div class="text-sm font-medium leading-snug mt-0.5" style="color: var(--text-primary);">
                            {{ $property->title ?: 'Property Listing' }}
                        </div>
                        @if($property->suburb)
                        <div class="flex items-center gap-1 text-xs mt-1.5" style="color: var(--text-muted);">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                            {{ $property->suburb }}{{ $property->city ? ', '.$property->city : '' }}
                        </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex items-center gap-4 text-xs" style="color: var(--text-secondary);">
                            @foreach([[$property->beds,'Beds'],[$property->baths,'Baths'],[$property->garages,'Gar']] as [$v,$l])
                            @if($v)
                            <div class="flex items-baseline gap-1">
                                <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ $v }}</span>
                                <span class="text-[0.6875rem]" style="color: var(--text-muted);">{{ $l }}</span>
                            </div>
                            @endif
                            @endforeach
                            @if($property->size_m2)
                            <div class="flex items-baseline gap-1">
                                <span class="text-sm font-semibold" style="color: var(--text-primary);">{{ number_format($property->size_m2) }}</span>
                                <span class="text-[0.6875rem]" style="color: var(--text-muted);">m²</span>
                            </div>
                            @endif
                        </div>
                        <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color: var(--brand-icon);">
                            View listing
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </span>
                    </div>
                </div>
            </a>

            {{-- Feedback row --}}
            <div class="flex items-center justify-between gap-2 px-4 py-2.5 flex-wrap"
                 style="border-top: 1px solid var(--border); background: var(--surface-2);">
                <div class="text-xs font-medium" style="color: var(--text-secondary);">What do you think of this one?</div>
                <div class="flex items-center gap-1.5"
                     data-feedback-url="{{ route('shared.match.feedback', [$groupToken, $property->id]) }}"
                     data-property-id="{{ $property->id }}">
                    @foreach([
                        ['interested',     'Interested', 'var(--ds-green)'],
                        ['not_interested', 'Not for me', 'var(--text-muted)'],
                    ] as [$key,$label,$colour])
                    <button type="button"
                            class="feedback-btn {{ $reaction === $key ? 'is-active' : '' }}"
                            data-reaction="{{ $key }}"
                            data-colour="{{ $colour }}"
                            @if($reaction === $key) style="background: {{ $colour }}; border-color: {{ $colour }}; color: #fff;" @endif>
                        {{ $label }}
                    </button>
                    @endforeach
                </div>
            </div>
        </article>
        @endforeach
    </div>

    {{-- Load more (client-side — reveals 10 more of the already-loaded, score-ranked results) --}}
    <div class="js-load-more-wrap flex justify-center mt-5" style="display:none;">
        <button type="button" class="js-load-more-btn btn-outline" style="padding:0.625rem 1.5rem;">
            Load more <span class="js-load-more-remain font-normal" style="color: var(--text-muted);"></span>
        </button>
    </div>

    {{-- Filtered-empty state (shown by JS when the refine bar hides every card) --}}
    <div class="js-filtered-empty rounded-xl py-10 px-6 text-center mt-3" style="display:none; background: var(--surface); border: 1px dashed var(--border);">
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No matches for these filters</h3>
        <p class="text-sm" style="color: var(--text-muted);">Loosen the price range or match minimum, or <button type="button" class="js-filtered-clear font-semibold" style="color: var(--brand-icon);">clear the filters</button>.</p>
    </div>
    @endif
</section>

{{-- Change search criteria (server-side — re-runs this wishlist's match with wider bounds) --}}
<details class="surface-card overflow-hidden mt-4">
    <summary class="flex items-center gap-2 px-5 lg:px-6 py-4 cursor-pointer select-none" style="list-style:none;">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" /></svg>
        <div>
            <h2 class="text-base font-bold" style="color: var(--text-primary);">Change your search criteria</h2>
            <p class="text-xs" style="color: var(--text-muted);">Broaden the underlying search — this re-runs your matches from scratch.</p>
        </div>
    </summary>

    <form method="GET" action="{{ route('shared.match', $token) }}" class="space-y-5 px-5 lg:px-6 pb-6 pt-1">
        <input type="hidden" name="match_id" value="{{ $m->id }}">

        {{-- Price range --}}
        <div>
            <label class="field-label">Price range (R)</label>
            <div class="grid grid-cols-2 gap-3">
                <input type="number" name="price_min" value="{{ old('price_min', $filters['priceMin']) }}"
                       placeholder="Min price" min="0" step="50000" class="field-input">
                <input type="number" name="price_max" value="{{ old('price_max', $filters['priceMax']) }}"
                       placeholder="Max price" min="0" step="50000" class="field-input">
            </div>
        </div>

        {{-- Suburb --}}
        <div>
            <label class="field-label">Suburb</label>
            <input type="text" name="suburb" value="{{ old('suburb', $filters['suburb']) }}"
                   placeholder="e.g. Uvongo, Margate, Shelly Beach" class="field-input">
        </div>

        {{-- Rooms --}}
        <div>
            <label class="field-label">Minimum rooms</label>
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <span class="field-helper">Bedrooms</span>
                    <input type="number" name="beds_min" value="{{ old('beds_min', $filters['bedsMin']) }}"
                           placeholder="Any" min="0" max="20" class="field-input">
                </div>
                <div>
                    <span class="field-helper">Bathrooms</span>
                    <input type="number" name="baths_min" value="{{ old('baths_min', $filters['bathsMin']) }}"
                           placeholder="Any" min="0" max="20" class="field-input">
                </div>
                <div>
                    <span class="field-helper">Garages</span>
                    <input type="number" name="garages_min" value="{{ old('garages_min', $filters['garagesMin']) }}"
                           placeholder="Any" min="0" max="20" class="field-input">
                </div>
            </div>
        </div>

        {{-- Floor + Erf --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="field-label">Floor size (m²)</label>
                <div class="grid grid-cols-2 gap-2">
                    <input type="number" name="floor_size_min" value="{{ old('floor_size_min', $filters['floorMin']) }}"
                           placeholder="Min" min="0" class="field-input">
                    <input type="number" name="floor_size_max" value="{{ old('floor_size_max', $filters['floorMax']) }}"
                           placeholder="Max" min="0" class="field-input">
                </div>
            </div>
            <div>
                <label class="field-label">Erf size (m²)</label>
                <div class="grid grid-cols-2 gap-2">
                    <input type="number" name="erf_size_min" value="{{ old('erf_size_min', $filters['erfMin']) }}"
                           placeholder="Min" min="0" class="field-input">
                    <input type="number" name="erf_size_max" value="{{ old('erf_size_max', $filters['erfMax']) }}"
                           placeholder="Max" min="0" class="field-input">
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="btn-primary">Update results</button>
            <a href="{{ route('shared.match', $token) }}" class="btn-outline">Reset to defaults</a>
        </div>
    </form>
</details>
