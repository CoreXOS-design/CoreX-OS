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

    $pointsBarClass = 'bg-gray-900';
    if ($pointsTarget > 0) {
        if ($pointsActual >= $pointsTarget) $pointsBarClass = 'bg-green-600';
        elseif ($pointsStatus === 'Ahead' || $pointsStatus === 'On pace') $pointsBarClass = 'bg-green-600';
        elseif ($pointsPct >= 75) $pointsBarClass = 'bg-amber-500';
        else $pointsBarClass = 'bg-red-600';
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
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="card">
                <div class="text-sm text-gray-600 font-semibold">Branch Points</div>
                <div class="text-4xl font-extrabold text-gray-900 leading-tight">
                    {{ number_format($pointsActual, 0) }}
                    <span class="text-gray-400 text-2xl font-bold">/ {{ number_format($pointsTarget, 0) }}</span>
                </div>
                <div class="mt-3 h-3 rounded bg-gray-200 overflow-hidden">
                    <div class="h-3 {{ $pointsBarClass }}" style="width: {{ min(100, max(0, $pointsPct)) }}%"></div>
                </div>
                <div class="mt-2 text-sm text-gray-700 font-semibold">Progress {{ number_format($pointsPct, 1) }}%</div>
            </div>

            <div class="card">
                <div class="text-sm text-gray-600 font-semibold">Today</div>
                <div class="text-4xl font-extrabold text-gray-900 leading-tight">{{ number_format($todayPoints, 0) }}</div>
                <div class="text-sm text-gray-600 mt-1">points captured</div>
                <div class="mt-3 text-sm text-gray-700">
                    Status: <span class="font-extrabold">{{ $pointsStatus }}</span>
                </div>
            </div>

            <div class="card">
                <div class="text-sm text-gray-600 font-semibold">Coaching Number</div>
                <div class="text-4xl font-extrabold text-gray-900 leading-tight">{{ number_format($pointsPerDayNeeded, 1) }}</div>
                <div class="text-sm text-gray-600 mt-1">points needed per day</div>
                <div class="mt-3 text-sm text-gray-700">
                    Remaining: <span class="font-extrabold">{{ number_format($pointsRemaining, 0) }}</span>
                </div>
            </div>

            <div class="card">
                <div class="text-sm text-gray-600 font-semibold">Days Left</div>
                <div class="text-4xl font-extrabold text-gray-900 leading-tight">{{ $daysLeft }}</div>
                <div class="text-sm text-gray-600 mt-1">this month</div>
            </div>
        </div>

        {{-- INTERVENTION NOW --}}
        <div class="card">
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-2xl font-extrabold text-gray-900 leading-tight">Intervention Now</div>
                    <div class="text-sm text-gray-600">Agents behind pace — focus here first.</div>
                </div>
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
                            <div class="font-extrabold text-gray-900">{{ $name }}</div>
                            <div class="text-sm text-gray-700 mt-1">
                                {{ number_format($aPts,0) }} / {{ number_format($tPts,0) }} pts • {{ number_format($pp,1) }}%
                            </div>
                            <div class="mt-3 text-sm text-gray-800">
                                Coaching number: <span class="font-extrabold">{{ number_format($need,1) }}</span> / day
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-4 text-sm text-gray-600">No one is flagged behind right now. Keep momentum.</div>
            @endif
        </div>

        {{-- MOMENTUM + TODAY --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="card">
                <div class="text-gray-900 font-extrabold text-xl">Momentum (last 7 days)</div>
                <div class="text-sm text-gray-600">Quick pulse — are we accelerating?</div>

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

            <div class="card">
                <div class="text-gray-900 font-extrabold text-xl">Today Breakdown</div>
                <div class="text-sm text-gray-600">What was loaded today (counts). Points already included above.</div>

                @if(count($today))
                    <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-3">
                        @foreach($today as $k => $v)
                            <div class="rounded-2xl border border-black/10 bg-gray-50 p-3">
                                <div class="text-xs text-gray-600 font-semibold">{{ str_replace('_',' ', $k) }}</div>
                                <div class="text-2xl font-extrabold text-gray-900">{{ (int)$v }}</div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-4 text-sm text-gray-500">No activity captured today yet.</div>
                @endif
            </div>
        </div>

        {{-- Branch Targets (BM-set) — KEEP AS-IS --}}
        <div class="card">
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-gray-900 font-semibold">Branch Targets (BM controls)</div>
                    <div class="text-sm text-gray-600">
                        These are branch-level goals. Agent targets below should sum close to this — if not, it highlights misalignment (agents set lower/higher targets).
                    </div>
                </div>

                <form method="POST" action="{{ route('bm.performance.save') }}" class="flex items-center gap-2 flex-wrap">
                    @csrf
                    <input type="hidden" name="period" value="{{ $r['period'] }}">
                    <input type="number" name="listings_target" value="{{ $branchListings }}" class="w-28" placeholder="Listings" min="0">
                    <input type="number" name="deals_target" value="{{ $branchDeals }}" class="w-24" placeholder="Deals" min="0">
                    <input type="number" step="0.01" name="value_target" value="{{ $branchValue }}" class="w-44" placeholder="Value" min="0">
                    <button class="btn-primary px-4 py-2">Save Branch Targets</button>
                </form>
            </div>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm text-gray-900">
                <div class="p-3 rounded bg-gray-50 border border-black/10">
                    <div class="text-gray-600 font-semibold">Agent Target Sum (Deals)</div>
                    <div class="text-2xl font-extrabold">{{ $sumDeals }}</div>
                    <div class="text-gray-700">Branch target: <span class="font-bold">{{ $branchDeals }}</span> • Diff: <span class="font-bold">{{ $sumDeals - $branchDeals }}</span></div>
                </div>
                <div class="p-3 rounded bg-gray-50 border border-black/10">
                    <div class="text-gray-600 font-semibold">Agent Target Sum (Listings)</div>
                    <div class="text-2xl font-extrabold">{{ $sumListings }}</div>
                    <div class="text-gray-700">Branch target: <span class="font-bold">{{ $branchListings }}</span> • Diff: <span class="font-bold">{{ $sumListings - $branchListings }}</span></div>
                </div>
                <div class="p-3 rounded bg-gray-50 border border-black/10">
                    <div class="text-gray-600 font-semibold">Agent Target Sum (Value)</div>
                    <div class="text-2xl font-extrabold">R {{ number_format($sumValue, 0) }}</div>
                    <div class="text-gray-700">Branch target: <span class="font-bold">R {{ number_format($branchValue, 0) }}</span> • Diff: <span class="font-bold">R {{ number_format($sumValue - $branchValue, 0) }}</span></div>
                </div>
            </div>
        </div>

        {{-- Excel-style Agents table — ENHANCED (status + pace) --}}
        <div class="card overflow-hidden">
            <div class="p-4">
                <div class="text-gray-900 font-semibold text-lg">Agents (targets vs actuals)</div>
                <div class="text-sm text-gray-600">Management view: pace + status + intervention cues.</div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-gray-900">
                    <thead class="bg-gray-50 text-gray-700">
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

                                $bar = 'bg-gray-900';
                                if ($tPts > 0) {
                                    if ($aPts >= $tPts) $bar = 'bg-green-600';
                                    elseif ($status === 'Ahead' || $status === 'On pace' || $pp >= 95) $bar = 'bg-green-600';
                                    elseif ($pp >= 75) $bar = 'bg-amber-500';
                                    else $bar = 'bg-red-600';
                                }

                                $pill = 'bg-gray-100 text-gray-700';
                                if ($status === 'Achieved') $pill = 'bg-purple-100 text-purple-700';
                                elseif ($status === 'Ahead') $pill = 'bg-green-100 text-green-700';
                                elseif ($status === 'On pace') $pill = 'bg-amber-100 text-amber-800';
                                elseif ($status === 'Behind') $pill = 'bg-red-100 text-red-700';

                                $rowEmphasis = ($status === 'Behind') ? 'bg-red-50' : '';
                            @endphp
                            <tr class="border-t border-black/10 {{ $rowEmphasis }}">
                                <td class="px-4 py-3">
                                    <div class="font-semibold">
                                        <a class="text-indigo-600 hover:underline" href="{{ route('bm.agent.performance', ['userId' => $row['user_id'], 'period' => $r['period']]) }}">
                                            {{ $row['name'] }}
                                        </a>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums">
                                    {{ number_format($aPts,0) }} / {{ number_format($tPts,0) }}
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums">
                                    <div class="inline-flex items-center gap-3">
                                        <div class="w-24 h-2 rounded bg-gray-200 overflow-hidden">
                                            <div class="h-2 {{ $bar }}" style="width: {{ min(100, max(0, $pp)) }}%"></div>
                                        </div>
                                        <span class="font-bold">{{ number_format($pp,1) }}%</span>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums font-bold">
                                    {{ number_format($need, 1) }}
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <span class="px-2 py-1 rounded-full text-xs font-bold {{ $pill }}">{{ $status }}</span>
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

            <div class="p-4 text-sm text-gray-600">
                Coaching: focus on <span class="font-bold">Need / Day</span> and <span class="font-bold">Behind</span> rows first.
            </div>
        </div>

    </div>
</x-app-layout>
