{{-- My Portal > Overview > Where am I vs where I should be. READ ONLY. Spec: agency-tracker-rehome.md section 6.
     "Where I should be" is the agent's monthly targets (the same ones Targets & Budget works from). --}}
@php
    $p = $myPortal;
    $monthLabel = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $p['period'])->format('F Y');
    $rand = fn ($v) => 'R ' . number_format((float) $v, 0, '.', ',');
    $t = $p['targets'];
    $pct = fn ($actual, $target) => $target > 0 ? min(100, max(0, (int) round($actual / $target * 100))) : null;
    $metrics = [
        ['label' => 'Points',      'actual' => $p['points']['month'],       'target' => $t['points'],   'fmt' => fn ($v) => number_format($v, 0), 'key' => 'points'],
        ['label' => 'Deals',       'actual' => $p['actuals']['deals'],      'target' => $t['deals'],    'fmt' => fn ($v) => number_format($v, 0), 'key' => 'deals'],
        ['label' => 'Sales value', 'actual' => $p['actuals']['salesValue'], 'target' => $t['value'],    'fmt' => $rand,                            'key' => 'value'],
        ['label' => 'Listings',    'actual' => $p['listingsLive'],          'target' => $t['listings'], 'fmt' => fn ($v) => number_format($v, 0), 'key' => 'listings'],
    ];
    $cmp = $p['comparison'];
    $valueBar = fn ($tot) => ['actual' => (float) data_get($tot, 'actuals.sales_value', 0), 'target' => (float) data_get($tot, 'targets.value', 0)];
@endphp
<div x-show="sub.overview === 'whereami'" x-cloak class="pg-pane pg-wide" data-testid="portal-where-am-i">
    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <h3 class="text-sm font-bold" style="color:var(--text-primary);">Where am I vs where I should be &mdash; {{ $monthLabel }}</h3>
        <x-period-selector :months="$p['months']" :keep="['sub' => 'whereami']" />
    </div>

    @if(! $t['set'])
        <p class="text-sm mb-3" style="color:var(--text-muted);" data-testid="no-targets">No targets are set for {{ $monthLabel }} yet, so there is nothing to measure against. What you have done so far is shown below.</p>
    @endif

    <div class="ds-status-card mb-4" data-testid="targets-vs-actuals">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($metrics as $m)
                @php $bar = $pct($m['actual'], $m['target']); @endphp
                <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);" data-testid="metric-{{ $m['key'] }}">
                    <div class="flex items-baseline justify-between">
                        <div class="ds-label">{{ $m['label'] }}</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $m['target'] > 0 ? 'target ' . $m['fmt']($m['target']) : 'no target set' }}</div>
                    </div>
                    <div class="ds-value-lg" data-testid="actual-{{ $m['key'] }}">{{ $m['fmt']($m['actual']) }}</div>
                    @if($bar !== null)
                        <div class="mt-2 ds-progress-track"><div class="ds-progress-bar {{ $bar >= 80 ? 'ds-bar-navy' : 'ds-bar-amber' }}" style="width: {{ $bar }}%"></div></div>
                        <div class="text-[11px] mt-1" style="color:var(--text-muted);">{{ $bar }}% of target</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="ds-status-card mb-4" data-testid="you-vs-branch-company">
        <div class="flex items-center justify-between mb-3 gap-3 flex-wrap">
            <h4 class="ds-section-header">You vs your branch vs the company</h4>
            <div class="text-xs" style="color:var(--text-muted);">Sales value &middot; totals only, no individuals</div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @php
                $you = ['actual' => (float) $p['actuals']['salesValue'], 'target' => (float) $t['value']];
                $cols = [['You', $you], ['Your branch', $cmp['branch'] ? $valueBar($cmp['branch']) : null], ['The company', $cmp['company'] ? $valueBar($cmp['company']) : null]];
            @endphp
            @foreach($cols as [$name, $v])
                <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="ds-label">{{ $name }}</div>
                    @if($v)
                        @php $bar = $pct($v['actual'], $v['target']); @endphp
                        <div class="ds-value-lg">{{ $rand($v['actual']) }}</div>
                        <div class="text-xs" style="color:var(--text-muted);">{{ $v['target'] > 0 ? 'of ' . $rand($v['target']) : 'no target set' }}</div>
                        @if($bar !== null)<div class="mt-2 ds-progress-track"><div class="ds-progress-bar {{ $bar >= 80 ? 'ds-bar-navy' : 'ds-bar-amber' }}" style="width: {{ $bar }}%"></div></div>@endif
                    @else
                        <div class="text-sm mt-1" style="color:var(--text-muted);">{{ $name === 'Your branch' ? 'No branch assigned.' : 'Not available.' }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @php $week = array_slice($p['points']['days'], -7, 7, true); $max = max(1, max($week)); @endphp
    <div class="ds-status-card" data-testid="momentum">
        <h4 class="ds-section-header" style="margin-bottom:0.75rem;">Momentum &mdash; last 7 days (points)</h4>
        <div class="flex items-end gap-2" style="height:72px;">
            @foreach($week as $day => $pts)
                <div class="flex-1 text-center" title="{{ \Carbon\CarbonImmutable::parse($day)->format('D j M') }}: {{ number_format($pts, 0) }} points">
                    <div style="height: {{ max(2, (int) round($pts / $max * 60)) }}px; background: var(--brand-button, #0ea5e9); border-radius:3px 3px 0 0;"></div>
                    <div class="text-[10px] mt-1" style="color:var(--text-muted);">{{ \Carbon\CarbonImmutable::parse($day)->format('D') }}</div>
                </div>
            @endforeach
        </div>
    </div>
</div>
