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

    $pointsBarClass = 'bg-gray-900';
    if ($pointsTarget > 0) {
        if ($pointsActual >= $pointsTarget) $pointsBarClass = 'bg-green-600';
        elseif ($pointsStatus === 'Ahead' || $pointsStatus === 'On pace') $pointsBarClass = 'bg-green-600';
        elseif ($pointsPct >= 75) $pointsBarClass = 'bg-amber-500';
        else $pointsBarClass = 'bg-red-600';
    }

    // Branch value/deals progress (agent-sum targets)
    $branchValueTarget = (float)($r['totals']['targets']['value'] ?? 0);
    $branchDealsTarget = (int)($r['totals']['targets']['deals'] ?? 0);

    $branchValueActual = (float)($r['totals']['actuals']['value'] ?? $r['totals']['actuals']['sales_value'] ?? 0);
    $branchDealsActual = (int)($r['totals']['actuals']['deals'] ?? 0);

    $valuePct = $branchValueTarget > 0 ? (($branchValueActual / $branchValueTarget) * 100) : 0;
    $dealsPct = $branchDealsTarget > 0 ? (($branchDealsActual / $branchDealsTarget) * 100) : 0;

    $valueBar = $valuePct >= 95 ? 'bg-green-600' : ($valuePct >= 75 ? 'bg-amber-500' : 'bg-red-600');
    $dealsBar = $dealsPct >= 95 ? 'bg-green-600' : ($dealsPct >= 75 ? 'bg-amber-500' : 'bg-red-600');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-200 leading-tight">
                    Admin — Branch Dashboard — {{ $r['period'] }}
                </h2>
                <div class="text-sm text-gray-400">{{ $branchName ?? 'Branch' }} (Admin view)</div>
            </div>

            <div class="flex flex-wrap md:flex-nowrap items-center gap-2">
                <a href="{{ route('admin.performance', ['period' => $r['period']]) }}" class="btn-primary px-4 py-2">Back</a>

                <form method="GET" action="{{ route('admin.branch.performance', ['branchId' => (int)($r['branch_id'] ?? 0)]) }}" class="flex flex-wrap md:flex-nowrap items-center gap-2">
                    <label class="text-sm font-semibold text-gray-200">Period</label>
                    <input type="month" name="period" value="{{ $r['period'] }}" />
                    <button type="submit" class="btn-primary px-4 py-2">Go</button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">


        <!-- ADMIN_BRANCH_STATUS_TILES -->
        <div class="space-y-3">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="/admin/deals?status=Declined&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-red-900 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Declined (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['declined_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Pending&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-amber-700 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Pending (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['pending_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-blue-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Granted (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['granted_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-green-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Registered (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['registered_period'] ?? 0 }}</div>
                    </div>
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="/admin/deals?status=Pending&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-amber-700 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Pending (Not Paid) — Company ex VAT</div>
                        <div class="text-3xl font-extrabold">R {{ number_format((float)($statusSummary['pending_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-blue-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Granted (Not Paid) — Company ex VAT</div>
                        <div class="text-3xl font-extrabold">R {{ number_format((float)($statusSummary['granted_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-green-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Registered (Not Paid) — Company ex VAT</div>
                        <div class="text-3xl font-extrabold">R {{ number_format((float)($statusSummary['registered_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>
            </div>
        </div>




        <!-- ADMIN_BRANCH_STATUS_TILES -->
        <div class="space-y-3">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="/admin/deals?status=Declined&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-red-900 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Declined (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['declined_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Pending&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-amber-700 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Pending (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['pending_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-blue-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Granted (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['granted_period'] ?? 0 }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&period={{ $r['period'] ?? now()->format('Y-m') }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-green-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Registered (period)</div>
                        <div class="text-3xl font-extrabold">{{ $statusSummary['registered_period'] ?? 0 }}</div>
                    </div>
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="/admin/deals?status=Pending&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-amber-700 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Pending (Not Paid) — Company ex VAT</div>
                        <div class="text-3xl font-extrabold">R {{ number_format((float)($statusSummary['pending_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Granted&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-blue-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Granted (Not Paid) — Company ex VAT</div>
                        <div class="text-3xl font-extrabold">R {{ number_format((float)($statusSummary['granted_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>

                <a href="/admin/deals?status=Registered&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                    <div class="rounded-2xl bg-green-800 text-white p-4 shadow">
                        <div class="text-xs opacity-80">Registered (Not Paid) — Company ex VAT</div>
                        <div class="text-3xl font-extrabold">R {{ number_format((float)($statusSummary['registered_unpaid_company_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                </a>
            </div>
        </div>



        {{-- Value + Deals --}}
        <div class="card">
            <div class="text-2xl md:text-3xl font-extrabold text-gray-900 leading-tight">Branch focus — Value & Deals</div>
            <div class="text-sm text-gray-600 mt-1">
                Targets are based on what agents planned for the month (agent target sum).
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="text-sm text-gray-600 font-semibold">Branch Value (Actual / Agent-Sum Target)</div>
                    <div class="text-3xl font-extrabold text-gray-900 leading-tight">
                        R {{ number_format($branchValueActual, 0) }}
                        <span class="text-gray-400 font-bold">/ R {{ number_format($branchValueTarget, 0) }}</span>
                    </div>
                    <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                        <div class="h-3 {{ $valueBar }}" style="width: {{ min(100, max(0, $valuePct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-sm text-gray-700 font-semibold">Progress {{ number_format($valuePct, 1) }}%</div>
                </div>

                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="text-sm text-gray-600 font-semibold">Deals (Actual / Agent-Sum Target)</div>
                    <div class="text-3xl font-extrabold text-gray-900 leading-tight">
                        {{ number_format($branchDealsActual, 0) }}
                        <span class="text-gray-400 font-bold">/ {{ number_format($branchDealsTarget, 0) }}</span>
                    </div>
                    <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                        <div class="h-3 {{ $dealsBar }}" style="width: {{ min(100, max(0, $dealsPct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-sm text-gray-700 font-semibold">Progress {{ number_format($dealsPct, 1) }}%</div>
                </div>
            </div>
        </div>

        {{-- Money + Pace --}}
        <div class="card">
            <div class="text-2xl md:text-3xl font-extrabold text-gray-900 leading-tight">Branch focus — Money</div>
            <div class="text-sm text-gray-600 mt-1">
                <span class="font-semibold">Team retained</span> = what your branch agents produced (BM reality).
                <span class="font-semibold">Ledger retained</span> = deals recorded against this branch.
            </div>

            <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-3">

                {{-- TEAM --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="text-xs font-bold text-gray-500 tracking-wide">TEAM (Agent-based)</div>
                    <div class="text-sm text-gray-600 mt-1">Company retained (ex VAT)</div>
                    <div class="text-3xl font-extrabold text-gray-900 leading-tight">
                        R {{ number_format($teamCompanyRetained, 0) }}
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-gray-700">
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="text-gray-500 font-semibold">Income</div>
                            <div class="font-extrabold">R {{ number_format($teamCompanyIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="text-gray-500 font-semibold">Agent share</div>
                            <div class="font-extrabold">R {{ number_format($teamAgentIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="text-gray-500 font-semibold">Retained</div>
                            <div class="font-extrabold">R {{ number_format($teamCompanyRetained, 0) }}</div>
                        </div>
                    </div>
                </div>

                {{-- LEDGER --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="text-xs font-bold text-gray-500 tracking-wide">LEDGER (Deal.branch_id)</div>
                    <div class="text-sm text-gray-600 mt-1">Company retained (ex VAT)</div>
                    <div class="text-3xl font-extrabold text-gray-900 leading-tight">
                        R {{ number_format($ledgerCompanyRetained, 0) }}
                    </div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-gray-700">
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="text-gray-500 font-semibold">Income</div>
                            <div class="font-extrabold">R {{ number_format($ledgerCompanyIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="text-gray-500 font-semibold">Agent share</div>
                            <div class="font-extrabold">R {{ number_format($ledgerAgentIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="text-gray-500 font-semibold">Retained</div>
                            <div class="font-extrabold">R {{ number_format($ledgerCompanyRetained, 0) }}</div>
                        </div>
                    </div>
                </div>

                {{-- PACE --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="text-xs font-bold text-gray-500 tracking-wide">PACE</div>
                    <div class="text-sm text-gray-600 mt-1">Today points: <span class="font-extrabold">{{ number_format($todayPoints, 0) }}</span></div>
                    <div class="text-sm text-gray-600 mt-1">Status: <span class="font-extrabold">{{ $pointsStatus }}</span></div>
                    <div class="text-sm text-gray-600 mt-1">Need <span class="font-extrabold">{{ number_format($pointsPerDayNeeded, 1) }}</span>/day</div>

                    <div class="mt-3 text-sm text-gray-600 font-semibold">Points progress</div>
                    <div class="mt-2 h-3 rounded bg-gray-200 overflow-hidden">
                        <div class="h-3 {{ $pointsBarClass }}" style="width: {{ min(100, max(0, $pointsPct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-xs text-gray-600">
                        {{ number_format($pointsActual, 0) }} / {{ number_format($pointsTarget, 0) }} ({{ number_format($pointsPct, 1) }}%)
                    </div>
                </div>

            </div>
        </div>

        {{-- Agents (drilldown to agent detail) --}}
        <div class="card">
            <div class="text-2xl md:text-3xl font-extrabold text-gray-900 leading-tight">Agents</div>
            <div class="text-sm text-gray-600 mt-1">Click an agent to drill down.</div>

            <div class="mt-4 rounded-2xl border border-black/10 bg-gray-50 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white border-b border-black/10">
                            <tr class="text-left text-gray-600">
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
                                        <div class="font-extrabold text-gray-900">
                                            <a class="text-indigo-700 hover:underline" href="{{ route('admin.agent.performance', ['userId' => $row['user_id'], 'period' => $r['period']]) }}">{{ $row['name'] }}</a>
                                        </div>
                                        <div class="text-gray-500 text-xs font-semibold">{{ $row['email'] }}</div>
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
