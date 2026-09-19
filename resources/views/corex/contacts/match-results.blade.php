{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    // Header facts — the same figures the action bar's stats row used to show,
    // now cells in the facts strip (the bar renders with showStats=false below).
    $matchCount  = $properties->count();
    $totalViews  = array_sum($match->property_view_counts ?? []);
    $hiddenCount = count($match->hidden_property_ids ?? []);

    $criteriaChips = [];
    foreach ($match->suburbList() as $sub)      { $criteriaChips[] = $sub; }
    if ($match->category)                       { $criteriaChips[] = $match->category; }
    if ($match->property_type)                  { $criteriaChips[] = $match->property_type; }
    foreach ([[$match->beds_min, 'Beds'], [$match->baths_min, 'Baths'], [$match->garages_min, 'Gar']] as [$val, $lbl]) {
        if ($val !== null) { $criteriaChips[] = $val . '+ ' . $lbl; }
    }
    if ($match->floor_size_min || $match->floor_size_max) {
        $criteriaChips[] = ($match->floor_size_min ? number_format($match->floor_size_min) : '—')
            . '–' . ($match->floor_size_max ? number_format($match->floor_size_max) : '—') . ' m²';
    }
    $hasPrice = $match->price_min || $match->price_max;
@endphp
<div class="w-full h-full flex flex-col">

    {{-- ════════════════════════════════════════════════════════════════════
         MATCH HEADER — same shape as the contact page header
         (corex.contacts._header): a surface card, NOT a corex-page-banner.

         Layout — three bands:
           · Identity row — Back (icon) + name + badges LEFT, actions RIGHT.
           · Criteria row — the wishlist as chips (wraps; suburb lists vary).
           · Facts strip  — six record facts as equal cells, hairline rules
                            (1px grid gap over a --border background).
         Every action is the original, unchanged — only the chrome moved.
         No overflow-hidden on the card: the Print / PDF dropdown in the
         action bar is absolutely positioned and must not be clipped, so the
         facts strip rounds its own bottom corners instead.
         ════════════════════════════════════════════════════════════════════ --}}
    <div class="rounded-lg flex-shrink-0" style="background:var(--surface); border:1px solid var(--border); box-shadow:0 1px 2px rgba(15,23,42,0.06);">

        {{-- Identity row — Back + name + badges LEFT, actions RIGHT. --}}
        <div class="px-5 pt-3.5 pb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 min-w-0">
                <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
                   class="corex-btn-outline text-xs no-underline inline-flex items-center flex-shrink-0"
                   style="padding-left:0.5rem; padding-right:0.5rem;"
                   title="Back to {{ $contact->full_name }}" aria-label="Back to {{ $contact->full_name }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    <span class="sr-only">Back to {{ $contact->full_name }}</span>
                </a>
                <h1 class="text-2xl font-bold leading-tight" style="color: var(--text-primary);">{{ $contact->full_name }}</h1>
                <div class="flex items-center gap-2 flex-wrap">
                    @if($contact->type)
                    <span class="text-[11px] px-2 py-0.5 rounded-md font-semibold"
                          style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                        {{ $contact->type->name }}
                    </span>
                    @endif
                    <span class="ds-badge {{ $match->listing_type === 'rental' ? 'ds-badge-info' : 'ds-badge-success' }}">
                        {{ $match->listingTypeLabel() }}
                    </span>
                    {{-- AT-402 audit - dates on entries (Johan). --}}
                    <span class="text-xs" style="color: var(--text-muted);" title="Saved">
                        Saved {{ $match->created_at?->format('d M Y') ?? '-' }}
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2"
                 style="--match-action-bar-stat-color: var(--text-primary);
                        --match-action-bar-stat-label-color: var(--text-muted);
                        --match-action-bar-stat-color-muted: var(--text-muted);
                        --match-action-bar-stat-label-color-muted: var(--text-muted);
                        --match-action-bar-divider-color: var(--border);
                        --match-action-bar-outline-bg: transparent;
                        --match-action-bar-outline-color: var(--text-secondary);
                        --match-action-bar-outline-border: var(--border);">
                @if(auth()->user()->hasPermission('access_core_matches'))
                {{-- AT-240 — edit this wishlist/criteria; opens the existing edit flow. --}}
                <a href="{{ route('corex.contacts.matches.edit', [$contact, $match]) }}"
                   class="corex-btn-outline text-xs flex-shrink-0 no-underline inline-flex items-center gap-1.5"
                   title="Edit this wishlist / match criteria">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                    Edit criteria
                </a>
                @endif
                {{-- Stats row off: matches / views / hidden are facts-strip cells below. --}}
                @include('corex.contacts._match-action-bar', ['contact' => $contact, 'match' => $match, 'matchCount' => $matchCount, 'showStats' => false])
            </div>
        </div>

        {{-- Criteria row — the wishlist this list was resolved from. --}}
        <div class="px-5 pb-3.5 flex items-center gap-x-3 gap-y-1.5 flex-wrap">
            <span class="text-[11px] uppercase tracking-widest font-semibold flex-shrink-0" style="color:var(--text-muted);">Criteria</span>
            @if($hasPrice)
            <span class="text-xs font-semibold px-2.5 py-1 rounded-md"
                  style="background: color-mix(in srgb, var(--brand-icon) 18%, transparent); color: var(--brand-icon); border: 1px solid color-mix(in srgb, var(--brand-icon) 35%, transparent);">
                {{ $match->priceRangeLabel() }}
            </span>
            @endif
            @foreach($criteriaChips as $chip)
            <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                  style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                {{ $chip }}
            </span>
            @endforeach
            @if(!$hasPrice && empty($criteriaChips))
            <span class="text-xs italic" style="color: var(--text-muted);">Any property</span>
            @endif
        </div>

        {{-- Facts strip — six cells, hairline-separated, flush to the card edge. --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-px rounded-b-lg overflow-hidden"
             style="background:var(--border); border-top:1px solid var(--border);">

            <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
                <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Phone</div>
                <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
                    @if($contact->phone)
                        <a href="tel:{{ preg_replace('/\s+/', '', $contact->phone) }}" class="no-underline hover:underline" style="color:inherit;">{{ $contact->phone }}</a>
                    @else
                        <span style="color:var(--text-muted);">—</span>
                    @endif
                </div>
            </div>

            <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
                <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Email</div>
                <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
                    @if($contact->email)
                        <a href="mailto:{{ $contact->email }}" class="no-underline hover:underline" style="color:inherit;">{{ $contact->email }}</a>
                    @else
                        <span style="color:var(--text-muted);">—</span>
                    @endif
                </div>
            </div>

            <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
                <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Agent</div>
                <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">{{ $contact->agent?->name ?? 'Unassigned' }}</div>
            </div>

            <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
                <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Matches</div>
                <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
                    {{ number_format($matchCount) }} {{ Str::plural('property', $matchCount) }}
                </div>
            </div>

            <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
                <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Client views</div>
                <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
                    @if($totalViews > 0)
                        {{ number_format($totalViews) }} {{ Str::plural('view', $totalViews) }}
                    @else
                        <span style="color:var(--text-muted);">None yet</span>
                    @endif
                </div>
            </div>

            <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
                <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Hidden</div>
                <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
                    @if($hiddenCount > 0)
                        {{ number_format($hiddenCount) }} {{ Str::plural('property', $hiddenCount) }}
                    @else
                        <span style="color:var(--text-muted);">—</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Frozen header (same pattern as the contact page, AT-393): the card above
         stays put and the property list scrolls in this inner region.
         `data-scroll-region` opts it into the global scroll preserve/restore. --}}
    <div class="flex-1 min-h-0 overflow-y-auto mt-4" data-scroll-region>

    {{-- Property list --}}
    @if($properties->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No active properties match these criteria</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Try broadening the price range, suburb, or room requirements.</p>
        <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches" class="corex-btn-outline">
            ← Back to {{ $contact->full_name }}
        </a>
    </div>
    @else
    <div class="space-y-3">
        @php
            // Belt-and-braces: hard-filter results to the match's listing_type.
            // The controller already uses ClientMatchResolver which filters strictly,
            // but if anything ever leaks through (legacy code path, cache, etc.)
            // a sale match must never display rentals, and vice versa.
            // Spec: .ai/specs/client-auth.md
            $matchListingType = $match->listing_type;
            $rentalStatuses   = ['to_rent','torent','for_rent','forrent','rented'];
            $saleStatuses     = ['for_sale','forsale','sold'];

            $filteredProperties = collect($properties)->filter(function ($p) use ($matchListingType, $rentalStatuses, $saleStatuses) {
                if (!$matchListingType) return true;
                $pLt = strtolower((string) ($p->listing_type ?? ''));
                $pSt = strtolower((string) ($p->status ?? ''));
                if ($matchListingType === 'sale') {
                    if ($pLt === 'rental') return false;
                    if (in_array($pSt, $rentalStatuses, true)) return false;
                }
                if ($matchListingType === 'rental') {
                    if ($pLt === 'sale') return false;
                    if (in_array($pSt, $saleStatuses, true)) return false;
                }
                return true;
            });

            // Visible properties first, hidden ones grouped at the bottom.
            $visibleProperties = $filteredProperties->reject(fn ($p) => $match->isPropertyHidden($p->id))->values();
            $hiddenProperties  = $filteredProperties->filter(fn ($p) => $match->isPropertyHidden($p->id))->values();
            $orderedProperties = $visibleProperties->concat($hiddenProperties);
            $firstHiddenId     = $hiddenProperties->first()?->id;
        @endphp
        @foreach($orderedProperties as $property)
        @php
            $isHidden = $match->isPropertyHidden($property->id);
        @endphp
        @if($isHidden && $property->id === $firstHiddenId)
        <div class="flex items-center gap-3 pt-6 pb-1">
            <div class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">
                Hidden from this match ({{ $hiddenProperties->count() }})
            </div>
            <div class="flex-1" style="height:1px; background: var(--border);"></div>
        </div>
        @endif
        <x-match-card :property="$property" :match="$match" :contact="$contact" :feedback="$feedback[$property->id] ?? null" />
        @endforeach
    </div>

    @endif

    </div>

</div>
@endsection
