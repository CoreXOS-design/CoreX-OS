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
    // a number or a bar opens the properties behind it - same period; "me" always, the branch for a manager, the agency for an admin (a colleague's properties are never listed to an agent)
    $canList = ['me' => true, 'branch' => in_array($level, ['branch', 'all'], true), 'agency' => $level === 'all'];
    $drill = fn (string $scope, string $station, string $kind, ?string $reason = null) => $canList[$scope]
        ? route('road-to-sold.belt-funnel.list', array_filter($params + ['station' => $station, 'kind' => $kind, 'reason' => $reason, 'scope' => $scope, 'period' => $preset, 'start' => $start, 'end' => $end]))
        : null;
    $link = fn (?string $url, $text) => $url ? '<a href="' . e($url) . '" class="underline" data-qa="drill">' . e((string) $text) . '</a>' : e((string) $text);
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
                <p class="text-xs mt-1" style="color: var(--text-muted);">{{ $period->label }} ({{ $period->start->format('d/m/Y') }} - {{ $period->end->format('d/m/Y') }})@if($cmp) against {{ $compare->label }} ({{ $compare->start->format('d/m/Y') }} - {{ $compare->end->format('d/m/Y') }})@endif. A property counts once at each station it reaches.</p>
            </div>
            <a href="{{ route('road-to-sold.index', $params) }}" class="corex-btn-outline corex-btn-xs">Back to Road to Sold</a>
        </div>
    </div>
    @if($error)<div class="rounded-md px-4 py-3 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">{{ $error }}</div>@endif

    <form method="GET" class="flex items-end gap-3 flex-wrap rounded-md px-4 py-3" style="background: var(--surface); border: 1px solid var(--border);" data-qa="belt-funnel-filters"
          x-data="{ period: @js($preset), compare: @js($mode) }">
        @if($track !== 'sales')<input type="hidden" name="track" value="{{ $track }}">@endif
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-p">Period</label>
            <select id="bf-p" name="period" x-model="period" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">@foreach($periods as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        <div x-show="period === 'custom'" x-cloak data-qa="custom-dates"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-s">From</label><x-dmy-date id="bf-s" name="start" :value="$start" /></div>
        <div x-show="period === 'custom'" x-cloak><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-e">To</label><x-dmy-date id="bf-e" name="end" :value="$end" /></div>
        <div><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-c">Compare with</label>
            <select id="bf-c" name="compare" x-model="compare" class="rounded-md px-3 py-2 text-xs" style="{{ $inputStyle }}">@foreach($compares as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        <div x-show="compare === 'custom'" x-cloak data-qa="custom-compare-dates"><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-cs">Compare from</label><x-dmy-date id="bf-cs" name="c_start" :value="$cStart" /></div>
        <div x-show="compare === 'custom'" x-cloak><label class="block text-xs font-semibold mb-1" style="color: var(--text-muted);" for="bf-ce">to</label><x-dmy-date id="bf-ce" name="c_end" :value="$cEnd" /></div>
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
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);"><strong>{!! $link($drill('me', $s['key'], 'reached'), $m['reached']) !!}</strong>@if($cm) <span class="ml-1">{!! $arrow($m['reached'], $cm['reached']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $pct($m['to_next_pct']) }}@if(!empty($m['to_next_capped']))<span title="More properties reached the next station than this one in these dates, because they got here before them. Shown as 100%.">*</span>@endif @if($cm) <span class="ml-1">{!! $arrow($m['to_next_pct'], $cm['to_next_pct']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{!! $link($drill('me', $s['key'], 'lost'), $m['lost']) !!}@if($m['lost_pct'] !== null) <span style="color: var(--text-muted);">({{ $pct($m['lost_pct']) }})</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{!! $link($drill('branch', $s['key'], 'reached'), $b['reached']) !!}@if($cb) <span class="ml-1">{!! $arrow($b['reached'], $cb['reached']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $pct($b['to_next_pct']) }}@if(!empty($b['to_next_capped']))<span title="More properties reached the next station than this one in these dates, because they got here before them. Shown as 100%.">*</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{!! $link($drill('branch', $s['key'], 'lost'), $b['lost']) !!}@if($b['lost_pct'] !== null) <span style="color: var(--text-muted);">({{ $pct($b['lost_pct']) }})</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{!! $link($drill('agency', $s['key'], 'reached'), $a['reached']) !!}@if($ca) <span class="ml-1">{!! $arrow($a['reached'], $ca['reached']) !!}</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{{ $pct($a['to_next_pct']) }}@if(!empty($a['to_next_capped']))<span title="More properties reached the next station than this one in these dates, because they got here before them. Shown as 100%.">*</span>@endif</td>
                    <td class="px-3 py-2 text-right" style="color: var(--text-primary);">{!! $link($drill('agency', $s['key'], 'lost'), $a['lost']) !!}@if($a['lost_pct'] !== null) <span style="color: var(--text-muted);">({{ $pct($a['lost_pct']) }})</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if(collect([$me, $branch, $agency])->contains(fn ($f) => collect($f['stations'])->contains(fn ($c) => ! empty($c['to_next_capped']))))
        <p class="text-xs" style="color: var(--text-muted);" data-qa="capped-note">* More properties reached the next station than this one in these dates - they got to this station before the dates picked - so the share is shown as 100%.</p>
    @endif
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
                                    <div style="width: 38%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $reason }}">{!! $link($drill($scopeKey, $row['key'], 'lost', $reason), $reason) !!}</div>
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
