{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.4 — Slide-over body. Returned by MarketIntelligenceController@details
    and injected via Alpine fetch into the slide-over wrapper.

    Inputs: $listing, $panel (PropertyIntelligencePanelService::load result), $state.
--}}

@include('corex.market-intelligence._slideover-header', [
    'listing' => $listing,
    'header'  => $panel['header'],
    'state'   => $state,
    'viewer'  => $panel['viewer'],
])

<div class="mi-tabs" data-active-tab="overview" style="flex: 1; display: flex; flex-direction: column; min-height: 0;">
    <div class="mi-tab-bar" role="tablist"
         style="display: flex; gap: 4px; padding: 0 14px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-shrink: 0; overflow-x: auto;">
        @php
            $tabBtnStyle = 'background: none; border: none; padding: 10px 12px; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; border-bottom: 2px solid transparent; white-space: nowrap;';
            $tabBtnActive = 'background: none; border: none; padding: 10px 12px; font-size: 0.75rem; font-weight: 600; color: var(--brand-icon); cursor: pointer; border-bottom: 2px solid var(--brand-icon); white-space: nowrap;';
        @endphp
        <button type="button" data-tab="overview" class="active" role="tab" aria-selected="true" style="{{ $tabBtnActive }}">Overview</button>
        <button type="button" data-tab="buyers"   role="tab" aria-selected="false" style="{{ $tabBtnStyle }}">
            Buyers <span style="background: var(--surface); color: var(--text-muted); padding: 1px 6px; border-radius: 8px; font-size: 0.625rem; margin-left: 2px;">{{ $panel['buyers']['tier_breakdown']['total'] ?? 0 }}</span>
        </button>
        <button type="button" data-tab="activity" role="tab" aria-selected="false" style="{{ $tabBtnStyle }}">Activity</button>
        <button type="button" data-tab="source"   role="tab" aria-selected="false" style="{{ $tabBtnStyle }}">Source</button>
        <button type="button" data-tab="market"   role="tab" aria-selected="false" style="{{ $tabBtnStyle }}">Market</button>
    </div>

    <div class="mi-tab-panels" style="flex: 1; overflow-y: auto;">
        <div data-panel="overview" role="tabpanel">
            @include('corex.market-intelligence._slideover-tab-overview', [
                'listing' => $listing,
                'overview' => $panel['overview'],
                'tierBreakdown' => $panel['buyers']['tier_breakdown'],
            ])
        </div>
        <div data-panel="buyers" role="tabpanel" hidden>
            @include('corex.market-intelligence._slideover-tab-buyers', [
                'listing' => $listing,
                'buyers' => $panel['buyers'],
            ])
        </div>
        <div data-panel="activity" role="tabpanel" hidden>
            @include('corex.market-intelligence._slideover-tab-activity', [
                'listing' => $listing,
                'pitches' => $panel['activity']['pitches'],
                'claimNotes' => $panel['activity']['claims'],
            ])
        </div>
        <div data-panel="source" role="tabpanel" hidden>
            @include('corex.market-intelligence._slideover-tab-source', [
                'listing' => $listing,
                'source'  => $panel['source'],
            ])
        </div>
        <div data-panel="market" role="tabpanel" hidden>
            @include('corex.market-intelligence._slideover-tab-market', ['listing' => $listing])
        </div>
    </div>
</div>
