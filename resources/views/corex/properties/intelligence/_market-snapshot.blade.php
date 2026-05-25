{{--
    Market Intelligence Snapshot — surfaces fragments the existing Intelligence tab does not show:
      - CMAInfo OCR output (erf, GPS, municipal valuation, CMA bands) extracted into presentation_fields
      - prospecting_listings (P24/PP) matched to this property via matched_property_id

    Inputs: $property, $cmaSnapshot, $matchedProspects
--}}

@php
    /** @var \App\Models\Property $property */
    /** @var array $cmaSnapshot */
    /** @var \Illuminate\Support\Collection $matchedProspects */

    $erf = $cmaSnapshot['erf_number'] ?? $property->property_number ?? $property->stand_number;
    $gps = $cmaSnapshot['gps'];
    if (!$gps && $property->latitude && $property->longitude) {
        $gps = number_format((float) $property->latitude, 6) . '°S  ' . number_format((float) $property->longitude, 6) . '°E';
    }
    $extentM2 = $cmaSnapshot['extent_m2'] ?? $property->erf_size_m2;
@endphp

<div class="space-y-3">
    <div class="flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" style="color: #00d4aa;">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" />
        </svg>
        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">Market Intelligence Snapshot</h3>
        @if($cmaSnapshot['has_data'])
            <span class="text-[10px] px-1.5 py-0.5 rounded font-medium" style="background: color-mix(in srgb, #00d4aa 12%, transparent); color: #00d4aa;">
                Live CMA data
            </span>
        @else
            <span class="text-[10px] px-1.5 py-0.5 rounded font-medium" style="background: color-mix(in srgb, var(--text-muted) 12%, transparent); color: var(--text-muted);">
                No CMA on file
            </span>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

        {{-- Column 1: Property identity facts --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">Property facts</div>
            <div class="space-y-1.5 text-xs">
                @if($erf)
                <div class="flex items-baseline justify-between gap-2">
                    <span style="color: var(--text-muted);">Erf / Stand</span>
                    <span class="font-medium" style="color: var(--text-primary);">{{ $erf }}</span>
                </div>
                @endif
                @if($extentM2)
                <div class="flex items-baseline justify-between gap-2">
                    <span style="color: var(--text-muted);">Extent</span>
                    <span class="font-medium" style="color: var(--text-primary);">{{ $extentM2 }} m²</span>
                </div>
                @endif
                @if($property->suburb || $property->town || $property->city)
                <div class="flex items-baseline justify-between gap-2">
                    <span style="color: var(--text-muted);">Area</span>
                    <span class="font-medium text-right" style="color: var(--text-primary);">
                        {{ trim(($property->suburb ?? '') . ($property->town || $property->city ? ', ' . ($property->town ?? $property->city) : '')) }}
                    </span>
                </div>
                @endif
                @if($gps)
                <div class="flex items-baseline justify-between gap-2">
                    <span style="color: var(--text-muted);">GPS</span>
                    <span class="font-medium text-right text-[11px]" style="color: var(--brand-icon); font-family: ui-monospace, SFMono-Regular, monospace;">
                        {{ $gps }}
                    </span>
                </div>
                @endif
                @if(!$erf && !$extentM2 && !$gps)
                    <div class="text-[11px] italic" style="color: var(--text-muted);">
                        Generate a CMA presentation to populate erf, GPS, and extent.
                    </div>
                @endif
            </div>
        </div>

        {{-- Column 2: Evaluations --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">Evaluations</div>
            <div class="space-y-2 text-xs">
                @if($cmaSnapshot['municipal_value'])
                    <div>
                        <div class="text-[10px]" style="color: var(--text-muted);">Municipal{{ $cmaSnapshot['municipal_valuation_year'] ? ' (' . $cmaSnapshot['municipal_valuation_year'] . ')' : '' }}</div>
                        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $cmaSnapshot['municipal_value'] }}</div>
                    </div>
                @endif
                @if($cmaSnapshot['cma_middle'])
                    <div>
                        <div class="text-[10px]" style="color: var(--text-muted);">CMA mid-range</div>
                        <div class="text-sm font-semibold" style="color: #00d4aa;">{{ $cmaSnapshot['cma_middle'] }}</div>
                        @if($cmaSnapshot['cma_lower'] && $cmaSnapshot['cma_upper'])
                            <div class="text-[10px]" style="color: var(--text-secondary);">
                                {{ $cmaSnapshot['cma_lower'] }} — {{ $cmaSnapshot['cma_upper'] }}
                            </div>
                        @endif
                    </div>
                @endif
                @if($cmaSnapshot['indexed_value'])
                    <div>
                        <div class="text-[10px]" style="color: var(--text-muted);">Indexed value{{ $cmaSnapshot['cagr_pct'] ? ' · CAGR ' . $cmaSnapshot['cagr_pct'] : '' }}</div>
                        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $cmaSnapshot['indexed_value'] }}</div>
                    </div>
                @endif
                @if($cmaSnapshot['last_sale_price'] || $cmaSnapshot['last_sale_date'])
                    <div class="pt-1.5" style="border-top: 1px solid var(--border);">
                        <div class="text-[10px]" style="color: var(--text-muted);">Last sale{{ $cmaSnapshot['last_sale_date'] ? ' · ' . $cmaSnapshot['last_sale_date'] : '' }}</div>
                        <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $cmaSnapshot['last_sale_price'] ?? '—' }}</div>
                    </div>
                @endif
                @if(!$cmaSnapshot['municipal_value'] && !$cmaSnapshot['cma_middle'] && !$cmaSnapshot['indexed_value'] && !$cmaSnapshot['last_sale_price'])
                    <div class="text-[11px] italic" style="color: var(--text-muted);">
                        No municipal, CMA, or historical sale data extracted yet.
                    </div>
                @endif
            </div>
        </div>

        {{-- Column 3: Source attribution --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">Sources</div>
            <div class="space-y-2 text-xs">
                @if($cmaSnapshot['has_data'])
                    <div>
                        <div class="text-[10px]" style="color: var(--text-muted);">Latest CMA analysis</div>
                        @if($cmaSnapshot['source_presentation_id'])
                            <a href="{{ \Illuminate\Support\Facades\Route::has('presentations.show') ? route('presentations.show', $cmaSnapshot['source_presentation_id']) : '#' }}"
                               target="_blank"
                               class="text-xs font-medium no-underline truncate block"
                               style="color: var(--brand-icon);">
                                {{ $cmaSnapshot['source_presentation_title'] ?: 'Untitled presentation' }} ↗
                            </a>
                        @endif
                        @if($cmaSnapshot['extracted_at'])
                            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                                {{ \Carbon\Carbon::parse($cmaSnapshot['extracted_at'])->diffForHumans() }}
                                @if($cmaSnapshot['extracted_by_name']) · {{ $cmaSnapshot['extracted_by_name'] }} @endif
                            </div>
                        @endif
                    </div>
                @endif
                <div class="pt-1.5" style="@if($cmaSnapshot['has_data']) border-top: 1px solid var(--border); @endif">
                    <div class="text-[10px]" style="color: var(--text-muted);">Portal listings matched</div>
                    <div class="text-base font-semibold" style="color: var(--text-primary);">{{ $matchedProspects->count() }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Matched Portal Listings (prospecting bridge) --}}
    @if($matchedProspects->isNotEmpty())
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-center gap-2 mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" style="color: var(--brand-icon);">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                </svg>
                <h4 class="text-sm font-semibold" style="color: var(--text-primary);">Matched Portal Listings</h4>
                <span class="text-[10px] ml-1" style="color: var(--text-muted);">via prospecting_listings.matched_property_id</span>
            </div>
            <div class="space-y-1.5">
                @foreach($matchedProspects as $pl)
                    @php
                        $isP24 = strtolower((string) $pl->portal_source) === 'p24';
                        $badgeBg = $isP24 ? '#1e40af' : '#059669';
                    @endphp
                    <div class="rounded px-3 py-2 flex items-center justify-between gap-3" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded text-white" style="background: {{ $badgeBg }};">
                                    {{ strtoupper((string) $pl->portal_source) }}
                                </span>
                                <span class="text-xs font-medium" style="color: var(--text-primary);">Ref {{ $pl->portal_ref }}</span>
                                @if(!$pl->is_active)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded" style="background: rgba(107,114,128,0.15); color: var(--text-muted);">inactive</span>
                                @endif
                                @if($pl->price)
                                    <span class="text-xs font-semibold" style="color: var(--brand-default);">R {{ number_format((float) $pl->price, 0, '.', ',') }}</span>
                                @endif
                            </div>
                            <div class="text-[11px] mt-0.5 truncate" style="color: var(--text-secondary);">
                                {{ $pl->address ?: 'Address not available' }}{{ $pl->suburb ? ', ' . $pl->suburb : '' }}
                                @if($pl->bedrooms) · {{ $pl->bedrooms }} bed @endif
                                @if($pl->bathrooms) · {{ $pl->bathrooms }} bath @endif
                                @if($pl->property_type) · {{ $pl->property_type }} @endif
                            </div>
                            <div class="text-[10px] mt-0.5" style="color: var(--text-muted);">
                                Discovered {{ \Carbon\Carbon::parse($pl->first_seen_at)->diffForHumans() }}
                                @if($pl->agent_name) · listed by {{ $pl->agent_name }}@if($pl->agency_name) ({{ $pl->agency_name }})@endif @endif
                                @if($pl->price_changed_at) · price last changed {{ \Carbon\Carbon::parse($pl->price_changed_at)->diffForHumans() }} @endif
                            </div>
                        </div>
                        @if($pl->portal_url)
                            <a href="{{ $pl->portal_url }}" target="_blank" rel="noopener"
                               class="text-[10px] font-medium px-2 py-1 rounded no-underline flex-shrink-0"
                               style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                                Open ↗
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
