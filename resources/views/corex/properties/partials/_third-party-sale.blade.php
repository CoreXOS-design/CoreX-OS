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

    Both the enrich form and the capture form open as a MODAL rather than an
    inline disclosure/dropdown — the inline version rendered the shared fields
    partial at text-[10px]/text-xs, which was unreadable in practice. A modal
    gives the same fields room to breathe at normal (text-sm) size, using the
    exact dialog pattern already established on this page (the asking-price
    modal above, x-teleport + fixed inset-0 + backdrop).

    Auto-opens on a failed submit (validation error or flashed old input for
    one of these fields) so a rejected save is never silently lost behind a
    closed modal.

    Expects: $property, $thirdPartySale (PropertyThirdPartySale|null), $canEdit
--}}

@php
    $tpsReasons = \App\Models\PropertyThirdPartySale::LOSS_REASONS;
    $tpsFieldNames = ['sold_by_agency', 'sold_price', 'sold_date', 'loss_reason', 'notes'];
    $tpsReopenOnError = collect($tpsFieldNames)->contains(fn ($f) => $errors->has($f) || old($f) !== null);
@endphp

@if($thirdPartySale)
    {{-- ── LOSS BANNER ──────────────────────────────────────────────────────
         STANDARDS "No Silent Locks": say what the state is AND offer the way
         out. The agent can enrich the detail or put the listing back on market. --}}
    <div class="rounded-md border px-4 py-3 text-sm"
         style="background:color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent);
                border-color:color-mix(in srgb, var(--ds-amber, #d97706) 35%, transparent);"
         x-data="{ tpsEditOpen: {{ $tpsReopenOnError ? 'true' : 'false' }} }">
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
                        <button type="button" @click="tpsEditOpen = true"
                                class="text-xs font-medium px-2 py-1 rounded"
                                style="color:var(--ds-amber, #d97706); background:color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent);">
                            {{ $thirdPartySale->sold_price ? 'Edit details' : 'Add details' }}
                        </button>

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

                    {{-- Enrichment modal. Posts only the detail fields; the service
                         writes only keys that are PRESENT, so nothing it does not
                         render can be blanked. --}}
                    <template x-teleport="body">
                        <div x-show="tpsEditOpen" x-cloak
                             class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                             x-transition.opacity>
                            <div class="absolute inset-0" style="background:rgba(0,0,0,0.45);" @click="tpsEditOpen = false"></div>
                            <div class="relative rounded-md w-full max-w-md p-5 shadow-xl max-h-[90vh] overflow-y-auto"
                                 style="background:var(--surface); border:1px solid var(--border);"
                                 @click.stop>
                                <h3 class="text-base font-bold mb-1" style="color:var(--text-primary);">Loss details</h3>
                                <p class="text-xs mb-3" style="color:var(--text-secondary);">
                                    Record what you know about the sale — every field stays optional.
                                </p>
                                <form method="POST" action="{{ route('corex.properties.third-party-sale.update', $property) }}"
                                      class="space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    @include('corex.properties.partials._third-party-sale-fields', [
                                        'record'    => $thirdPartySale,
                                        'tpsReasons' => $tpsReasons,
                                    ])
                                    <div class="mt-4 flex items-center justify-end gap-2">
                                        <button type="button" @click="tpsEditOpen = false"
                                                class="px-3 py-1.5 text-sm font-medium rounded-md"
                                                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                                            Cancel
                                        </button>
                                        <button type="submit" class="px-3 py-1.5 text-sm font-semibold rounded-md text-white"
                                                style="background:var(--ds-amber, #d97706);">
                                            Save details
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </template>
                @endif
            </div>
        </div>
    </div>

@elseif($canEdit && ! $property->isConcluded())
    {{-- ── CAPTURE ACTION ───────────────────────────────────────────────────
         Sibling of "Mark as Sold", amber against its red so the two are never
         mis-clicked. Hidden on an already-concluded listing (no dead buttons —
         STANDARDS "a blocked action is hidden"). --}}
    <div class="inline-block" x-data="{ tpsCaptureOpen: {{ $tpsReopenOnError ? 'true' : 'false' }} }">
        <button type="button" @click="tpsCaptureOpen = true"
                class="text-xs font-medium px-2 py-1 rounded"
                style="color: var(--ds-amber, #d97706); background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent);"
                title="Record that another agency sold this property. It leaves the market and is never counted as an HFC sale.">
            Sold by 3rd Party
        </button>

        <template x-teleport="body">
            <div x-show="tpsCaptureOpen" x-cloak
                 class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
                 x-transition.opacity>
                <div class="absolute inset-0" style="background:rgba(0,0,0,0.45);" @click="tpsCaptureOpen = false"></div>
                <div class="relative rounded-md w-full max-w-md p-5 shadow-xl max-h-[90vh] overflow-y-auto"
                     style="background:var(--surface); border:1px solid var(--border);"
                     @click.stop>
                    <h3 class="text-base font-bold mb-1" style="color:var(--text-primary);">Sold by another agency</h3>
                    <p class="text-xs mb-3" style="color:var(--text-secondary);">
                        Everything below is optional — record what you know. The listing comes
                        off Property24, Private Property and your website, and is never counted
                        as an HFC sale.
                    </p>
                    <form method="POST" action="{{ route('corex.properties.third-party-sale.store', $property) }}"
                          class="space-y-3">
                        @csrf
                        @include('corex.properties.partials._third-party-sale-fields', [
                            'record'    => null,
                            'tpsReasons' => $tpsReasons,
                        ])
                        <div class="mt-4 flex items-center justify-end gap-2">
                            <button type="button" @click="tpsCaptureOpen = false"
                                    class="px-3 py-1.5 text-sm font-medium rounded-md"
                                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                                Cancel
                            </button>
                            <button type="submit" class="px-3 py-1.5 text-sm font-semibold rounded-md text-white"
                                    style="background: var(--ds-amber, #d97706);">
                                Confirm — sold by another agency
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    </div>
@endif
