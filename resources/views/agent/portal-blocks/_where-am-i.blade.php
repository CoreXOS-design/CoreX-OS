{{-- My Portal > Overview > Where am I vs where I should be. READ ONLY. Spec: agency-tracker-rehome.md section 6.
     "Where I should be" is the agent's monthly targets (the same ones Targets & Budget works from).
     Laid out to fit one laptop screen with no scrolling (my-portal-restyle.md section 9): four figures across the top,
     then You / branch / company beside the 7-day momentum strip. --}}
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
    $you = ['actual' => (float) $p['actuals']['salesValue'], 'target' => (float) $t['value']];
    $cols = [['You', $you], ['Your branch', $cmp['branch'] ? $valueBar($cmp['branch']) : null], ['The company', $cmp['company'] ? $valueBar($cmp['company']) : null]];
    $weekSplit = array_slice($p['points']['daysSplit'], -7, 7, true);
@endphp
<div x-show="sub.overview === 'whereami'" x-cloak class="pg-pane pg-wide" data-testid="portal-where-am-i">
    <div class="mp-h">
        <h3>Where am I vs where I should be &mdash; {{ $monthLabel }}</h3>
        <x-period-selector :months="$p['months']" :keep="['sub' => 'whereami']" />
    </div>

    @if(! $t['set'])
        <p class="text-xs" style="color:var(--text-muted); margin:0 0 8px;" data-testid="no-targets">No targets are set for {{ $monthLabel }} yet, so there is nothing to measure against. What you have done so far is shown below.</p>
    @endif

    <div class="mp-grid mp-g4" style="margin-bottom:8px;" data-testid="targets-vs-actuals">
        @foreach($metrics as $m)
            @php $bar = $pct($m['actual'], $m['target']); @endphp
            <div class="mp-card" data-testid="metric-{{ $m['key'] }}">
                <div class="flex items-baseline justify-between gap-2">
                    <div class="mp-lbl">{{ $m['label'] }}</div>
                    <div class="mp-note">{{ $m['target'] > 0 ? 'target ' . $m['fmt']($m['target']) : 'no target set' }}</div>
                </div>
                <div class="mp-val" data-testid="actual-{{ $m['key'] }}">{{ $m['fmt']($m['actual']) }}</div>
                @if($m['key'] === 'points')
                    <div class="mp-note flex flex-wrap gap-x-3" data-testid="points-split">
                        <span class="inline-flex items-center gap-1"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--brand-icon, #0ea5e9);"></span>Manual {{ number_format($p['points']['monthSplit']['manual'], 0) }}</span>
                        <span class="inline-flex items-center gap-1"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--ds-amber, #f59e0b);"></span>Automatic {{ number_format($p['points']['monthSplit']['automatic'], 0) }}</span>
                    </div>
                @endif
                @if($bar !== null)
                    <div class="ds-progress-track" style="margin-top:6px;"><div class="ds-progress-bar {{ $bar >= 80 ? 'ds-bar-navy' : 'ds-bar-amber' }}" style="width: {{ $bar }}%"></div></div>
                    <div class="mp-note" style="margin-top:2px;">{{ $bar }}% of target</div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mp-grid mp-g2">
        <div class="mp-card" data-testid="you-vs-branch-company">
            <div class="flex items-baseline justify-between gap-2" style="margin-bottom:6px;">
                <div class="ds-section-header" style="margin:0;">You vs your branch vs the company</div>
                <div class="mp-note">Sales value &middot; totals only</div>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px;">
                @foreach($cols as [$name, $v])
                    <div>
                        @if($v)
                            @php $bar = $pct($v['actual'], $v['target']); @endphp
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="mp-lbl">{{ $name }}</span>
                                <span class="text-sm" style="color:var(--text-primary);"><strong>{{ $rand($v['actual']) }}</strong> <span class="mp-note">{{ $v['target'] > 0 ? 'of ' . $rand($v['target']) : 'no target set' }}</span></span>
                            </div>
                            @if($bar !== null)<div class="ds-progress-track" style="margin-top:3px;"><div class="ds-progress-bar {{ $bar >= 80 ? 'ds-bar-navy' : 'ds-bar-amber' }}" style="width: {{ $bar }}%"></div></div>@endif
                        @else
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="mp-lbl">{{ $name }}</span>
                                <span class="mp-note">{{ $name === 'Your branch' ? 'No branch assigned.' : 'Not available.' }}</span>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mp-card" data-testid="momentum">
            <div class="ds-section-header" style="margin:0 0 4px;">Momentum &mdash; last 7 days (points)</div>
            <x-points-split-strip :days="$weekSplit" label="D" :height="44" testid="momentum-strip" />
        </div>
    </div>
</div>
