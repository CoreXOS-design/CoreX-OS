{{-- My Portal > Overview > My Activity: my own daily-activity points. READ ONLY here; the capture form is
     agent.daily (unchanged). Spec: agency-tracker-rehome.md section 5.2 (personal figures). --}}
@php
    $p = $myPortal;
    $monthLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p['period'])->format('F Y');
    $days = $p['points']['days']; $daysSplit = $p['points']['daysSplit'];
    $canCapture = auth()->user()->hasPermission('access_daily_activity') && \Illuminate\Support\Facades\Route::has('agent.daily');
    $hasSummary = \Illuminate\Support\Facades\Route::has('agent.daily.summary');
@endphp
<div x-show="sub.overview === 'myactivity'" x-cloak class="pg-pane pg-wide" data-testid="portal-my-activity">
    <div class="mp-h">
        <h3>My Activity &mdash; {{ $monthLabel }}</h3>
        <x-period-selector :months="$p['months']" :keep="['sub' => 'myactivity']" />
    </div>

    <div class="mp-grid mp-g3" style="margin-bottom:8px;">
        <div class="mp-card" data-testid="points-month">
            <x-points-split-total :split="$p['points']['monthSplit']" label="Points this month" />
            <div class="mp-note">{{ $p['points']['target'] > 0 ? 'target ' . number_format($p['points']['target'], 0) : 'no target set' }}</div>
        </div>
        <div class="mp-card" data-testid="points-today">
            <x-points-split-total :split="$p['points']['todaySplit']" label="Points today" />
        </div>
        <div class="mp-card" style="display:flex; flex-direction:column; justify-content:center; gap:6px;">
            @if($canCapture)<a href="{{ route('agent.daily') }}" class="text-sm" style="color:var(--brand-icon); text-decoration:none; font-weight:500;">Capture today's activity &rarr;</a>@endif
            @if($canCapture && $hasSummary)<a href="{{ route('agent.daily.summary') }}" class="text-sm" style="color:var(--brand-icon); text-decoration:none; font-weight:500;">My activity summary &rarr;</a>@endif
        </div>
    </div>

    <div class="mp-card" data-testid="activity-strip">
        <div class="ds-section-header" style="margin:0 0 4px;">The last 14 days (points)</div>
        @if(array_sum($days) <= 0)
            <p class="text-sm" style="color:var(--text-muted); margin:0 0 4px;">No activity points in the last 14 days.</p>
        @endif
        <x-points-split-strip :days="$daysSplit" label="j" :height="56" />
    </div>
</div>
