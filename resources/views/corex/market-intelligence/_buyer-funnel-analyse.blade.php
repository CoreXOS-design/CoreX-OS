{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.6 — Buyer funnel for Analyse mode. Wraps the existing legacy
    `prospecting._buyer-funnel` partial (which expects $snapshot,
    $filters, $urlWith) inside the Analyse card chrome so it visually
    fits the rest of the Analyse mode.

    Spec: §9.6.
--}}

<div class="mi-card">
    <div class="mi-card-title">Buyer funnel</div>
    <div class="mi-card-subtitle">new buyers entering by status, over time windows</div>

    @include('prospecting._buyer-funnel', [
        'snapshot' => $snapshot,
        'filters'  => $filters,
        'urlWith'  => $urlWith,
    ])
</div>
