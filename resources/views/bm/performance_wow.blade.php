@php
    $r = $rollup;

    $pts = $r['points'] ?? ['actual'=>0,'target'=>0,'pct'=>0,'status'=>'—','remaining'=>0,'per_day_needed'=>0,'today_points'=>0,'days_left'=>0];
    $m7  = $r['momentum_7d'] ?? [];
    $today = $r['activities_today'] ?? [];

    $pointsActual = (float)($pts['actual'] ?? 0);
    $pointsTarget = (float)($pts['target'] ?? 0);
    $pointsPct = (float)($pts['pct'] ?? 0);
    $pointsStatus = (string)($pts['status'] ?? '—');
    $pointsRemaining = (float)($pts['remaining'] ?? 0);
    $pointsPerDayNeeded = (float)($pts['per_day_needed'] ?? 0);
    $todayPoints = (float)($pts['today_points'] ?? 0);
    $daysLeft = (int)($pts['days_left'] ?? 0);

    $pointsBarClass = 'ds-bar-navy';
    if ($pointsTarget > 0) {
        if ($pointsActual >= $pointsTarget) $pointsBarClass = 'ds-bar-navy';
        elseif ($pointsStatus === 'Ahead' || $pointsStatus === 'On pace') $pointsBarClass = 'ds-bar-navy';
        elseif ($pointsPct >= 50) $pointsBarClass = 'ds-bar-amber';
        else $pointsBarClass = 'ds-bar-crimson';
    }

    $bg = $branchGoal ?? null;
    $branchDeals = (int)($bg?->deals_target ?? 0);
    $branchListings = (int)($bg?->listings_target ?? 0);
    $branchValue = (float)($bg?->value_target ?? 0);

    $sumDeals = (int)($r["totals"]["targets"]["deals"] ?? 0);
    $sumListings = (int)($r["totals"]["targets"]["listings"] ?? 0);
    $sumValue = (float)($r["totals"]["targets"]["value"] ?? 0);

    // Intervention list (behind agents)
    $behind = [];
    foreach (($r['rows'] ?? []) as $row) {
        $status = (string)($row['progress']['points_status'] ?? '');
        $pp = (float)($row['progress']['points_pct'] ?? 0);
        $tPts = (float)($row['targets']['points'] ?? 0);

        $isBehind = ($status === 'Behind') || ($tPts > 0 && $pp < 75);
        if ($isBehind) $behind[] = $row;
    }
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-200 leading-tight">
                    Branch Dashboard — {{ $r['period'] }} <span class="text-gray-400 font-semibold">(WOW)</span>
                </h2>
                <div class="text-sm text-gray-400">Branch Manager wallboard (stable view stays at /bm/performance)</div>
            </div>

            <div class="flex flex-wrap md:flex-nowrap items-center gap-2">
                <form method="GET" action="{{ route('bm.performance') }}" class="flex flex-wrap md:flex-nowrap items-center gap-2">
                    <input type="hidden" name="wow" value="1" />
                    <label class="text-sm font-semibold text-gray-200">Period</label>
                    <input type="month" name="period" value="{{ $r['period'] }}" />
                    <button type="submit" class="btn-primary px-4 py-2">Go</button>
                    <a href="{{ route('bm.performance', ['period' => $r['period']]) }}" class="btn-primary px-4 py-2">Back to Stable</a>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if(session("status"))
            <div class="bg-green-500/10 border border-green-500/20 text-green-200 rounded-xl p-3 text-sm">
                {{ session("status") }}
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-500/10 border border-red-500/20 text-red-200 rounded-xl p-3 text-sm">
                {{ implode(", ", $errors->all()) }}
            </div>
        @endif

        {{-- TOP SCOREBOARD (TV-friendly) --}}
        <h2 class="ds-section-header">Branch Scoreboard</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="ds-status-card">
                <div class="ds-label">Branch Points</div>
                <div class="ds-value-xl">
                    {{ number_format($pointsActual, 0) }}
                    <span class="ds-value" style="font-size:0.6em">/ {{ number_format($pointsTarget, 0) }}</span>
                </div>
                <div class="mt-3 ds-progress-track">
                    <div class="ds-progress-bar {{ $pointsBarClass }}" style="width: {{ min(100, max(0, $pointsPct)) }}%"></div>
                </div>
                <div class="mt-2 ds-value">Progress {{ number_format($pointsPct, 1) }}%</div>
            </div>

            <div class="ds-status-card">
                <div class="ds-label">Today</div>
                <div class="ds-value-xl">{{ number_format($todayPoints, 0) }}</div>
                <div class="ds-label mt-1">points captured</div>
                <div class="mt-3 ds-value">
                    Status: <span class="font-extrabold">{{ $pointsStatus }}</span>
                </div>
            </div>

            <div class="ds-status-card">
                <div class="ds-label">Coaching Number</div>
                <div class="ds-value-xl">{{ number_format($pointsPerDayNeeded, 1) }}</div>
                <div class="ds-label mt-1">points needed per day</div>
                <div class="mt-3 ds-value">
                    Remaining: <span class="font-extrabold">{{ number_format($pointsRemaining, 0) }}</span>
                </div>
            </div>

            <div class="ds-status-card">
                <div class="ds-label">Days Left</div>
                <div class="ds-value-xl">{{ $daysLeft }}</div>
                <div class="ds-label mt-1">this month</div>
            </div>
        </div>

        {{-- INTERVENTION NOW --}}
        <div class="ds-section-header">Intervention Now</div>
        <div class="ds-section-sub mb-4">Agents behind pace — focus here first.</div>
        <div class="ds-status-card">
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div class="text-sm text-gray-700">
                    Rule of thumb: <span class="font-bold">Behind</span> = needs action today.
                </div>
            </div>

            @if(count($behind))
                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                    @foreach(array_slice($behind, 0, 3) as $row)
                        @php
                            $name = $row['name'] ?? 'Agent';
                            $aPts = (float)($row['actuals']['points'] ?? 0);
                            $tPts = (float)($row['targets']['points'] ?? 0);
                            $pp = (float)($row['progress']['points_pct'] ?? 0);
                            $need = (float)($row['progress']['points_per_day_needed'] ?? 0);
                        @endphp
                        <div class="rounded-2xl border border-red-500/20 bg-red-500/5 p-4">
                            <div class="ds-value font-extrabold">{{ $name }}</div>
                            <div class="ds-label mt-1">
                                {{ number_format($aPts,0) }} / {{ number_format($tPts,0) }} pts • {{ number_format($pp,1) }}%
                            </div>
                            <div class="mt-3 ds-label">
                                Coaching number: <span class="font-extrabold">{{ number_format($need,1) }}</span> / day
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4 ds-label">No one is flagged behind right now. Keep momentum.</div>
            @endif
        </div>

        {{-- MOMENTUM + TODAY --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="ds-status-card">
                <div class="ds-section-header" style="font-size:1.25rem">Momentum (last 7 days)</div>
                <div class="ds-section-sub">Quick pulse — are we accelerating?</div>

                <div class="mt-4 grid grid-cols-7 gap-2">
                    @foreach($m7 as $d)
                        @php
                            $v = (float)($d['points'] ?? 0);
                            $h = min(80, max(10, $v));
                        @endphp
                        <div class="rounded-xl border border-black/10 bg-white p-2 text-center">
                            <div class="text-[10px] text-gray-500">{{ \Carbon\Carbon::parse($d['date'])->format('D') }}</div>
                            <div class="mt-2 h-16 flex items-end justify-center">
                                <div class="w-3 rounded bg-gray-900" style="height: {{ $h }}%"></div>
                            </div>
                            <div class="text-[11px] font-extrabold text-gray-900 mt-1">{{ number_format($v,0) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="ds-status-card">
                <div class="ds-section-header" style="font-size:1.25rem">Today Breakdown</div>
                <div class="ds-section-sub">What was loaded today (counts). Points already included above.</div>

                @if(count($today))
                    <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-3">
                        @foreach($today as $k => $v)
                            <div class="rounded-2xl border border-black/10 bg-gray-50 p-3">
                                <div class="ds-label">{{ str_replace('_',' ', $k) }}</div>
                                <div class="ds-value-lg">{{ (int)$v }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 ds-label">No activity captured today yet.</div>
                @endif
            </div>
        </div>

        {{-- Branch Targets (BM-set) --}}
        <div class="ds-section-header">Branch Targets (BM controls)</div>
        <div class="ds-section-sub mb-4">These are branch-level goals. Agent targets below should sum close to this — if not, it highlights misalignment (agents set lower/higher targets).</div>
        <div class="ds-status-card">
            <div class="flex items-center justify-end gap-4 flex-wrap">
                <form method="POST" action="{{ route('bm.performance.save') }}" class="flex items-center gap-2 flex-wrap">
                    @csrf
                    <input type="hidden" name="period" value="{{ $r['period'] }}">
                    <input type="number" name="listings_target" value="{{ $branchListings }}" class="w-28" placeholder="Listings" min="0">
                    <input type="number" name="deals_target" value="{{ $branchDeals }}" class="w-24" placeholder="Deals" min="0">
                    <input type="number" step="0.01" name="value_target" value="{{ $branchValue }}" class="w-44" placeholder="Value" min="0">
                    <button class="btn-primary px-4 py-2">Save Branch Targets</button>
                </form>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <div class="p-3 rounded bg-gray-50 border border-black/10">
                    <div class="ds-label">Agent Target Sum (Deals)</div>
                    <div class="ds-value-lg">{{ $sumDeals }}</div>
                    <div class="ds-label">Branch target: <span class="font-bold">{{ $branchDeals }}</span> • Diff: <span class="font-bold">{{ $sumDeals - $branchDeals }}</span></div>
                </div>
                <div class="p-3 rounded bg-gray-50 border border-black/10">
                    <div class="ds-label">Agent Target Sum (Listings)</div>
                    <div class="ds-value-lg">{{ $sumListings }}</div>
                    <div class="ds-label">Branch target: <span class="font-bold">{{ $branchListings }}</span> • Diff: <span class="font-bold">{{ $sumListings - $branchListings }}</span></div>
                </div>
                <div class="p-3 rounded bg-gray-50 border border-black/10">
                    <div class="ds-label">Agent Target Sum (Value)</div>
                    <div class="ds-value-lg">R {{ number_format($sumValue, 0) }}</div>
                    <div class="ds-label">Branch target: <span class="font-bold">R {{ number_format($branchValue, 0) }}</span> • Diff: <span class="font-bold">R {{ number_format($sumValue - $branchValue, 0) }}</span></div>
                </div>
            </div>
        </div>

        {{-- Excel-style Agents table — ENHANCED (status + pace) --}}
        <div class="ds-section-header">Agents (targets vs actuals)</div>
        <div class="ds-section-sub mb-4">Management view: pace + status + intervention cues.</div>
        <div class="ds-status-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="ds-table min-w-full text-sm">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-3">Agent</th>
                            <th class="text-right px-4 py-3">Points (A/T)</th>
                            <th class="text-right px-4 py-3">Points %</th>
                            <th class="text-right px-4 py-3">Need / Day</th>
                            <th class="text-right px-4 py-3">Status</th>
                            <th class="text-right px-4 py-3">Deals (A/T)</th>
                            <th class="text-right px-4 py-3">Value (A/T)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($r['rows'] as $row)
                            @php
                                $pp = (float)($row['progress']['points_pct'] ?? 0);
                                $status = (string)($row['progress']['points_status'] ?? '—');
                                $need = (float)($row['progress']['points_per_day_needed'] ?? 0);

                                $tPts = (float)($row['targets']['points'] ?? 0);
                                $aPts = (float)($row['actuals']['points'] ?? 0);

                                $bar = 'ds-bar-navy';
                                if ($tPts > 0) {
                                    if ($aPts >= $tPts) $bar = 'ds-bar-navy';
                                    elseif ($status === 'Ahead' || $status === 'On pace' || $pp >= 95) $bar = 'ds-bar-navy';
                                    elseif ($pp >= 50) $bar = 'ds-bar-amber';
                                    else $bar = 'ds-bar-crimson';
                                }

                                $pill = 'ds-badge-default';
                                if ($status === 'Achieved') $pill = 'ds-badge-achieved';
                                elseif ($status === 'Ahead') $pill = 'ds-badge-ahead';
                                elseif ($status === 'On pace') $pill = 'ds-badge-onpace';
                                elseif ($status === 'Behind') $pill = 'ds-badge-behind';

                                $rowEmphasis = ($status === 'Behind') ? 'bg-red-50' : '';
                            @endphp
                            <tr class="border-t border-black/10 {{ $rowEmphasis }}">
                                <td class="px-4 py-3">
                                    <div class="font-semibold">
                                        <a class="ds-agent-link" href="{{ route('bm.agent.performance', ['userId' => $row['user_id'], 'period' => $r['period']]) }}">
                                            {{ $row['name'] }}
                                        </a>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ number_format($aPts,0) }} / {{ number_format($tPts,0) }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums">
                                    <div class="inline-flex items-center gap-3">
                                        <div class="ds-progress-track w-24" style="height:0.5rem">
                                            <div class="ds-progress-bar {{ $bar }}" style="width: {{ min(100, max(0, $pp)) }}%"></div>
                                        </div>
                                        <span class="font-bold">{{ number_format($pp,1) }}%</span>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums font-bold">
                                    {{ number_format($need, 1) }}
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <span class="ds-badge {{ $pill }}">{{ $status }}</span>
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ (int)($row['actuals']['deals'] ?? 0) }} / {{ (int)($row['targets']['deals'] ?? 0) }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums">
                                    R {{ number_format((float)($row['actuals']['value'] ?? 0),0) }} / R {{ number_format((float)($row['targets']['value'] ?? 0),0) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-4 ds-label">
                Coaching: focus on <span class="font-bold">Need / Day</span> and <span class="font-bold">Behind</span> rows first.
            </div>
        </div>

    </div>
</x-app-layout>
