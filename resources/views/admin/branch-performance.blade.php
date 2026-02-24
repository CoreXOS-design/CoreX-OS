@php
    $r = $rollup;

    // MONEY (ex VAT) — Branch ledger vs Team (agents-in-branch regardless of deal.branch_id)
    $money = $r['totals']['actuals'] ?? [];
    $ledgerCompanyIncome   = (float)($money['ledger_company_income'] ?? 0);
    $ledgerAgentIncome     = (float)($money['ledger_agent_income'] ?? 0);
    $ledgerCompanyRetained = (float)($money['ledger_company_retained'] ?? max(0, $ledgerCompanyIncome - $ledgerAgentIncome));

    $teamCompanyIncome   = (float)($money['team_company_income'] ?? 0);
    $teamAgentIncome     = (float)($money['team_agent_income'] ?? 0);
    $teamCompanyRetained = (float)($money['team_company_retained'] ?? 0);

    $pts = $r['points'] ?? ['actual'=>0,'target'=>0,'pct'=>0,'status'=>'—','remaining'=>0,'per_day_needed'=>0,'today_points'=>0,'days_left'=>0];
    $m7  = $r['momentum_7d'] ?? [];
    $today = $r['activities_today'] ?? [];

    $statusSummary = $statusSummary ?? [];

    $pointsActual = (float)($pts['actual'] ?? 0);
    $pointsTarget = (float)($pts['target'] ?? 0);
    $pointsPct = (float)($pts['pct'] ?? 0);
    $pointsStatus = (string)($pts['status'] ?? '—');
    $pointsPerDayNeeded = (float)($pts['per_day_needed'] ?? 0);
    $todayPoints = (float)($pts['today_points'] ?? 0);

    $pointsBarClass = $pointsPct >= 80 ? 'ds-bar-navy' : ($pointsPct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');

    // Branch value/deals progress (agent-sum targets)
    $branchValueTarget = (float)($r['totals']['targets']['value'] ?? 0);
    $branchDealsTarget = (int)($r['totals']['targets']['deals'] ?? 0);

    $branchValueActual = (float)($r['totals']['actuals']['value'] ?? $r['totals']['actuals']['sales_value'] ?? 0);
    $branchDealsActual = (int)($r['totals']['actuals']['deals'] ?? 0);

    $valuePct = $branchValueTarget > 0 ? (($branchValueActual / $branchValueTarget) * 100) : 0;
    $dealsPct = $branchDealsTarget > 0 ? (($branchDealsActual / $branchDealsTarget) * 100) : 0;

    $valueBar = $valuePct >= 80 ? 'ds-bar-navy' : ($valuePct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
    $dealsBar = $dealsPct >= 80 ? 'ds-bar-navy' : ($dealsPct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">
                        Branch Performance — {{ $branchName ?? 'Branch' }}
                    </h2>
                    <div class="text-sm text-white/60">Admin view — {{ $r['period'] }}</div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.performance', ['period' => $r['period']]) }}" class="px-3 py-1.5 text-sm font-semibold rounded border border-white/30 text-white hover:bg-white/10">Back</a>
                    <form method="GET" action="{{ route('admin.branch.performance', ['branchId' => (int)($r['branch_id'] ?? 0)]) }}" class="flex items-center gap-2">
                        <input type="month" name="period" value="{{ $r['period'] }}" class="h-8 text-sm rounded border border-white/20 bg-white/10 text-white px-2" />
                        <button type="submit" class="px-3 py-1.5 text-sm font-semibold rounded bg-white/20 text-white hover:bg-white/30">Go</button>
                    </form>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">


        <!-- ADMIN_BRANCH_STATUS_TILES -->
        <div class="space-y-3">
            <h2 class="ds-section-header">Deal Status</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="/admin/deals?status=Declined&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-declined">
                        <div class="ds-label">Declined (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['declined_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Pending&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-pending">
                        <div class="ds-label">Pending (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['pending_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-granted">
                        <div class="ds-label">Granted (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['granted_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-registered">
                        <div class="ds-label">Registered (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['registered_period'] ?? 0 }}</div>
                    </div>
                </a>
            </div>

            <h2 class="ds-section-header">Outstanding Commission</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="/admin/deals?status=Pending&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-money-pending">
                        <div class="ds-label">Pending (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format((float)($statusSummary['pending_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-money-granted">
                        <div class="ds-label">Granted (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format((float)($statusSummary['granted_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-money-registered">
                        <div class="ds-label">Registered (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format((float)($statusSummary['registered_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>
            </div>
        </div>




        <!-- ADMIN_BRANCH_STATUS_TILES -->
        <div class="space-y-3">
            <h2 class="ds-section-header">Deal Status</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="/admin/deals?status=Declined&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-declined">
                        <div class="ds-label">Declined (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['declined_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Pending&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-pending">
                        <div class="ds-label">Pending (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['pending_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-granted">
                        <div class="ds-label">Granted (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['granted_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-status-registered">
                        <div class="ds-label">Registered (period)</div>
                        <div class="ds-value-xl">{{ $statusSummary['registered_period'] ?? 0 }}</div>
                    </div>
                </a>
            </div>

            <h2 class="ds-section-header">Outstanding Commission</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="/admin/deals?status=Pending&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-money-pending">
                        <div class="ds-label">Pending (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format((float)($statusSummary['pending_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-money-granted">
                        <div class="ds-label">Granted (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format((float)($statusSummary['granted_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="ds-status-card ds-money-registered">
                        <div class="ds-label">Registered (Not Paid) — Company ex VAT</div>
                        <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format((float)($statusSummary['registered_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>
            </div>
        </div>



        {{-- Value + Deals --}}
        <div class="ds-section-header">Branch focus — Value & Deals</div>
        <div class="ds-section-sub">
            Targets are based on what agents planned for the month (agent target sum).
        </div>
        <div class="ds-status-card">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label">Branch Value (Actual / Agent-Sum Target)</div>
                    <div class="ds-value-lg leading-tight">
                        R {{ number_format($branchValueActual, 0) }}
                        <span class="text-gray-400 font-bold">/ R {{ number_format($branchValueTarget, 0) }}</span>
                    </div>
                    <div class="ds-progress-track mt-2">
                        <div class="ds-progress-bar {{ $valueBar }}" style="width: {{ min(100, max(0, $valuePct)) }}%"></div>
                    </div>
                    <div class="mt-2 ds-label">Progress {{ number_format($valuePct, 1) }}%</div>
                </div>

                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label">Deals (Actual / Agent-Sum Target)</div>
                    <div class="ds-value-lg leading-tight">
                        {{ number_format($branchDealsActual, 0) }}
                        <span class="text-gray-400 font-bold">/ {{ number_format($branchDealsTarget, 0) }}</span>
                    </div>
                    <div class="ds-progress-track mt-2">
                        <div class="ds-progress-bar {{ $dealsBar }}" style="width: {{ min(100, max(0, $dealsPct)) }}%"></div>
                    </div>
                    <div class="mt-2 ds-label">Progress {{ number_format($dealsPct, 1) }}%</div>
                </div>
            </div>
        </div>

        {{-- Money + Pace --}}
        <div class="ds-section-header">Branch focus — Money</div>
        <div class="ds-section-sub">
            <span class="font-semibold">Team retained</span> = what your branch agents produced (BM reality).
            <span class="font-semibold">Ledger retained</span> = deals recorded against this branch.
        </div>
        <div class="ds-status-card">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">

                {{-- TEAM --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label tracking-wide">TEAM (Agent-based)</div>
                    <div class="ds-label mt-1">Company retained (ex VAT)</div>
                    <div class="ds-value-lg leading-tight">
                        R {{ number_format($teamCompanyRetained, 0) }}
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Income</div>
                            <div class="ds-value">R {{ number_format($teamCompanyIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Agent share</div>
                            <div class="ds-value">R {{ number_format($teamAgentIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Retained</div>
                            <div class="ds-value">R {{ number_format($teamCompanyRetained, 0) }}</div>
                        </div>
                    </div>
                </div>

                {{-- LEDGER --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label tracking-wide">LEDGER (Deal.branch_id)</div>
                    <div class="ds-label mt-1">Company retained (ex VAT)</div>
                    <div class="ds-value-lg leading-tight">
                        R {{ number_format($ledgerCompanyRetained, 0) }}
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Income</div>
                            <div class="ds-value">R {{ number_format($ledgerCompanyIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Agent share</div>
                            <div class="ds-value">R {{ number_format($ledgerAgentIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Retained</div>
                            <div class="ds-value">R {{ number_format($ledgerCompanyRetained, 0) }}</div>
                        </div>
                    </div>
                </div>

                {{-- PACE --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label tracking-wide">PACE</div>
                    <div class="ds-label mt-1">Today points: <span class="ds-value">{{ number_format($todayPoints, 0) }}</span></div>
                    <div class="ds-label mt-1">Status: <span class="ds-value">{{ $pointsStatus }}</span></div>
                    <div class="ds-label mt-1">Need <span class="ds-value">{{ number_format($pointsPerDayNeeded, 1) }}</span>/day</div>

                    <div class="mt-3 ds-label">Points progress</div>
                    <div class="ds-progress-track mt-2">
                        <div class="ds-progress-bar {{ $pointsBarClass }}" style="width: {{ min(100, max(0, $pointsPct)) }}%"></div>
                    </div>
                    <div class="mt-2 ds-label">
                        {{ number_format($pointsActual, 0) }} / {{ number_format($pointsTarget, 0) }} ({{ number_format($pointsPct, 1) }}%)
                    </div>
                </div>

            </div>
        </div>

        {{-- Agents (drilldown to agent detail) --}}
        <div class="ds-section-header">Agents</div>
        <div class="ds-section-sub">Click an agent to drill down.</div>
        <div class="ds-status-card">
            <div class="rounded-2xl border border-black/10 bg-gray-50 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="ds-table min-w-full text-sm">
                        <thead class="bg-white border-b border-black/10">
                            <tr class="text-left">
                                <th class="px-4 py-3">Agent</th>
                                <th class="px-4 py-3 text-right">Team Retained</th>
                                <th class="px-4 py-3 text-right">Team Income</th>
                                <th class="px-4 py-3 text-right">Deals (A/T)</th>
                                <th class="px-4 py-3 text-right">Value (A/T)</th>
                                <th class="px-4 py-3 text-right">Points</th>
                                <th class="px-4 py-3 text-right">Pace</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/10 bg-gray-50">
                            @foreach(($r['rows'] ?? []) as $row)
                                <tr class="hover:bg-black/5">
                                    <td class="px-4 py-3">
                                        <div class="font-extrabold">
                                            <a class="ds-agent-link" href="{{ route('admin.agent.performance', ['userId' => $row['user_id'], 'period' => $r['period']]) }}">{{ $row['name'] }}</a>
                                        </div>
                                        <div class="ds-label text-xs">{{ $row['email'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-extrabold text-gray-900">R {{ number_format((float)($row['actuals']['company_retained'] ?? 0), 0) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">R {{ number_format((float)($row['actuals']['company_income'] ?? 0), 0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900">{{ (int)($row['actuals']['deals'] ?? 0) }} / {{ (int)($row['targets']['deals'] ?? 0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900">R {{ number_format((float)($row['actuals']['value'] ?? $row['actuals']['sales_value'] ?? 0),0) }} / R {{ number_format((float)($row['targets']['value'] ?? 0),0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900">{{ number_format((float)($row['actuals']['points'] ?? 0),0) }} / {{ number_format((float)($row['targets']['points'] ?? 0),0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900 font-semibold">{{ $row['progress']['points_status'] ?? '—' }}</td>
                                </tr>
                            @endforeach

                            @if(empty($r['rows']))
                                <tr><td colspan="7" class="px-4 py-8 text-gray-500 font-semibold">No agents found.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
