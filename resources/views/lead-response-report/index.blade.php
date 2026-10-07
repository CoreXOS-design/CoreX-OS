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
                @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets])
            </div>
        </div>
    </div>

    @if(session('period_error'))
        <div class="text-xs rounded-md px-3 py-2" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ session('period_error') }}</div>
    @endif

    @include('lead-response-report._figures')

    @include('buyers-report._drilldown-modal')
</div>
<script>
    // Print / PDF carry whatever scope, period and custom dates are in the URL right now.
    function leadResponseExportUrl(base) {
        const qs = window.location.search;
        return base + (qs ? qs : '');
    }
</script>
@endsection
