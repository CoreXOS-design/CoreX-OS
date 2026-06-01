{{-- Build 2 — agent's pre-flight review screen.

     Three vertical sections (NOT tabs — single-page scroll, mobile-friendly):
       1. Subject snapshot — confirm what we know
       2. Comparable sales — tickbox table + live map sync
       3. Generate — finalise or discard

     Routes used:
       - POST presentations.review.toggle-comp
       - POST presentations.review.publish
       - POST presentations.review.revert
       - POST presentations.review.takeover

     The map intentionally inlines a SMALL Leaflet snippet reusing the
     bucket palette from Build 1 — extracting the full pin module
     (resources/views/corex/map/index.blade.php) was deemed risky in
     the Phase A audit, so only the SVG shape generators we need are
     duplicated. Single source of palette: data-bucket attributes +
     the shared @push('head') block above. --}}
@extends('layouts.corex-app')

@push('head')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<style>
    /* Build 2 review-page styles — sympathetic to the rest of CoreX
       (navy + teal + sharp 2-3px corners). No emojis. */
    .review-card { background: var(--surface); border: 1px solid var(--border); border-radius: 4px; padding: 16px; margin-bottom: 16px; }
    .review-section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    .review-section-tag { width: 4px; height: 18px; background: #00d4aa; }
    .review-section-title { margin: 0; font-size: 13px; font-weight: 700; color: #0b2a4a; letter-spacing: 0.04em; text-transform: uppercase; }
    .review-warn-banner { padding: 10px 12px; background: color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent); border-radius: 4px; color: var(--ds-amber, #d97706); font-size: 12px; margin-bottom: 12px; }
    .comp-row { display: grid; grid-template-columns: 28px 1fr 90px 100px 110px 70px 90px 28px; gap: 8px; align-items: center; padding: 8px 4px; border-bottom: 1px solid var(--border); font-size: 12px; }
    .comp-row.excluded { opacity: 0.45; }
    .comp-row.cross-type { background: color-mix(in srgb, var(--ds-amber, #d97706) 6%, transparent); }
    .comp-row input[type="checkbox"] { accent-color: #00d4aa; width: 16px; height: 16px; cursor: pointer; }
    .tt-badge { display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; }
    .tt-badge.full_title      { background: #0b2a4a; color: #fff; }
    .tt-badge.sectional_title { background: #7c3aed; color: #fff; }
    .tt-badge.vacant_land     { background: #06b6d4; color: #0b2a4a; }
    .tt-badge.other           { background: #475569; color: #fff; }
    #review-map { height: 460px; border: 1px solid var(--border); border-radius: 4px; }
    .review-pin { background: transparent !important; border: 0 !important; }
    .review-pin svg { display: block; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.4)); }
    .review-pin-cross { outline: 2px dashed var(--ds-amber, #d97706); outline-offset: 2px; border-radius: 4px; }
    .review-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #0b2a4a; color: #fff; padding: 8px 16px; border-radius: 4px; font-size: 12px; opacity: 0; transition: opacity 200ms; pointer-events: none; z-index: 9999; }
    .review-toast.show { opacity: 1; }
    /* Build 3 — condition picker + valuation strip. */
    .cond-picker { padding: 5px 10px; font-size: 12.5px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface); color: var(--text-primary); min-width: 280px; }
    .cond-source-tag { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
    .valuation-cell { padding: 10px 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; }
    .cell-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 600; margin-bottom: 4px; }
    .cell-value { font-size: 16px; font-weight: 700; color: #0b2a4a; tabular-nums: tabular-nums; }
    .cell-adj-flag { display: inline-block; margin-left: 4px; padding: 1px 5px; background: #00d4aa; color: #0b2a4a; border-radius: 2px; font-size: 9px; font-weight: 700; }
    .cell-adj-flag[hidden] { display: none; }
    .cma-adj-line { margin-top: 6px; font-size: 11px; color: var(--text-muted); text-align: center; }
    .cma-adj-line[hidden] { display: none; }
    .cma-no-cond-banner { margin-top: 8px; padding: 6px 10px; background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 25%, transparent); border-radius: 3px; font-size: 11px; color: var(--ds-amber, #d97706); }
    .cma-no-cond-banner[hidden] { display: none; }
    /* Build 4 — section toggles. */
    .section-toggle-row { display:flex; align-items:flex-start; gap:8px; padding:8px 10px; background:var(--surface-2); border:1px solid var(--border); border-radius:4px; }
    .section-toggle-row input[type="checkbox"] { margin-top:2px; accent-color:#00d4aa; width:16px; height:16px; }
    .section-name { font-size:12px; font-weight:600; color:var(--text-primary); }
    .section-floor-chip { display:inline-block; margin-left:6px; padding:1px 6px; background:color-mix(in srgb, #00d4aa 18%, transparent); color:#0b2a4a; border-radius:2px; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
    .section-dep-chip { display:inline-block; margin-left:6px; padding:1px 6px; background:color-mix(in srgb, var(--ds-amber, #d97706) 14%, transparent); color:var(--ds-amber, #d97706); border-radius:2px; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
    .page-estimate-line { padding:8px 10px; background:var(--surface-2); border:1px solid var(--border); border-radius:4px; font-size:12px; color:var(--text-muted); }
    .page-estimate-line strong { color:#0b2a4a; }
    .page-estimate-sub { font-size:11px; color:var(--text-muted); margin-left:6px; }
</style>
@endpush

@section('corex-content')
<div style="max-width: 1400px; margin: 0 auto; padding: 16px 20px;">

    {{-- Concurrent-reviewer banner. --}}
    @if($isLockedByOther)
        <div class="review-warn-banner">
            <strong>Currently being reviewed by {{ $currentReviewer->name ?? 'another agent' }}</strong>
            — they opened this presentation
            {{ $version->reviewer_locked_at?->diffForHumans() ?? 'recently' }}.
            <form method="POST" action="{{ route('presentations.review.takeover', $version->id) }}" style="display:inline; margin-left:8px;">
                @csrf
                <button type="submit" style="background:transparent;border:1px solid var(--ds-amber,#d97706);color:var(--ds-amber,#d97706);padding:4px 10px;font-size:11px;border-radius:3px;cursor:pointer;font-weight:600;">
                    Take over
                </button>
            </form>
        </div>
    @endif

    {{-- Soft-deleted comp banner. --}}
    @if($unavailableLogged > 0)
        <div class="review-warn-banner">
            {{ $unavailableLogged }} comparable
            {{ $unavailableLogged === 1 ? 'row was' : 'rows were' }}
            removed by the system between generation and this review
            (likely soft-deleted by a parallel update). Logged for audit.
        </div>
    @endif

    {{-- Header --}}
    <div style="margin-bottom: 18px;">
        <h1 style="font-size: 18px; font-weight: 700; color: var(--text-primary); margin: 0;">
            Review Presentation
        </h1>
        <p style="font-size: 13px; color: var(--text-muted); margin: 4px 0 0 0;">
            Confirm the subject facts and the comparable sales we picked, then publish.
            Untick anything you don't want included. Your overrides are logged for
            future learning.
        </p>
    </div>

    {{-- ─────────── SECTION 1 — Subject snapshot ─────────── --}}
    <div class="review-card">
        <div class="review-section-header">
            <div class="review-section-tag"></div>
            <h2 class="review-section-title">1 · Subject Snapshot</h2>
        </div>

        <table style="width: 100%; font-size: 12.5px; color: var(--text-primary); border-collapse: collapse;">
            <tr>
                <td style="padding: 5px 0; width: 18%; color: var(--text-muted); font-weight: 600;">Address</td>
                <td style="padding: 5px 0; width: 32%;">{{ $presentation->property_address ?? '—' }}</td>
                <td style="padding: 5px 0; width: 18%; color: var(--text-muted); font-weight: 600;">Suburb</td>
                <td style="padding: 5px 0; width: 32%;">{{ $presentation->suburb ?? '—' }}</td>
            </tr>
            <tr>
                <td style="padding: 5px 0; color: var(--text-muted); font-weight: 600;">Type</td>
                <td style="padding: 5px 0;">{{ \Illuminate\Support\Str::humanType($presentation->property_type) }}</td>
                <td style="padding: 5px 0; color: var(--text-muted); font-weight: 600;">Category</td>
                <td style="padding: 5px 0;">
                    @php
                        // Build 7 — render the agency's configured label
                        // (proper-case) instead of the raw lowercase column.
                        $catLabel = $presentation->property?->category
                            ? app(\App\Services\TitleTypeClassifier::class)
                                ->displayCategoryLabel((int) $version->agency_id, $presentation->property->category)
                            : null;
                    @endphp
                    {{ $catLabel ?? '— (no category set — comp filter skipped)' }}
                    @if($subjectTitleType)
                        <span class="tt-badge {{ $subjectTitleType }}" style="margin-left:6px;">
                            {{ \App\Models\PropertySettingItem::TITLE_TYPES[$subjectTitleType] ?? $subjectTitleType }}
                        </span>
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding: 5px 0; color: var(--text-muted); font-weight: 600;">Bedrooms</td>
                <td style="padding: 5px 0;">{{ $presentation->bedrooms ?? '—' }}</td>
                @php
                    // Build 7 — switch the size row by title_type (keystone
                    // single source of truth). Sectional → "Floor area" +
                    // floor_area_m2. Full title + vacant land → "Erf size"
                    // + erf_size_m2.
                    $subjectIsSectional = ($presentation->property?->title_type ?? null) === 'sectional_title';
                    $sizeLabel = $subjectIsSectional ? 'Floor area' : 'Erf size';
                    $sizeValue = $subjectIsSectional
                        ? $presentation->floor_area_m2
                        : $presentation->erf_size_m2;
                @endphp
                <td style="padding: 5px 0; color: var(--text-muted); font-weight: 600;">{{ $sizeLabel }}</td>
                <td style="padding: 5px 0;">
                    {{ $sizeValue ? number_format($sizeValue) . ' m²' : '—' }}
                </td>
            </tr>
            <tr>
                <td style="padding: 5px 0; color: var(--text-muted); font-weight: 600;">Asking price</td>
                <td style="padding: 5px 0;">
                    {{ $presentation->asking_price_inc ? 'R ' . number_format($presentation->asking_price_inc, 0, '.', ' ') : '—' }}
                </td>
                <td style="padding: 5px 0; color: var(--text-muted); font-weight: 600;">Source property</td>
                <td style="padding: 5px 0;">
                    @if($presentation->property)
                        <a href="{{ route('corex.properties.show', $presentation->property) }}" target="_blank" style="color: #00d4aa; text-decoration: none;">
                            Open property record &rarr;
                        </a>
                    @else
                        —
                    @endif
                </td>
            </tr>
            {{-- Build 3 — condition picker. Pre-populated from version
                 override > property > none. Changes POST to setCondition
                 and the JS patches the valuation strip below in-place. --}}
            <tr>
                <td style="padding: 8px 0; color: var(--text-muted); font-weight: 600;">Condition</td>
                <td style="padding: 8px 0;" colspan="3">
                    <select id="condition-picker" class="cond-picker">
                        <option value="">— No condition (baseline only) —</option>
                        @foreach($conditionLevels as $level)
                            <option value="{{ $level->id }}"
                                    data-pct="{{ (float) $level->adjustment_pct }}"
                                    {{ $currentConditionId === $level->id ? 'selected' : '' }}>
                                {{ $level->name }}
                                ({{ $level->adjustment_pct >= 0 ? '+' : '' }}{{ (float) $level->adjustment_pct }}%)
                            </option>
                        @endforeach
                    </select>
                    <span id="condition-source" class="cond-source-tag" style="margin-left:8px;">
                        @if($currentConditionSrc === 'version_override')
                            Set on this presentation
                        @elseif($currentConditionSrc === 'property_default')
                            From property record
                        @else
                            No condition set
                        @endif
                    </span>
                </td>
            </tr>
        </table>

        {{-- Build 3 — CMA valuation strip. Updates live when the
             condition picker changes (id targets for JS). When no
             condition is set, surfaces a hint to encourage capture. --}}
        <div class="valuation-strip" style="margin-top:14px;">
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px;">
                <div class="valuation-cell">
                    <div class="cell-label">Lower</div>
                    <div id="cma-lower" class="cell-value">{{ ($cmaValuation['cma_lower'] ?? null) ? 'R ' . number_format($cmaValuation['cma_lower'], 0, '.', ' ') : '—' }}</div>
                </div>
                <div class="valuation-cell" style="background: color-mix(in srgb, #00d4aa 8%, transparent); border-color: #00d4aa;">
                    <div class="cell-label">Middle <span id="cma-adjusted-flag" class="cell-adj-flag" {{ ($cmaValuation['condition_applied'] ?? false) ? '' : 'hidden' }}>adjusted</span></div>
                    <div id="cma-middle" class="cell-value">{{ ($cmaValuation['cma_middle'] ?? null) ? 'R ' . number_format($cmaValuation['cma_middle'], 0, '.', ' ') : '—' }}</div>
                </div>
                <div class="valuation-cell">
                    <div class="cell-label">Upper</div>
                    <div id="cma-upper" class="cell-value">{{ ($cmaValuation['cma_upper'] ?? null) ? 'R ' . number_format($cmaValuation['cma_upper'], 0, '.', ' ') : '—' }}</div>
                </div>
            </div>
            <div id="cma-adj-line" class="cma-adj-line"
                 {{ ($cmaValuation['condition_applied'] ?? false) ? '' : 'hidden' }}>
                <span id="cma-adj-text">
                    Baseline R {{ number_format($cmaValuation['cma_middle_baseline'] ?? 0, 0, '.', ' ') }}
                    →
                    Adjusted R {{ number_format($cmaValuation['cma_middle'] ?? 0, 0, '.', ' ') }}
                    ({{ ($cmaValuation['condition_pct'] ?? 0) >= 0 ? '+' : '' }}{{ (float)($cmaValuation['condition_pct'] ?? 0) }}%
                    — {{ $cmaValuation['condition_label'] ?? '' }})
                </span>
            </div>
            <div id="cma-no-condition-banner" class="cma-no-cond-banner"
                 {{ ($currentConditionId || ($cmaValuation['condition_applied'] ?? false)) ? 'hidden' : '' }}>
                No condition set — using baseline valuation. Set a condition above to refine.
            </div>

            {{-- Tick-wire build — CoreX-computed pool size + CMA Info
                 benchmark. INTERNAL review-screen only. NOT rendered on
                 the seller PDF (PresentationPdfService reads cma_lower/
                 cma_middle/cma_upper from cma_valuation, which now hold
                 the CoreX-computed values; this benchmark block is
                 review-blade only). --}}
            <div id="cma-pool-meta" style="margin-top:10px; font-size:11px; color:var(--text-muted);">
                CoreX evaluation —
                <span id="cma-pool-n">{{ (int) ($cmaValuation['compute_pool_n'] ?? 0) }}</span>
                comps included (method: median).
            </div>
            @php
                $bm = $cmaValuation['cma_info_benchmark'] ?? [];
                $bmAny = !empty($bm['lower']) || !empty($bm['middle']) || !empty($bm['upper']);
            @endphp
            @if($bmAny)
            <div id="cma-info-benchmark" style="margin-top:6px; padding:6px 10px; font-size:11px; color:var(--text-muted); background:var(--surface-2); border:1px dashed var(--border); border-radius:4px;">
                <strong style="color:var(--text-secondary);">CMA Info benchmark</strong>
                (internal, not on seller PDF):
                Lower R {{ $bm['lower'] ? number_format($bm['lower'], 0, '.', ' ') : '—' }}
                · Middle R {{ $bm['middle'] ? number_format($bm['middle'], 0, '.', ' ') : '—' }}
                · Upper R {{ $bm['upper'] ? number_format($bm['upper'], 0, '.', ' ') : '—' }}
                @if(!empty($bm['from_fallback']))
                    <span style="color:#a16207;">(middle synthesised from L+U/2)</span>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- ─────────── SECTION 2 — Comparable sales ─────────── --}}
    <div class="review-card">
        <div class="review-section-header">
            <div class="review-section-tag"></div>
            <h2 class="review-section-title">2 · Comparable Sales — {{ count($compRows) }} found</h2>
        </div>

        <div style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(360px, 560px); gap: 16px;">
            <div>
                {{-- Comp table --}}
                <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px; font-size: 11px; color: var(--text-muted);">
                    <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                        <input type="checkbox" id="show-excluded" checked>
                        <span>Show excluded rows</span>
                    </label>
                    <span style="margin-left: auto;">
                        Cross-type rows flagged
                        <span class="tt-badge other" style="background:var(--ds-amber,#d97706);color:#0b2a4a;">!</span>
                    </span>
                </div>

                <div id="comp-table" style="border: 1px solid var(--border); border-radius: 4px; overflow: hidden;">
                    <div class="comp-row" style="background: var(--surface-2); font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted);">
                        <div></div>
                        <div>Address</div>
                        <div>Sale date</div>
                        <div style="text-align:right;">Sold price</div>
                        <div>Type</div>
                        <div style="text-align:right;">R/m²</div>
                        <div>Title</div>
                        <div></div>
                    </div>
                    @foreach($compRows as $row)
                        <div class="comp-row {{ $row['is_included'] ? '' : 'excluded' }} {{ $row['is_cross_type'] ? 'cross-type' : '' }}"
                             data-comp-id="{{ $row['id'] }}"
                             data-included="{{ $row['is_included'] ? '1' : '0' }}"
                             data-cross-type="{{ $row['is_cross_type'] ? '1' : '0' }}"
                             data-lat="{{ $row['lat'] }}"
                             data-lng="{{ $row['lng'] }}"
                             data-title-type="{{ $row['title_type'] }}">
                            <div>
                                <input type="checkbox" class="comp-toggle" {{ $row['is_included'] ? 'checked' : '' }}>
                            </div>
                            <div title="{{ $row['address'] }}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                {{ $row['address'] }}
                            </div>
                            <div style="color: var(--text-muted); font-size: 11px;">{{ $row['sale_date'] ?? '—' }}</div>
                            <div style="text-align:right; font-weight: 600;">
                                {{ $row['sold_price_inc'] ? 'R ' . number_format($row['sold_price_inc'], 0, '.', ' ') : '—' }}
                            </div>
                            <div style="color: var(--text-muted);">{{ \Illuminate\Support\Str::humanType($row['property_type']) }}</div>
                            <div style="text-align:right; color: var(--text-muted);">
                                {{ $row['r_per_m2'] ? number_format($row['r_per_m2']) : '—' }}
                            </div>
                            <div>
                                <span class="tt-badge {{ $row['title_type'] }}">
                                    {{ substr(\App\Models\PropertySettingItem::TITLE_TYPES[$row['title_type']] ?? $row['title_type'], 0, 4) }}
                                </span>
                            </div>
                            <div title="{{ $row['is_cross_type'] ? 'Cross-title comparison — not recommended for valuation' : '' }}"
                                 style="font-size: 14px; color: {{ $row['is_cross_type'] ? 'var(--ds-amber,#d97706)' : 'transparent' }};">
                                {{ $row['is_cross_type'] ? '!' : '' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                {{-- Map --}}
                <div id="review-map"></div>
                <div style="margin-top: 6px; font-size: 11px; color: var(--text-muted); display: flex; gap: 14px; flex-wrap: wrap;">
                    <span><span style="display:inline-block;width:10px;height:10px;background:#00d4aa;clip-path:polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%);vertical-align:middle;margin-right:4px;"></span>Subject</span>
                    <span><span style="display:inline-block;width:8px;height:8px;background:#0b2a4a;vertical-align:middle;margin-right:4px;border-radius:50%;"></span>Full title comp</span>
                    <span><span style="display:inline-block;width:8px;height:8px;background:#7c3aed;vertical-align:middle;margin-right:4px;"></span>Sectional comp</span>
                    <span><span style="display:inline-block;width:8px;height:8px;background:#06b6d4;vertical-align:middle;margin-right:4px;clip-path:polygon(50% 0,100% 100%,0 100%);"></span>Vacant land comp</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────── SECTION 2b — Active Competition (scored stock) ─────────── --}}
    @php
        $competitorMatches  = $competitorStock['matches']      ?? [];
        $competitorIncluded = $competitorStock['included_ids'] ?? null;
        $includedSet        = $competitorIncluded === null
            ? null
            : array_flip(array_map('intval', $competitorIncluded));
        $isCompetitorIncluded = function ($listingId) use ($includedSet) {
            if ($includedSet === null) return true;
            return isset($includedSet[(int) $listingId]);
        };
        // Annotate each match with the resolved included-state so the JS
        // doesn't have to recompute the whitelist semantics — same shape
        // CoreXBuildListingCard consumes.
        $competitorMatchesForJs = array_map(function ($m) use ($isCompetitorIncluded) {
            $m['is_included'] = $isCompetitorIncluded($m['listing_id']);
            return $m;
        }, $competitorMatches);
    @endphp
    @if(count($competitorMatches) > 0)
    {{-- Shared listing-card builder. Defines window.CoreXBuildListingCard. --}}
    @include('partials._listing-card-helper')

    <div class="review-card">
        <div class="review-section-header">
            <div class="review-section-tag" style="background:#7c3aed;"></div>
            <h2 class="review-section-title">2b · Active Competition — {{ count($competitorMatches) }} scored</h2>
        </div>
        <p style="margin:0 0 12px 0;font-size:11px;color:var(--text-muted);">
            Active stock the seller competes against (P24 + PP alert imports), scored against
            this subject by the Core Matches engine. Tick to include in the seller PDF;
            unticked cards stay on this review screen but drop from the published version.
        </p>
        <div id="competitor-stock-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;"></div>
    </div>

    <script>
    (function () {
        var COMPETITOR_MATCHES = @json($competitorMatchesForJs);
        var listEl = document.getElementById('competitor-stock-list');
        if (!listEl || !window.CoreXBuildListingCard) return;

        // Tier → badge palette. Mirrors the colors used pre-refactor for
        // the inline Section 2b markup.
        function tierBadge(tier) {
            switch (tier) {
                case 'perfect':     return { label: 'Perfect',     fg: '#10b981', bg: '#ecfdf5' };
                case 'strong':      return { label: 'Strong',      fg: '#0ea5e9', bg: '#eff6ff' };
                case 'approximate': return { label: 'Approximate', fg: '#a16207', bg: '#fefce8' };
                default:            return { label: (tier || 'Match'), fg: '#475569', bg: '#f1f5f9' };
            }
        }

        function buildCompetitorCard(m) {
            var badges = [tierBadge(m.tier)];
            if (m.is_hfc_owned) {
                badges.push({ label: 'HFC', fg: '#10b981', bg: '#ecfdf5' });
                if (m.days_on_market !== null && m.days_on_market !== undefined) {
                    badges.push({ label: m.days_on_market + 'd', fg: '#475569', bg: '#f1f5f9' });
                }
                if (m.views !== null && m.views !== undefined) {
                    badges.push({ label: Number(m.views).toLocaleString('en-ZA') + ' views', fg: '#475569', bg: '#f1f5f9' });
                }
            }
            var checked = m.is_included ? 'checked' : '';
            var includeToggle =
                '<label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:10px;color:#94a3b8;" title="Include in seller PDF">'
                + '<input type="checkbox" class="competitor-toggle" data-listing-id="' + m.listing_id + '" ' + checked + '>'
                + 'Include</label>';

            var html = window.CoreXBuildListingCard({
                image_url:    m.thumbnail_url || null,
                title:        m.address || ('Listing #' + m.listing_id),
                address:      m.suburb && m.suburb !== m.address ? m.suburb : null,
                price:        m.price,
                beds:         m.bedrooms,
                baths:        m.bathrooms,
                garages:      m.garages,
                erf_m2:       m.erf_size_m2 ? Math.round(m.erf_size_m2) : null,
                floor_m2:     m.property_size_m2 ? Math.round(m.property_size_m2) : null,
                agent_name:   m.agent_name || m.agency_name || null,
                ref:          m.portal_ref || null,
                click_url:    m.portal_url || null,
                badges:       badges,
                top_right_pill: { label: m.score + '%', fg: tierBadge(m.tier).fg, bg: 'rgba(255,255,255,0.92)' },
                actions_html: includeToggle,
            });

            // Wrap so we can dim on untick + carry the listing id.
            var wrapper = '<div class="competitor-card' + (m.is_included ? '' : ' excluded')
                + '" data-listing-id="' + m.listing_id + '"'
                + (m.is_included ? '' : ' style="opacity:0.45;"') + '>' + html + '</div>';
            return wrapper;
        }

        listEl.innerHTML = COMPETITOR_MATCHES.map(buildCompetitorCard).join('');
    })();
    </script>
    @endif

    {{-- ─────────── SECTION 3 — Generate ─────────── --}}
    <div class="review-card">
        <div class="review-section-header">
            <div class="review-section-tag"></div>
            <h2 class="review-section-title">3 · What's in Your Presentation</h2>
        </div>

        {{-- Build 4 — section toggles. Floor sections are locked-on with
             a Lock chip. Dependencies surface as small "requires X" tags.
             Live page estimate updates on every toggle. --}}
        <div id="section-toggles" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:8px; margin-bottom:14px;">
            @foreach($sectionsCatalogue as $sKey => $sLabel)
                @php
                    $isFloor = in_array($sKey, $sectionFloor, true);
                    $isOn    = (bool) ($sectionSnapshot[$sKey] ?? true);
                    $deps    = $sectionDeps[$sKey] ?? [];
                @endphp
                <label class="section-toggle-row" data-section-key="{{ $sKey }}"
                       style="{{ $isFloor ? 'opacity:0.8;' : 'cursor:pointer;' }}">
                    <input type="checkbox" class="section-toggle"
                           data-section-key="{{ $sKey }}"
                           {{ $isOn ? 'checked' : '' }}
                           {{ $isFloor ? 'disabled' : '' }}>
                    <div style="flex:1; min-width:0;">
                        <div class="section-name">
                            {{ $sLabel }}
                            @if($isFloor)
                                <span class="section-floor-chip">Always shown</span>
                            @endif
                            @foreach($deps as $depKey)
                                <span class="section-dep-chip" data-dep-of="{{ $sKey }}" data-needs="{{ $depKey }}">
                                    needs {{ $sectionsCatalogue[$depKey] ?? $depKey }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                </label>
            @endforeach
        </div>

        <div class="page-estimate-line">
            Estimated final page count:
            <strong id="page-estimate-value">~{{ $pageEstimate }} pages</strong>
            <span class="page-estimate-sub">(cover + facts always included; layout may flex by a page either way).</span>
        </div>

        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
            <button id="btn-publish" type="button"
                    style="background:#00d4aa;color:#0b2a4a;border:1px solid #00d4aa;padding:10px 20px;font-size:13px;font-weight:700;border-radius:4px;cursor:pointer;">
                Generate Presentation
            </button>
            <button id="btn-save" type="button"
                    style="background:transparent;color:#00d4aa;border:1px solid #00d4aa;padding:10px 16px;font-size:13px;font-weight:600;border-radius:4px;cursor:pointer;">
                Save &amp; continue later
            </button>
            <span style="margin-left:auto;">
                <button id="btn-revert" type="button"
                        style="background:transparent;color:var(--text-muted);border:0;padding:10px 16px;font-size:12px;text-decoration:underline;cursor:pointer;">
                    Discard &amp; return to property
                </button>
            </span>
        </div>
    </div>

    <div id="review-toast" class="review-toast"></div>

</div>

<script>
(function () {
    'use strict';
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
    const VERSION_ID = {{ $version->id }};
    const TOGGLE_TPL = @json(route('presentations.review.toggle-comp', ['version' => $version->id, 'comp' => '__COMP_ID__']));
    const COMPETITOR_TOGGLE_TPL = @json(route('presentations.review.toggle-competitor', ['version' => $version->id, 'listingId' => '__LISTING_ID__']));
    const PUBLISH_URL = @json(route('presentations.review.publish', $version->id));
    const REVERT_URL  = @json(route('presentations.review.revert',  $version->id));
    const CONDITION_URL = @json(route('presentations.review.condition', $version->id));
    const SECTION_URL   = @json(route('presentations.review.sections',  $version->id));
    const SECTION_LABELS = @json($sectionsCatalogue);

    // Subject GPS comes from the linked property record (presentations
    // table has no lat/lng columns). When the property has no resolved
    // coords yet, leave the values null so the map can render an empty
    // state instead of pinning every presentation to a hardcoded
    // Uvongo Beach centroid (the legacy `-30.84 / 30.39` fallback was
    // the second root bug — every presentation map showed the same pin
    // regardless of property location).
    const SUBJECT_LAT = {{ $presentation->property?->latitude !== null ? (float) $presentation->property->latitude : 'null' }};
    const SUBJECT_LNG = {{ $presentation->property?->longitude !== null ? (float) $presentation->property->longitude : 'null' }};
    const SUBJECT_HAS_GPS = SUBJECT_LAT !== null && SUBJECT_LNG !== null;

    const toastEl = document.getElementById('review-toast');
    function toast(msg) {
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        clearTimeout(toastEl._t);
        toastEl._t = setTimeout(() => toastEl.classList.remove('show'), 2200);
    }

    // ── Map init with bucket palette (Build 1) ────────────────────────
    // When the property has GPS, center on it at street zoom. Without GPS
    // we still draw the tile layer (so comps render on a real map) but
    // open at a KZN South Coast overview zoom + drop a banner.
    const map = L.map('review-map', { scrollWheelZoom: true })
        .setView(SUBJECT_HAS_GPS ? [SUBJECT_LAT, SUBJECT_LNG] : [-30.84, 30.39], SUBJECT_HAS_GPS ? 14 : 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors', maxZoom: 19,
    }).addTo(map);
    if (!SUBJECT_HAS_GPS) {
        const banner = document.createElement('div');
        banner.style.cssText = 'position:absolute;top:8px;left:50%;transform:translateX(-50%);z-index:1000;background:rgba(245,158,11,0.95);color:#1f2937;font-size:11px;font-weight:600;padding:4px 10px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,0.2);pointer-events:none;';
        banner.textContent = 'No subject GPS — open the linked property and use the Map strip to set the pin.';
        document.getElementById('review-map').appendChild(banner);
    }

    function bucketSvg(titleType, size) {
        const px = size || 18, sw = 2;
        const fill = ({
            sectional_title: '#7c3aed',
            vacant_land:     '#06b6d4',
            other:           '#475569',
            full_title:      '#0b2a4a',
        })[titleType] || '#0b2a4a';
        const stroke = '#ffffff';

        if (titleType === 'sectional_title') {
            return '<svg xmlns="http://www.w3.org/2000/svg" width="'+px+'" height="'+px+'" viewBox="0 0 '+px+' '+px+'">'
                 + '<rect x="1" y="1" width="'+(px-sw)+'" height="'+(px-sw)+'" rx="2" fill="'+fill+'" stroke="'+stroke+'" stroke-width="'+sw+'"/></svg>';
        }
        if (titleType === 'vacant_land') {
            const cx = px/2;
            return '<svg xmlns="http://www.w3.org/2000/svg" width="'+px+'" height="'+px+'" viewBox="0 0 '+px+' '+px+'">'
                 + '<polygon points="'+cx+',1 '+(px-1)+','+(px-1)+' 1,'+(px-1)+'" fill="'+fill+'" stroke="'+stroke+'" stroke-width="'+sw+'" stroke-linejoin="round"/></svg>';
        }
        // full_title + other → circle
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'+px+'" height="'+px+'" viewBox="0 0 '+px+' '+px+'">'
             + '<circle cx="'+(px/2)+'" cy="'+(px/2)+'" r="'+((px-sw)/2)+'" fill="'+fill+'" stroke="'+stroke+'" stroke-width="'+sw+'"/></svg>';
    }

    // Subject hexagon (teal — Build 1's TRACKED spine colour reused as
    // the subject marker so it pops against the bucket palette).
    function subjectSvg() {
        const px = 22, sw = 2, cx = px/2, cy = px/2, r = Math.min(px, px)/2 - sw/2;
        const pts = [];
        for (let i = 0; i < 6; i++) {
            const a = (Math.PI / 3) * i - Math.PI / 2;
            pts.push((cx + r * Math.cos(a)).toFixed(2) + ',' + (cy + r * Math.sin(a)).toFixed(2));
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'+px+'" height="'+px+'" viewBox="0 0 '+px+' '+px+'">'
             + '<polygon points="'+pts.join(' ')+'" fill="#00d4aa" stroke="#0b2a4a" stroke-width="2"/></svg>';
    }

    // Subject marker — only drawn when the property has resolved GPS.
    if (SUBJECT_HAS_GPS) {
        L.marker([SUBJECT_LAT, SUBJECT_LNG], {
            icon: L.divIcon({
                html: subjectSvg(), className: 'review-pin',
                iconSize: [22, 22], iconAnchor: [11, 11],
            }),
            zIndexOffset: 1000,
        }).addTo(map);
    }

    // Comp markers — keyed by comp_id so toggle can hide/show.
    const markers = new Map();
    document.querySelectorAll('#comp-table .comp-row[data-comp-id]').forEach(row => {
        const id  = parseInt(row.dataset.compId, 10);
        const lat = parseFloat(row.dataset.lat);
        const lng = parseFloat(row.dataset.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const tt    = row.dataset.titleType || 'full_title';
        const cross = row.dataset.crossType === '1';
        const m = L.marker([lat, lng], {
            icon: L.divIcon({
                html: bucketSvg(tt, 18),
                className: 'review-pin' + (cross ? ' review-pin-cross' : ''),
                iconSize: [18, 18], iconAnchor: [9, 9],
            }),
        });
        m.bindTooltip(row.querySelector('div:nth-of-type(2)')?.textContent.trim() || '', { direction: 'top' });
        if (row.dataset.included === '1') m.addTo(map);
        markers.set(id, m);
    });

    // ── Live tile patch helper (shared by comp toggle + condition picker) ─
    // applyCmaUpdate is defined further down (after the valuation-strip
    // DOM nodes are resolved); both flushToggle here and the condition
    // change handler below call it on response. Patches the three
    // valuation tiles + condition strip + pool size in place — no
    // full-page reload on comp ticks.
    let _applyCmaUpdate = function () { /* late-bound below */ };
    function applyCmaUpdate(data) { _applyCmaUpdate(data); }

    // ── Comp toggle with debounce + optimistic UI ─────────────────────
    let pendingToggles = new Map();
    let toggleTimer = null;
    function flushToggle(compId) {
        const pending = pendingToggles.get(compId);
        if (!pending) return;
        pendingToggles.delete(compId);
        const url = TOGGLE_TPL.replace('__COMP_ID__', String(compId));
        const body = new FormData();
        body.append('_token', csrf);
        body.append('included', pending.included ? '1' : '0');
        fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body,
            credentials: 'same-origin',
        }).then(r => r.json()).then(d => {
            if (!d?.ok) {
                // Optimistic rollback.
                pending.rollback();
                toast('Could not save toggle — please retry');
                return;
            }
            // Tick-wire build — server returns recomputed bands; patch
            // the valuation tiles in place. Same response shape as
            // setCondition; same helper handles both.
            applyCmaUpdate(d);
        }).catch(() => {
            pending.rollback();
            toast('Network error saving toggle');
        });
    }

    document.querySelectorAll('.comp-toggle').forEach(cb => {
        cb.addEventListener('change', () => {
            const row    = cb.closest('.comp-row');
            const id     = parseInt(row.dataset.compId, 10);
            const next   = cb.checked;
            const marker = markers.get(id);

            // Optimistic: toggle marker + row class immediately.
            row.dataset.included = next ? '1' : '0';
            row.classList.toggle('excluded', !next);
            if (marker) {
                if (next) marker.addTo(map); else map.removeLayer(marker);
            }

            const rollback = () => {
                cb.checked = !next;
                row.dataset.included = (!next) ? '1' : '0';
                row.classList.toggle('excluded', next);
                if (marker) {
                    if (!next) marker.addTo(map); else map.removeLayer(marker);
                }
            };
            pendingToggles.set(id, { included: next, rollback });
            clearTimeout(toggleTimer);
            toggleTimer = setTimeout(() => {
                Array.from(pendingToggles.keys()).forEach(flushToggle);
            }, 300);
        });
    });

    // ── Competitor Stock toggle (mirrors comp toggle pattern) ──────────
    // Event delegation — the Active Competition cards are JS-rendered
    // by the shared CoreXBuildListingCard helper AFTER this handler
    // attaches, so we listen at the container level. The container
    // (#competitor-stock-list) is present in the static HTML even when
    // empty.
    var competitorList = document.getElementById('competitor-stock-list');
    if (competitorList) {
        competitorList.addEventListener('change', function (e) {
            if (!e.target || !e.target.classList.contains('competitor-toggle')) return;
            var cb = e.target;
            const listingId = parseInt(cb.dataset.listingId, 10);
            const card = cb.closest('.competitor-card');
            const next = cb.checked;

            // Optimistic dim/undim.
            if (card) {
                card.style.opacity = next ? '' : '0.45';
                card.classList.toggle('excluded', !next);
            }

            const url = COMPETITOR_TOGGLE_TPL.replace('__LISTING_ID__', String(listingId));
            const body = new FormData();
            body.append('_token', csrf);
            body.append('included', next ? '1' : '0');

            fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body, credentials: 'same-origin',
            }).then(r => r.json()).then(d => {
                if (!d?.ok) {
                    cb.checked = !next;
                    if (card) {
                        card.style.opacity = (!next) ? '' : '0.45';
                        card.classList.toggle('excluded', next);
                    }
                    toast('Could not save competitor toggle — please retry');
                }
            }).catch(() => {
                cb.checked = !next;
                if (card) card.style.opacity = (!next) ? '' : '0.45';
                toast('Network error saving competitor toggle');
            });
        });
    }

    // Show/hide excluded rows.
    document.getElementById('show-excluded').addEventListener('change', e => {
        const show = e.target.checked;
        document.querySelectorAll('#comp-table .comp-row[data-comp-id]').forEach(row => {
            if (row.dataset.included === '0') row.style.display = show ? '' : 'none';
        });
    });

    // ── Publish / Revert ─────────────────────────────────────────────
    document.getElementById('btn-publish').addEventListener('click', async () => {
        const body = new FormData(); body.append('_token', csrf);
        try {
            const r = await fetch(PUBLISH_URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body, credentials: 'same-origin',
            });
            const d = await r.json();
            if (d?.ok && d.public_url) {
                window.location.href = d.public_url;
            } else {
                toast('Publish failed — please retry');
            }
        } catch (e) { toast('Network error publishing'); }
    });

    document.getElementById('btn-revert').addEventListener('click', async () => {
        if (!confirm('Discard this presentation? Your overrides will be logged but the version will be archived.')) return;
        const body = new FormData(); body.append('_token', csrf);
        try {
            const r = await fetch(REVERT_URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body, credentials: 'same-origin',
            });
            const d = await r.json();
            if (d?.ok && d.property_url) {
                window.location.href = d.property_url;
            } else {
                toast('Discard failed — please retry');
            }
        } catch (e) { toast('Network error discarding'); }
    });

    document.getElementById('btn-save').addEventListener('click', () => {
        // Save = no-op server-side; the toggle endpoint already persisted
        // each change. Just confirm to the user + close (or stay).
        toast('Saved — your overrides are stored.');
    });

    // ── Build 3 — condition picker live recalc ───────────────────────
    const condEl     = document.getElementById('condition-picker');
    const condSrcEl  = document.getElementById('condition-source');
    const middleEl   = document.getElementById('cma-middle');
    const lowerEl    = document.getElementById('cma-lower');
    const upperEl    = document.getElementById('cma-upper');
    const adjFlagEl  = document.getElementById('cma-adjusted-flag');
    const adjLineEl  = document.getElementById('cma-adj-line');
    const adjTextEl  = document.getElementById('cma-adj-text');
    const noCondBan  = document.getElementById('cma-no-condition-banner');

    function fmtZAR(n) {
        if (n === null || n === undefined) return '—';
        return 'R ' + Number(n).toLocaleString('en-ZA', { useGrouping: true, maximumFractionDigits: 0 }).replace(/,/g, ' ');
    }
    const poolNEl = document.getElementById('cma-pool-n');
    _applyCmaUpdate = function (data) {
        if (!data || !data.cma) return;
        lowerEl.textContent  = fmtZAR(data.cma.lower);
        middleEl.textContent = fmtZAR(data.cma.middle);
        upperEl.textContent  = fmtZAR(data.cma.upper);

        // Tick-wire build — pool size in the "CoreX evaluation — X comps
        // included" subtitle. Updates whenever ticks change.
        if (poolNEl && typeof data.cma.pool_n !== 'undefined') {
            poolNEl.textContent = String(data.cma.pool_n);
        }

        const applied = !!(data.condition && data.condition.applied);
        adjFlagEl.hidden = !applied;
        adjLineEl.hidden = !applied;
        if (applied) {
            const pct = data.condition.pct;
            const sign = pct >= 0 ? '+' : '';
            adjTextEl.textContent =
                'Baseline ' + fmtZAR(data.cma.middle_baseline) +
                ' → Adjusted ' + fmtZAR(data.cma.middle) +
                ' (' + sign + pct + '% — ' + (data.condition.label || '') + ')';
        }
        noCondBan.hidden = !(data.condition && data.condition.source === 'none');

        // Source tag.
        if (data.condition) {
            condSrcEl.textContent = ({
                version_override: 'Set on this presentation',
                property_default: 'From property record',
                none:             'No condition set',
            })[data.condition.source] || '';
        }
    };

    // ── Build 4 — section toggles ────────────────────────────────────
    const pageEstimateEl = document.getElementById('page-estimate-value');
    document.querySelectorAll('.section-toggle').forEach(cb => {
        cb.addEventListener('change', async () => {
            const key  = cb.dataset.sectionKey;
            const next = cb.checked;
            const row  = cb.closest('.section-toggle-row');

            // Optimistic UI — flip immediately; rollback on failure.
            const rollback = () => { cb.checked = !next; };

            const body = new FormData();
            body.append('_token', csrf);
            body.append('section_key', key);
            body.append('enabled', next ? '1' : '0');

            try {
                const r = await fetch(SECTION_URL, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body, credentials: 'same-origin',
                });
                const d = await r.json();
                if (!d?.ok) { rollback(); toast('Could not save section toggle.'); return; }

                // Update sibling checkboxes for cascaded keys.
                if (d.cascaded && Object.keys(d.cascaded).length > 0) {
                    Object.entries(d.cascaded).forEach(([cKey, cVal]) => {
                        const cb2 = document.querySelector('.section-toggle[data-section-key="' + cKey + '"]');
                        if (cb2) cb2.checked = !!cVal;
                    });
                    // Toast explaining the cascade — e.g. enabling Pricing
                    // Strategy auto-enabled CMA.
                    const labels = Object.entries(d.cascaded)
                        .map(([k, v]) => (v ? 'enabled ' : 'disabled ') + (SECTION_LABELS[k] || k))
                        .join('; ');
                    toast('Auto-' + labels + ' (dependency).');
                } else {
                    toast(next ? 'Section enabled.' : 'Section disabled.');
                }

                if (pageEstimateEl && d.page_estimate) {
                    pageEstimateEl.textContent = '~' + d.page_estimate + ' pages';
                }
            } catch (e) {
                rollback();
                toast('Network error saving section toggle.');
            }
        });
    });

    if (condEl) {
        condEl.addEventListener('change', async () => {
            const body = new FormData();
            body.append('_token', csrf);
            const val = condEl.value;
            if (val) body.append('condition_level_id', val);

            try {
                const r = await fetch(CONDITION_URL, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body, credentials: 'same-origin',
                });
                const d = await r.json();
                if (d?.ok) {
                    applyCmaUpdate(d);
                    toast('Condition updated — bands recalculated.');
                } else {
                    toast('Could not save condition — please retry.');
                }
            } catch (e) {
                toast('Network error saving condition.');
            }
        });
    }
})();
</script>
@endsection
