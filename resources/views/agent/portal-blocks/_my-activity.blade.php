{{-- My Portal > Overview > My Activity: my own daily-activity points. READ ONLY here; the capture form is
     agent.daily (unchanged). Spec: agency-tracker-rehome.md section 5.2 (personal figures). --}}
@php
    $p = $myPortal;
    $monthLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p['period'])->format('F Y');
    $days = $p['points']['days']; $max = max(1, max($days));
    $canCapture = auth()->user()->hasPermission('access_daily_activity') && \Illuminate\Support\Facades\Route::has('agent.daily');
    $hasSummary = \Illuminate\Support\Facades\Route::has('agent.daily.summary');
@endphp
<div x-show="sub.overview === 'myactivity'" x-cloak class="pg-pane pg-wide" data-testid="portal-my-activity">
    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <h3 class="text-sm font-bold" style="color:var(--text-primary);">My Activity &mdash; {{ $monthLabel }}</h3>
        <x-period-selector :months="$p['months']" :keep="['sub' => 'myactivity']" />
    </div>

    <div class="grid grid-cols-2 gap-3 mb-4" style="max-width:520px;">
        <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Points this month</div>
            <div class="text-lg font-bold" style="color:var(--text-primary);" data-testid="points-month">{{ number_format($p['points']['month'], 0) }}</div>
            <div class="text-[11px]" style="color:var(--text-muted);">{{ $p['points']['target'] > 0 ? 'target ' . number_format($p['points']['target'], 0) : 'no target set' }}</div>
        </div>
        <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Points today</div>
            <div class="text-lg font-bold" style="color:var(--text-primary);" data-testid="points-today">{{ number_format($p['points']['today'], 0) }}</div>
        </div>
    </div>

    <div class="ds-status-card mb-4" data-testid="activity-strip">
        <h4 class="ds-section-header" style="margin-bottom:0.75rem;">The last 14 days (points)</h4>
        @if(array_sum($days) <= 0)
            <p class="text-sm" style="color:var(--text-muted);">No activity points in the last 14 days.</p>
        @endif
        <div class="flex items-end gap-1" style="height:72px;">
            @foreach($days as $day => $pts)
                <div class="flex-1 text-center" title="{{ \Carbon\CarbonImmutable::parse($day)->format('D j M') }}: {{ number_format($pts, 0) }} points">
                    <div style="height: {{ max(2, (int) round($pts / $max * 60)) }}px; background: var(--brand-button, #0ea5e9); border-radius:3px 3px 0 0;"></div>
                    <div class="text-[9px] mt-1" style="color:var(--text-muted);">{{ \Carbon\CarbonImmutable::parse($day)->format('j') }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="flex flex-wrap gap-4 text-sm">
        @if($canCapture)<a href="{{ route('agent.daily') }}" style="color:var(--brand-icon); text-decoration:none; font-weight:500;">Capture today's activity &rarr;</a>@endif
        @if($canCapture && $hasSummary)<a href="{{ route('agent.daily.summary') }}" style="color:var(--brand-icon); text-decoration:none; font-weight:500;">My activity summary &rarr;</a>@endif
    </div>
</div>
