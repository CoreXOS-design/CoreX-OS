{{-- My Portal > Overview > My Performance. READ ONLY, OWN scope only (the signed-in user's id, nobody else's).
     The very figures behind Dashboard > My Performance (command-center.reporting.agent / ReportingService), on one screen
     that fits (my-portal-restyle.md sections 9 and 10): eight figures in two rows, the funnel beside the insights. One link
     to the full report. Spec: agency-tracker-rehome.md ruling F (section 6). --}}
@php
    $perf = $myPerformance;
    $m = $perf['metrics'];
    $funnel = $perf['funnel'] ?? [];
    $insights = array_slice((array) ($perf['insights'] ?? []), 0, 4);
    $feedbackOnTrack = $m['feedback_rate'] >= 70;
    $maxCount = collect($funnel)->max('count') ?: 1;
    $change = $m['events_prior'] > 0 ? (int) round(($m['events_completed'] - $m['events_prior']) / $m['events_prior'] * 100) : null;
@endphp
<div x-show="sub.overview === 'performance'" x-cloak class="pg-pane pg-wide" data-testid="portal-my-performance">
    <div class="mp-h">
        <h3>My Performance &mdash; last {{ number_format($perf['days']) }} days</h3>
        <span class="flex flex-wrap items-center gap-2">
            @foreach(\App\Http\Controllers\Agent\AgentPortalController::PERFORMANCE_RANGES as $d => $label)
                <a href="{{ route('agent.portal', ['sub' => 'performance', 'days' => $d]) }}"
                   class="text-xs px-2 py-0.5 rounded-md no-underline {{ $perf['days'] == $d ? 'font-semibold' : '' }}"
                   style="{{ $perf['days'] == $d ? 'background: var(--brand-icon); color: #fff;' : 'background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border);' }}">{{ $label }}</a>
            @endforeach
            @feature('performance-dashboards')
            <a href="{{ route('command-center.reporting.agent', ['days' => $perf['days']]) }}" class="mp-more" data-testid="full-performance-link">Full performance &rarr;</a>
            @endfeature
        </span>
    </div>

    <div class="mp-grid mp-g4" style="margin-bottom:8px;">
        <div class="mp-card"><div class="mp-lbl">Events completed</div>
            <div class="mp-val" data-testid="perf-events">{{ number_format($m['events_completed']) }}
                @if($change !== null)<span class="mp-note" style="font-weight:600; color: {{ $change >= 0 ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)' }};">{{ $change >= 0 ? '+' : '' }}{{ $change }}%</span>@endif
            </div></div>
        <div class="mp-card"><div class="mp-lbl">Viewings</div><div class="mp-val" data-testid="perf-viewings">{{ number_format($m['viewings']) }}</div></div>
        <div class="mp-card"><div class="mp-lbl">Presentations</div><div class="mp-val" data-testid="perf-presentations">{{ number_format($m['presentations']) }}</div></div>
        <div class="mp-card" style="border-color: {{ $feedbackOnTrack ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)' }};"><div class="mp-lbl">Feedback rate</div>
            <div class="mp-val" data-testid="perf-feedback" style="color: {{ $feedbackOnTrack ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)' }};">{{ number_format($m['feedback_rate'], 0) }}%</div></div>
        <div class="mp-card"><div class="mp-lbl">Active buyers</div><div class="mp-val" data-testid="perf-active-buyers">{{ number_format($m['active_buyers']) }}</div></div>
        <div class="mp-card"><div class="mp-lbl">High-risk buyers</div><div class="mp-val" data-testid="perf-high-risk" style="color: {{ $m['high_risk_buyers'] > 3 ? 'var(--ds-amber, #f59e0b)' : 'var(--text-primary)' }};">{{ number_format($m['high_risk_buyers']) }}</div></div>
        <div class="mp-card"><div class="mp-lbl">Lost deals</div><div class="mp-val" data-testid="perf-lost-deals" style="color:var(--ds-amber, #f59e0b);">{{ number_format($m['lost_deals']) }}</div></div>
        <div class="mp-card"><div class="mp-lbl">Lost value</div><div class="mp-val" data-testid="perf-lost-value" style="color:var(--ds-amber, #f59e0b);">R&nbsp;{{ number_format($m['lost_value']) }}</div></div>
    </div>

    <div class="mp-grid mp-g2">
        <div class="mp-card" data-testid="perf-funnel">
            <div class="ds-section-header" style="margin:0 0 6px;">Conversion funnel</div>
            @if(empty($funnel))
                <p class="mp-note" style="margin:0;">No funnel activity in this period.</p>
            @else
                <div style="display:flex; flex-direction:column; gap:5px;">
                    @foreach($funnel as $stage)
                        @php
                            $rate = $stage['rate'];
                            $rateColor = $rate === null ? 'var(--text-muted)' : ($rate >= 30 ? 'var(--ds-green, #059669)' : 'var(--ds-amber, #f59e0b)');
                        @endphp
                        <div class="flex items-center gap-2">
                            <span class="text-xs flex-shrink-0 font-medium" style="width:7rem; color:var(--text-primary);">{{ $stage['stage'] }}</span>
                            <div class="ds-progress-track flex-1" style="height:12px;">
                                <div class="ds-progress-bar" style="width: {{ max(5, ($stage['count'] / $maxCount) * 100) }}%; background: var(--brand-icon, #0ea5e9); display:flex; align-items:center; padding:0 6px;">
                                    <span class="text-[0.6875rem] font-semibold text-white">{{ number_format($stage['count']) }}</span>
                                </div>
                            </div>
                            <span class="text-xs text-right font-semibold" style="width:3rem; color: {{ $rateColor }};">{{ $rate !== null ? number_format($rate, 1) . '%' : '—' }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="mp-card" data-testid="perf-insights" data-portal-rows="performance-insights" data-portal-cap="4">
            <div class="ds-section-header" style="margin:0 0 6px;">Insights</div>
            @forelse($insights as $insight)
                <div class="flex items-start gap-2 text-xs" style="color:var(--text-secondary); margin-bottom:4px;">
                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="margin-top:6px; background: var(--brand-icon, #0ea5e9);"></span>
                    <span>{{ $insight }}</span>
                </div>
            @empty
                <p class="mp-note" style="margin:0;">Nothing to flag for this period.</p>
            @endforelse
        </div>
    </div>
</div>
