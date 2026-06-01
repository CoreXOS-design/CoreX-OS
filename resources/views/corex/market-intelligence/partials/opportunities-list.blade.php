{{--
    MIC Phase D4 — Opportunities tab paginated row list (spec §5.4.1).
    Address-aware: missing-address TPs surface a click-to-add affordance.
    Sort defaults to strong_match_count DESC so high-signal TPs land at top.
--}}
@if($tps->isEmpty())
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
            </svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No matching properties</h3>
        <p class="text-sm" style="color: var(--text-muted);">
            No tracked properties match these filters. Try clearing some, or choose &ldquo;All&rdquo; to see everything.
        </p>
    </div>
@else
    <div style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
        @foreach($tps as $tp)
            @php
                $detailUrl = route('market-intelligence.opportunities.show', $tp);
                $primary = $tp->primaryAddress;
                $hasStreet = $primary && !empty($primary->street_name);
                $isPromoted = ($tp->status === \App\Models\Prospecting\TrackedProperty::STATUS_PROMOTED)
                              || $tp->promoted_to_property_id !== null;
                $sourceSet = $tp->externalRefs->pluck('source_type')->unique()->values();
                $strong = (int) ($tp->strong_match_count ?? 0);
            @endphp
            <a href="{{ $detailUrl }}"
               class="block transition-colors hover:bg-[color:var(--surface-2)]"
               style="text-decoration: none; color: inherit; border-bottom: 1px solid var(--border);">
                <div style="padding: 12px 16px; display: flex; align-items: flex-start; gap: 16px;">
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap;">
                            <span style="font-weight: 500; color: var(--text-primary); font-size: 0.875rem;">
                                @if($hasStreet)
                                    {{ $primary->formatted_address }}
                                @else
                                    <span style="color: var(--text-muted); font-style: italic;">Address pending</span>
                                    <span style="font-size: 0.6875rem; color: var(--brand-button); margin-left: 4px;">click to add →</span>
                                @endif
                            </span>
                            @if($isPromoted)
                                <span style="font-size: 0.625rem; font-weight: 600; padding: 2px 6px; white-space: nowrap;
                                             background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent);
                                             color: var(--ds-amber, #f59e0b); border-radius: 6px;">
                                    IN STOCK
                                </span>
                            @endif
                            @if($primary)
                                @php
                                    $confColor = match ($primary->confidence) {
                                        'verified', 'high' => 'var(--ds-green, #059669)',
                                        'medium'           => 'var(--ds-amber, #f59e0b)',
                                        default            => 'var(--text-muted)',
                                    };
                                @endphp
                                <span style="font-size: 0.625rem; font-weight: 600; padding: 2px 6px; white-space: nowrap;
                                             background: color-mix(in srgb, {{ $confColor }} 14%, transparent);
                                             color: {{ $confColor }}; border-radius: 6px;">
                                    {{ strtoupper($primary->confidence) }}
                                </span>
                            @endif
                        </div>
                        <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                            {{ $tp->suburb ?? '—' }}
                            @if($tp->property_type) · {{ $tp->property_type }} @endif
                            @if($tp->bedrooms) · {{ $tp->bedrooms }}-bed @endif
                            @if($tp->erf_number) · erf {{ $tp->erf_number }} @endif
                        </div>
                        <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">
                            @foreach($sourceSet as $src)
                                <span style="display: inline-block; margin-right: 8px;">{{ strtoupper(str_replace('_', ' ', $src)) }}</span>
                            @endforeach
                            @if($tp->last_enriched_at) · {{ $tp->last_enriched_at->diffForHumans() }} @endif
                        </div>
                    </div>
                    <div style="text-align: right; font-size: 0.8125rem; white-space: nowrap;">
                        @if($strong > 0)
                            <div style="font-weight: 600; color: var(--ds-green, #10b981);">
                                {{ $strong }} strong match{{ $strong === 1 ? '' : 'es' }}
                            </div>
                        @endif
                        @if(($tp->listing_count ?? 0) > 0)
                            <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">
                                {{ $tp->listing_count }} portal listing{{ $tp->listing_count === 1 ? '' : 's' }}
                            </div>
                        @endif
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    <div style="padding: 12px 4px;">
        {{ $tps->links() }}
    </div>
@endif
