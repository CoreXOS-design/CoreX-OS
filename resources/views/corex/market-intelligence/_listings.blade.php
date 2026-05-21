{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.3 — Market Intelligence listings wrapper.

    Renders the right-column listings section: result-count header strip,
    sort selector, the row stack, pagination. Carries the Alpine x-data
    that the row partials reference (openBuyerPanel, etc.) so the buyer
    side panel from F.1/F.2 still works on the new rows.

    Spec: build-f-market-intelligence-redesign-spec.md §8.3, §8.4.
--}}

@php
    $viewerId = auth()->id();
    $isManager = $isProspectingManager ?? false;
    $sortBy = request('sort', 'last_seen_at');
    $sortDir = request('dir', 'desc');

    // Preserve all other filters when changing sort.
    $sortUrl = function (string $newSort) use ($sortBy, $sortDir) {
        $dir = ($sortBy === $newSort && $sortDir === 'desc') ? 'asc' : 'desc';
        return route('market-intelligence.work', array_merge(
            request()->except(['sort', 'dir', 'page']),
            ['sort' => $newSort, 'dir' => $dir],
        ));
    };

    $sortOptions = [
        'last_seen_at'  => 'Last seen',
        'first_seen_at' => 'First seen',
        'price'         => 'Price',
        'suburb'        => 'Suburb',
        'buyer_matches' => 'Buyer demand',
    ];
@endphp

