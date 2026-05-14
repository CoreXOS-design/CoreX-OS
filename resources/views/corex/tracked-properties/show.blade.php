@extends('layouts.corex')

@section('corex-content')
<div class="p-6 max-w-7xl mx-auto">

    {{-- Header --}}
    <div class="mb-4 p-4 rounded-md" style="background: var(--brand-default); color: #fff;">
        <nav class="text-xs mb-1" style="color: rgba(255,255,255,0.75);">
            <a href="{{ route('corex.tracked-properties.index') }}" class="no-underline" style="color: rgba(255,255,255,0.85);">
                ← All tracked properties
            </a>
        </nav>
        <h1 class="text-xl font-semibold leading-tight">{{ $tp->displayAddress() }}</h1>
        <div class="text-sm mt-1" style="color: rgba(255,255,255,0.75);">
            @if($tp->isPromoted())
                Promoted to agency stock
            @else
                Tracked property — not yet mandated
            @endif
            · external_id: <code class="text-[11px]" style="background: rgba(255,255,255,0.10); padding: 1px 4px; border-radius: 2px;">{{ $tp->external_id }}</code>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('error'))
        <div class="mb-3 rounded-md px-4 py-2 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson, #dc2626) 12%, transparent); color: var(--ds-crimson, #dc2626); border: 1px solid var(--ds-crimson, #dc2626);">
            {{ session('error') }}
        </div>
    @endif

    {{-- Snapshot cards --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

        {{-- Identity card --}}
        <div class="p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">Identity</div>
            <div class="text-sm space-y-1.5" style="color: var(--text-primary);">
                <div>
                    <span class="text-[11px]" style="color: var(--text-muted);">Address</span><br>
                    {{ $tp->displayAddress() }}
                </div>
                @if($tp->suburb)
                    <div>
                        <span class="text-[11px]" style="color: var(--text-muted);">Suburb</span><br>
                        {{ $tp->suburb }}{{ $tp->town ? ', ' . $tp->town : '' }}
                    </div>
                @endif
                @if($tp->erf_number)
                    <div>
                        <span class="text-[11px]" style="color: var(--text-muted);">Erf</span><br>
                        {{ $tp->erf_number }}
                    </div>
                @endif
                @if($tp->title_deed_number)
                    <div>
                        <span class="text-[11px]" style="color: var(--text-muted);">Title deed</span><br>
                        <code class="text-[12px]">{{ $tp->title_deed_number }}</code>
                    </div>
                @endif
                @if($tp->cma_gps_lat && $tp->cma_gps_lng)
                    <div class="text-[11px] pt-1 mt-1" style="border-top: 1px solid var(--border);">
                        <span style="color: var(--text-muted);">CMA GPS</span><br>
                        <code style="color: var(--brand-button); font-family: ui-monospace, SFMono-Regular, monospace;">
                            {{ number_format((float) $tp->cma_gps_lat, 6) }}, {{ number_format((float) $tp->cma_gps_lng, 6) }}
                        </code>
                    </div>
                @endif
            </div>
        </div>

        {{-- Valuation card --}}
        <div class="p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">Valuation</div>
            <div class="text-sm space-y-2" style="color: var(--text-primary);">
                @if($tp->municipal_valuation)
                    <div>
                        <div class="text-[11px]" style="color: var(--text-muted);">
                            Municipal{{ $tp->municipal_valuation_year ? ' (' . $tp->municipal_valuation_year . ')' : '' }}
                        </div>
                        <div class="text-base font-semibold">R {{ number_format((float) $tp->municipal_valuation, 0, '.', ',') }}</div>
                    </div>
                @endif
                @if($tp->last_known_asking_price)
                    <div>
                        <div class="text-[11px]" style="color: var(--text-muted);">Last known asking</div>
                        <div class="text-sm">R {{ number_format((float) $tp->last_known_asking_price, 0, '.', ',') }}</div>
                    </div>
                @endif
                @if($tp->last_known_sold_price)
                    <div class="pt-1" style="border-top: 1px solid var(--border);">
                        <div class="text-[11px]" style="color: var(--text-muted);">
                            Last sold{{ $tp->last_known_sold_date ? ' · ' . $tp->last_known_sold_date->format('j M Y') : '' }}
                        </div>
                        <div class="text-sm">R {{ number_format((float) $tp->last_known_sold_price, 0, '.', ',') }}</div>
                    </div>
                @endif
                @if(!$tp->municipal_valuation && !$tp->last_known_asking_price && !$tp->last_known_sold_price)
                    <div class="text-xs italic" style="color: var(--text-muted);">
                        No valuation data accumulated yet.
                    </div>
                @endif
            </div>
        </div>

        {{-- Status + Promote card --}}
        <div class="p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">Status</div>
            <div class="text-sm space-y-2" style="color: var(--text-primary);">
                @if($tp->isPromoted())
                    <div>
                        <div style="color: var(--ds-green, #10b981);" class="font-semibold">Promoted to agency stock</div>
                        <div class="text-[11px] mt-1" style="color: var(--text-muted);">
                            on {{ optional($tp->promoted_at)->format('j M Y') ?? '—' }}
                            @if($tp->promotedBy) · by {{ $tp->promotedBy->name }} @endif
                        </div>
                    </div>
                    <a href="{{ route('corex.properties.show', $tp->promoted_to_property_id) }}"
                       class="inline-block mt-2 px-3 py-1.5 text-xs font-medium rounded no-underline"
                       style="background: var(--ds-green, #10b981); color: #fff;">
                        Open in agency stock →
                    </a>
                @else
                    <div>
                        <div style="color: var(--brand-button);" class="font-semibold">Active — opportunity</div>
                        <div class="text-[11px] mt-1" style="color: var(--text-muted);">
                            Tracked but not mandated. Promote when HFC wins this mandate; the full source chain stays preserved here.
                        </div>
                    </div>
                    @permission('outreach.compose')
                        <form method="POST" action="{{ route('corex.tracked-properties.promote', $tp) }}" class="mt-2">
                            @csrf
                            <button type="submit"
                                    onclick="return confirm('Promote this Tracked Property to Agency Stock?\n\nA Property record will be created and linked to this TP. The full source attribution is preserved here.');"
                                    class="px-3 py-1.5 text-xs font-medium rounded"
                                    style="background: var(--brand-button); color: #fff;">
                                Promote to Stock
                            </button>
                        </form>
                    @endpermission
                @endif

                @if($tp->last_enriched_at)
                    <div class="text-[11px] pt-2 mt-2" style="border-top: 1px solid var(--border); color: var(--text-muted);">
                        Last enriched {{ $tp->last_enriched_at->diffForHumans() }}
                        @if($tp->last_enrichment_source) by {{ strtoupper($tp->last_enrichment_source) }} @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Source chain (the audit trail) --}}
    <div class="mb-6 p-4 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">
                Source Chain ({{ $intelligence['source_chain_count'] }})
            </h2>
            <span class="text-[11px]" style="color: var(--text-muted);">
                Every contribution that built this record · most recent first
            </span>
        </div>

        @if(empty($intelligence['source_chain']))
            <div class="text-sm italic" style="color: var(--text-muted);">No source chain entries.</div>
        @else
            <div class="space-y-2">
                @foreach(collect($intelligence['source_chain'])->reverse() as $entry)
                    <div class="flex items-start gap-3 p-2.5 rounded-md"
                         style="background: var(--surface-2); border: 1px solid var(--border);">
                        <span class="inline-flex items-center px-2 py-0.5 text-[10px] font-bold rounded flex-shrink-0"
                              style="background: var(--brand-default); color: #fff;">
                            {{ strtoupper($entry['type'] ?? '?') }}
                        </span>
                        <div class="flex-1 min-w-0 text-xs" style="color: var(--text-secondary);">
                            <div>
                                @if(!empty($entry['ref']))
                                    <span style="color: var(--text-muted);">Ref:</span>
                                    <code class="text-[11px]">{{ $entry['ref'] }}</code>
                                @endif
                                @if(!empty($entry['date']))
                                    <span class="ml-2" style="color: var(--text-muted);">·</span>
                                    {{ \Carbon\Carbon::parse($entry['date'])->format('j M Y H:i') }}
                                @endif
                            </div>
                            @if(!empty($entry['fields_contributed']) && is_array($entry['fields_contributed']))
                                <div class="mt-1 text-[11px]">
                                    <span style="color: var(--text-muted);">Contributed:</span>
                                    <span style="color: var(--brand-button);">{{ implode(', ', $entry['fields_contributed']) }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Linked portal listings (cross-source identity) --}}
    @if($intelligence['linked_listings']->isNotEmpty())
        <div class="mb-6">
            <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">
                Linked portal listings ({{ $intelligence['linked_listings']->count() }})
            </h2>
            <div class="space-y-2">
                @foreach($intelligence['linked_listings'] as $listing)
                    @php $isP24 = strtolower((string) $listing->portal_source) === 'p24'; @endphp
                    <div class="p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="flex items-center justify-between flex-wrap gap-2 mb-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded text-white"
                                      style="background: {{ $isP24 ? '#1e40af' : '#059669' }};">
                                    {{ strtoupper((string) $listing->portal_source) }}
                                </span>
                                <span class="text-sm font-medium" style="color: var(--text-primary);">
                                    Ref {{ $listing->portal_ref }}
                                </span>
                                @if(!$listing->is_active)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded"
                                          style="background: rgba(107,114,128,0.15); color: var(--text-muted);">inactive</span>
                                @endif
                            </div>
                            @if($listing->portal_url)
                                <a href="{{ $listing->portal_url }}" target="_blank" rel="noopener"
                                   class="text-[11px] no-underline"
                                   style="color: var(--brand-icon);">Open on portal ↗</a>
                            @endif
                        </div>
                        <div class="text-xs" style="color: var(--text-secondary);">
                            {{ $listing->address ?: 'Address not available' }}{{ $listing->suburb ? ', ' . $listing->suburb : '' }}
                            @if($listing->price) · R {{ number_format((float) $listing->price, 0, '.', ',') }} @endif
                            @if($listing->bedrooms) · {{ $listing->bedrooms }} bed @endif
                            @if($listing->bathrooms) · {{ $listing->bathrooms }} bath @endif
                            @if($listing->property_type) · {{ $listing->property_type }} @endif
                        </div>
                        @if($listing->first_seen_at)
                            <div class="text-[11px] mt-1" style="color: var(--text-muted);">
                                First seen {{ \Carbon\Carbon::parse($listing->first_seen_at)->diffForHumans() }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- External references grouped by source --}}
    @if($intelligence['external_refs_by_source']->isNotEmpty())
        <div class="mb-6">
            <h2 class="text-sm font-semibold mb-2" style="color: var(--text-primary);">
                External references
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($intelligence['external_refs_by_source'] as $source => $refs)
                    <div class="p-3 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
                        <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: var(--text-muted);">
                            {{ strtoupper($source) }} ({{ $refs->count() }})
                        </div>
                        <div class="space-y-1">
                            @foreach($refs as $ref)
                                <div class="text-[11px]" style="color: var(--text-secondary);">
                                    <code class="text-[11px]">{{ $ref->source_ref }}</code>
                                    · first seen {{ optional($ref->first_seen_at)->diffForHumans() ?? 'unknown' }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
