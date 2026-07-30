{{--
    AT-350 — "Sold by 3rd Party" capture + loss banner.
    Spec: .ai/specs/property-sold-by-third-party.md §6
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — tokens only, var(--token, #fallback).

    Two states, never both:
      OPEN RECORD  → the loss banner (what we know + how to change it)
      NO RECORD    → the capture action, shown only on a listing still on the market

    Every field is optional by design (spec D4): we frequently only hear THAT it
    sold. A required field would push the agent back to "Withdrawn" and we would
    lose the intel entirely.

    Expects: $property, $thirdPartySale (PropertyThirdPartySale|null), $canEdit
--}}

@php
    $tpsReasons = \App\Models\PropertyThirdPartySale::LOSS_REASONS;
@endphp

@if($thirdPartySale)
    {{-- ── LOSS BANNER ──────────────────────────────────────────────────────
         STANDARDS "No Silent Locks": say what the state is AND offer the way
         out. The agent can enrich the detail or put the listing back on market. --}}
    <div class="rounded-md border px-4 py-3 text-sm"
         style="background:color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent);
                border-color:color-mix(in srgb, var(--ds-amber, #d97706) 35%, transparent);"
         x-data="{ editing: false }">
        <div class="flex items-start gap-2.5">
            <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:var(--ds-amber, #d97706);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="font-semibold" style="color:var(--ds-amber, #d97706);">
                    Sold by {{ $thirdPartySale->sold_by_agency ?: 'another agency' }}
                    @if($thirdPartySale->sold_date)
                        on {{ $thirdPartySale->sold_date->format('j M Y') }}
                    @endif
                    @if($thirdPartySale->sold_price)
                        for R {{ number_format((float) $thirdPartySale->sold_price, 0, '.', ',') }}
                    @endif
                </p>

                <p class="mt-1 text-xs" style="color:var(--text-secondary);">
                    @if(! $thirdPartySale->sold_price && ! $thirdPartySale->sold_date)
                        {{-- The lazy-but-valid shortcut landed here. Never scold the
                             agent for it — just offer the next step. --}}
                        Details not captured. Adding the price and date turns this into a
                        comparable sale for CMAs and suburb intelligence.
                    @else
                        @php $gap = $thirdPartySale->priceGap(); @endphp
                        @if($gap !== null && abs($gap) >= 1)
                            Our asking price was
                            <strong>R {{ number_format(abs($gap), 0, '.', ',') }}</strong>
                            {{ $gap > 0 ? 'above' : 'below' }} what they achieved.
                        @endif
                        @if($thirdPartySale->days_on_market !== null)
                            On our books {{ $thirdPartySale->days_on_market }} days.
                        @endif
                    @endif
                </p>

                @if($thirdPartySale->lossReasonLabel())
                    <p class="mt-1 text-xs" style="color:var(--text-muted);">
                        <span class="font-medium">Why we lost it:</span> {{ $thirdPartySale->lossReasonLabel() }}
                    </p>
                @endif
                @if($thirdPartySale->notes)
                    <p class="mt-1 text-xs" style="color:var(--text-muted);">{{ $thirdPartySale->notes }}</p>
                @endif
                <p class="mt-1 text-[0.6875rem]" style="color:var(--text-muted);">
                    Recorded {{ $thirdPartySale->recorded_at?->format('j M Y') }}
                    {{-- BUILD_STANDARD §4 — a deleted user must render, never crash. --}}
                    by {{ $thirdPartySale->recordedBy?->name ?? 'a user who has since been removed' }}.
                    Not counted as an HFC sale.
                </p>

                @if($canEdit)
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" @click="editing = !editing"
                                class="text-xs font-medium px-2 py-1 rounded"
                                style="color:var(--ds-amber, #d97706); background:color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent);"
                                x-text="editing ? 'Cancel' : '{{ $thirdPartySale->sold_price ? 'Edit details' : 'Add details' }}'"></button>

                        <form method="POST" action="{{ route('corex.properties.third-party-sale.revert', $property) }}"
                              onsubmit="return confirm('Put this listing back on the market? The loss record is kept for reporting.');">
                            @csrf
                            <button type="submit" class="text-xs font-medium px-2 py-1 rounded"
                                    style="color:var(--text-secondary); background:var(--surface-2); border:1px solid var(--border);"
                                    title="Return the listing to the market. The loss record is kept.">
                                Re-list
                            </button>
                        </form>
                    </div>

                    {{-- Enrichment form. Posts only the detail fields; the service
                         writes only keys that are PRESENT, so nothing it does not
                         render can be blanked. --}}
                    <form x-show="editing" x-cloak method="POST"
                          action="{{ route('corex.properties.third-party-sale.update', $property) }}"
                          class="mt-3 p-3 rounded space-y-2"
                          style="background:var(--surface-2); border:1px solid var(--border);">
                        @csrf
                        @method('PATCH')
                        @include('corex.properties.partials._third-party-sale-fields', [
                            'record'    => $thirdPartySale,
                            'tpsReasons' => $tpsReasons,
                        ])
                        <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white"
                                style="background:var(--ds-amber, #d97706);">Save details</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

@elseif($canEdit && ! $property->isConcluded())
    {{-- ── CAPTURE ACTION ───────────────────────────────────────────────────
         Sibling of "Mark as Sold", amber against its red so the two are never
         mis-clicked. Hidden on an already-concluded listing (no dead buttons —
         STANDARDS "a blocked action is hidden"). --}}
    <details class="inline">
        <summary class="text-xs font-medium cursor-pointer px-2 py-1 rounded"
                 style="color: var(--ds-amber, #d97706); background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent);"
                 title="Record that another agency sold this property. It leaves the market and is never counted as an HFC sale.">
            Sold by 3rd Party
        </summary>
        <form method="POST" action="{{ route('corex.properties.third-party-sale.store', $property) }}"
              class="mt-2 p-3 rounded space-y-2"
              style="background: var(--surface-2); border: 1px solid var(--border); min-width:20rem;">
            @csrf
            <p class="text-[0.6875rem]" style="color:var(--text-muted);">
                Everything below is optional — record what you know. The listing comes
                off Property24, Private Property and your website, and is never counted
                as an HFC sale.
            </p>
            @include('corex.properties.partials._third-party-sale-fields', [
                'record'    => null,
                'tpsReasons' => $tpsReasons,
            ])
            <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white"
                    style="background: var(--ds-amber, #d97706);">
                Confirm — sold by another agency
            </button>
        </form>
    </details>
@endif