<div x-data="{
        buyerPanelOpen: false,
        buyerPanelLoading: false,
        buyerPanelHtml: '',
        async openBuyerPanel(listingId) {
            this.buyerPanelOpen = true;
            this.buyerPanelLoading = true;
            this.buyerPanelHtml = '';
            try {
                const r = await fetch(`/corex/market-intelligence/${listingId}/buyer-matches`, {
                    headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (!r.ok) throw new Error('Failed (' + r.status + ')');
                this.buyerPanelHtml = await r.text();
            } catch (e) {
                this.buyerPanelHtml = '<div style=\'padding:24px; color: var(--ds-crimson);\'>Failed to load buyers: ' + (e.message || 'error') + '</div>';
            } finally {
                this.buyerPanelLoading = false;
            }
        }
     }">

    {{-- F.8 — quiet buyer-tier legend strip. Decodes the green/amber dots
         on every row in one place so a first-day agent doesn't have to
         hover each dot to learn what it means. The "tune" link goes to
         the existing buyer-tier settings tab so a manager can adjust the
         score cutoffs that decide tier membership. --}}
    <div class="mi-buyer-legend"
         style="display: flex; align-items: center; gap: 10px; padding: 6px 0;
                font-size: 0.6875rem; color: var(--text-muted, #9ca3af);
                border-bottom: 1px solid var(--border, rgba(0,0,0,0.07)); margin-bottom: 8px;">
        <span>Buyer matches:</span>
        <span style="display: inline-flex; align-items: center; gap: 4px;"
              title="Strong-tier: buyer-match score ≥ 80 — high likelihood of conversion.">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--ds-green, #10b981); display: inline-block;"></span>
            strong-tier
        </span>
        <span style="display: inline-flex; align-items: center; gap: 4px;"
              title="Mid-tier: buyer-match score 50–79.">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--ds-amber, #f59e0b); display: inline-block;"></span>
            mid-tier
        </span>
        <span style="display: inline-flex; align-items: center; gap: 4px;"
              title="Weak-tier: buyer-match score under 50. Hidden from the row by default; visible in the buyer panel.">
            <span style="width: 7px; height: 7px; border-radius: 50%; background: var(--text-muted, #9ca3af); display: inline-block;"></span>
            weak-tier
        </span>
        <a href="{{ route('settings.prospecting.index') }}#buyer-match-tiers"
           style="margin-left: auto; color: var(--brand-icon, #0ea5e9); text-decoration: none;"
           title="Open Prospecting Setup → Buyer Match Tiers to adjust the score cutoffs that decide tier membership.">
            tune ↗
        </a>
    </div>

    {{-- Result-count + sort header strip --}}
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 4px 0 10px; gap: 12px; flex-wrap: wrap;">
        <div style="font-size: 0.8125rem; color: var(--text-secondary);">
            <strong style="color: var(--text-primary);">{{ number_format($listings->total()) }}</strong>
            {{ \Illuminate\Support\Str::plural('listing', $listings->total()) }}
            @if(request('action_preset'))
                · preset <em>{{ str_replace('_', ' ', request('action_preset')) }}</em>
            @endif
            @if(request('suburb'))
                · {{ request('suburb') }}
            @endif
            @if(request('search'))
                · matching "{{ request('search') }}"
            @endif
        </div>
        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.75rem; color: var(--text-muted);">
            <label for="mi-sort-select" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.625rem;">Sort</label>
            <select id="mi-sort-select"
                    onchange="(function(s){
                        var url = new URL(window.location.href);
                        var [k, d] = s.value.split('|');
                        url.searchParams.set('sort', k);
                        if (d) url.searchParams.set('dir', d); else url.searchParams.delete('dir');
                        url.searchParams.delete('page');
                        window.location.href = url.toString();
                    })(this)"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; cursor: pointer;">
                @foreach($sortOptions as $key => $label)
                    @php
                        $dirOptions = $key === 'price' ? [['desc','↓'], ['asc','↑']] : [[$sortDir === 'asc' ? 'asc' : 'desc', '']];
                    @endphp
                    @foreach($dirOptions as [$dir, $marker])
                    @php $selected = ($sortBy === $key) && ($sortDir === $dir || $marker === ''); @endphp
                    <option value="{{ $key }}|{{ $dir }}" {{ $selected ? 'selected' : '' }}>
                        {{ $label }}{{ $marker ? ' ' . $marker : '' }}
                    </option>
                    @endforeach
                @endforeach
            </select>
        </div>
    </div>

    {{-- Row stack --}}
    @if($listings->isEmpty())
        <div style="text-align: center; padding: 64px 24px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-muted); margin-bottom: 12px;">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <h3 style="font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">No listings match your filters</h3>
            <p style="font-size: 0.8125rem; color: var(--text-muted); margin-bottom: 12px;">Try widening your search or clearing some filters.</p>
            <a href="{{ route('market-intelligence.work') }}"
               style="display: inline-block; padding: 6px 14px; background: var(--brand-default); color: #fff; text-decoration: none; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                Clear filters
            </a>
        </div>
    @else
        <div class="mi-row-stack" style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px; overflow: hidden;">
            @foreach($listings as $listing)
                @php
                    // Build the per-listing state slice. The enricher returns
                    // its result keyed by category at the top level (pitches /
                    // claims / etc), so we map it down to the per-listing
                    // shape the row partial expects — same shape the
                    // controller assembles for SuggestedActionResolver.
                    $_lid = $listing->id;
                    $_state = [
                        'pitch'          => $listingStates['pitches'][$_lid]        ?? null,
                        'claim'          => $listingStates['claims'][$_lid]         ?? null,
                        'presentation'   => $listingStates['presentations'][$_lid]  ?? null,
                        'contacts'       => $listingStates['contact_counts'][$_lid] ?? 0,
                        'temp_lock'      => $listingStates['temp_locks'][$_lid]     ?? null,
                        'promoted'       => $listing->matched_property_id
                                            && isset($listingStates['promotions'][(int) $listing->matched_property_id]),
                        'needs_reminder' => $listingStates['claims'][$_lid]['needs_reminder'] ?? false,
                        'needs_bm_flag'  => $listingStates['claims'][$_lid]['needs_bm_flag']  ?? false,
                    ];
                @endphp
                @include('corex.market-intelligence._listing-row', [
                    'listing'   => $listing,
                    'state'     => $_state,
                    'tiers'     => $buyerTiers[$_lid] ?? ['strong'=>0,'mid'=>0,'weak'=>0,'total'=>0,'top_score'=>null],
                    'suggested' => $suggestedActions[$_lid] ?? null,
                    'isManager' => $isManager,
                    'viewerId'  => $viewerId,
                ])
            @endforeach
        </div>

        <div style="padding: 12px 0;">
            {{ $listings->withQueryString()->links() }}
        </div>
    @endif

    {{-- Buyer-match side panel (slides from right) — Alpine wired above.
         Same shape as the F.1/F.2 panel. F.4 will add the row-click slide-over;
         this one is unchanged. --}}
    <div x-show="buyerPanelOpen" x-cloak
         @click="buyerPanelOpen = false"
         class="fixed inset-0 z-40"
         style="background: rgba(0,0,0,0.45);"
         x-transition.opacity></div>

    <div x-show="buyerPanelOpen" x-cloak
         class="fixed inset-y-0 right-0 z-50 shadow-2xl overflow-y-auto"
         style="background: var(--surface); border-left: 1px solid var(--border); width: 100%; max-width: 480px;"
         x-transition:enter="transition transform duration-200"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition transform duration-200"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         @keydown.escape.window="buyerPanelOpen = false">
        <div class="sticky top-0 flex items-center justify-between px-4 py-3 z-10"
             style="background: var(--brand-default, #0b2a4a); color: #fff;">
            <h2 class="text-sm font-semibold">Buyer matches</h2>
            <button type="button" @click="buyerPanelOpen = false"
                    class="text-2xl leading-none px-2"
                    style="color: rgba(255,255,255,0.9); background: none; border: none; cursor: pointer;">×</button>
        </div>
        <template x-if="buyerPanelLoading">
            <div class="p-8 text-center text-sm" style="color: var(--text-muted);">Loading…</div>
        </template>
        <div x-show="!buyerPanelLoading" x-html="buyerPanelHtml"></div>
    </div>
</div>

<style>
    .mi-row:not(:last-child) { border-bottom: 1px solid var(--border); }
    .mi-row-selected {
        background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface)) !important;
        border-left: 3px solid var(--brand-icon, #0ea5e9);
        padding-left: 11px !important;
    }
    .mi-suggested-chip:hover { filter: brightness(1.05); }
    [x-cloak] { display: none !important; }
</style>
