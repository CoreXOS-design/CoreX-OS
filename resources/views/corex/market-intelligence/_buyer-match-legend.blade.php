{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.8 — quiet buyer-tier legend strip + Work-tab filter ticks.

    Lives in normal flow between the intro banner and the filter-rail/list
    grid (see work.blade.php) — NOT inside the scrolling list, and NOT
    sticky. Relocated here 2026-08-19 (Johan) after the first placement
    (sticky, nested inside the list column) pinned over the top of the
    first property row: a sticky element deep inside a long-scrolling
    column reserves its OWN box in flow, but that box sits far down the
    page, so by the time it "sticks" the following rows have already
    scrolled up underneath it. Placing it here, right after the intro
    banner near the top of the page (the empty band Johan circled), removes
    the bug at the root — nothing can slide under content that isn't
    floating over anything to begin with.

    Decodes the green/amber dots on every row in one place so a first-day
    agent doesn't have to hover each dot to learn what it means. The "tune"
    link goes to the existing buyer-tier settings tab so a manager can
    adjust the score cutoffs that decide tier membership.
--}}
<div class="mi-buyer-legend"
     style="display: flex; align-items: center; gap: 10px; padding: 6px 0;
            font-size: 0.6875rem; color: var(--text-muted, #9ca3af);
            border-bottom: 1px solid var(--border, rgba(0,0,0,0.07)); margin-bottom: 8px;
            flex-wrap: wrap;">
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

    <span style="display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-left: auto;">
    @include('corex.market-intelligence.partials._work-filter-ticks')
    </span>

    <a href="{{ route('settings.prospecting.index') }}#buyer-match-tiers"
       style="color: var(--brand-icon, #0ea5e9); text-decoration: none;"
       title="Open Prospecting Setup → Buyer Match Tiers to adjust the score cutoffs that decide tier membership.">
        tune ↗
    </a>
</div>
