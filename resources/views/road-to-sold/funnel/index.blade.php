{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-454 v2 phase 5 - the team funnel: agent x ratio (or agent x figure) with the agency median and the training flags. Needs $rows (paginator of User),
     $computed, $medians, $flags, $team, $teamRatios, $view, $filters, $branches, $period, $days, $doneBy, $settings, $drilldownBase, $track, $viewer, $tab.
     Every figure opens its rows (the drill-down). The rows are the agents inside the viewer's breadth; the median is the whole agency's. --}}
@extends('layouts.corex-app')
@section('title', 'Team funnel')

@section('corex-content')
@php
    $f = $filters;
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $params = \App\Services\RoadToSold\Track::params($track);
    $metrics = \App\Services\RoadToSold\FunnelService::METRICS;
    $ratios = \App\Services\RoadToSold\FunnelService::RATIOS;
    $windows = \App\Services\RoadToSold\FunnelService::WINDOWS + [$days => 'Last ' . $days . ' days'];
    ksort($windows);
    $anyMedian = collect($medians)->filter(fn ($m) => $m !== null)->isNotEmpty();
    $filtered = ($f['q'] ?? '') !== '' || ! empty($f['flagged']) || ! empty($f['branch']);
    $fmt = fn ($n) => number_format((int) $n);
@endphp

