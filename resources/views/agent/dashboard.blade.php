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
            $todayAim = max(10, round($pointsPerDayNeeded, 0));
            $calls = (int)$todayAim;
            $whatsapps = (int)max(0, $todayAim - 5);
            $coachMsg = "Come on — you can still do this. Aim for {$todayAim} points today: about {$calls} calls, or 5 calls + {$whatsapps} WhatsApps, or 1 buyer appointment to swing momentum.";
        }
    }

    $pointsStatus = '—';
    $pointsBarClass = 'ds-bar-navy';

    if ($pointsTarget > 0 && $daysLeft > 0) {
        $daysElapsed = max(1, $daysInMonth - $daysLeft);
        $expectedByNow = round(($pointsTarget / $daysInMonth) * $daysElapsed, 1);
        if ($pointsActual >= $expectedByNow * 1.05) $pointsStatus = 'Ahead';
        elseif ($pointsActual >= $expectedByNow * 0.95) $pointsStatus = 'On pace';
        else $pointsStatus = 'Behind';
    }

    if ($pointsTarget > 0) {
        if ($pointsActual >= $pointsTarget) $pointsBarClass = 'ds-bar-navy';
        elseif ($pointsPct >= 80) $pointsBarClass = 'ds-bar-navy';
        else $pointsBarClass = 'ds-bar-amber';
    }

    $firstDow = (int)$monthStart->dayOfWeekIso;
    $padLeft = $firstDow - 1;

    $dailyMap = $snapshot['daily_map'] ?? [];

    $fmtPct = function ($v) {
        if ($v === null || $v === '') return '—';
        return number_format((float)$v, 1) . '%';
    };

    $expiringMandateCount = \App\Models\DocumentFiling::forAgent(auth()->id())
        ->expiringSoon(30)->count();
@endphp

