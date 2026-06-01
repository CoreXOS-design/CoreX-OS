{{--
    MIC Phase D4 — Opportunities tab. Replaces the D1 stub.
    Spec: .ai/specs/mic-complete-spec.md §5.4.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
--}}
@extends('layouts.corex-app')

@section('corex-content')
<div style="width: 100%;">

    <x-mic-page-header
        title="Opportunities"
        subtitle="Tracked properties matched to your buyers and renters, ranked by strong-match count." />

    @include('corex.market-intelligence.partials.tabs')

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="margin-bottom: 12px;
                    background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="margin-bottom: 12px;
                    background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">{{ session('error') }}</div>
        </div>
    @endif

    @include('corex.market-intelligence.partials.opportunities-stats-strip')
    @include('corex.market-intelligence.partials.opportunities-filter-chips')
    @include('corex.market-intelligence.partials.opportunities-secondary-filters')

    <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 6px;">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin: 0;">
            Showing {{ $tps->total() }} {{ $tps->total() === 1 ? 'property' : 'properties' }}
            @if(($activeFilter ?? 'all') !== 'all')
                <span style="font-weight: 400; color: var(--text-muted);">· filter: {{ str_replace('_', ' ', $activeFilter) }}</span>
            @endif
        </h2>
        <span style="font-size: 0.6875rem; color: var(--text-muted);">
            Sorted by strong-match count, most recent first
        </span>
    </div>

    @include('corex.market-intelligence.partials.opportunities-list')

</div>
@endsection
