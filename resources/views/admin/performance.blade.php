@php
    $r = $rollup ?? [];

    // MONEY (ex VAT) — Company ledger vs Team
    $money = $r['totals']['actuals'] ?? [];
    $ledgerCompanyIncome   = (float)($money['ledger_company_income'] ?? 0);
    $ledgerAgentIncome     = (float)($money['ledger_agent_income'] ?? 0);
    $ledgerCompanyRetained = (float)($money['ledger_company_retained'] ?? max(0, $ledgerCompanyIncome - $ledgerAgentIncome));

    $teamCompanyIncome   = (float)($money['team_company_income'] ?? 0);
    $teamAgentIncome     = (float)($money['team_agent_income'] ?? 0);
    $teamCompanyRetained = (float)($money['team_company_retained'] ?? 0);

    // Points / pace (company aggregated)
    $pts = $r['points'] ?? ['actual'=>0,'target'=>0,'pct'=>0,'status'=>'—','remaining'=>0,'per_day_needed'=>0,'today_points'=>0,'days_left'=>0];
    $pointsActual = (float)($pts['actual'] ?? 0);
    $pointsTarget = (float)($pts['target'] ?? 0);
    $pointsPct = (float)($pts['pct'] ?? 0);
    $pointsStatus = (string)($pts['status'] ?? '—');
    $pointsRemaining = (float)($pts['remaining'] ?? 0);
    $pointsPerDayNeeded = (float)($pts['per_day_needed'] ?? 0);
    $todayPoints = (float)($pts['today_points'] ?? 0);

    // Company targets/actuals (agent target sum -> progress)
    $companyValueTarget_agentsum = (float)($r['totals']['targets']['value'] ?? 0);
    $companyDealsTarget_agentsum = (int)($r['totals']['targets']['deals'] ?? 0);

    $companyValueActual = (float)($r['totals']['actuals']['value'] ?? $r['totals']['actuals']['sales_value'] ?? 0);
    $companyDealsActual_rollup = (int)($r['totals']['actuals']['deals'] ?? $r['totals']['actuals']['deals_count'] ?? 0);

    // ADMIN PERFORMANCE: deal counts must be DISTINCT deals (not per-agent rows).
    // Use statusSummary period counts (Pending + Granted + Registered). Declined excluded.
    $companyDealsActual_distinct = (int)($statusSummary['pending_period'] ?? 0)
                                + (int)($statusSummary['granted_period'] ?? 0)
                                + (int)($statusSummary['registered_period'] ?? 0);

    $companyDealsActual = $companyDealsActual_distinct > 0 ? $companyDealsActual_distinct : $companyDealsActual_rollup;

    $valuePct = $companyValueTarget_agentsum > 0 ? (($companyValueActual / $companyValueTarget_agentsum) * 100) : 0;
    $dealsPct = $companyDealsTarget_agentsum > 0 ? (($companyDealsActual / $companyDealsTarget_agentsum) * 100) : 0;

    $valueBar = $valuePct >= 80 ? 'ds-bar-navy' : ($valuePct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
    $dealsBar = $dealsPct >= 80 ? 'ds-bar-navy' : ($dealsPct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');

    $statusSummary = $statusSummary ?? [];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">
                        Company Dashboard — {{ $r['period'] ?? now()->format('Y-m') }}
                    </h2>
                    <div class="text-sm text-white/60">Admin view</div>
                </div>
                <div class="flex items-center gap-2">
                    <form method="GET" action="{{ route('admin.performance') }}" class="flex items-center gap-2">
                        <input type="month" name="period" value="{{ $r['period'] ?? now()->format('Y-m') }}" class="h-8 text-sm rounded border border-white/20 bg-white/10 text-white px-2" />
                        <button type="submit" class="px-3 py-1.5 text-sm font-semibold rounded bg-white/20 text-white hover:bg-white/30">Go</button>
                    </form>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-8">

        {{-- STATUS TILES --}}
        <div class="space-y-3">
            <h2 class="ds-section-header">Deal Status</h2>
            {{-- Set 1: Period (counts) --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

                <a href="/admin/deals?status=Declined&period={{ $r['period'] ?? now()->format('Y-m') }}" class="block">
                    <div class="ds-status-card ds-status-declined">
                        <div class="ds-label mb-2">Declined</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="ds-label" style="font-size:0.6875rem">Period</div>
                                <div class="ds-value-lg">{{ $statusSummary['declined_period'] ?? 0 }}</div>
                            </div>
                            <div class="text-right">
                                <div class="ds-label" style="font-size:0.6875rem">All time</div>
                                <div class="ds-value-lg" style="opacity:0.4">—</div>
                            </div>
                        </div>
                    </div>
                </a>

                <a href="/admin/deals?status=Pending&period={{ $r['period'] ?? now()->format('Y-m') }}" class="block">
                    <div class="ds-status-card ds-status-pending">
                        <div class="ds-label mb-2">Pending</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="ds-label" style="font-size:0.6875rem">Period</div>
                                <div class="ds-value-lg">{{ $statusSummary['pending_period'] ?? 0 }}</div>
                            </div>
                            <div class="text-right">
                                <div class="ds-label" style="font-size:0.6875rem">All time</div>
                                <div class="ds-value-lg">{{ $statusSummary['pending_total'] ?? 0 }}</div>
                            </div>
                        </div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&period={{ $r['period'] ?? now()->format('Y-m') }}" class="block">
                    <div class="ds-status-card ds-status-granted">
                        <div class="ds-label mb-2">Granted</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="ds-label" style="font-size:0.6875rem">Period</div>
                                <div class="ds-value-lg">{{ $statusSummary['granted_period'] ?? 0 }}</div>
                            </div>
                            <div class="text-right">
                                <div class="ds-label" style="font-size:0.6875rem">All time</div>
                                <div class="ds-value-lg">{{ $statusSummary['granted_total'] ?? 0 }}</div>
                            </div>
                        </div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&period={{ $r['period'] ?? now()->format('Y-m') }}" class="block">
                    <div class="ds-status-card ds-status-registered">
                        <div class="ds-label mb-2">Registered</div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="ds-label" style="font-size:0.6875rem">Period</div>
                                <div class="ds-value-lg">{{ $statusSummary['registered_period'] ?? 0 }}</div>
                            </div>
                            <div class="text-right">
                                <div class="ds-label" style="font-size:0.6875rem">All time</div>
                                <div class="ds-value-lg" style="opacity:0.4">—</div>
                            </div>
                        </div>
                    </div>
                </a>

            </div>

            <h2 class="ds-section-header">Outstanding Commission</h2>
            {{-- Set 2: Outstanding (Not Paid) — Company ex VAT --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="/admin/deals?status=Pending&commission_status=Not%20Paid" class="block">
                    <div class="ds-status-card ds-money-pending">
                        <div class="ds-label">Pending (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl mt-1" style="color:#0b2a4a">
                            R {{ number_format((float)($statusSummary['pending_unpaid_company_ex_vat'] ?? 0), 0) }}
                        </div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&commission_status=Not%20Paid" class="block">
                    <div class="ds-status-card ds-money-granted">
                        <div class="ds-label">Granted (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl mt-1" style="color:#0b2a4a">
                            R {{ number_format((float)($statusSummary['granted_unpaid_company_ex_vat'] ?? 0), 0) }}
                        </div>
                        <div class="mt-1 ds-label" style="text-transform:none;font-size:0.75rem">
                            Paid this period: R {{ number_format((float)($statusSummary['granted_paid_company_ex_vat_period'] ?? 0), 0) }}
                        </div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&commission_status=Not%20Paid" class="block">
                    <div class="ds-status-card ds-money-registered">
                        <div class="ds-label">Registered (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl mt-1" style="color:#0b2a4a">
                            R {{ number_format((float)($statusSummary['registered_unpaid_company_ex_vat'] ?? 0), 0) }}
                        </div>
                        <div class="mt-1 ds-label" style="text-transform:none;font-size:0.75rem">
                            Paid this period: R {{ number_format((float)($statusSummary['registered_paid_company_ex_vat_period'] ?? 0), 0) }}
                        </div>
                    </div>
                </a>
            </div>
        </div>

        @if(session("status"))
            <div class="bg-green-500/10 border border-green-500/20 text-green-200 rounded-xl p-3 text-sm">
                {{ session("status") }}
            </div>
        @endif

        @if(isset($errors) && $errors->any())
            <div class="bg-red-500/10 border border-red-500/20 text-red-200 rounded-xl p-3 text-sm">
                {{ implode(", ", $errors->all()) }}
            </div>
        @endif

        {{-- Company Listing Stock (Propcon) --}}
        <div>
            <div class="ds-section-header">Listing Stock (Company)</div>
            <div class="ds-section-sub mb-4">
                <a href="{{ route('admin.listings.stock') }}" class="ds-link text-sm hover:underline">View all</a>
            </div>

            <div class="ds-status-card">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <a href="{{ route('admin.listings.stock', ['filter' => 'active']) }}" class="ds-status-card hover:shadow-md transition block">
                        <div class="ds-label">Active</div>
                        <div class="ds-value-lg mt-1">{{ (int)($listingStats['total'] ?? 0) }}</div>
                    </a>

                    <a href="{{ route('admin.listings.stock', ['filter' => 'dom']) }}" class="ds-status-card hover:shadow-md transition block">
                        <div class="ds-label">Avg DOM</div>
                        <div class="ds-value-lg mt-1">{{ (int)($listingStats['avg_days_on_market'] ?? 0) }}</div>
                    </a>

                    <a href="{{ route('admin.listings.stock', ['filter' => 'stale']) }}" class="ds-status-card hover:shadow-md transition block">
                        <div class="ds-label">Stale</div>
                        <div class="ds-value-lg mt-1">{{ (int)($listingStats['stale'] ?? 0) }}</div>
                    </a>

                    <a href="{{ route('admin.listings.stock', ['filter' => 'expiring']) }}" class="ds-status-card hover:shadow-md transition block">
                        <div class="ds-label">Expiring</div>
                        <div class="ds-value-lg mt-1">{{ (int)($listingStats['expiring_soon'] ?? 0) }}</div>
                    </a>

                    <a href="{{ route('admin.listings.stock', ['filter' => 'expired']) }}" class="ds-status-card hover:shadow-md transition block">
                        <div class="ds-label">Expired</div>
                        <div class="ds-value-lg mt-1">{{ (int)($listingStats['expired'] ?? 0) }}</div>
                    </a>
                </div>
            </div>
        </div>


        {{-- HERO: Money (company) --}}
        <div>
            <div class="ds-section-header">Company Focus — Money</div>
            <div class="ds-section-sub mb-4">
                Value is priority. Targets below are based on what agents planned for the month (agent target sum).
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="ds-status-card">
                    <div class="ds-label mb-1">Company Value (Actual / Agent-Sum Target)</div>
                    <div class="ds-value-xl">
                        R {{ number_format($companyValueActual, 0) }}
                        <span style="color:#94a3b8;font-weight:600">/ R {{ number_format($companyValueTarget_agentsum, 0) }}</span>
                    </div>
                    <div class="ds-progress-track mt-3">
                        <div class="ds-progress-bar {{ $valueBar }}" style="width: {{ min(100, max(0, $valuePct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-sm ds-value">Progress {{ number_format($valuePct, 1) }}%</div>
                </div>

                <div class="ds-status-card">
                    <div class="ds-label mb-1">Company Deals (Actual / Agent-Sum Target)</div>
                    <div class="ds-value-xl">
                        {{ (int)$companyDealsActual }}
                        <span style="color:#94a3b8;font-weight:600">/ {{ (int)$companyDealsTarget_agentsum }}</span>
                    </div>
                    <div class="ds-progress-track mt-3">
                        <div class="ds-progress-bar {{ $dealsBar }}" style="width: {{ min(100, max(0, $dealsPct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-sm ds-value">Progress {{ number_format($dealsPct, 1) }}%</div>
                </div>
            </div>
        </div>

        {{-- EXTRA: Branch progress tiles (value + points) --}}
        <div>
            <div class="ds-section-header">Branches — Progress</div>
            <div class="ds-section-sub mb-4">Value and points progress per branch for the selected period.</div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach(($r['branches'] ?? []) as $b)
                    @php
                        $bid = (int)($b['branch_id'] ?? 0);
                        $bName = (string)($b['branch_name'] ?? 'Branch');

                        $bTotals = $b['totals'] ?? ['actuals'=>[], 'targets'=>[]];
                        $bA = $b['actuals'] ?? ($bTotals['actuals'] ?? []);
                        $bT = $b['targets'] ?? ($bTotals['targets'] ?? []);

                        $bValueTarget = (float)($bT['value'] ?? 0);
                        $bValueActual = (float)($bA['value'] ?? $bA['sales_value'] ?? 0);
                        $bValuePct = $bValueTarget > 0 ? (($bValueActual / $bValueTarget) * 100) : 0;
                        $bValueBar  = $bValuePct >= 80 ? 'ds-bar-navy' : ($bValuePct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');

                        $bPointsTarget = (float)($bT['points'] ?? 0);
                        $bPointsActual = (float)($bA['points'] ?? 0);
                        $bPointsPct = $bPointsTarget > 0 ? (($bPointsActual / $bPointsTarget) * 100) : 0;
                        $bPointsBar  = $bPointsPct >= 80 ? 'ds-bar-navy' : ($bPointsPct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
                    @endphp

                    <a href="{{ route('admin.branch.performance', ['branchId' => $bid, 'period' => ($r['period'] ?? now()->format('Y-m'))]) }}"
                       class="ds-status-card block hover:shadow-lg transition">
                        <div class="ds-label">BRANCH</div>
                        <div class="text-lg font-bold" style="color:#0b2a4a">{{ $bName }}</div>

                        <div class="mt-4">
                            <div class="ds-label mb-1">Value</div>
                            <div class="ds-progress-track">
                                <div class="ds-progress-bar {{ $bValueBar }}" style="width: {{ min(100, max(0, $bValuePct)) }}%"></div>
                            </div>
                            <div class="mt-1 text-xs" style="color:#64748b">
                                R {{ number_format($bValueActual,0) }} / R {{ number_format($bValueTarget,0) }} ({{ number_format($bValuePct,1) }}%)
                            </div>
                        </div>

                        <div class="mt-4">
                            <div class="ds-label mb-1">Points</div>
                            <div class="ds-progress-track">
                                <div class="ds-progress-bar {{ $bPointsBar }}" style="width: {{ min(100, max(0, $bPointsPct)) }}%"></div>
                            </div>
                            <div class="mt-1 text-xs" style="color:#64748b">
                                {{ number_format($bPointsActual,0) }} / {{ number_format($bPointsTarget,0) }} ({{ number_format($bPointsPct,1) }}%)
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Excel-style Agents table (company) --}}
        <div>
            <div class="ds-section-header">Agents — Targets vs Actuals</div>
            <div class="ds-section-sub mb-4">This is the management view: who is on pace, who is behind, and where to intervene.</div>

            <div class="ds-status-card overflow-hidden" style="padding:0">
                <div class="overflow-x-auto">
                    <table class="ds-table min-w-full text-sm">
                        <thead>
                            <tr>
                                <th class="text-left px-4 py-3">Agent</th>
                                <th class="text-right px-4 py-3">Deals (A/T)</th>
                                <th class="text-right px-4 py-3">Sales Value (A/T)</th>
                                <th class="text-right px-4 py-3">Points (A/T)</th>
                                <th class="text-right px-4 py-3">Company Retained</th>
                                <th class="text-right px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="background:#f8fafc">
                                <td class="px-4 py-3 font-extrabold" style="color:#0b2a4a">COMPANY TOTAL</td>
                                <td class="px-4 py-3 text-right font-bold ds-value">
                                    {{ (int)($companyDealsActual ?? 0) }} / {{ (int)($r['totals']['targets']['deals'] ?? 0) }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold ds-value">
                                    R {{ number_format((float)($r['totals']['actuals']['value'] ?? $r['totals']['actuals']['sales_value'] ?? 0), 0) }}
                                    / R {{ number_format((float)($r['totals']['targets']['value'] ?? 0), 0) }}
                                </td>
                                <td class="px-4 py-3 text-right font-bold ds-value">
                                    {{ number_format((float)($r['totals']['actuals']['points'] ?? 0), 0) }}
                                    / {{ number_format((float)($r['totals']['targets']['points'] ?? 0), 0) }}
                                </td>
                                <td class="px-4 py-3 text-right font-extrabold ds-value">
                                    R {{ number_format((float)($r['totals']['actuals']['team_company_retained'] ?? 0), 0) }}
                                    <div class="text-[11px]" style="color:#94a3b8;font-weight:600">
                                        Ledger: R {{ number_format((float)($r['totals']['actuals']['ledger_company_retained'] ?? 0), 0) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-bold" style="color:#94a3b8">—</td>
                            </tr>

                            @foreach(($r['rows'] ?? []) as $row)
                                @php
                                    $pointsTargetRow = (float)($row['targets']['points'] ?? 0);
                                    $pointsActualRow = (float)($row['actuals']['points'] ?? 0);
                                    $pct = ($pointsTargetRow > 0) ? round(($pointsActualRow/$pointsTargetRow)*100, 1) : 0;
                                    $status = (string)($row['progress']['points_status'] ?? '—');

                                    $badgeClass = 'ds-badge-default';
                                    if (in_array($status, ['Achieved'])) $badgeClass = 'ds-badge-achieved';
                                    elseif (in_array($status, ['Ahead'])) $badgeClass = 'ds-badge-ahead';
                                    elseif (in_array($status, ['On pace'])) $badgeClass = 'ds-badge-ontrack';
                                    elseif ($status === 'Behind') $badgeClass = 'ds-badge-behind';

                                    $rowBar = $pct >= 80 ? 'ds-bar-navy' : ($pct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');

                                    $retained = (float)($row['actuals']['company_retained'] ?? 0);
                                    $agentIncome = (float)($row['actuals']['agent_income'] ?? 0);
                                    $valueActualRow = (float)($row['actuals']['value'] ?? $row['actuals']['sales_value'] ?? 0);
                                    $valueTargetRow = (float)($row['targets']['value'] ?? 0);
                                @endphp

                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold">
                                            <a class="ds-agent-link"
                                               href="{{ route('admin.agent.performance', ['userId' => $row['user_id'], 'period' => ($r['period'] ?? now()->format('Y-m'))]) }}">
                                                {{ $row['name'] }}
                                            </a>
                                        </div>
                                        <div class="text-xs" style="color:#94a3b8">
                                            Per-day needed: {{ number_format((float)($row['progress']['points_per_day_needed'] ?? 0), 1) }}
                                        </div>
                                    </td>

                                    <td class="px-4 py-3 text-right font-semibold ds-value">
                                        {{ (int)($row['actuals']['deals'] ?? 0) }} / {{ (int)($row['targets']['deals'] ?? 0) }}
                                    </td>

                                    <td class="px-4 py-3 text-right font-semibold ds-value">
                                        R {{ number_format($valueActualRow, 0) }} / R {{ number_format($valueTargetRow, 0) }}
                                    </td>

                                    <td class="px-4 py-3 text-right font-semibold ds-value">
                                        {{ number_format($pointsActualRow, 0) }} / {{ number_format($pointsTargetRow, 0) }}
                                        <div class="ds-progress-track mt-1">
                                            <div class="ds-progress-bar {{ $rowBar }}"
                                                 style="width: {{ min(100, max(0, $pct)) }}%"></div>
                                        </div>
                                    </td>

                                    <td class="px-4 py-3 text-right font-extrabold ds-value">
                                        R {{ number_format($retained, 0) }}
                                        <div class="text-[11px]" style="color:#94a3b8;font-weight:600">
                                            Agent: R {{ number_format($agentIncome, 0) }}
                                        </div>
                                    </td>

                                    <td class="px-4 py-3 text-right">
                                        <span class="ds-badge {{ $badgeClass }}">{{ $status }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ── TV ACCESS CODES ── --}}
        <div class="space-y-3">
            <h3 class="ds-section-header">TV Access Codes</h3>

            {{-- Company TV Code --}}
            <div class="ds-status-card p-5">
                <div class="font-bold text-sm mb-3" style="color:#0b2a4a">Company TV Display</div>
                @if(isset($companyTvCode) && $companyTvCode)
                    <div class="flex items-center gap-4 mb-3">
                        <span class="font-mono text-2xl font-black tracking-[0.3em]" style="color:#0b2a4a">{{ $companyTvCode->code }}</span>
                        <div class="text-xs text-gray-500 leading-snug">
                            <div>Generated {{ $companyTvCode->created_at->diffForHumans() }} by {{ $companyTvCode->creator->name ?? '—' }}</div>
                            @if($companyTvCode->last_used_at)
                                <div class="text-green-600">Last used {{ $companyTvCode->last_used_at->diffForHumans() }}</div>
                            @else
                                <div>Never used</div>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mb-2">
                        <div class="text-xs text-gray-400">
                            URL: <span class="font-mono text-gray-300">{{ route('tv.display', ['code' => $companyTvCode->code]) }}</span>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('admin.tv-code.generate-company') }}">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 rounded bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                                Regenerate Code
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.tv-code.revoke-company') }}">
                            @csrf
                            <button type="submit" class="px-2 py-1.5 rounded bg-red-700 text-white text-sm font-semibold hover:bg-red-800"
                                    onclick="return confirm('Revoke company TV code?')">
                                Revoke
                            </button>
                        </form>
                    </div>
                @else
                    <div class="text-sm text-gray-400 mb-3">No active company TV code.</div>
                    <form method="POST" action="{{ route('admin.tv-code.generate-company') }}">
                        @csrf
                        <button type="submit" class="px-3 py-1.5 rounded bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                            Generate Company TV Code
                        </button>
                    </form>
                @endif
            </div>

            {{-- Branch TV Codes --}}
            <div class="ds-status-card p-5">
                @if(isset($tvCodes) && $tvCodes->count())
                    <div class="overflow-x-auto">
                        <table class="ds-table w-full text-sm">
                            <thead>
                                <tr class="ds-table-header">
                                    <th class="px-4 py-2 text-left">Branch</th>
                                    <th class="px-4 py-2 text-left">Code</th>
                                    <th class="px-4 py-2 text-left">Generated by</th>
                                    <th class="px-4 py-2 text-left">Created</th>
                                    <th class="px-4 py-2 text-left">Last used</th>
                                    <th class="px-4 py-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($tvCodes as $tc)
                                    <tr class="{{ $loop->even ? 'bg-slate-800/30' : '' }}">
                                        <td class="px-4 py-2 font-semibold" style="color:#0b2a4a">{{ $tc->branch->name ?? 'Unknown' }}</td>
                                        <td class="px-4 py-2">
                                            <span class="font-mono text-lg font-black tracking-widest" style="color:#0b2a4a">{{ $tc->code }}</span>
                                        </td>
                                        <td class="px-4 py-2 ds-label">{{ $tc->creator->name ?? '—' }}</td>
                                        <td class="px-4 py-2 ds-label">{{ $tc->created_at->format('d M Y H:i') }}</td>
                                        <td class="px-4 py-2 ds-label">
                                            @if($tc->last_used_at)
                                                <span class="text-green-600">{{ $tc->last_used_at->diffForHumans() }}</span>
                                            @else
                                                Never
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-right">
                                            <form method="POST" action="{{ route('admin.tv-code.revoke') }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="code_id" value="{{ $tc->id }}">
                                                <button type="submit" class="px-2 py-1 rounded bg-red-700 text-white text-xs font-semibold hover:bg-red-800"
                                                        onclick="return confirm('Revoke this code?')">
                                                    Revoke
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-sm text-gray-400">No active TV codes.</div>
                @endif

                {{-- Generate for any branch --}}
                <div class="mt-4 flex items-center gap-3">
                    <form method="POST" action="{{ route('admin.tv-code.generate') }}" class="flex items-center gap-2">
                        @csrf
                        <select name="branch_id" class="border rounded px-3 py-1.5 text-sm">
                            <option value="">Select branch...</option>
                            @foreach($branches ?? [] as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="px-3 py-1.5 rounded bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                            Generate Code
                        </button>
                    </form>
                    <div class="text-xs text-gray-400">
                        TV entry: <span class="font-mono">{{ url('/tv') }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-xs text-gray-500">
            Privacy: This page shows derived targets + activity + deal actuals. No worksheet net-income fields are exposed.
        </div>
    </div>
</x-app-layout>
