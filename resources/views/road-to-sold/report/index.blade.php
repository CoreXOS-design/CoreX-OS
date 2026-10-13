{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold report')

@section('corex-content')
@php
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $phrase = $comparisonMeta['phrase'] ?? '';
    $sortUrl = function (string $key) use ($sort, $dir) {
        $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';

        return request()->fullUrlWithQuery(['sort' => $key, 'dir' => $next, 'page' => null]);
    };
    $arrow = fn (string $key) => $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $exportQuery = request()->getQueryString();
    $exportUrl = fn (string $name) => route($name) . ($exportQuery ? '?' . $exportQuery : '');
@endphp

<div class="w-full space-y-4" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">

    <div class="rounded-md px-6 py-4 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Road to Sold report</h1>
                <div class="text-xs mt-0.5" style="color: var(--text-muted);">{{ $scopeLabel }} · {{ $period->label }}</div>
            </div>
            <div class="flex items-end gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.index') }}" class="corex-btn-outline corex-btn-xs">Back to Road to Sold</a>
                <a href="{{ $exportUrl('road-to-sold.report.print') }}" target="_blank" class="corex-btn-outline corex-btn-xs">Print</a>
                <a href="{{ $exportUrl('road-to-sold.report.pdf') }}" class="corex-btn-outline corex-btn-xs">PDF</a>
                <a href="{{ $exportUrl('road-to-sold.report.xlsx') }}" class="corex-btn-outline corex-btn-xs">Excel</a>
                @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets, 'compareMode' => $compareMode, 'compareModes' => $compareModes])
            </div>
        </div>
    </div>

    @if(session('period_error'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ session('period_error') }}</div>
    @endif
    @if(session('compare_error'))
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">Comparison range: {{ session('compare_error') }}</div>
    @endif
    @if($comparisonMeta)
        <div class="text-xs px-3 py-2 rounded flex items-center justify-between gap-3 flex-wrap" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
            <span>Comparing to <strong style="color: var(--text-primary);">{{ $comparisonMeta['period']['label'] }}</strong></span>
            @if($comparisonMeta['unequal_length'])
                <span style="color: var(--ds-amber, #f59e0b); font-weight: 600;">Unequal-length ranges: comparing {{ $comparisonMeta['period_days'] }} days to {{ $comparisonMeta['comparison_days'] }} days. Totals are not like-for-like.</span>
            @endif
        </div>
    @endif

    {{-- Filters --}}
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        @foreach(['period', 'start', 'end', 'compare', 'compare_start', 'compare_end'] as $keep)
            @if(request()->filled($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif
        @endforeach
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rr-q">Search agent</label>
            <input id="rr-q" type="text" name="q" value="{{ $filters['q'] }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">
        </div>
        @if($branches->count() > 1)
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="rr-branch">Branch</label>
                <select id="rr-branch" name="branch_id" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) $filters['branch_id'] === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filters['q'] !== '' || $filters['branch_id'] !== '')
            <a href="{{ request()->fullUrlWithQuery(['q' => null, 'branch_id' => null, 'page' => null]) }}" class="corex-btn-outline corex-btn-xs">Clear</a>
        @endif
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left" style="position: sticky; left: 0; background: var(--surface);"><a href="{{ $sortUrl('name') }}">Agent{{ $arrow('name') }}</a></th>
                    @foreach($columns as $c)
                        <th class="px-3 py-2 text-right whitespace-nowrap" @if($c['group'] === 'outcomes') style="background: var(--surface-2);" @endif>
                            <a href="{{ $sortUrl($c['key']) }}">{{ $c['label'] }}{{ $arrow($c['key']) }}</a>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @forelse($paged as $row)
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2 font-semibold whitespace-nowrap" style="color: var(--text-primary); position: sticky; left: 0; background: var(--surface);">{{ $row['name'] }}</td>
                    @foreach($columns as $c)
                        @php $cell = $row['cells'][$c['key']]; @endphp
                        <td class="px-3 py-2 text-right" @if($c['group'] === 'outcomes') style="background: var(--surface-2);" @endif>
                            <button type="button" class="font-semibold" style="color: var(--text-primary);"
                                    title="Click to see the rows behind this figure"
                                    @click="drill(@js($c['metric']), @js($c['label'] . ' - ' . $row['name']), {{ $row['agent_id'] }})">{{ number_format($cell['value']) }}</button>
                            @if($compared)
                                <button type="button" class="block ml-auto" title="Click to see the comparison rows"
                                        @click="drill(@js($c['metric']), @js($c['label'] . ' - ' . $row['name'] . ' (comparison)'), {{ $row['agent_id'] }}, null, null, 'compare')">
                                    <x-performance-delta :c="$cell['compare']" :phrase="$phrase" />
                                </button>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($columns) + 1 }}" class="px-6 py-12 text-center">
                        <div class="text-base font-semibold" style="color: var(--text-primary);">
                            @if($filters['q'] !== '' || $filters['branch_id'] !== '') No agents match this search. @else There are no agents to report on yet. @endif
                        </div>
                        <p class="text-xs mt-1" style="color: var(--text-muted);">
                            @if($filters['q'] !== '' || $filters['branch_id'] !== '') Clear the search to see everyone you can view. @else Give agents the Road to Sold permission in Role Manager. @endif
                        </p>
                    </td>
                </tr>
            @endforelse
            </tbody>
            @if($agentCount > 1)
            <tfoot>
                <tr style="border-top: 2px solid var(--border);">
                    <td class="px-3 py-2 font-bold" style="color: var(--text-primary); position: sticky; left: 0; background: var(--surface);">Total</td>
                    @foreach($columns as $c)
                        @php $cell = $totals['cells'][$c['key']]; @endphp
                        <td class="px-3 py-2 text-right" @if($c['group'] === 'outcomes') style="background: var(--surface-2);" @endif>
                            <button type="button" class="font-bold" style="color: var(--text-primary);" title="Click to see the rows behind this figure"
                                    @click="drill(@js($c['metric']), @js($c['label'] . ' - total'))">{{ number_format($cell['value']) }}</button>
                            @if($compared)
                                <button type="button" class="block ml-auto" title="Click to see the comparison rows"
                                        @click="drill(@js($c['metric']), @js($c['label'] . ' - total (comparison)'), null, null, null, 'compare')">
                                    <x-performance-delta :c="$cell['compare']" :phrase="$phrase" />
                                </button>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
            @endif
        </table>
    </div>

    {{ $paged->links() }}

    @include('buyers-report._drilldown-modal')
</div>
<style>
    .report-delta { display: block; font-size: 9px; line-height: 1.3; font-weight: 500; white-space: nowrap; }
    .report-delta-good { color: var(--ds-green, #059669); }
    .report-delta-bad { color: var(--ds-crimson, #c41e3a); }
    .report-delta-neutral { color: var(--text-muted); }
</style>
@endsection