@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">

    {{-- PAGE HEADER (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" data-tour="at-agent-dashboard-header" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight tracking-tight">
                    My Dashboard — {{ $snapshot['month_label'] }}
                </h1>
                <p class="text-sm text-white/60">
                    {{ $snapshot['range']['start'] }} → {{ $snapshot['range']['end'] }}
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <form method="GET" action="{{ route('agent.dashboard') }}" class="flex items-center gap-2">
                    <label for="period" class="text-xs font-semibold text-white/70">Period</label>
                    <input id="period" type="month" name="period" value="{{ $snapshot['period'] ?? '' }}"
                           class="h-9 text-sm rounded-md border border-white/20 bg-white/10 text-white px-2 transition-all duration-300" />
                    <button type="submit"
                            class="px-3 py-1.5 text-sm font-semibold rounded-md bg-white/20 text-white hover:bg-white/30 transition-all duration-300">
                        Go
                    </button>
                </form>
                @include('layouts.partials.tour-header-launcher')
                <a href="{{ route('agent.daily') }}" class="corex-btn-primary" data-tour="at-agent-dashboard-daily-cta">Daily Activity</a>
            </div>
        </div>
    </div>

    {{-- EXPIRING MANDATES ALERT (§3.9 alert pattern) --}}
    @if($expiringMandateCount > 0)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">
                <strong>{{ number_format($expiringMandateCount) }} mandate{{ $expiringMandateCount === 1 ? '' : 's' }} expiring in the next 30 days.</strong>
            </div>
            <a href="{{ route('filing-register.index', ['agent_id' => auth()->id(), 'status' => 'Expiring']) }}"
               class="text-xs font-semibold whitespace-nowrap" style="color: var(--ds-amber);">View</a>
        </div>
    @endif

    {{-- HERO / MOTIVATION --}}
    <div class="ds-status-card" data-tour="at-agent-dashboard-focus">
        <div class="flex flex-col gap-4">
            <div>
                <div class="ds-section-header">Your focus — {{ $snapshot['month_label'] }}</div>
                <div class="ds-section-sub">
                    {{ $snapshot['range']['start'] }} → {{ $snapshot['range']['end'] }}
                </div>
            </div>

            {{-- Points + Value (agent-focused) --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                {{-- Points --}}
                <div class="ds-status-card">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <div class="ds-label">Points</div>
                            <div class="ds-value-xl leading-tight">
                                {{ number_format($pointsActual, 1) }}
                                <span style="color: var(--text-muted);" class="font-bold">/ {{ number_format($pointsTarget, 0) }}</span>
                                @if($pointsTarget > 0 && $pointsActual >= $pointsTarget)
                                    <span title="Target achieved">🏆</span>
                                @endif
                            </div>
                            <div class="text-sm font-semibold mt-1" style="color: var(--text-secondary);">
                                Progress {{ $fmtPct($p['points_pct'] ?? null) }}
                            </div>
                        </div>

                        <div class="text-right">
                            <div class="ds-label">Remaining</div>
                            <div class="ds-value-lg leading-tight">
                                {{ number_format($pointsRemaining, 1) }}
                            </div>
                            <div class="text-sm mt-1" style="color: var(--text-secondary);">
                                Need <span class="font-bold">{{ number_format($pointsPerDayNeeded, 1) }}</span>/day
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 ds-label">Points progress</div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $pointsBarClass ?? 'ds-bar-navy' }}" style="width: {{ $pointsPct }}%"></div>
                    </div>
                </div>

                {{-- Value --}}
                <div class="ds-status-card">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <div class="ds-label">Sales Value</div>
                            <div class="ds-value-xl leading-tight">
                                R {{ number_format((float)($a['sales_value'] ?? 0),0) }}
                                <span style="color: var(--text-muted);" class="font-bold">/ R {{ number_format((float)($t['value_target'] ?? 0),0) }}</span>
                                @if(($t['value_target'] ?? 0) > 0 && ((float)($a['sales_value'] ?? 0) >= (float)($t['value_target'] ?? 0)))
                                    <span title="Target achieved">🏆</span>
                                @endif
                            </div>
                            <div class="text-sm font-semibold mt-1" style="color: var(--text-secondary);">
                                Progress {{ $fmtPct($p['value_pct'] ?? null) }}
                            </div>
                        </div>

                        <div class="text-right">
                            <div class="ds-label">Remaining</div>
                            <div class="ds-value-lg leading-tight">
                                R {{ number_format(max(0, (float)($t['value_target'] ?? 0) - (float)($a['sales_value'] ?? 0)), 0) }}
                            </div>
                        </div>
                    </div>

                    @php
                        $valueBarClass = 'ds-bar-navy';
                        if (($t['value_target'] ?? 0) > 0) {
                            if (($a['sales_value'] ?? 0) >= ($t['value_target'] ?? 0)) $valueBarClass = 'ds-bar-navy';
                            elseif ($valuePct >= 80) $valueBarClass = 'ds-bar-navy';
                            else $valueBarClass = 'ds-bar-amber';
                        }
                    @endphp

                    <div class="mt-3 ds-label">Value progress</div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $valueBarClass }}" style="width: {{ $valuePct }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- YOUR ACTUALS --}}
    <div class="ds-status-card" data-tour="at-agent-dashboard-actuals">
        <h3 class="ds-section-header mb-1">Your Actuals</h3>
        <div class="ds-section-sub mb-4">What you've done so far — updated from Deals + Daily Activity + Points.</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
            <div class="ds-status-card">
                <div class="ds-label">Deals done</div>
                <div class="ds-value-lg">{{ number_format((int)($a['deals_count'] ?? 0)) }}</div>
                <div class="mt-1 text-xs" style="color: var(--text-muted);">All-time: {{ number_format((int)($a['deals_count_all_time'] ?? 0)) }}</div>
            </div>
            <div class="ds-status-card">
                <div class="ds-label">Sales value</div>
                <div class="ds-value-lg">R {{ number_format((float)$a['sales_value'],0) }}</div>
                <div class="mt-1 text-xs" style="color: var(--text-muted);">All-time: R {{ number_format((float)($a['sales_value_all_time'] ?? 0),0) }}</div>
            </div>
            <div class="ds-status-card">
                <div class="ds-label">Avg sale price (captured)</div>
                <div class="ds-value-lg">R {{ number_format((float)($a['avg_sale_price_actual'] ?? 0), 0) }}</div>
                <div class="mt-1 text-xs" style="color: var(--text-muted);">All-time: R {{ number_format((float)($a['avg_sale_price_actual_all_time'] ?? 0), 0) }}</div>
            </div>
            <div class="ds-status-card">
                <div class="ds-label">Avg commission % (Ex VAT)</div>
                <div class="ds-value-lg">{{ number_format((float)($a['effective_commission_percent'] ?? 0), 2) }}%</div>
                <div class="mt-1 text-xs" style="color: var(--text-muted);">All-time: {{ number_format((float)($a['effective_commission_percent_all_time'] ?? 0), 2) }}%</div>
            </div>

            <div class="ds-status-card">
                <div class="ds-label">Daily activity entries</div>
                <div class="ds-value-lg">{{ number_format((int)($a['daily_rows'] ?? 0)) }}</div>
                <div class="mt-1 text-xs" style="color: var(--text-muted);">All-time: {{ number_format((int)($a['daily_rows_all_time'] ?? 0)) }}</div>
            </div>
            <div class="ds-status-card" data-tour="at-agent-dashboard-listing-stock">
                <div class="ds-label">Listing stock</div>
                <div class="mt-2 grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs" style="color: var(--text-muted);">Active</div>
                        <div class="ds-value-lg"><a class="ds-link" href="{{ route('agent.listings', ['filter' => 'active']) }}">{{ number_format((int)($listingStats['total'] ?? 0)) }}</a></div>
                    </div>
                    <div>
                        <div class="text-xs" style="color: var(--text-muted);">Avg DOM</div>
                        <div class="ds-value-lg"><a class="ds-link" href="{{ route('agent.listings', ['filter' => 'dom']) }}">{{ number_format((int)($listingStats['avg_days_on_market'] ?? 0)) }}</a></div>
                    </div>
                    <div>
                        <div class="text-xs" style="color: var(--text-muted);">Stale (14d)</div>
                        <div class="ds-value-lg"><a class="ds-link" href="{{ route('agent.listings', ['filter' => 'stale']) }}">{{ number_format((int)($listingStats['stale'] ?? 0)) }}</a></div>
                    </div>
                    <div>
                        <div class="text-xs" style="color: var(--text-muted);">Expiring (14d)</div>
                        <div class="ds-value-lg"><a class="ds-link" href="{{ route('agent.listings', ['filter' => 'expiring']) }}">{{ number_format((int)($listingStats['expiring_soon'] ?? 0)) }}</a></div>
                    </div>
                </div>
                <div class="mt-2 text-xs" style="color: var(--text-muted);">Expired: <a class="ds-link" href="{{ route('agent.listings', ['filter' => 'expired']) }}">{{ number_format((int)($listingStats['expired'] ?? 0)) }}</a></div>
            </div>
        </div>
    </div>

    {{-- YOU vs BRANCH vs COMPANY --}}
    <div class="ds-status-card" data-tour="at-agent-dashboard-comparison">
        <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
            <h3 class="ds-section-header">You vs Branch vs Company</h3>
            <div class="text-xs" style="color: var(--text-muted);">Totals only (privacy safe)</div>
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
                if ($pct === null) return 'ds-bar-navy';
                if ($pct >= 80) return 'ds-bar-navy';
                return 'ds-bar-amber';
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
            <div class="ds-status-card">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="ds-label">You</div>
                        <div class="mt-1 ds-value-lg leading-tight">
                            {{ number_format($youPointsA, 1) }}
                            <span style="color: var(--text-muted);" class="font-bold">/ {{ number_format($youPointsT, 0) }}</span>
                            @if($youPointsT > 0 && $youPointsA >= $youPointsT) <span title="Target achieved">🏆</span> @endif
                        </div>
                        <div class="text-sm font-semibold" style="color: var(--text-secondary);">Points</div>
                    </div>

                    <div class="text-right">
                        <div class="ds-label">Status</div>
                        <div class="mt-1 ds-value-lg">
                            {{ $pointsStatus ?? '—' }}
                        </div>
                    </div>
                </div>

                <div class="mt-3 ds-label">Points progress</div>
                <div class="mt-2 ds-progress-track">
                    <div class="ds-progress-bar {{ $barClass($youPointsPct) }}" style="width: {{ (int)($youPointsPct ?? 0) }}%"></div>
                </div>

                <div class="mt-4">
                    <div class="text-xs" style="color: var(--text-muted);">Value</div>
                    <div class="ds-value-lg">
                        R {{ number_format($youValueA,0) }}
                    </div>
                    <div class="text-xs" style="color: var(--text-muted);">
                        / R {{ number_format($youValueT,0) }}
                        @if($youValueT > 0 && $youValueA >= $youValueT) <span title="Target achieved">🏆</span> @endif
                    </div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $barClass($youValuePct) }}" style="width: {{ (int)($youValuePct ?? 0) }}%"></div>
                    </div>
                </div>

                <div class="mt-4 text-xs" style="color: var(--text-secondary);">
                    Daily activity entries: <span class="font-bold" style="color: var(--text-primary);">{{ number_format((int)($a['daily_rows'] ?? 0)) }}</span>
                </div>
            </div>

            {{-- BRANCH --}}
            <div class="ds-status-card">
                <div class="ds-label">Your Branch</div>

                @if($cmpBranch)
                    <div class="mt-4 ds-label">Value progress</div>
                    <div class="mt-1 ds-value-lg">
                        R {{ number_format($bValueA,0) }}
                        <span style="color: var(--text-muted);" class="font-bold">/ R {{ number_format($bValueT,0) }}</span>
                        @if($bValueT > 0 && $bValueA >= $bValueT) <span title="Target achieved">🏆</span> @endif
                    </div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $barClass($bValuePct) }}" style="width: {{ (int)($bValuePct ?? 0) }}%"></div>
                    </div>

                    <div class="mt-4 text-xs" style="color: var(--text-secondary);">
                        Daily activity entries: <span class="font-bold" style="color: var(--text-primary);">{{ number_format((int)($cmpBranch['actuals']['daily_rows'] ?? 0)) }}</span>
                    </div>
                @else
                    <div class="mt-2 text-sm" style="color: var(--text-secondary);">No branch assigned.</div>
                    <div class="mt-3 ds-progress-track">
                        <div class="ds-progress-bar ds-bar-navy" style="width: 10%"></div>
                    </div>
                @endif
            </div>

            {{-- COMPANY --}}
            <div class="ds-status-card">
                <div class="ds-label">Company</div>

                @if($cmpCompany)
                    <div class="mt-4 ds-label">Value progress</div>
                    <div class="mt-1 ds-value-lg">
                        R {{ number_format($cValueA,0) }}
                        <span style="color: var(--text-muted);" class="font-bold">/ R {{ number_format($cValueT,0) }}</span>
                        @if($cValueT > 0 && $cValueA >= $cValueT) <span title="Target achieved">🏆</span> @endif
                    </div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $barClass($cValuePct) }}" style="width: {{ (int)($cValuePct ?? 0) }}%"></div>
                    </div>

                    <div class="mt-4 text-xs" style="color: var(--text-secondary);">
                        Daily activity entries: <span class="font-bold" style="color: var(--text-primary);">{{ number_format((int)($cmpCompany['actuals']['daily_rows'] ?? 0)) }}</span>
                    </div>
                @else
                    <div class="mt-2 text-sm" style="color: var(--text-secondary);">Not available.</div>
                    <div class="mt-3 ds-progress-track">
                        <div class="ds-progress-bar ds-bar-navy" style="width: 10%"></div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- MOMENTUM --}}
    <div class="ds-status-card">
        <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
            <h3 class="ds-section-header">Momentum — last 7 days</h3>
            <div class="text-xs" style="color: var(--text-muted);">Points per day + streak</div>
        </div>

        @php
            $pointsByDate = $snapshot['points_by_date'] ?? [];

            $today = now()->startOfDay();

            $last7 = [];
            for ($i = 6; $i >= 0; $i--) {
                $last7[] = $today->copy()->subDays($i);
            }

            $maxPts = 1;
            foreach ($last7 as $dt) {
                $d = $dt->toDateString();
                $pts = (float)($pointsByDate[$d] ?? 0);
                if ($pts > $maxPts) $maxPts = $pts;
            }

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
                    $pts = (float)($pointsByDate[$d] ?? 0);
                    $h = (int)round(($pts / $maxPts) * 64);
                @endphp
                <div class="ds-status-card p-2 text-center">
                    <div class="text-xs font-semibold" style="color: var(--text-muted);">{{ $dt->format('D') }}</div>
                    <div class="mt-2 h-16 flex items-end justify-center">
                        <div class="w-6 rounded-md" style="height: {{ $h }}px; background: var(--brand-default, #0b2a4a);"></div>
                    </div>
                    <div class="mt-2 text-xs font-bold" style="color: var(--text-primary);">{{ number_format($pts, 1) }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 flex items-center justify-between flex-wrap gap-2">
            <div class="text-sm" style="color: var(--text-secondary);">
                Streak: <span class="font-bold" style="color: var(--text-primary);">{{ number_format($streak) }}</span> day{{ $streak === 1 ? '' : 's' }}
            </div>
            <div class="text-sm" style="color: var(--text-secondary);">
                Today aim: <span class="font-bold" style="color: var(--text-primary);">{{ number_format(max(10, round($pointsPerDayNeeded, 0)), 0) }}</span> pts
            </div>
        </div>
    </div>

    <div class="text-xs" style="color: var(--text-muted);">
        Privacy: Agent view shows only derived targets + actuals. No worksheet net-income fields are exposed to anyone else.
    </div>

</div>
@endsection
