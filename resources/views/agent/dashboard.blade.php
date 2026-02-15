<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-200 leading-tight">
                Agent Dashboard — {{ $snapshot['month_label'] }}
            </h2>
            <div class="text-sm text-gray-400">
                {{ $snapshot['range']['start'] }} → {{ $snapshot['range']['end'] }}
            </div>
        </div>
    </x-slot>

    @php
        $t = $snapshot['derived_targets'] ?? ['deals_needed'=>0,'listings_needed'=>0,'value_target'=>0];
        $a = $snapshot['actuals'] ?? ['deals_count'=>0,'sales_value'=>0,'avg_sale_price_actual'=>0,'effective_commission_percent'=>0,'daily_rows'=>0,'points_actual'=>0,'points_target'=>0];
        $p = $snapshot['progress'] ?? ['deals_pct'=>null,'value_pct'=>null,'points_pct'=>null];
        $rem = $snapshot['remaining'] ?? ['deals'=>0,'value'=>0,'days_left'=>0];
        $pace = $snapshot['pace'] ?? ['deals_per_day'=>0,'value_per_day'=>0];
        $cmpBranch = $snapshot['comparisons']['branch'] ?? null;
        $cmpCompany = $snapshot['comparisons']['company'] ?? null;

        $dealsPct = is_null($p['deals_pct']) ? 0 : min(100, max(0, (float)$p['deals_pct']));
        $valuePct = is_null($p['value_pct']) ? 0 : min(100, max(0, (float)$p['value_pct']));

        $monthStart = \Carbon\Carbon::createFromFormat('Y-m', $snapshot['period'])->startOfMonth();
        $daysInMonth = $monthStart->daysInMonth;



        $pointsActual = (float)($a['points_actual'] ?? 0);
        $pointsTarget = (float)($a['points_target'] ?? 0);
        $pointsPctRaw = $p['points_pct'] ?? null;
        $pointsPct = is_null($pointsPctRaw) ? 0 : min(100, max(0, (float)$pointsPctRaw));

        $pointsRemaining = max(0, $pointsTarget - $pointsActual);
        $daysLeft = (int)($rem['days_left'] ?? 0);
        $pointsPerDayNeeded = ($daysLeft > 0) ? round($pointsRemaining / $daysLeft, 1) : $pointsRemaining;

        // Simple coach message (Blade-only, no DB calls)
        $pointsStatus = $pointsStatus ?? '—';
        $coachMsg = 'Set a points target to unlock coaching.';
        if ($pointsTarget > 0) {
            if ($pointsActual >= $pointsTarget) {
                $coachMsg = 'Target achieved — excellent work. Keep going for a personal best.';
            } elseif ($pointsStatus === 'Ahead') {
                $coachMsg = 'You are ahead of pace — keep the rhythm and protect your lead.';
            } elseif ($pointsStatus === 'On pace') {
                $coachMsg = 'You are on pace — one strong day keeps you in the green.';
            } else {
                $todayAim = max(10, round($pointsPerDayNeeded, 0)); // minimum meaningful aim
                $calls = (int)$todayAim;            // calls are 1 point each
                $whatsapps = (int)max(0, $todayAim - 5); // example mix
                $coachMsg = "Come on — you can still do this. Aim for {$todayAim} points today: about {$calls} calls, or 5 calls + {$whatsapps} WhatsApps, or 1 buyer appointment to swing momentum.";
            }
        }


        // Simple pace status vs "needed per day" (if target exists)
        $pointsStatus = '—';
        $pointsBarClass = 'bg-gray-900';

        // Color rules (no JS)
        // - Target achieved: green + trophy
        // - Ahead/On pace: green
        // - Behind: amber if close, red if far
        if ($pointsTarget > 0 && $daysLeft > 0) {
            $daysElapsed = max(1, $daysInMonth - $daysLeft);
            $expectedByNow = round(($pointsTarget / $daysInMonth) * $daysElapsed, 1);
            if ($pointsActual >= $expectedByNow * 1.05) $pointsStatus = 'Ahead';
            elseif ($pointsActual >= $expectedByNow * 0.95) $pointsStatus = 'On pace';
            else $pointsStatus = 'Behind';
        }

        // TARGET_BAR_CLASS_APPLIED
        if ($pointsTarget > 0) {
            if ($pointsActual >= $pointsTarget) {
                $pointsBarClass = 'bg-green-600';
            } elseif ($pointsStatus === 'Ahead' || $pointsStatus === 'On pace') {
                $pointsBarClass = 'bg-green-600';
            } else {
                $pointsBarClass = ($pointsPct >= 75) ? 'bg-amber-500' : 'bg-red-600';
            }
        }

        // Calendar starts on Monday
        $firstDow = (int)$monthStart->dayOfWeekIso; // 1..7
        $padLeft = $firstDow - 1;

        $dailyMap = $snapshot['daily_map'] ?? [];
    @endphp

    <div class="space-y-6">
        {{-- HERO / MOTIVATION --}}
        <div class="card">
            <div class="flex flex-col gap-4">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <div class="text-2xl md:text-3xl font-extrabold text-gray-900 leading-tight">
                            Your focus — {{ $snapshot['month_label'] }}
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            {{ $snapshot['range']['start'] }} → {{ $snapshot['range']['end'] }}
                        </div>
                    </div>

                    <div class="flex flex-wrap md:flex-nowrap items-center gap-2">
                        <form method="GET" action="{{ route('agent.dashboard') }}" class="flex items-center gap-2">
                            <label class="text-sm font-semibold text-gray-700">Period</label>
                            <input type="month" class="h-10 px-2 text-sm w-auto" name="period" value="{{ $snapshot['period'] ?? '' }}" />
                            <button type="submit" class="px-3 py-2 text-sm rounded-lg border bg-white hover:bg-gray-50">Go</button>
                        </form>

                        <a href="{{ route('agent.daily') }}" class="btn-primary inline-flex items-center justify-center min-h-[40px] px-4 whitespace-nowrap">
                            Daily Activity
                        </a>
                    </div>
                </div>

                {{-- Points + Value (agent-focused) --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {{-- Points --}}
                    <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <div class="text-sm text-gray-600 font-semibold">Points</div>
                                <div class="text-3xl font-extrabold text-gray-900 leading-tight">
                                    {{ number_format($pointsActual, 1) }}
                                    <span class="text-gray-400 font-bold">/ {{ number_format($pointsTarget, 0) }}</span>
                                    @if($pointsTarget > 0 && $pointsActual >= $pointsTarget)
                                        <span title="Target achieved">🏆</span>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-700 mt-1 font-semibold">
                                    Progress {{ $p['points_pct'] ?? '—' }}%
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-sm text-gray-600 font-semibold">Remaining</div>
                                <div class="text-2xl font-extrabold text-gray-900 leading-tight">
                                    {{ number_format($pointsRemaining, 1) }}
                                </div>
                                <div class="text-sm text-gray-700 mt-1">
                                    Need <span class="font-bold">{{ number_format($pointsPerDayNeeded, 1) }}</span>/day
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 text-sm text-gray-600 font-semibold">Points progress</div>
                        <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                            <div class="h-3 {{ $pointsBarClass ?? 'bg-gray-900' }}" style="width: {{ $pointsPct }}%"></div>
                        </div>
                    </div>

                    {{-- Value --}}
                    <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <div class="text-sm text-gray-600 font-semibold">Sales Value</div>
                                <div class="text-3xl font-extrabold text-gray-900 leading-tight">
                                    R {{ number_format((float)($a['sales_value'] ?? 0),0) }}
                                    <span class="text-gray-400 font-bold">/ R {{ number_format((float)($t['value_target'] ?? 0),0) }}</span>
                                    @if(($t['value_target'] ?? 0) > 0 && ((float)($a['sales_value'] ?? 0) >= (float)($t['value_target'] ?? 0)))
                                        <span title="Target achieved">🏆</span>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-700 mt-1 font-semibold">
                                    Progress {{ $p['value_pct'] ?? '—' }}%
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-sm text-gray-600 font-semibold">Remaining</div>
                                <div class="text-2xl font-extrabold text-gray-900 leading-tight">
                                    R {{ number_format(max(0, (float)($t['value_target'] ?? 0) - (float)($a['sales_value'] ?? 0)), 0) }}
                                </div>
                            </div>
                        </div>

                        @php
                            $valueBarClass = 'bg-gray-900';
                            if (($t['value_target'] ?? 0) > 0) {
                                if (($a['sales_value'] ?? 0) >= ($t['value_target'] ?? 0)) $valueBarClass = 'bg-green-600';
                                elseif ($valuePct >= 95) $valueBarClass = 'bg-green-600';
                                elseif ($valuePct >= 75) $valueBarClass = 'bg-amber-500';
                                else $valueBarClass = 'bg-red-600';
                            }
                        @endphp

                        <div class="mt-3 text-sm text-gray-600 font-semibold">Value progress</div>
                        <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                            <div class="h-3 {{ $valueBarClass }}" style="width: {{ $valuePct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

<div class="card">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Your Actuals</h3>
                    <div class="text-xs text-gray-500 -mt-2 mb-4">What you’ve done so far — updated from Deals + Daily Activity + Points.</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-black/10 bg-gray-50 p-4">
                        <div class="text-sm text-gray-600">Deals done</div>
                                                <div class="text-2xl font-bold text-gray-900">{{ $a['deals_count'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">All-time: {{ $a['deals_count_all_time'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-black/10 bg-gray-50 p-4">
                        <div class="text-sm text-gray-600">Sales value</div>
                                                <div class="text-2xl font-bold text-gray-900">R {{ number_format((float)$a['sales_value'],0) }}</div>
                        <div class="mt-1 text-xs text-gray-500">All-time: R {{ number_format((float)($a['sales_value_all_time'] ?? 0),0) }}</div>
                    </div>
                    <div class="rounded-xl border border-black/10 bg-gray-50 p-4">
                        <div class="text-sm text-gray-600">Avg sale price (captured)</div>
                                                <div class="text-2xl font-bold text-gray-900">R {{ number_format((float)($a['avg_sale_price_actual'] ?? 0), 0) }}</div>
                        <div class="mt-1 text-xs text-gray-500">All-time: R {{ number_format((float)($a['avg_sale_price_actual_all_time'] ?? 0), 0) }}</div>
                    </div>
                    <div class="rounded-xl border border-black/10 bg-gray-50 p-4">
                        <div class="text-sm text-gray-600">Avg commission % (Ex VAT)</div>
                                                <div class="text-2xl font-bold text-gray-900">{{ number_format((float)($a['effective_commission_percent'] ?? 0), 2) }}%</div>
                        <div class="mt-1 text-xs text-gray-500">All-time: {{ number_format((float)($a['effective_commission_percent_all_time'] ?? 0), 2) }}%</div>
                    </div>

                    <div class="rounded-xl border border-black/10 bg-gray-50 p-4">
                        <div class="text-sm text-gray-600">Daily activity entries</div>

                                                <div class="text-2xl font-bold text-gray-900">{{ $a['daily_rows'] }}</div>
                        <div class="mt-1 text-xs text-gray-500">All-time: {{ $a['daily_rows_all_time'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-black/10 bg-gray-50 p-4">
<div class="text-sm text-gray-600">Listing stock</div>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            <div>
                                <div class="text-xs text-gray-500">Active</div>
                                <div class="text-xl font-bold text-gray-900"><a class="underline hover:no-underline" href="{{ route('agent.listings', ['filter' => 'active']) }}">{{ (int)($listingStats['total'] ?? 0) }}</a></div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Avg DOM</div>
                                <div class="text-xl font-bold text-gray-900"><a class="underline hover:no-underline" href="{{ route('agent.listings', ['filter' => 'dom']) }}">{{ (int)($listingStats['avg_days_on_market'] ?? 0) }}</a></div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Stale (14d)</div>
                                <div class="text-xl font-bold text-gray-900"><a class="underline hover:no-underline" href="{{ route('agent.listings', ['filter' => 'stale']) }}">{{ (int)($listingStats['stale'] ?? 0) }}</a></div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Expiring (14d)</div>
                                <div class="text-xl font-bold text-gray-900"><a class="underline hover:no-underline" href="{{ route('agent.listings', ['filter' => 'expiring']) }}">{{ (int)($listingStats['expiring_soon'] ?? 0) }}</a></div>
                            </div>
                        </div>
                        <div class="mt-2 text-xs text-gray-500">Expired: <a class="underline hover:no-underline" href="{{ route('agent.listings', ['filter' => 'expired']) }}">{{ (int)($listingStats['expired'] ?? 0) }}</a></div>
                    </div>
                </div>
            </div>
        </div>

                {{-- YOU vs BRANCH vs COMPANY (WOW scorecards, totals only) --}}
        <div class="card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">You vs Branch vs Company</h3>
                <div class="text-xs text-gray-500">Totals only (privacy safe)</div>
            </div>

            @php

                $youValueT = (float)($t['value_target'] ?? 0);
                $youValueA = (float)($a['sales_value'] ?? 0);

                $youPointsT = (float)($a['points_target'] ?? 0);
                $youPointsA = (float)($a['points_actual'] ?? 0);

                $pct = function($a, $t) {
                    if (!$t || $t <= 0) return null;
                    $v = ($a / $t) * 100;
                    if ($v < 0) $v = 0;
                    if ($v > 100) $v = 100;
                    return round($v, 1);
                };

                $barClass = function($pct) {
                    if ($pct === null) return 'bg-gray-300';
                    if ($pct >= 95) return 'bg-green-600';
                    if ($pct >= 75) return 'bg-amber-500';
                    return 'bg-red-600';
                };
                $youValuePct  = $pct($youValueA,  $youValueT);
                $youPointsPct = $pct($youPointsA, $youPointsT);
                $bValueA = (float)($cmpBranch['actuals']['sales_value'] ?? 0);
                $bValueT = (float)($cmpBranch['targets']['value'] ?? 0);
                $bValuePct = $pct($bValueA, $bValueT);
                $cValueA = (float)($cmpCompany['actuals']['sales_value'] ?? 0);
                $cValueT = (float)($cmpCompany['targets']['value'] ?? 0);
                $cValuePct = $pct($cValueA, $cValueT);
            @endphp

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                {{-- YOU --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold">You</div>
                            <div class="mt-1 text-2xl font-extrabold text-gray-900 leading-tight">
                                {{ number_format($youPointsA, 1) }}
                                <span class="text-gray-400 font-bold">/ {{ number_format($youPointsT, 0) }}</span>
                                @if($youPointsT > 0 && $youPointsA >= $youPointsT) <span title="Target achieved">🏆</span> @endif
                            </div>
                            <div class="text-sm text-gray-700 font-semibold">Points</div>
                        </div>

                        <div class="text-right">
                            <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Status</div>
                            <div class="mt-1 text-lg font-extrabold text-gray-900">
                                {{ $pointsStatus ?? '—' }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 text-xs text-gray-600 font-semibold">Points progress</div>
                    <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                        <div class="h-3 {{ $barClass($youPointsPct) }}" style="width: {{ (int)($youPointsPct ?? 0) }}%"></div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3">

                          <div>
                              <div class="text-xs text-gray-600 font-semibold">Value</div>
                            <div class="text-lg font-extrabold text-gray-900">
                                R {{ number_format($youValueA,0) }}
                            </div>
                            <div class="text-xs text-gray-500">
                                / R {{ number_format($youValueT,0) }}
                                @if($youValueT > 0 && $youValueA >= $youValueT) <span title="Target achieved">🏆</span> @endif
                            </div>
                            <div class="mt-2 h-2 rounded bg-gray-200 overflow-hidden">
                                <div class="h-2 {{ $barClass($youValuePct) }}" style="width: {{ (int)($youValuePct ?? 0) }}%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-xs text-gray-600">
                        Daily activity entries: <span class="font-bold text-gray-900">{{ (int)($a['daily_rows'] ?? 0) }}</span>
                    </div>
                </div>

                {{-- BRANCH --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Your Branch</div>

                    @if($cmpBranch)
                        </div>

                        <div class="mt-4 text-xs text-gray-600 font-semibold">Value progress</div>
                        <div class="mt-1 text-lg font-extrabold text-gray-900">
                            R {{ number_format($bValueA,0) }}
                            <span class="text-gray-400 font-bold">/ R {{ number_format($bValueT,0) }}</span>
                            @if($bValueT > 0 && $bValueA >= $bValueT) <span title="Target achieved">🏆</span> @endif
                        </div>
                        <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                            <div class="h-3 {{ $barClass($bValuePct) }}" style="width: {{ (int)($bValuePct ?? 0) }}%"></div>
                        </div>

                        <div class="mt-4 text-xs text-gray-600">
                            Daily activity entries: <span class="font-bold text-gray-900">{{ (int)($cmpBranch['actuals']['daily_rows'] ?? 0) }}</span>
                        </div>
                    @else
                        <div class="mt-2 text-sm text-gray-600">No branch assigned.</div>
                        <div class="mt-3 h-3 rounded bg-gray-200 overflow-hidden">
                            <div class="h-3 bg-gray-300" style="width: 10%"></div>
                        </div>
                    @endif
                </div>

                {{-- COMPANY --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold">Company</div>

                    @if($cmpCompany)
                        </div>

                        <div class="mt-4 text-xs text-gray-600 font-semibold">Value progress</div>
                        <div class="mt-1 text-lg font-extrabold text-gray-900">
                            R {{ number_format($cValueA,0) }}
                            <span class="text-gray-400 font-bold">/ R {{ number_format($cValueT,0) }}</span>
                            @if($cValueT > 0 && $cValueA >= $cValueT) <span title="Target achieved">🏆</span> @endif
                        </div>
                        <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                            <div class="h-3 {{ $barClass($cValuePct) }}" style="width: {{ (int)($cValuePct ?? 0) }}%"></div>
                        </div>

                        <div class="mt-4 text-xs text-gray-600">
                            Daily activity entries: <span class="font-bold text-gray-900">{{ (int)($cmpCompany['actuals']['daily_rows'] ?? 0) }}</span>
                        </div>
                    @else
                        <div class="mt-2 text-sm text-gray-600">Not available.</div>
                        <div class="mt-3 h-3 rounded bg-gray-200 overflow-hidden">
                            <div class="h-3 bg-gray-300" style="width: 10%"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Momentum — last 7 days</h3>
                <div class="text-xs text-gray-500">Points per day + streak (no JS)</div>
            </div>

            @php
                $pointsByDate = $snapshot['points_by_date'] ?? [];

                $today = now()->startOfDay();

                // build last 7 days (oldest -> newest)
                $last7 = [];
                for ($i = 6; $i >= 0; $i--) {
                    $last7[] = $today->copy()->subDays($i);
                }

                // max for bar scaling
                $maxPts = 1;
                foreach ($last7 as $dt) {
                    $d = $dt->toDateString();
                    $pts = (float)($pointsByDate[$d] ?? 0);
                    if ($pts > $maxPts) $maxPts = $pts;
                }

                // streak = consecutive days backwards with any points > 0
                $streak = 0;
                for ($i = 0; $i < 30; $i++) {
                    $d = $today->copy()->subDays($i)->toDateString();
                    $pts = (float)($pointsByDate[$d] ?? 0);
                    if ($pts > 0) { $streak++; continue; }
                    break;
                }
            @endphp


            <div class="grid grid-cols-7 gap-2">
                @foreach($last7 as $dt)
                    @php
                        $d = $dt->toDateString();
                        $pts = $pointsByDate[$d] ?? 0;
                        $h = (int)round(($pts / $maxPts) * 64);
                    @endphp
                    <div class="rounded-xl border border-black/10 bg-gray-50 p-2 text-center">
                        <div class="text-[10px] text-gray-600 font-semibold">{{ $dt->format('D') }}</div>
                        <div class="mt-2 h-16 flex items-end justify-center">
                            <div class="w-6 rounded bg-gray-900" style="height: {{ $h }}px;"></div>
                        </div>
                        <div class="mt-2 text-xs font-extrabold text-gray-900">{{ number_format($pts, 1) }}</div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex items-center justify-between">
                <div class="text-sm text-gray-700">
                    Streak: <span class="font-extrabold text-gray-900">{{ $streak }}</span> day{{ $streak === 1 ? '' : 's' }}
                </div>
                <div class="text-sm text-gray-600">
                    Today aim: <span class="font-bold text-gray-900">{{ number_format(max(10, round($pointsPerDayNeeded, 0)), 0) }}</span> pts
                </div>
            </div>
        </div>

        <div class="text-xs text-gray-500">
            Privacy: Agent view shows only derived targets + actuals. No worksheet net-income fields are exposed to anyone else.
        </div>

    </div>
</x-app-layout>
