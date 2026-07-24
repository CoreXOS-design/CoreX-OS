{{-- Build 2 — agent's pre-flight review screen.

     DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

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
    /* Build 2 review-page styles — tokenised to the CoreX design system
       (brand tokens, --ds-* semantics, rounded-md 6px corners). No emojis.
       The map title-type palette (.tt-badge colour classes + the map legend
       swatches + the JS marker SVGs) is a categorical data-visualisation
       palette and is intentionally kept as fixed colours so the table,
       badges and map pins stay in sync. */
    .review-card { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 16px; margin-bottom: 16px; }
    .review-section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
    .review-section-tag { width: 4px; height: 18px; background: var(--brand-icon, #0ea5e9); }
    .review-section-title { margin: 0; font-size: 13px; font-weight: 700; color: var(--text-primary); letter-spacing: 0.04em; text-transform: uppercase; }
    .review-warn-banner { padding: 10px 12px; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent); border-radius: 6px; color: var(--ds-amber, #f59e0b); font-size: 12px; margin-bottom: 12px; }
    .comp-row { display: grid; grid-template-columns: 28px minmax(110px, 1fr) 76px 98px 78px 56px 58px 60px 22px; gap: 8px; align-items: center; padding: 8px 4px; border-bottom: 1px solid var(--border); font-size: 12px; }
    .comp-row .sortable { cursor: pointer; user-select: none; }
    .comp-row .sortable:hover { color: var(--text-primary); }
    .comp-row .sort-arrow { opacity: 0.5; font-size: 9px; }
    .comp-row.hidden-by-filter { display: none; }
    .comp-tool-btn { font-size: 11px; padding: 3px 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface); color: var(--text-primary); cursor: pointer; }
    .comp-tool-btn:hover { background: var(--surface-2); }
    /* Comp table + map side-by-side layout. Pre-fix the wrapper used
       minmax(0,1fr) on the left column, which let the table's fixed-
       width Type / R/m² / Title cells slide UNDER the map at narrower
       viewports while the Address 1fr collapsed to zero. The min here
       is the comp-row's natural floor: 28 + 120 (address floor) + 90
       + 100 + 110 + 70 + 90 + 28 = 636 fixed + 56 gap = 692px → 700
       with a small cushion. Below the breakpoint we stack vertically
       so the layout degrades gracefully on small laptops. */
    .review-comps-layout { display: grid; grid-template-columns: minmax(700px, 1fr) minmax(360px, 560px); gap: 16px; }
    @media (max-width: 1199px) {
        .review-comps-layout { grid-template-columns: 1fr; }
    }
    .comp-row.excluded { opacity: 0.45; }
    .comp-row.cross-type { background: color-mix(in srgb, var(--ds-amber, #f59e0b) 6%, transparent); }
    .comp-row input[type="checkbox"] { accent-color: var(--brand-icon, #0ea5e9); width: 16px; height: 16px; cursor: pointer; }
    /* Categorical title-type palette — kept fixed (synced with map pins + legend). */
    .tt-badge { display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.02em; }
    .tt-badge.full_title      { background: #0b2a4a; color: #fff; }
    .tt-badge.sectional_title { background: #7c3aed; color: #fff; }
    .tt-badge.vacant_land     { background: #06b6d4; color: #0b2a4a; }
    .tt-badge.other           { background: #475569; color: #fff; }
    #review-map { height: 460px; border: 1px solid var(--border); border-radius: 6px; }
    .review-pin { background: transparent !important; border: 0 !important; }
    .review-pin svg { display: block; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.4)); }
    .review-pin-cross { outline: 2px dashed var(--ds-amber, #f59e0b); outline-offset: 2px; border-radius: 6px; }
    .review-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--brand-default, #0b2a4a); color: #fff; padding: 8px 16px; border-radius: 6px; font-size: 12px; opacity: 0; transition: opacity 200ms; pointer-events: none; z-index: 9999; }
    .review-toast.show { opacity: 1; }
    /* Build 3 — condition picker + valuation strip. */
    .cond-picker { padding: 5px 10px; font-size: 12.5px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-primary); min-width: 280px; }
    .cond-source-tag { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); }
    .valuation-cell { padding: 10px 12px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; }
    .cell-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); font-weight: 600; margin-bottom: 4px; }
    .cell-value { font-size: 16px; font-weight: 700; color: var(--text-primary); font-variant-numeric: tabular-nums; }
    .cell-adj-flag { display: inline-block; margin-left: 4px; padding: 1px 5px; background: var(--brand-icon, #0ea5e9); color: #fff; border-radius: 4px; font-size: 9px; font-weight: 700; }
    .cell-adj-flag[hidden] { display: none; }
    .cma-adj-line { margin-top: 6px; font-size: 11px; color: var(--text-muted); text-align: center; }
    .cma-adj-line[hidden] { display: none; }
    .cma-no-cond-banner { margin-top: 8px; padding: 6px 10px; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 8%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); border-radius: 6px; font-size: 11px; color: var(--ds-amber, #f59e0b); }
    .cma-no-cond-banner[hidden] { display: none; }
    {{-- AT-27 Phase B.3 — section-toggle + page-estimate styles removed with the
         toggle UI (moved to the Analysis screen). --}}
</style>
@endpush

@section('corex-content')
{{-- Full-bleed: the main content area (layouts.corex-app <main>) already
     applies p-4 lg:p-6, and this wide table+map layout uses the full screen
     like the Properties index — no max-width cap, no redundant padding. --}}
<div class="w-full">

    {{-- AT-27 C1a — looped back from an Analysis subject edit with a refreshed comp set. --}}
    @if(session('subject_refreshed'))
        <div class="review-warn-banner" role="status"
             style="border-left:3px solid var(--brand-icon,#0ea5e9); background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent); color:var(--text-primary);">
            <strong>Comparable set refreshed.</strong> {{ session('subject_refreshed') }}
        </div>
    @endif

    {{-- Concurrent-reviewer banner. --}}
    @if($isLockedByOther)
        <div class="review-warn-banner">
            <strong>Currently being reviewed by {{ $currentReviewer->name ?? 'another agent' }}</strong>
            — they opened this presentation
            {{ $version->reviewer_locked_at?->diffForHumans() ?? 'recently' }}.
            <form method="POST" action="{{ route('presentations.review.takeover', $version->id) }}" style="display:inline; margin-left:8px;">
                @csrf
                <button type="submit" style="background:transparent;border:1px solid var(--ds-amber,#f59e0b);color:var(--ds-amber,#f59e0b);padding:4px 10px;font-size:11px;border-radius:6px;cursor:pointer;font-weight:600;">
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

    {{-- Header — branded Pattern A (UI_DESIGN_SYSTEM.md §2.4). --}}
    <div class="rounded-md px-6 py-5 mb-4" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Review Presentation</h1>
                <p class="text-sm text-white/60">
                    Confirm the subject facts and the comparable sales we picked, then continue.
                    Untick anything you don't want included — your overrides are logged for future learning.
                </p>
            </div>
            @if($presentation->property)
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('corex.properties.show', $presentation->property) }}" target="_blank"
                   class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    Open property record
                </a>
            </div>
            @endif
        </div>
    </div>

    {{-- ─────────── Import-confirmation summary ───────────
         The honest "did my upload work?" answer: real hydrated counts, not a
         badge. Spec: data-lineage §2.3 / cma-comp-gps-axis-investigation §7. --}}
    @isset($importSummary)
    @php
        $_s = $importSummary;
        $_totalHydrated = ($_s['sold_hydrated'] ?? 0) + ($_s['active_hydrated'] ?? 0);
    @endphp
    <div class="import-summary-banner" role="status" aria-label="Import summary"
         style="display:flex; flex-wrap:wrap; align-items:center; gap:8px 14px; padding:10px 14px; margin-bottom:14px;
                border:1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 28%, transparent);
                background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, transparent);
                border-radius:8px; font-size:12.5px; color:var(--ds-text, #0b2a4a);">
        <span style="font-weight:700;">Imported &amp; hydrated:</span>
        <span>{{ $_s['reports_imported'] ?? 0 }} {{ \Illuminate\Support\Str::plural('report', $_s['reports_imported'] ?? 0) }} imported</span>
        <span aria-hidden="true" style="opacity:.4;">·</span>
        <span>{{ $_s['comps_parsed'] ?? 0 }} {{ \Illuminate\Support\Str::plural('comp', $_s['comps_parsed'] ?? 0) }} parsed</span>
        <span aria-hidden="true" style="opacity:.4;">·</span>
        <span>{{ $_s['sold_hydrated'] ?? 0 }} sold + {{ $_s['active_hydrated'] ?? 0 }} active hydrated</span>
        <span aria-hidden="true" style="opacity:.4;">·</span>
        <span>{{ $_s['mapped'] ?? 0 }} of {{ $_totalHydrated }} mapped</span>
        @if(($_s['unmapped'] ?? 0) > 0)
            <span style="flex-basis:100%; font-size:11px; color:var(--ds-text-muted, #64748b);">
                {{ $_s['unmapped'] }} could not be placed on the map (no location resolved) — they are still counted in the analysis.
            </span>
        @elseif($_totalHydrated === 0)
            <span style="flex-basis:100%; font-size:11px; color:var(--ds-amber, #f59e0b);">
                No comparable sales hydrated yet — upload a CMA report or widen the comp scope, then regenerate.
            </span>
        @endif
    </div>
    @endisset

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
                        <a href="{{ route('corex.properties.show', $presentation->property) }}" target="_blank" style="color: var(--brand-icon, #0ea5e9); text-decoration: none;">
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
                <div class="valuation-cell" style="background: color-mix(in srgb, var(--brand-icon,#0ea5e9) 8%, transparent); border-color: var(--brand-icon,#0ea5e9);">
                    <div class="cell-label">Middle <span id="cma-adjusted-flag" class="cell-adj-flag" {{ ($cmaValuation['condition_applied'] ?? false) ? '' : 'hidden' }}>adjusted</span></div>
                    <div id="cma-middle" class="cell-value">{{ ($cmaValuation['cma_middle'] ?? null) ? 'R ' . number_format($cmaValuation['cma_middle'], 0, '.', ' ') : '—' }}</div>
                </div>
                <div class="valuation-cell">
                    <div class="cell-label">Upper</div>
                    <div id="cma-upper" class="cell-value">{{ ($cmaValuation['cma_upper'] ?? null) ? 'R ' . number_format($cmaValuation['cma_upper'], 0, '.', ' ') : '—' }}</div>
                </div>
            </div>

            {{-- CMA valuation sanity guardrail — additive warning, surfacing only.
                 Fires when the median headline may be unreliable (size-normalised
                 cross-check diverges >30%, or comps/subject are not size-comparable).
                 Never changes the headline number above; same philosophy as the
                 CMA parse-failure alert. --}}
            @php $vg = $cmaValuation['valuation_guardrail'] ?? []; @endphp
            @if(!empty($vg['flagged']))
            <div id="cma-valuation-guardrail" class="review-warn-banner"
                 data-severity="{{ $vg['severity'] ?? 'review' }}"
                 style="margin-top:10px;{{ ($vg['severity'] ?? '') === 'high' ? ' border-left:4px solid var(--ds-amber,#f59e0b);' : '' }}">
                <strong>⚠ Valuation sanity check</strong> — {{ $vg['message'] }}
                <span style="display:block; margin-top:4px; font-size:11px; color:var(--text-muted);">
                    Median headline R {{ number_format((int) ($vg['median_value'] ?? 0), 0, '.', ' ') }}
                    · size-normalised R {{ ($vg['rm2_value'] ?? null) ? number_format((int) $vg['rm2_value'], 0, '.', ' ') : '—' }}
                    @if(!is_null($vg['divergence_pct'] ?? null)) · divergence {{ $vg['divergence_pct'] }}% @endif
                    @if(!empty($vg['basis_mismatch'])) · size-basis mismatch ({{ $vg['basis_ratio'] }}×) @endif
                </span>
            </div>
            @endif

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
                comps included (method: {{ ($cmaValuation['compute_method'] ?? 'median') === 'size_adjusted' ? 'size-adjusted' : 'median' }}).
            </div>

            {{-- STEP 2a — size-adjusted headline note. Shown only when the median
                 was lifted toward the size-normalised value because the subject is
                 a genuinely larger, same-basis stand than its comparables. --}}
            @if(!empty($cmaValuation['headline_lifted']))
            <div id="cma-size-adjusted-note" style="margin-top:6px; font-size:11px; color:var(--text-secondary);">
                Size-adjusted: comp-median R {{ number_format((int) ($cmaValuation['headline_median_raw'] ?? 0), 0, '.', ' ') }}
                lifted to R {{ number_format((int) ($cmaValuation['cma_middle_baseline'] ?? 0), 0, '.', ' ') }}
                (+{{ $cmaValuation['headline_uplift_pct'] ?? 0 }}%) — subject larger than the comparables
                (size-normalised R {{ number_format((int) ($cmaValuation['size_normalised_value'] ?? 0), 0, '.', ' ') }}).
            </div>
            @endif
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
                    <span style="color:var(--ds-amber,#f59e0b);">(middle synthesised from L+U/2)</span>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- ─────────── SECTION 2 — Comparable sales ─────────── --}}
    <div class="review-card">
        <div class="review-section-header">
            <div class="review-section-tag"></div>
            {{-- AT-214 — presentation-scoped: N = comps used in THIS CMA, M = the
                 CANONICAL count available for the property (CmaCoverageService, the
                 same figure as the Intelligence panel + coverage badge). Not
                 count($compRows) (this presentation's own set), which read "N of N"
                 and implied every available comp was used. --}}
            @php
                $compsUsed      = collect($compRows)->where('is_included', true)->count();
                $compsAvailable = max((int) ($canonicalCompCount ?? 0), $compsUsed);
            @endphp
            <h2 class="review-section-title">2 · Comparable Sales — {{ $compsUsed }} of {{ $compsAvailable }} comps used in this CMA</h2>
        </div>

        <div class="review-comps-layout">
            <div>
                {{-- AT-22 / AT-21 — curation toolkit. Sort any column, filter +
                     select by price range, select-all/none/visible, bulk tick
                     on the current view. All writes go to included_comp_ids_json
                     via the batch endpoint (one source of truth). --}}
                <div id="comp-toolkit" data-set-url="{{ route('presentations.review.set-comps', ['version' => $version->id]) }}"
                     data-browse-url="{{ route('presentations.review.browse-comps', ['version' => $version->id]) }}"
                     data-add-url="{{ route('presentations.review.add-comps', ['version' => $version->id]) }}"
                     style="margin-bottom: 10px; display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:11px; color:var(--text-muted);">
                        <button type="button" class="comp-tool-btn" data-sel="all">Select all</button>
                        <button type="button" class="comp-tool-btn" data-sel="none">Select none</button>
                        <button type="button" class="comp-tool-btn" data-sel="visible">Select visible</button>
                        <span style="width:1px;height:16px;background:var(--border);"></span>
                        <button type="button" class="comp-tool-btn" data-bulk="include">Tick visible</button>
                        <button type="button" class="comp-tool-btn" data-bulk="exclude">Untick visible</button>
                        <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;margin-left:auto;">
                            <input type="checkbox" id="show-excluded" checked>
                            <span>Show excluded</span>
                        </label>
                        <span><span id="comp-included-count">{{ collect($compRows)->where('is_included',true)->count() }}</span>/{{ count($compRows) }} included</span>
                    </div>
                    {{-- Price-range slider — drag to select comps within a band --}}
                    @php
                        $compPrices = collect($compRows)->pluck('sold_price_inc')->filter()->values();
                        $priceFloor = (int) ($compPrices->min() ?? 0);
                        $priceCeil  = (int) ($compPrices->max() ?? 0);
                    @endphp
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:11px; color:var(--text-muted);">
                        <span style="font-weight:600;">Price range</span>
                        <input type="range" id="price-min" min="{{ $priceFloor }}" max="{{ $priceCeil }}" value="{{ $priceFloor }}" step="10000" style="flex:1; min-width:120px;">
                        <input type="range" id="price-max" min="{{ $priceFloor }}" max="{{ $priceCeil }}" value="{{ $priceCeil }}" step="10000" style="flex:1; min-width:120px;">
                        <span id="price-range-label" style="min-width:170px; text-align:right; font-variant-numeric:tabular-nums;"></span>
                        <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;">
                            <input type="checkbox" id="price-selects" checked>
                            <span>selects comps in range</span>
                        </label>
                    </div>
                </div>

                <div id="comp-table" style="border: 1px solid var(--border); border-radius: 4px; overflow: hidden;">
                    <div class="comp-row" style="background: var(--surface-2); font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted);">
                        <div></div>
                        <div class="sortable" data-sort="address">Address <span class="sort-arrow"></span></div>
                        <div class="sortable" data-sort="saledate">Sale date <span class="sort-arrow"></span></div>
                        <div class="sortable" data-sort="price" style="text-align:right;">Sold price <span class="sort-arrow"></span></div>
                        <div class="sortable" data-sort="type">Type <span class="sort-arrow"></span></div>
                        <div class="sortable" data-sort="rm2" style="text-align:right;">R/m² <span class="sort-arrow"></span></div>
                        <div class="sortable" data-sort="titletype">Title <span class="sort-arrow"></span></div>
                        <div class="sortable" data-sort="distance" style="text-align:right;">Dist <span class="sort-arrow"></span></div>
                        <div></div>
                    </div>
                    @forelse($compRows as $row)
                        <div class="comp-row {{ $row['is_included'] ? '' : 'excluded' }} {{ $row['is_cross_type'] ? 'cross-type' : '' }}"
                             data-comp-id="{{ $row['id'] }}"
                             data-included="{{ $row['is_included'] ? '1' : '0' }}"
                             data-cross-type="{{ $row['is_cross_type'] ? '1' : '0' }}"
                             data-lat="{{ $row['lat'] }}"
                             data-lng="{{ $row['lng'] }}"
                             data-title-type="{{ $row['title_type'] }}"
                             data-price="{{ (int) ($row['sold_price_inc'] ?? 0) }}"
                             data-distance="{{ $row['distance_m'] ?? '' }}"
                             data-size="{{ (int) ($row['size_m2'] ?? 0) }}"
                             data-rm2="{{ (int) ($row['r_per_m2'] ?? 0) }}"
                             data-type="{{ $row['property_type'] }}"
                             data-saledate="{{ $row['sale_date'] ?? '' }}"
                             data-address="{{ \Illuminate\Support\Str::lower($row['address']) }}">
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
                            <div style="text-align:right; color: var(--text-muted); font-size:11px;">
                                {{ $row['distance_m'] !== null ? ($row['distance_m'] < 1000 ? $row['distance_m'].'m' : number_format($row['distance_m']/1000, 1).'km') : '—' }}
                            </div>
                            <div title="{{ $row['is_cross_type'] ? 'Cross-title comparison — not recommended for valuation' : '' }}"
                                 style="font-size: 14px; color: {{ $row['is_cross_type'] ? 'var(--ds-amber,#f59e0b)' : 'transparent' }};">
                                {{ $row['is_cross_type'] ? '!' : '' }}
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            No comparable sales were found for this subject. Use “Browse more freehold comps” below to pull in sales beyond the auto-pool.
                        </div>
                    @endforelse
                </div>

                {{-- AT-22 — browse & pull in freehold comps BEYOND the auto-pool.
                     The gate stops at a tight, defensible set; this lets the
                     agent reach genuine comparable sales a little further out
                     (e.g. premium homes just past the auto radius) and add them. --}}
                <div id="comp-browse" style="margin-top:12px; border:1px solid var(--border); border-radius:4px; padding:10px; background:var(--surface-2);">
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:11px; color:var(--text-muted);">
                        <span style="font-weight:600; color:var(--text-primary);">Browse more freehold comps</span>
                        <label>within
                            <input type="number" id="browse-radius" value="3000" min="100" max="20000" step="100" style="width:74px;"> m
                        </label>
                        <label>R
                            <input type="number" id="browse-pmin" placeholder="min" min="0" step="50000" style="width:90px;">
                        </label>
                        <label>–
                            <input type="number" id="browse-pmax" placeholder="max" min="0" step="50000" style="width:90px;">
                        </label>
                        <button type="button" class="comp-tool-btn" id="browse-search">Search</button>
                        <button type="button" class="comp-tool-btn" id="browse-add" disabled>Add selected</button>
                        <span id="browse-status" style="margin-left:auto;"></span>
                    </div>
                    <div id="browse-results" style="margin-top:8px; max-height:240px; overflow:auto; display:none;"></div>
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
                    <span><span style="display:inline-block;width:9px;height:9px;background:#f59e0b;margin-right:4px;transform:rotate(45deg);"></span>Active competition</span>
                </div>
                <div id="review-map-plot-caption" style="margin-top:4px; font-size:11px; color: var(--text-muted);"></div>
            </div>
        </div>
    </div>

    {{-- ─────────── SECTION 2b — Active Competition (scored stock) ─────────── --}}
    @php
        // VISIBLE = what renders on the section (top-N auto-pick when no
        // override, or the agent's whitelist when overridden — already
        // includes whitelist-only extras outside the auto-pool per
        // decision B). MATCHES is the full auto-pool, used for the modal
        // bootstrap + the "of N scored" summary in the header.
        $competitorVisible  = $competitorStock['visible']      ?? [];
        $competitorMatches  = $competitorStock['matches']      ?? [];
        $competitorDisplayCap = $competitorStock['display_cap'] ?? null;
        // Annotate each visible row with is_included=true (visible IS the
        // included set, by definition — either auto-top-N or whitelist).
        $competitorVisibleForJs = array_map(function ($m) {
            $m['is_included'] = true;
            return $m;
        }, $competitorVisible);
        $totalScored   = count($competitorMatches);
        $visibleCount  = count($competitorVisible);
    @endphp
    @if($totalScored > 0)
    {{-- Shared listing-card builder. Defines window.CoreXBuildListingCard. --}}
    @include('partials._listing-card-helper')

    <div class="review-card"
         x-data="competitorPicker({
             searchUrl:     '{{ route('presentations.review.competitor-picker', $version->id) }}',
             dataUrl:       '{{ route('presentations.review.competitor-data',  $version->id) }}',
             toggleTpl:     '{{ route('presentations.review.toggle-competitor', ['version' => $version->id, 'listingId' => '__LISTING_ID__']) }}',
             csrf:          '{{ csrf_token() }}',
             displayCap:    {{ (int) ($competitorDisplayCap ?? 10) }},
         })">
        <div class="review-section-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <div class="review-section-tag" style="background:#7c3aed;"></div>
                <h2 class="review-section-title" style="margin:0;">
                    2b · Active Competition —
                    <span id="competitor-count-summary">
                        showing top {{ $visibleCount }} of {{ $totalScored }} scored
                    </span>
                </h2>
            </div>
            {{-- Manual-picker CTA. Opens the modal pre-populated to the
                 auto-picker's criteria (suburb / family / property_type /
                 price band). Agent can widen filters; the Level-1 family
                 gate stays enforced on the backend. --}}
            <button type="button"
                    @click="openModal()"
                    class="prop-action-btn prop-action-btn-neutral"
                    style="font-size:11px;font-weight:600;padding:6px 12px;"
                    title="Browse all sectional/freehold stock in the family and tick which to include in the seller PDF.">
                Attach other properties
            </button>
        </div>
        <p style="margin:0 0 12px 0;font-size:11px;color:var(--text-muted);">
            Active stock the seller competes against (P24 + PP alert imports), scored against
            this subject. Top {{ $competitorDisplayCap ?? 10 }} auto-included; the rest live in the
            picker. Tick to include in the seller PDF; unticked cards drop from the published version.
        </p>
        <div id="competitor-stock-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;"></div>

        @include('presentations._competitor-picker-modal')
    </div>

    <script>
    (function () {
        // Exposed on window so the review-map script (which runs earlier
        // in the page) can read the visible competition rows for marker
        // rendering. Same array drives the cards + the orange diamonds.
        window.COMPETITOR_VISIBLE = @json($competitorVisibleForJs);
        var COMPETITOR_VISIBLE = window.COMPETITOR_VISIBLE;
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

        // Exposed on window so the manual-picker modal's close handler
        // can re-render the list from a refreshed competitor-data
        // payload (same card mapper, no DOM logic duplicated).
        window.buildCompetitorCard = function (m) {
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
                '<label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;font-size:10px;color:var(--text-muted,#94a3b8);" title="Include in seller PDF">'
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
                // AT-22 item 2 (CRITICAL): no third-party agency/agent name on
                // the seller-facing card. The row still CARRIES agency_name for
                // internal/provenance surfaces (e.g. the comp-picker modal) —
                // the seller card simply does not emit it. A competitor brand
                // on a Home Finders seller report is unshippable.
                agent_name:   null,
                ref:          m.portal_ref || null,
                click_url:    m.portal_url || null,
                badges:       badges,
                top_right_pill: { label: m.score + '%', fg: tierBadge(m.tier).fg, bg: 'rgba(255,255,255,0.92)' },
                actions_html: includeToggle,
            });

            return '<div class="competitor-card' + (m.is_included ? '' : ' excluded')
                + '" data-listing-id="' + m.listing_id + '"'
                + (m.is_included ? '' : ' style="opacity:0.45;"') + '>' + html + '</div>';
        };

        // Renderer also on window — called on initial paint AND by the
        // modal-close path (fetches /review/competitor-data then calls
        // this with the fresh visible[] array).
        window.renderCompetitorStockList = function (visibleRows, summaryText) {
            var el = document.getElementById('competitor-stock-list');
            if (!el) return;
            el.innerHTML = (visibleRows || []).map(window.buildCompetitorCard).join('');
            if (summaryText) {
                var summaryEl = document.getElementById('competitor-count-summary');
                if (summaryEl) summaryEl.textContent = summaryText;
            }
        };

        window.renderCompetitorStockList(COMPETITOR_VISIBLE);
    })();
    </script>
    @endif

    {{-- ─────────── SECTION 3 — Continue ─────────── --}}
    {{-- AT-27 Phase B.3 — the "What's in your presentation" section toggles
         moved to the Analysis screen (inclusion decisions belong where the
         numbers are visible). Review keeps per-comp curation only. --}}
    <div class="review-card">
        <div class="review-section-header">
            <div class="review-section-tag"></div>
            <h2 class="review-section-title">3 · Continue</h2>
        </div>

        <p style="font-size:12px; color:var(--text-muted); margin:0 0 4px 0;">
            Comp curation saves automatically. Continue to the Analysis screen to finalise the
            numbers and choose which sections appear, then Confirm &amp; Generate.
        </p>

        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
            <button id="btn-continue" type="button" class="corex-btn-primary">
                Continue to Analysis
            </button>
            <button id="btn-save" type="button" class="corex-btn-outline">
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
    const CONTINUE_URL = @json(route('presentations.review.continue', $version->id));
    const REVERT_URL  = @json(route('presentations.review.revert',  $version->id));
    const CONDITION_URL = @json(route('presentations.review.condition', $version->id));
    {{-- AT-27 Phase B.3 — SECTION_URL moved with the toggles to Analysis. --}}
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

    // ── Active Competition layer — orange diamond, distinct from sold-
    // comp circles/squares so "what's competing now" reads as a different
    // visual stratum from "what sold". Wired to the competition tick
    // state via window.competitionMarkers (Map keyed by listing_id) so
    // the modal close + per-card untick paths can show/hide markers in
    // place. Honest no-coord handling — rows without lat/lng are silently
    // skipped (no fake fallback pin) and counted in the caption below.
    function diamondSvg(px) {
        const half = px / 2;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + px + '" height="' + px + '" viewBox="0 0 ' + px + ' ' + px + '">'
             + '<polygon points="' + half + ',1 ' + (px - 1) + ',' + half + ' ' + half + ',' + (px - 1) + ' 1,' + half + '"'
             + ' fill="#f59e0b" stroke="#ffffff" stroke-width="2"/></svg>';
    }
    window.competitionMarkers = window.competitionMarkers || new Map();

    function renderCompetitionMarkers(visibleRows) {
        // Clear existing layer (modal close re-fetches and re-renders).
        window.competitionMarkers.forEach(m => map.removeLayer(m));
        window.competitionMarkers.clear();
        let plotted = 0, unplotted = 0;
        (visibleRows || []).forEach(row => {
            const lat = row.latitude  !== null && row.latitude  !== undefined ? parseFloat(row.latitude)  : NaN;
            const lng = row.longitude !== null && row.longitude !== undefined ? parseFloat(row.longitude) : NaN;
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                unplotted++;
                return;
            }
            plotted++;
            const m = L.marker([lat, lng], {
                icon: L.divIcon({
                    html: diamondSvg(16),
                    className: 'review-pin review-pin-competition',
                    iconSize: [16, 16], iconAnchor: [8, 8],
                }),
                zIndexOffset: 500,
            });
            const title = (row.address || ('Listing #' + row.listing_id))
                        + (row.price ? ' · R ' + Number(row.price).toLocaleString('en-ZA') : '')
                        + (row.score ? ' · ' + row.score + '%' : '');
            m.bindTooltip(title, { direction: 'top' });
            m.addTo(map);
            window.competitionMarkers.set(row.listing_id, m);
        });
        return { plotted, unplotted };
    }

    // Initial paint — visible rows are passed in by the section script
    // (window.COMPETITOR_VISIBLE is populated at section render time).
    window.refreshMapCaption = function (compCounts, competitionCounts) {
        const el = document.getElementById('review-map-plot-caption');
        if (!el) return;
        const parts = [];
        const compPlotted   = compCounts?.plotted   ?? 0;
        const compUnplotted = compCounts?.unplotted ?? 0;
        const competitionPlotted   = competitionCounts?.plotted   ?? 0;
        const competitionUnplotted = competitionCounts?.unplotted ?? 0;
        parts.push('Sold comps: ' + compPlotted + ' plotted' + (compUnplotted ? ' · ' + compUnplotted + ' no location' : ''));
        parts.push('Active competition: ' + competitionPlotted + ' plotted' + (competitionUnplotted ? ' · ' + competitionUnplotted + ' no location' : ''));
        el.textContent = parts.join(' · ');
    };

    // Count sold-comp plotted vs unplotted from the rows we already
    // iterated above (markers Map holds the plotted set; the table rows
    // include those with no lat/lng too).
    const compPlottedCount = markers.size;
    const compTotalIncluded = document.querySelectorAll('#comp-table .comp-row[data-included="1"]').length;
    const compUnplotted = Math.max(0, compTotalIncluded - compPlottedCount);

    // Source of truth for competition rows on the section render is
    // COMPETITOR_VISIBLE, defined in the Active Competition script
    // below. We hook the initial paint via a microtask so that runs
    // first.
    queueMicrotask(() => {
        const competitionRows = window.COMPETITOR_VISIBLE || [];
        const counts = renderCompetitionMarkers(competitionRows);
        window.refreshMapCaption(
            { plotted: compPlottedCount, unplotted: compUnplotted },
            counts,
        );
    });

    // Expose for the manual-picker modal close path: re-render the
    // competition markers from a fresh visible[] payload + update the
    // caption.
    window.refreshCompetitionMarkers = function (visibleRows) {
        const counts = renderCompetitionMarkers(visibleRows);
        window.refreshMapCaption(
            { plotted: compPlottedCount, unplotted: compUnplotted },
            counts,
        );
    };

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

    // ── AT-22 / AT-21 — Comparable-sales curation toolkit ─────────────
    // Sort any column, filter+select by price range, select-all/none/visible,
    // bulk tick the current view, and browse+add freehold comps beyond the
    // auto-pool. All selection writes the FULL included set once via the
    // batch endpoint — one source of truth (included_comp_ids_json). Bulk ops
    // set checkboxes programmatically (no 'change' event → the per-row
    // toggleComp handler above does NOT double-fire).
    (function compToolkit() {
        const tk = document.getElementById('comp-toolkit');
        const table = document.getElementById('comp-table');
        if (!tk || !table) return;
        const setUrl = tk.dataset.setUrl, browseUrl = tk.dataset.browseUrl, addUrl = tk.dataset.addUrl;
        const countEl = document.getElementById('comp-included-count');
        const rows = () => Array.from(table.querySelectorAll('.comp-row[data-comp-id]'));
        const isVisible = (r) => r.style.display !== 'none';
        const fmtR = (v) => 'R ' + Number(v || 0).toLocaleString('en-ZA');

        function setRowIncluded(row, next) {
            const id = parseInt(row.dataset.compId, 10);
            const cb = row.querySelector('.comp-toggle');
            if (cb) cb.checked = next;
            row.dataset.included = next ? '1' : '0';
            row.classList.toggle('excluded', !next);
            const marker = (typeof markers !== 'undefined') ? markers.get(id) : null;
            if (marker) { if (next) marker.addTo(map); else map.removeLayer(marker); }
        }
        function updateCount() {
            if (countEl) countEl.textContent = rows().filter(r => r.dataset.included === '1').length;
        }
        let commitTimer = null;
        function commitIncluded() {
            updateCount();
            clearTimeout(commitTimer);
            commitTimer = setTimeout(() => {
                const ids = rows().filter(r => r.dataset.included === '1').map(r => parseInt(r.dataset.compId, 10));
                const body = new FormData();
                body.append('_token', csrf);
                ids.forEach(id => body.append('included_ids[]', id));
                fetch(setUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body, credentials: 'same-origin' })
                    .then(r => r.json()).then(d => { if (d && d.ok) applyCmaUpdate(d); else toast('Could not save selection'); })
                    .catch(() => toast('Network error saving selection'));
            }, 350);
        }

        // Sorting — reorder the row elements in place.
        let sortKey = null, sortDir = 1;
        function sortBy(key) {
            sortDir = (sortKey === key) ? -sortDir : 1;
            sortKey = key;
            const dk = (key === 'titletype') ? 'titleType' : key;
            const numeric = ['price', 'distance', 'rm2', 'size'].includes(key);
            const get = (r) => numeric ? (parseFloat(r.dataset[dk]) || 0) : (r.dataset[dk] || '').toString();
            rows().sort((a, b) => { const x = get(a), y = get(b); return (x < y ? -1 : x > y ? 1 : 0) * sortDir; })
                  .forEach(r => table.appendChild(r));
            table.querySelectorAll('.sort-arrow').forEach(el => el.textContent = '');
            const arr = table.querySelector('.sortable[data-sort="' + key + '"] .sort-arrow');
            if (arr) arr.textContent = sortDir > 0 ? '▲' : '▼';
        }
        table.querySelectorAll('.sortable').forEach(h => h.addEventListener('click', () => sortBy(h.dataset.sort)));

        // Price-range slider.
        const pmin = document.getElementById('price-min'), pmax = document.getElementById('price-max');
        const plabel = document.getElementById('price-range-label'), pselects = document.getElementById('price-selects');
        function priceBounds() { const lo = Math.min(+pmin.value, +pmax.value), hi = Math.max(+pmin.value, +pmax.value); if (plabel) plabel.textContent = fmtR(lo) + ' – ' + fmtR(hi); return [lo, hi]; }
        function applyPrice() {
            const [lo, hi] = priceBounds();
            if (!pselects || !pselects.checked) return;
            rows().forEach(r => setRowIncluded(r, (+r.dataset.price || 0) >= lo && (+r.dataset.price || 0) <= hi));
            commitIncluded();
        }
        if (pmin && pmax) { pmin.addEventListener('input', applyPrice); pmax.addEventListener('input', applyPrice); priceBounds(); }

        // Select all / none / visible + bulk tick the visible view.
        tk.querySelectorAll('[data-sel]').forEach(b => b.addEventListener('click', () => {
            const mode = b.dataset.sel;
            rows().forEach(r => { if (mode === 'all') setRowIncluded(r, true); else if (mode === 'none') setRowIncluded(r, false); else if (mode === 'visible') setRowIncluded(r, isVisible(r)); });
            commitIncluded();
        }));
        tk.querySelectorAll('[data-bulk]').forEach(b => b.addEventListener('click', () => {
            const inc = b.dataset.bulk === 'include';
            rows().forEach(r => { if (isVisible(r)) setRowIncluded(r, inc); });
            commitIncluded();
        }));

        // (Show/hide excluded rows is wired once, further below — not here,
        // to avoid double-binding the #show-excluded checkbox.)

        // Browse & add freehold comps beyond the auto-pool.
        const bRadius = document.getElementById('browse-radius'), bMin = document.getElementById('browse-pmin'), bMax = document.getElementById('browse-pmax');
        const bSearch = document.getElementById('browse-search'), bAdd = document.getElementById('browse-add');
        const bStatus = document.getElementById('browse-status'), bResults = document.getElementById('browse-results');
        if (bSearch) bSearch.addEventListener('click', () => {
            bStatus.textContent = 'Searching…';
            const url = new URL(browseUrl, window.location.origin);
            url.searchParams.set('radius_m', bRadius.value || 3000);
            if (bMin.value) url.searchParams.set('price_min', bMin.value);
            if (bMax.value) url.searchParams.set('price_max', bMax.value);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(r => r.json()).then(d => {
                    const cs = (d && d.candidates) || [];
                    bStatus.textContent = cs.length + ' found' + (d && d.reason === 'subject_no_coords' ? ' (subject has no GPS)' : '');
                    bResults.style.display = cs.length ? '' : 'none';
                    bResults.innerHTML = cs.map(c => '<label style="display:grid;grid-template-columns:22px 1fr 96px 60px 56px;gap:6px;align-items:center;padding:4px 2px;border-bottom:1px solid var(--border);font-size:11px;">'
                        + '<input type="checkbox" class="browse-cb" value="' + c.comp_row_id + '">'
                        + '<span title="' + (c.address || '') + '" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (c.address || '—') + '</span>'
                        + '<span style="text-align:right;font-weight:600;">R ' + Number(c.sold_price).toLocaleString('en-ZA') + '</span>'
                        + '<span style="text-align:right;color:var(--text-muted);">' + (c.size_m2 ? c.size_m2 + 'm²' : '—') + '</span>'
                        + '<span style="text-align:right;color:var(--text-muted);">' + (c.distance_m < 1000 ? c.distance_m + 'm' : (c.distance_m / 1000).toFixed(1) + 'km') + '</span>'
                        + '</label>').join('');
                    bAdd.disabled = cs.length === 0;
                }).catch(() => { bStatus.textContent = 'Search failed'; });
        });
        if (bAdd) bAdd.addEventListener('click', () => {
            const ids = Array.from(bResults.querySelectorAll('.browse-cb:checked')).map(cb => parseInt(cb.value, 10));
            if (!ids.length) { toast('Select comps to add'); return; }
            bStatus.textContent = 'Adding…'; bAdd.disabled = true;
            const body = new FormData(); body.append('_token', csrf); ids.forEach(id => body.append('comp_row_ids[]', id));
            fetch(addUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body, credentials: 'same-origin' })
                .then(r => r.json()).then(d => { if (d && d.ok) { bStatus.textContent = 'Added ' + d.added_count + ' — reloading…'; window.location.reload(); } else { bStatus.textContent = 'Add failed'; bAdd.disabled = false; } })
                .catch(() => { bStatus.textContent = 'Network error'; bAdd.disabled = false; });
        });

        updateCount();
    })();

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

            // Optimistic dim/undim + diamond show/hide on the map.
            if (card) {
                card.style.opacity = next ? '' : '0.45';
                card.classList.toggle('excluded', !next);
            }
            if (window.competitionMarkers) {
                const marker = window.competitionMarkers.get(listingId);
                if (marker) {
                    if (next) marker.addTo(map); else map.removeLayer(marker);
                }
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

    // ── Continue to Analysis / Revert ────────────────────────────────
    // AT-27 Phase A — the forward action persists curation only and hands off
    // to the Analysis working surface (no publish/freeze here).
    document.getElementById('btn-continue').addEventListener('click', async () => {
        const body = new FormData(); body.append('_token', csrf);
        try {
            const r = await fetch(CONTINUE_URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body, credentials: 'same-origin',
            });
            const d = await r.json();
            if (d?.ok && d.redirect_url) {
                window.location.href = d.redirect_url;
            } else {
                toast('Could not continue — please retry');
            }
        } catch (e) { toast('Network error'); }
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

    // AT-27 Phase B.3 — section-toggle handler MOVED to the Analysis screen
    // (resources/views/presentations/analysis.blade.php). Review keeps per-comp
    // curation only.

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
