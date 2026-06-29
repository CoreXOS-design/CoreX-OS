{{--
    Tier-grouped buyer-match drill-down panel.

    Inputs:
      $listing    — ProspectingListing model
      $buyers     — Collection<stdClass> from BuyerMatchTierService::buyersForListing()
                    each decorated with `tier` and `display_name`
      $tierConfig — array with strong/mid/weak labels + min scores
--}}

<div class="p-4">

    {{-- Listing context header --}}
    <div class="mb-4 pb-4" style="border-bottom: 1px solid var(--border);">
        <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
            Listing
        </div>
        <div class="font-medium text-sm" style="color: var(--text-primary);">
            {{ $listing->address ?: 'Address not available' }}{{ $listing->suburb ? ', ' . $listing->suburb : '' }}
        </div>
        <div class="text-xs mt-1" style="color: var(--text-secondary);">
            @if($listing->price) R {{ number_format((float) $listing->price, 0, '.', ',') }} @endif
            @if($listing->bedrooms) · {{ $listing->bedrooms }} bed @endif
            @if($listing->property_type) · {{ $listing->property_type }} @endif
            @if($listing->portal_source) · {{ strtoupper((string) $listing->portal_source) }}/{{ $listing->portal_ref }} @endif
        </div>
        @if($listing->matched_property_id)
            <a href="{{ route('corex.properties.show', $listing->matched_property_id) }}" target="_blank"
               class="inline-block text-[10px] font-semibold mt-2 px-1.5 py-0.5 rounded no-underline"
               style="background: var(--brand-default); color: #fff;">
                IN STOCK · view property ↗
            </a>
        @endif
    </div>

    @php
        $byTier = $buyers->groupBy('tier');
        $tierOrder = ['strong', 'mid', 'weak'];
        $tierMeta = [
            'strong' => ['icon' => '🟢', 'label' => $tierConfig['strong_label'] ?? 'Strong', 'color' => 'var(--ds-green, #10b981)'],
            'mid'    => ['icon' => '🟡', 'label' => $tierConfig['mid_label']    ?? 'Mid',    'color' => 'var(--ds-amber, #f59e0b)'],
            'weak'   => ['icon' => '⚪', 'label' => $tierConfig['weak_label']   ?? 'Weak',   'color' => 'var(--text-muted)'],
        ];
        $anyShown = false;
    @endphp

    @foreach($tierOrder as $tierKey)
        @if($byTier->has($tierKey))
            @php $anyShown = true; @endphp
            <div class="mb-5">
                <div class="text-[10px] uppercase tracking-wider font-semibold mb-2" style="color: {{ $tierMeta[$tierKey]['color'] }};">
                    {{ $tierMeta[$tierKey]['icon'] }} {{ $tierMeta[$tierKey]['label'] }} — {{ $byTier->get($tierKey)->count() }}
                </div>

                <div class="space-y-2">
                    @foreach($byTier->get($tierKey) as $buyer)
                        @php
                            $suburbs = null;
                            if ($buyer->wishlist_suburbs) {
                                $suburbs = is_string($buyer->wishlist_suburbs)
                                    ? json_decode($buyer->wishlist_suburbs, true)
                                    : $buyer->wishlist_suburbs;
                            }
                            // Normalise SA phone for WhatsApp deep-link.
                            $waPhone = null;
                            if (!empty($buyer->phone) && empty($buyer->messaging_opt_out_at)) {
                                $clean = preg_replace('/\D/', '', (string) $buyer->phone);
                                if (str_starts_with($clean, '0')) $clean = '27' . substr($clean, 1);
                                if (strlen($clean) >= 10) $waPhone = $clean;
                            }
                        @endphp

                        <div class="p-3 rounded-md" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <div class="font-medium text-sm truncate" style="color: var(--text-primary);">
                                    {{ $buyer->display_name ?: 'Unknown buyer' }}
                                </div>
                                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded flex-shrink-0"
                                      style="background: color-mix(in srgb, {{ $tierMeta[$tierKey]['color'] }} 18%, transparent); color: {{ $tierMeta[$tierKey]['color'] }};">
                                    {{ $buyer->score }}%
                                </span>
                            </div>

                            <div class="text-xs space-y-0.5" style="color: var(--text-secondary);">
                                @if($buyer->phone) <div>📞 {{ $buyer->phone }}</div> @endif
                                @if($buyer->email) <div class="truncate">✉️ {{ $buyer->email }}</div> @endif
                                @if($buyer->messaging_opt_out_at)
                                    <div style="color: var(--ds-crimson, #dc2626);">⚠ Opted out of messaging</div>
                                @endif

                                @if($buyer->wishlist_id)
                                    <div class="mt-1 pt-1" style="border-top: 1px solid var(--border);">
                                        <strong class="text-[11px]">Wants:</strong>
                                        @if($buyer->price_min || $buyer->price_max)
                                            R {{ number_format((float) ($buyer->price_min ?? 0), 0, '.', ',') }}–R {{ number_format((float) ($buyer->price_max ?? 0), 0, '.', ',') }}
                                        @endif
                                        @if($buyer->beds_min) · {{ $buyer->beds_min }}+ beds @endif
                                        @if(is_array($suburbs) && count($suburbs)) · {{ implode(', ', $suburbs) }} @endif
                                    </div>
                                @endif

                                @if($buyer->last_engaged_at)
                                    <div class="text-[10px]" style="color: var(--text-muted);">
                                        Last engaged {{ \Carbon\Carbon::parse($buyer->last_engaged_at)->diffForHumans() }}
                                    </div>
                                @endif
                            </div>

                            <div class="mt-2 flex items-center gap-1 flex-wrap">
                                <a href="/corex/contacts/{{ $buyer->contact_id }}" target="_blank"
                                   class="text-[10px] font-medium px-2 py-1 rounded no-underline"
                                   style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">
                                    View contact ↗
                                </a>
                                @if($buyer->phone)
                                    <a href="tel:{{ $buyer->phone }}"
                                       class="text-[10px] font-medium px-2 py-1 rounded no-underline"
                                       style="background: var(--brand-button); color: #fff;">
                                        📞 Call
                                    </a>
                                @endif
                                @if($waPhone)
                                    @if($outreachWindow['allowed'] ?? true)
                                    <a href="https://wa.me/{{ $waPhone }}" target="_blank" rel="noopener"
                                       class="text-[10px] font-medium px-2 py-1 rounded no-underline"
                                       style="background: #25D366; color: #fff;">
                                        💬 WhatsApp
                                    </a>
                                    @else
                                    {{-- AT-117 §4a — outside the outreach send-window. --}}
                                    <span class="text-[10px] font-medium px-2 py-1 rounded"
                                          style="background: #9ca3af; color: #fff; cursor: not-allowed;"
                                          title="{{ $outreachWindow['message'] ?? '' }}">💬 Closed</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    @if(!$anyShown)
        <div class="p-8 text-center text-sm" style="color: var(--text-muted);">
            No buyer matches above the configured weak-tier threshold.
        </div>
    @endif
</div>
