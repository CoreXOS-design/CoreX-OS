{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- Road to Sold v4 (road-to-sold.md 23.12): the belt funnel and where properties are lost. Needs $stations, $me, $branch, $agency, $agents, $compare_*, $blockers, $period, $compare, $level. --}}
@extends('layouts.corex-app')
@section('title', 'Road to Sold funnel')

@section('corex-content')
@php
    $inputStyle = 'background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);';
    $params = \App\Services\RoadToSold\Track::params($track);
    $cmp = $compare !== null;
    $cellOf = fn (array $f, string $k) => collect($f['stations'])->firstWhere('key', $k);
    $pct = fn ($v) => $v === null ? '-' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    $arrow = function ($now, $then, $lowerIsBetter = false) {
        if ($then === null || $now === null) return '';
        $d = round($now - $then, 1);
        if ($d == 0) return '<span style="color:var(--text-muted);">same</span>';
        $good = $lowerIsBetter ? $d < 0 : $d > 0;
        return '<span style="color:' . ($good ? 'var(--ds-green, #059669)' : 'var(--ds-crimson, #c41e3a)') . ';">' . ($d > 0 ? '+' : '') . $d . '</span>';
    };
@endphp
<div class="w-full space-y-4 rts-page" data-qa="belt-funnel">
    <div class="rounded-md px-6 py-4 corex-page-banner">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Road to Sold funnel</h1>
                <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $period->label }} ({{ $period->start->format('j M') }} - {{ $period->end->format('j M Y') }})@if($cmp) against {{ $compare->label }} ({{ $compare->start->format('j M') }} - {{ $compare->end->format('j M Y') }})@endif. A property counts once at each station it reaches.</p>
            </div>
            <a href="{{ route('road-to-sold.index', $params) }}" class="corex-btn-outline corex-btn-xs">Back to Road to Sold</a>
        </div>
    </div>
    @if($error)<div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $error }}</div>@endif

    <form method="GET" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="belt-funnel-filters">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-p">Period</label>
            <select id="bf-p" name="period" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">@foreach($periods as $k => $l)<option value="{{ $k }}" @selected($preset === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-s">From</label><input id="bf-s" type="date" name="start" value="{{ $start }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-e">To</label><input id="bf-e" type="date" name="end" value="{{ $end }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-c">Compare with</label>
            <select id="bf-c" name="compare" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">@foreach($compares as $k => $l)<option value="{{ $k }}" @selected($mode === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-cs">Compare from</label><input id="bf-cs" type="date" name="c_start" value="{{ $cStart }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-ce">to</label><input id="bf-ce" type="date" name="c_end" value="{{ $cEnd }}" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}"></div>
        <button type="submit" class="corex-btn-primary corex-btn-xs">Show</button>
    </form>

    <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-xs ds-table" data-qa="belt-funnel-table">
            <thead><tr style="color: var(--text-muted);">
                <th class="px-3 py-2 text-left">Station</th>
                <th class="px-3 py-2 text-right">Me</th><th class="px-3 py-2 text-right">To next</th><th class="px-3 py-2 text-right">Lost here</th>
                <th class="px-3 py-2 text-right">My branch</th><th class="px-3 py-2 text-right">To next</th><th class="px-3 py-2 text-right">Lost here</th>
                <th class="px-3 py-2 text-right">The agency</th><th class="px-3 py-2 text-right">To next</th><th class="px-3 py-2 text-right">Lost here</th>
            </tr></thead>
            <tbody>
            @foreach($stations as $s)
                @php $m = $cellOf($me, $s['key']); $b = $cellOf($branch, $s['key']); $a = $cellOf($agency, $s['key']);
                     $cm = $cmp ? $cellOf($compare_me, $s['key']) : null; $cb = $cmp ? $cellOf($compare_branch, $s['key']) : null; $ca = $cmp ? $cellOf($compare_agency, $s['key']) : null; @endphp
                <tr style="border-top: 1px solid var(--border);" data-station="{{ $s['key'] }}">
                    <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);">{{ $s['label'] }}</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);"><strong>{{ $m['reached'] }}</strong>@if($cm) <span class="ml-1">{!! $arrow($m['reached'], $cm['reached']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $pct($m['to_next_pct']) }}@if($cm) <span class="ml-1">{!! $arrow($m['to_next_pct'], $cm['to_next_pct']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $m['lost'] }}@if($m['lost_pct'] !== null) <span style="color: var(--text-muted);">({{ $pct($m['lost_pct']) }})</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $b['reached'] }}@if($cb) <span class="ml-1">{!! $arrow($b['reached'], $cb['reached']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $pct($b['to_next_pct']) }}</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $b['lost'] }}@if($b['lost_pct'] !== null) <span style="color: var(--text-muted);">({{ $pct($b['lost_pct']) }})</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $a['reached'] }}@if($ca) <span class="ml-1">{!! $arrow($a['reached'], $ca['reached']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $pct($a['to_next_pct']) }}</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $a['lost'] }}@if($a['lost_pct'] !== null) <span style="color: var(--text-muted);">({{ $pct($a['lost_pct']) }})</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 md:grid-cols-3" data-qa="belt-offramps">
        @foreach(['me' => ['My off-ramps', $me], 'branch' => ['My branch', $branch], 'agency' => ['The agency', $agency]] as $scopeKey => [$title, $f])
            <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" data-qa="offramps-{{ $scopeKey }}">
                <h2 class="text-sm font-bold mb-2" style="color: var(--text-primary);">{{ $title }} - why properties stopped</h2>
                @php $any = false; @endphp
                @foreach($f['stations'] as $row)
                    @if(! empty($row['reasons']))
                        @php $any = true; $max = max($row['reasons']); @endphp
                        <div class="mb-2"><div class="text-xs font-semibold" style="color: var(--text-primary);">{{ $row['label'] }}</div>
                            @foreach($row['reasons'] as $reason => $n)
                                <div class="flex items-center gap-2 text-xs" style="color: var(--text-primary);">
                                    <div style="width: 38%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $reason }}">{{ $reason }}</div>
                                    <div class="grow" style="background: var(--border); border-radius: 3px; height: 8px;"><div style="width: {{ max(4, (int) round($n / max(1, $max) * 100)) }}%; height: 8px; border-radius: 3px; background: var(--brand-icon, #0ea5e9);"></div></div>
                                    <div style="width: 2rem; text-align: right;">{{ $n }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
                @unless($any)<div class="text-xs" style="color: var(--text-muted);" data-qa="offramps-empty">Nothing stopped in this period.</div>@endunless
            </div>
        @endforeach
    </div>

    @if(! empty($blockers))
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);" data-qa="belt-blockers">
            <h2 class="text-sm font-bold mb-2" style="color: var(--text-primary);">Stuck right now (named by the system, not chosen)</h2>
            @foreach($blockers as $station => $byReason)
                @foreach($byReason as $reason => $n)<div class="text-xs" style="color: var(--text-primary);">{{ ucfirst($station) }}: {{ $reason }} - {{ $n }}</div>@endforeach
            @endforeach
        </div>
    @endif

    @if(count($agents))
        <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);" data-qa="belt-agents">
            <table class="w-full text-xs ds-table">
                <thead><tr style="color: var(--text-muted);"><th class="px-3 py-2 text-left">Agent</th><th class="px-3 py-2 text-left">Branch</th>@foreach($stations as $s)<th class="px-2 py-2 text-right" title="{{ $s['label'] }}">{{ $s['short'] }}</th>@endforeach<th class="px-3 py-2 text-right">Lost</th></tr></thead>
                <tbody>
                @foreach($agents as $row)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="px-3 py-2 font-semibold" style="color: var(--text-primary);">{{ $row['name'] }}</td><td class="px-3 py-2" style="color: var(--text-primary);">{{ $row['branch'] ?: '-' }}</td>
                        @foreach($row['funnel']['stations'] as $c)<td class="px-2 py-2 text-right" style="color: var(--text-primary);">{{ $c['reached'] }}</td>@endforeach
                        <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ collect($row['funnel']['stations'])->sum('lost') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