<div class="w-full space-y-4 rts-page" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="rounded-md px-6 py-3 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Team funnel</h1>
                <span class="text-xs" style="color: var(--text-primary);">{{ $period->start->format('j M') }} to {{ $period->end->format('j M Y') }}</span>
                <span class="text-xs" style="color: var(--text-primary);"><strong>{{ count($flags) }}</strong> {{ \Illuminate\Support\Str::plural('agent', count($flags)) }} flagged across the agency</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('road-to-sold.index', $params) }}" class="corex-btn-outline corex-btn-xs">Road to Sold</a>
            </div>
        </div>
    </div>
    @include('road-to-sold.funnel._tabs')

    @if(! $anyMedian)
        <div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
            Not enough data yet to flag anyone: a ratio needs at least {{ $settings->flagMinSample() }} events in the window for {{ \App\Services\RoadToSold\FunnelService::MIN_AGENTS_FOR_MEDIAN }} or more agents before the agency's own median means anything.
        </div>
    @endif

    <form method="GET" action="{{ route('road-to-sold.funnel.index') }}" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fn-q">Search</label>
            <input id="fn-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Agent name" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }} min-width: 12rem;">
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fn-view">Show</label>
            <select id="fn-view" name="view" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="ratios" @selected($view === 'ratios')>Ratios</option>
                <option value="figures" @selected($view === 'figures')>Figures</option>
                @if($canBranches)<option value="branches" @selected($view === 'branches')>By branch</option>@endif
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fn-window">Window</label>
            <select id="fn-window" name="window" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach($windows as $d => $l)<option value="{{ $d }}" @selected((int) $days === (int) $d)>{{ $l }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fn-by">Done by</label>
            <select id="fn-by" name="by" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                @foreach(\App\Http\Controllers\RoadToSold\FunnelController::DONE_BY as $k => $l)<option value="{{ $k }}" @selected(($doneBy ?? '') === $k)>{{ $l }}</option>@endforeach
            </select>
        </div>
        @if($branches->isNotEmpty())
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fn-branch">Branch</label>
                <select id="fn-branch" name="branch" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                    <option value="">All branches</option>
                    @foreach($branches as $b)<option value="{{ $b->id }}" @selected((string) ($f['branch'] ?? '') === (string) $b->id)>{{ $b->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div>
            <label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="fn-sort">Sort</label>
            <select id="fn-sort" name="sort" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}" onchange="this.form.submit()">
                <option value="flag" @selected($f['sort'] === 'flag')>Worst flag first</option>
                <option value="agent" @selected($f['sort'] === 'agent')>Agent</option>
                <option value="branch" @selected($f['sort'] === 'branch')>Branch</option>
            </select>
        </div>
        <label class="flex items-center gap-2 text-xs pb-2" style="color: var(--text-primary);">
            <input type="checkbox" name="flagged" value="1" @checked(! empty($f['flagged'])) onchange="this.form.submit()"> Flagged only
        </label>
        <button type="submit" class="corex-btn-outline corex-btn-xs">Search</button>
        @if($filtered)<a href="{{ route('road-to-sold.funnel.index', $params) }}" class="corex-btn-outline corex-btn-xs">Clear</a>@endif
    </form>

    @if($view === 'branches')
        <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
            <table class="w-full text-xs ds-table">
                <thead>
                    <tr style="color: var(--text-muted);">
                        <th class="px-3 py-2 text-left">Branch</th>
                        <th class="px-3 py-2 text-right">Agents</th>
                        <th class="px-3 py-2 text-right">Flagged</th>
                        @foreach($ratios as $key => $r)<th class="px-3 py-2 text-left">{{ $r[0] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                <tr style="border-top: 1px solid var(--border); background: var(--surface-2);">
                    <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);" colspan="3">Agency median</td>
                    @foreach($ratios as $key => $r)<td class="px-3 py-2" style="color: var(--text-primary);">{{ $medians[$key] !== null ? $medians[$key] . '%' : 'Not enough data yet' }}</td>@endforeach
                </tr>
                @forelse($byBranch as $b)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);">{{ $b['name'] }}</td>
                        <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $b['agents'] }}</td>
                        <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $b['flagged'] }}</td>
                        @foreach($ratios as $key => $r)
                            @php $t = $b['ratios'][$key]; @endphp
                            <td class="px-3 py-2" style="color: var(--text-primary);">{{ $t['pct'] !== null ? $t['pct'] . '%' : '-' }} <span style="color: var(--text-muted);">({{ $fmt($t['num']) }} of {{ $fmt($t['den']) }})</span></td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ 3 + count($ratios) }}" class="px-6 py-12 text-center">
                        <div class="text-base font-semibold" style="color: var(--text-primary);">{{ $filtered ? 'Nothing matches this search.' : 'There are no branches to show.' }}</div>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $filtered ? 'Clear the search or change a filter to see more.' : 'Branches appear here once their agents are allowed into Road to Sold.' }}</div>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @else
    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table">
            <thead>
                <tr style="color: var(--text-muted);">
                    <th class="px-3 py-2 text-left">Agent</th>
                    <th class="px-3 py-2 text-left">Branch</th>
                    @if($view === 'ratios')
                        @foreach($ratios as $key => $r)<th class="px-3 py-2 text-left" title="{{ $r[0] }}">{{ $r[0] }}</th>@endforeach
                        <th class="px-3 py-2 text-left">Needs</th>
                    @else
                        @foreach($metrics as $m => $label)<th class="px-3 py-2 text-right">{{ $label }}</th>@endforeach
                    @endif
                </tr>
            </thead>
            <tbody>
            <tr style="border-top: 1px solid var(--border); background: var(--surface-2);">
                <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);" colspan="2">Agency median</td>
                @if($view === 'ratios')
                    @foreach($ratios as $key => $r)<td class="px-3 py-2" style="color: var(--text-primary);">{{ $medians[$key] !== null ? $medians[$key] . '%' : 'Not enough data yet' }}</td>@endforeach
                    <td></td>
                @else
                    @foreach($metrics as $m => $label)<td></td>@endforeach
                @endif
            </tr>
            <tr style="border-top: 1px solid var(--border);">
                <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);" colspan="2">This view's total</td>
                @if($view === 'ratios')
                    @foreach($ratios as $key => $r)
                        @php $t = $teamRatios[$key]; @endphp
                        <td class="px-3 py-2" style="color: var(--text-primary);">{{ $t['pct'] !== null ? $t['pct'] . '%' : '-' }} <span style="color: var(--text-muted);">({{ $fmt($t['num']) }} of {{ $fmt($t['den']) }})</span></td>
                    @endforeach
                    <td></td>
                @else
                    @foreach($metrics as $m => $label)<td class="px-3 py-2 text-right font-semibold" style="color: var(--text-primary);">{{ $fmt($team[$m] ?? 0) }}</td>@endforeach
                @endif
            </tr>
            @forelse($rows as $agent)
                @php $agentFlags = $flags[$agent->id] ?? []; $counts = $computed['counts'][$agent->id] ?? []; $agentRatios = $computed['ratios'][$agent->id] ?? []; @endphp
                <tr style="border-top: 1px solid var(--border);">
                    <td class="px-3 py-2 font-semibold whitespace-nowrap" style="color: var(--text-primary);">{{ $agent->name }}</td>
                    <td class="px-3 py-2" style="color: var(--text-primary);">{{ $agent->branch?->name ?? '-' }}</td>
                    @if($view === 'ratios')
                        @foreach($ratios as $key => [$label, , $numMetric, $denMetric])
                            @php $cell = $agentRatios[$key] ?? ['num' => 0, 'den' => 0, 'pct' => null]; $flag = $agentFlags[$key] ?? null; @endphp
                            <td class="px-3 py-2" style="color: var(--text-primary);{{ $flag ? ' background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent);' : '' }}">
                                @if($cell['pct'] !== null)
                                    <strong>{{ $cell['pct'] }}%</strong>@if($flag) <span class="ds-badge ds-badge-warning">Below median</span>@endif
                                    <div style="color: var(--text-muted);">
                                        <button type="button" class="underline" @click="drill(@js($numMetric), @js($metrics[$numMetric] . ' - ' . $agent->name), {{ $agent->id }})">{{ $fmt($cell['num']) }}</button> of
                                        <button type="button" class="underline" @click="drill(@js($denMetric), @js($metrics[$denMetric] . ' - ' . $agent->name), {{ $agent->id }})">{{ $fmt($cell['den']) }}</button>
                                    </div>
                                @else
                                    <span style="color: var(--text-muted);">-</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-3 py-2" style="color: var(--text-primary);">
                            @foreach(collect($agentFlags)->sortByDesc('severity') as $key => $flag)
                                <div>{{ \App\Services\RoadToSold\FunnelService::flagText($key) }}</div>
                            @endforeach
                        </td>
                    @else
                        @foreach($metrics as $m => $label)
                            <td class="px-3 py-2 text-right" style="color: var(--text-primary);">
                                <button type="button" class="underline" @click="drill(@js($m), @js($label . ' - ' . $agent->name), {{ $agent->id }})">{{ $fmt($counts[$m] ?? 0) }}</button>
                            </td>
                        @endforeach
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ 3 + ($view === 'ratios' ? count($ratios) : count($metrics)) - 1 }}" class="px-6 py-12 text-center">
                        <div class="text-base font-semibold" style="color: var(--text-primary);">{{ $filtered ? 'Nothing matches this search.' : 'There are no agents to show.' }}</div>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">{{ $filtered ? 'Clear the search or change a filter to see more.' : 'Agents appear here once they are allowed into Road to Sold.' }}</div>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
    @endif

    @include('buyers-report._drilldown-modal')
</div>
@endsection
