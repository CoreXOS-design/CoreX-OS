{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-6">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">

        {{-- Top bar: back nav --}}
        <div class="flex items-center gap-2 mb-4">
            <a href="{{ route('corex.contacts.show', $contact) }}?tab=matches"
               class="inline-flex items-center gap-1.5 text-xs font-semibold no-underline"
               style="color: var(--text-secondary);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                Back to {{ $contact->full_name }}
            </a>
            <span class="text-xs" style="color: var(--text-muted);">/</span>
            <span class="text-xs font-semibold" style="color: var(--text-secondary);">Core Matches</span>
        </div>

        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">

            {{-- Left: contact + criteria --}}
            <div class="flex items-start gap-4 min-w-0">
                {{-- Avatar --}}
                <div class="w-12 h-12 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0"
                     style="background: {{ $contact->type?->color ?? 'var(--brand-icon)' }}; color:#fff;">
                    {{ $contact->initials }}
                </div>

                <div class="min-w-0">
                    {{-- Title row --}}
                    <div class="flex items-center gap-2 flex-wrap mb-1">
                        <h1 class="text-xl font-bold leading-tight text-white">{{ $contact->full_name }}</h1>
                        @if($contact->type)
                        <span class="ds-badge ds-badge-default" style="background: color-mix(in srgb, {{ $contact->type->color }} 15%, transparent); color: {{ $contact->type->color }}; border-color: color-mix(in srgb, {{ $contact->type->color }} 40%, transparent);">
                            {{ $contact->type->name }}
                        </span>
                        @endif
                        <span class="ds-badge {{ $match->listing_type === 'rental' ? 'ds-badge-info' : 'ds-badge-success' }}">
                            {{ $match->listingTypeLabel() }}
                        </span>
                        @if(auth()->user()->hasPermission('access_core_matches'))
                        {{-- AT-240 — edit this wishlist/criteria; opens the existing edit flow. --}}
                        <a href="{{ route('corex.contacts.matches.edit', [$contact, $match]) }}"
                           class="ds-badge no-underline inline-flex items-center gap-1"
                           style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);"
                           title="Edit this wishlist / match criteria">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
                            Edit criteria
                        </a>
                        @endif
                    </div>

                    {{-- Phone / email --}}
                    <div class="flex items-center gap-3 mb-3 flex-wrap text-sm" style="color: var(--text-secondary);">
                        @if($contact->phone)<span>{{ $contact->phone }}</span>@endif
                        @if($contact->email)<span>{{ $contact->email }}</span>@endif
                    </div>

                    {{-- Criteria chips --}}
                    <div class="flex items-center gap-1.5 flex-wrap">
                        @if($match->price_min || $match->price_max)
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-md"
                              style="background: color-mix(in srgb, var(--brand-icon) 18%, transparent); color: var(--brand-icon); border: 1px solid color-mix(in srgb, var(--brand-icon) 35%, transparent);">
                            {{ $match->priceRangeLabel() }}
                        </span>
                        @endif
                        @foreach($match->suburbList() as $sub)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $sub }}
                        </span>
                        @endforeach
                        @if($match->category)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $match->category }}
                        </span>
                        @endif
                        @if($match->property_type)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $match->property_type }}
                        </span>
                        @endif
                        @foreach([[$match->beds_min,'Beds'],[$match->baths_min,'Baths'],[$match->garages_min,'Gar']] as [$val,$lbl])
                        @if($val !== null)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $val }}+ {{ $lbl }}
                        </span>
                        @endif
                        @endforeach
                        @if($match->floor_size_min || $match->floor_size_max)
                        <span class="text-xs font-medium px-2.5 py-1 rounded-md"
                              style="background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border);">
                            {{ $match->floor_size_min ? number_format($match->floor_size_min) : '—' }}–{{ $match->floor_size_max ? number_format($match->floor_size_max) : '—' }} m²
                        </span>
                        @endif
                        @if(!$match->category && !$match->property_type && !$match->suburb && !$match->price_min && !$match->price_max && !$match->beds_min && !$match->baths_min)
                        <span class="text-xs italic" style="color: var(--text-muted);">Any property</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Right: stats + actions --}}
            <div class="flex flex-col md:items-end gap-3 flex-shrink-0"
                 style="--match-action-bar-stat-color: var(--text-primary);
                        --match-action-bar-stat-label-color: var(--text-muted);
                        --match-action-bar-stat-color-muted: var(--text-muted);
                        --match-action-bar-stat-label-color-muted: var(--text-muted);
                        --match-action-bar-divider-color: var(--border);
                        --match-action-bar-outline-bg: transparent;
                        --match-action-bar-outline-color: var(--text-secondary);
                        --match-action-bar-outline-border: var(--border);">
                @include('corex.contacts._match-action-bar', ['contact' => $contact, 'match' => $match, 'matchCount' => $properties->count()])
            </div>
        </div>
    </div>

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
@endsection
