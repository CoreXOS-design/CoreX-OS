{{-- Prospecting Intelligence — Empty-state banner.

     Three kinds:
       'regenerating'      — Cache::get('corex.matches.regenerating') is true; counts may be stale
       'no_data'           — agency has zero listings + zero buyers + no filters applied
       'filtered_to_zero'  — filters applied, results empty; show per-filter clear buttons

     Consumes (per kind):
       'regenerating'     — (no extra)
       'no_data'          — (no extra)
       'filtered_to_zero' — $filters (array), $urlWithout (closure: string → string URL)
--}}

@php
    $kind = $kind ?? 'no_data';

    $clearButtons = [];
    if ($kind === 'filtered_to_zero') {
        $localFilters = $filters ?? [];
        if (!empty($localFilters['town_id']))            $clearButtons[] = ['key' => 'town_id',            'label' => 'town filter'];
        if (!empty($localFilters['property_type_slug'])) $clearButtons[] = ['key' => 'property_type_slug', 'label' => 'property type filter'];
        if (!empty($localFilters['bedroom_segment_id'])) $clearButtons[] = ['key' => 'bedroom_segment_id', 'label' => 'bedrooms filter'];
        if (!empty($localFilters['price_band_id']))      $clearButtons[] = ['key' => 'price_band_id',      'label' => 'price band filter'];
        if (!empty($localFilters['buyer_state']))        $clearButtons[] = ['key' => 'buyer_state',        'label' => 'status filter'];
        if (!empty($localFilters['buyers_since']))       $clearButtons[] = ['key' => 'buyers_since',       'label' => 'date filter'];
        if (!empty($localFilters['unmapped_only']))      $clearButtons[] = ['key' => 'unmapped_only',      'label' => 'unmapped-only filter'];
        if (!empty($localFilters['sources']))            $clearButtons[] = ['key' => 'sources',            'label' => 'source filter'];
        if (!empty($localFilters['suburb_normalised']))  $clearButtons[] = ['key' => 'suburb_normalised',  'label' => 'suburb filter'];
    }
@endphp

@if($kind === 'regenerating')
    <div class="my-4 rounded-md p-4"
         style="background: color-mix(in srgb, #3b82f6 8%, transparent); border: 1px solid color-mix(in srgb, #3b82f6 40%, var(--border));">
        <div class="flex items-start gap-3">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full flex-shrink-0"
                  style="background: color-mix(in srgb, #3b82f6 20%, transparent);">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #3b82f6;">
                    <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
                    <path d="M21 3v5h-5"></path>
                    <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path>
                    <path d="M8 16H3v5"></path>
                </svg>
            </span>
            <div class="min-w-0">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Match counts are being rebuilt</div>
                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                    Buyer-to-listing matches are recomputing in the background. The counts you see may be slightly out of date.
                    Refresh the page in a minute or two to see the latest numbers.
                </div>
            </div>
        </div>
    </div>

@elseif($kind === 'filtered_to_zero')
    <div class="my-6 rounded-md p-6 text-center"
         style="background: var(--surface-2); border: 1px dashed var(--border);">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3"
             style="background: var(--surface);">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--text-muted);">
                <circle cx="11" cy="11" r="8"></circle>
                <path d="m21 21-4.3-4.3"></path>
                <line x1="8" y1="11" x2="14" y2="11"></line>
            </svg>
        </div>
        <h3 class="text-base font-semibold" style="color: var(--text-primary);">No listings match your current filters</h3>
        @if(count($clearButtons) > 0)
            <p class="text-sm mt-1" style="color: var(--text-secondary);">Try removing a filter to widen the search:</p>
            <div class="flex flex-wrap items-center justify-center gap-2 mt-3">
                @foreach($clearButtons as $btn)
                    <a href="{{ ($urlWithout ?? fn ($k) => route('prospecting.index'))($btn['key']) }}"
                       class="inline-flex items-center gap-1 px-3 py-1.5 rounded text-xs font-medium no-underline transition hover:brightness-105"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">
                        ← Remove {{ $btn['label'] }}
                    </a>
                @endforeach
                <a href="{{ route('prospecting.index') }}"
                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded text-xs font-semibold no-underline transition hover:brightness-105"
                   style="background: var(--brand-button); color: #fff;">
                    Clear all filters
                </a>
            </div>
        @else
            <p class="text-sm mt-1" style="color: var(--text-secondary);">
                Your data may still be syncing. Check the unmapped suburbs widget on the
                <a href="{{ route('settings.prospecting.index', ['tab' => 'towns']) }}" style="color: var(--brand-button);">Prospecting Setup</a>
                page for any cleanup tasks.
            </p>
        @endif
    </div>

@elseif($kind === 'no_data')
    <div class="my-6 rounded-md p-6 text-center"
         style="background: var(--surface-2); border: 1px dashed var(--border);">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3"
             style="background: var(--surface);">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--text-muted);">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9 22 9 12 15 12 15 22"></polyline>
            </svg>
        </div>
        <h3 class="text-base font-semibold" style="color: var(--text-primary);">No prospecting data yet</h3>
        <p class="text-sm mt-1 max-w-md mx-auto" style="color: var(--text-secondary);">
            You'll see listings here as your portal alerts come in.
            Add buyers with wishlists in the buyer pipeline, and configure your towns &amp; price bands to start matching.
        </p>
        <div class="flex flex-wrap items-center justify-center gap-2 mt-4">
            <a href="{{ route('settings.prospecting.index') }}"
               class="inline-flex items-center gap-1 px-4 py-2 rounded text-xs font-semibold no-underline transition hover:brightness-105"
               style="background: var(--brand-button); color: #fff;">
                Configure prospecting setup →
            </a>
            <a href="{{ route('command-center.buyers.pipeline') }}"
               class="inline-flex items-center gap-1 px-4 py-2 rounded text-xs font-semibold no-underline transition hover:brightness-105"
               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                Go to buyer pipeline →
            </a>
        </div>
    </div>
@endif
