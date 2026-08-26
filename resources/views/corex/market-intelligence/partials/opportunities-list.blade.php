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
                $commentCount = (int) ($tp->comments_count ?? 0);
            @endphp
            {{-- MIC property row comments (.ai/specs/mic-property-row-comments.md)
                 fast-follow — the known obstacle: this row used to be one
                 wrapping <a href>, which a nested <button @click.stop> can't
                 reliably escape (a native anchor's default navigation is
                 governed by preventDefault, not stopPropagation, so .stop
                 alone — sufficient on the Work tab's Alpine-dispatch row —
                 is not reliable here). Fixed the same way the Work tab's OWN
                 row already solves "clickable row + inner escape-hatch
                 button": swapped the anchor for a role="button" element with
                 its own click/keydown handlers (same pattern as
                 _listing-row.blade.php's <article>), so the comment chip's
                 @click.stop guard works identically on both tabs. --}}
            <div role="button" tabindex="0"
                 @click="window.location.href = '{{ $detailUrl }}'"
                 @keydown.enter.prevent="window.location.href = '{{ $detailUrl }}'"
                 @keydown.space.prevent="window.location.href = '{{ $detailUrl }}'"
                 class="block transition-colors hover:bg-[color:var(--surface-2)]"
                 style="text-decoration: none; color: inherit; border-bottom: 1px solid var(--border); cursor: pointer;">
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
                        {{-- Comment chip — same markup/style as the Work tab's
                             buyer-match chip (tagOutline: transparent bg,
                             muted text, 1px border, pill radius), always
                             rendered (never hidden at zero), numeric badge
                             only when > 0. .ai/specs/mic-property-row-comments.md --}}
                        @if($canViewComments ?? false)
                            <button type="button"
                                    @click.stop="openCommentsModal({{ $tp->id }})"
                                    style="display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; font-size: 0.625rem; font-weight: 600; border-radius: 999px; line-height: 1.4; white-space: nowrap; background: transparent; color: var(--text-secondary); border: 1px solid var(--border); cursor: pointer; margin-top: 4px;"
                                    title="{{ $commentCount > 0 ? ($commentCount . ' comment' . ($commentCount === 1 ? '' : 's') . ' on this property — click to view and add.') : 'No comments yet on this property — click to add the first one for other agents.' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                                </svg><span data-tp-comment-count="{{ $tp->id }}" style="font-weight: 700; margin-left: {{ $commentCount > 0 ? '3px' : '0' }};">{{ $commentCount > 0 ? $commentCount : '' }}</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div style="padding: 12px 4px;">
        {{ $tps->links() }}
    </div>
@endif
