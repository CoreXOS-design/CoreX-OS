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
    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <h3 class="text-sm font-bold" style="color:var(--text-primary);">My Activity &mdash; {{ $monthLabel }}</h3>
        <x-period-selector :months="$p['months']" :keep="['sub' => 'myactivity']" />
    </div>

    <div class="grid grid-cols-2 gap-3 mb-4" style="max-width:520px;">
        <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);" data-testid="points-month">
            <x-points-split-total :split="$p['points']['monthSplit']" label="Points this month" />
            <div class="text-[11px]" style="color:var(--text-muted);">{{ $p['points']['target'] > 0 ? 'target ' . number_format($p['points']['target'], 0) : 'no target set' }}</div>
        </div>
        <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);" data-testid="points-today">
            <x-points-split-total :split="$p['points']['todaySplit']" label="Points today" />
        </div>
    </div>

    <div class="ds-status-card mb-4" data-testid="activity-strip">
        <h4 class="ds-section-header" style="margin-bottom:0.75rem;">The last 14 days (points)</h4>
        @if(array_sum($days) <= 0)
            <p class="text-sm" style="color:var(--text-muted);">No activity points in the last 14 days.</p>
        @endif
        <x-points-split-strip :days="$daysSplit" label="j" :height="60" />
    </div>

    <div class="flex flex-wrap gap-4 text-sm">
        @if($canCapture)<a href="{{ route('agent.daily') }}" style="color:var(--brand-icon); text-decoration:none; font-weight:500;">Capture today's activity &rarr;</a>@endif
        @if($canCapture && $hasSummary)<a href="{{ route('agent.daily.summary') }}" style="color:var(--brand-icon); text-decoration:none; font-weight:500;">My activity summary &rarr;</a>@endif
    </div>
</div>
