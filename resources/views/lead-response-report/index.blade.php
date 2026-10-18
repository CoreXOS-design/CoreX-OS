@extends('layouts.corex-app')

@section('title', 'Lead Response')

@section('corex-content')
{{-- Lead Response report (Johan, 2026-10-07) — a report of its own under Reports. .ai/specs/lead-response-time.md §5.
     Same page shell, period selector, clickable figures and detail popup as the Buyers Report; the popup component
     is shared from there (it only needs a drilldownBase URL). --}}
<div class="w-full space-y-5" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Lead Response</h1>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                    {{ match($scope->level) { 'own' => 'Your leads', 'branch' => 'Your branch', default => 'Whole agency' } }}
                    · {{ $periodLabel }}
                </p>
            </div>
            <div class="flex items-end gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <button type="button" onclick="window.open(leadResponseExportUrl('{{ route('lead-response-report.print') }}'), '_blank')"
                            class="text-xs font-medium px-3 py-1.5 rounded-md" style="border: 1px solid var(--border); color: var(--text-primary); background: var(--surface);">Print</button>
                    <button type="button" onclick="window.location.href = leadResponseExportUrl('{{ route('lead-response-report.pdf') }}')"
                            class="text-xs font-medium px-3 py-1.5 rounded-md" style="border: 1px solid var(--border); color: #fff; background: var(--brand-icon, #0ea5e9);">Download PDF</button>
                </div>
                @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets, 'compareMode' => $compareMode, 'compareModes' => $compareModes])
            </div>
        </div>
    </div>

    @if(session('period_error'))
        <div class="text-xs px-3 py-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 12%, transparent); color: var(--ds-crimson, #c41e3a);">{{ session('period_error') }}</div>
    @endif
    @if(session('compare_error'))
        <div class="text-xs px-3 py-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 12%, transparent); color: var(--ds-crimson, #c41e3a);">Comparison range: {{ session('compare_error') }}</div>
    @endif
    {{-- Period comparison (Johan, 2026-10-07) — same banner as the Performance & ROI report: what it is compared to,
         and a plain warning when the two ranges are not the same length. --}}
    @if($comparisonMeta)
        <div class="text-xs px-3 py-2 rounded flex items-center justify-between gap-3 flex-wrap"
             style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
            <span>Comparing to <strong style="color:var(--text-primary);">{{ $comparisonMeta['period']['label'] }}</strong> &middot; for response time, lower is better</span>
            @if($comparisonMeta['unequal_length'])
                <span style="color:var(--ds-amber, #f59e0b); font-weight:600;">
                    Unequal-length ranges — comparing {{ $comparisonMeta['period_days'] }} days to {{ $comparisonMeta['comparison_days'] }} days. Totals are not like-for-like.
                </span>
            @endif
        </div>
    @endif

    @include('lead-response-report._figures')

    @include('buyers-report._drilldown-modal')
</div>
<style>
    /* The delta line the Performance & ROI report draws (same classes, same size). */
    .report-delta { display: block; font-size: 9px; line-height: 1.3; font-weight: 500; white-space: nowrap; }
    .report-delta-good { color: var(--ds-green, #059669); }
    .report-delta-bad { color: var(--ds-crimson, #c41e3a); }
    .report-delta-neutral { color: var(--text-muted); }
</style>
<script>
    // Print / PDF carry whatever scope, period and custom dates are in the URL right now.
    function leadResponseExportUrl(base) {
        const qs = window.location.search;
        return base + (qs ? qs : '');
    }
</script>
@endsection
