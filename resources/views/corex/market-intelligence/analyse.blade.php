{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    MIC Phase D1 — Analyse tab (standalone view extending the layout).
    Body content unchanged from F.6: Ellie brief, 2-col grid with matrix +
    velocity + funnel left, pockets + agency share right.

    Spec: build-f-market-intelligence-redesign-spec.md §9 +
          .ai/specs/mic-complete-spec.md §5.2.
--}}
@extends('layouts.corex-app')

@section('corex-content')

@include('corex.market-intelligence.partials.tabs')

<header class="mi-header"
        style="position: sticky; top: 0; z-index: 10; background: var(--surface);">
    @include('corex.market-intelligence._top-bar')
    @include('corex.market-intelligence._stats-strip', [
        'snapshotKpis'       => $snapshotKpis,
        'actionPresetCounts' => $actionPresetCounts,
        'actionPreset'       => null,
    ])
</header>

<div class="mi-analyse-body" style="padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; max-width: 1640px; margin: 0 auto;">

    @include('corex.market-intelligence._ellie-brief', ['brief' => $data['brief']])

    <div class="mi-analyse-grid"
         style="display: grid; grid-template-columns: 1fr 320px; gap: 16px; align-items: start;">

        <div class="mi-analyse-left" style="display: flex; flex-direction: column; gap: 16px; min-width: 0;">
            @include('corex.market-intelligence._heat-matrix', ['matrix' => $data['matrix']])
            @include('corex.market-intelligence._market-velocity', ['velocity' => $data['velocity']])
            @include('corex.market-intelligence._buyer-funnel-analyse', [
                'snapshot' => $snapshot,
                'filters'  => $filters,
                'urlWith'  => $urlWith,
            ])
        </div>

        <div class="mi-analyse-right" style="display: flex; flex-direction: column; gap: 16px; min-width: 0;">
            @include('corex.market-intelligence._opportunity-pockets', ['pockets' => $data['pockets']])
            @include('corex.market-intelligence._agency-share', [
                'comp'   => $data['competitive'],
                'suburb' => $data['competitive_suburb'],
            ])
        </div>
    </div>
</div>

<style>
    @media (max-width: 1024px) {
        .mi-analyse-grid { grid-template-columns: 1fr !important; }
    }
    @media (max-width: 768px) {
        .mi-analyse-body { padding: 10px !important; gap: 10px !important; }
    }

    /* Shared card style for every Analyse panel. */
    .mi-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 14px;
        min-width: 0;
    }
    .mi-card-title {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin-bottom: 4px;
    }
    .mi-card-subtitle {
        font-size: 0.6875rem;
        color: var(--text-muted);
        margin-bottom: 10px;
    }
</style>
@endsection
