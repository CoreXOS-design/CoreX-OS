@php
    // Inputs from controller (already aligned to truth source)
    $moneyCompanyIncome   = (float)($actuals['company_income'] ?? 0);
    $moneyAgentIncome     = (float)($actuals['agent_income'] ?? 0);
    $moneyCompanyRetained = (float)($actuals['company_retained'] ?? 0);

    $valueActual = (float)($actuals['value'] ?? $actuals['sales_value'] ?? 0);
    $valueTarget = (float)($targets['value'] ?? 0);
    $valuePct = $valueTarget > 0 ? (($valueActual / $valueTarget) * 100) : 0;

    $dealsActual = (int)($actuals['deals'] ?? 0);
    $dealsTarget = (int)($targets['deals'] ?? 0);
    $dealsPct = $dealsTarget > 0 ? (($dealsActual / $dealsTarget) * 100) : 0;

    $pointsActual = (float)($actuals['points'] ?? 0);
    $pointsTarget = (float)($targets['points'] ?? 0);
    $pointsPct = (float)($progress['points_pct'] ?? ($pointsTarget > 0 ? (($pointsActual / $pointsTarget) * 100) : 0));
    $pointsStatus = (string)($progress['points_status'] ?? '—');
    $pointsPerDayNeeded = (float)($progress['points_per_day_needed'] ?? 0);

    // DS bar classes
    $valueBar = $valuePct >= 80 ? 'ds-bar-navy' : ($valuePct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
    $dealsBar = $dealsPct >= 80 ? 'ds-bar-navy' : ($dealsPct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');

    $pointsBar = 'ds-bar-navy';
    if ($pointsTarget > 0) {
        if ($pointsActual >= $pointsTarget) $pointsBar = 'ds-bar-navy';
        elseif ($pointsStatus === 'Ahead' || $pointsStatus === 'On pace') $pointsBar = 'ds-bar-navy';
        elseif ($pointsPct >= 50) $pointsBar = 'ds-bar-amber';
        else $pointsBar = 'ds-bar-crimson';
    }

    $m7 = $momentum_7d ?? [];
    $todayPoints = 0.0;
    if (!empty($m7)) {
        $last = end($m7);
        $todayPoints = (float)($last['points'] ?? 0);
        reset($m7);
    }

    // Deals table totals (should align with money tiles)
    $dealsCompanyIncome = (float) collect($deals ?? [])->sum('company_income_ex_vat');
    $dealsAgentIncome   = (float) collect($deals ?? [])->sum('agent_income_ex_vat');
    $dealsRetained      = (float) collect($deals ?? [])->sum('company_retained_ex_vat');

    // Simple pretty labels for activity keys
    $pretty = function(string $k): string {
        $k = str_replace('_',' ', $k);
        return ucwords($k);
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-200 leading-tight">
                    Agent Dashboard — {{ $period }}
                </h2>
                <div class="text-sm text-gray-400">
                    {{ $agent->name }} — {{ $agent->email }} @if($branchName) ({{ $branchName }}) @endif
                </div>
            </div>

            <div class="flex flex-wrap md:flex-nowrap items-center gap-2">
                <a href="{{ route('bm.performance', ['period' => $period]) }}" class="btn-primary px-4 py-2">Back</a>

                <form method="GET" action="{{ route('bm.agent.performance', ['userId' => $agent->id]) }}" class="flex flex-wrap md:flex-nowrap items-center gap-2">
                    <label class="text-sm font-semibold text-gray-200">Period</label>
                    <input type="month" name="period" value="{{ $period }}" />
                    <button type="submit" class="btn-primary px-4 py-2">Go</button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        {{-- HERO: Money (agent) --}}
        <div class="ds-section-header">Agent focus — Money</div>
        <div class="ds-section-sub mb-4">
            Business truth (ex VAT) from Deal Register &rarr; side share/external flags &rarr; agent split.
        </div>

        <div class="card">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label">RETAINED</div>
                    <div class="text-sm text-gray-600 mt-1">Company retained (ex VAT)</div>
                    <div class="ds-value-xl mt-1">R {{ number_format($moneyCompanyRetained, 0) }}</div>

                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs text-gray-700">
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Income</div>
                            <div class="font-extrabold">R {{ number_format($moneyCompanyIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Agent share</div>
                            <div class="font-extrabold">R {{ number_format($moneyAgentIncome, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Retained</div>
                            <div class="font-extrabold">R {{ number_format($moneyCompanyRetained, 0) }}</div>
                        </div>
                    </div>

                    <div class="mt-3 text-xs text-gray-600">
                        Deals table totals: Income <span class="font-bold">R {{ number_format($dealsCompanyIncome, 0) }}</span>,
                        Agent <span class="font-bold">R {{ number_format($dealsAgentIncome, 0) }}</span>,
                        Retained <span class="font-bold">R {{ number_format($dealsRetained, 0) }}</span>
                    </div>
                </div>

                {{-- Value --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label">VALUE</div>
                    <div class="text-sm text-gray-600 mt-1">Sales Value (Actual / Target)</div>
                    <div class="ds-value-xl mt-1">
                        R {{ number_format($valueActual, 0) }}
                        <span class="text-gray-400 font-bold text-lg">/ R {{ number_format($valueTarget, 0) }}</span>
                    </div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $valueBar }}" style="width: {{ min(100, max(0, $valuePct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-sm text-gray-700 font-semibold">Progress {{ number_format($valuePct, 1) }}%</div>

                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-700">
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Deals</div>
                            <div class="font-extrabold">{{ number_format($dealsActual, 0) }} / {{ number_format($dealsTarget, 0) }}</div>
                        </div>
                        <div class="rounded-xl bg-white border border-black/10 p-2">
                            <div class="ds-label">Listings target</div>
                            <div class="font-extrabold">{{ number_format((int)($targets['listings'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                </div>

                {{-- Pace --}}
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label">PACE</div>
                    <div class="text-sm text-gray-600 mt-1">Today: <span class="font-extrabold">{{ number_format($todayPoints, 0) }}</span> pts</div>
                    <div class="text-sm text-gray-600 mt-1">Status: <span class="font-extrabold">{{ $pointsStatus }}</span></div>
                    <div class="text-sm text-gray-600 mt-1">Need <span class="font-extrabold">{{ number_format($pointsPerDayNeeded, 1) }}</span>/day</div>

                    <div class="mt-3 text-sm text-gray-600 font-semibold">Points progress</div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $pointsBar }}" style="width: {{ min(100, max(0, $pointsPct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-xs text-gray-600">
                        {{ number_format($pointsActual, 0) }} / {{ number_format($pointsTarget, 0) }} ({{ number_format($pointsPct, 1) }}%)
                        @if($pointsTarget > 0 && $pointsActual >= $pointsTarget) <span title="Target achieved">🏆</span> @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Momentum + today breakdown (matches BM style) --}}
        <div class="ds-section-header">Activity focus — Momentum</div>
        <div class="ds-section-sub mb-4">Last 7 days points + today breakdown (agent scoped).</div>

        <div class="card">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label">Momentum (last 7 days)</div>
                    <div class="mt-3 grid grid-cols-7 gap-2">
                        @foreach($m7 as $d)
                            @php
                                $v = (float)($d['points'] ?? 0);
                                $h = min(80, max(10, $v)); // keep it TV-friendly
                            @endphp
                            <div class="rounded-xl border border-black/10 bg-white p-2 text-center">
                                <div class="text-[10px] text-gray-500">{{ \Carbon\Carbon::parse($d['date'])->format('D') }}</div>
                                <div class="mt-2 h-20 flex items-end justify-center">
                                    <div class="w-4 rounded {{ $v > 0 ? 'bg-gray-900' : 'bg-gray-200' }}" style="height: {{ $h }}px;"></div>
                                </div>
                                <div class="text-xs font-extrabold text-gray-900 mt-1">{{ number_format($v, 0) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-black/10 bg-gray-50 p-4">
                    <div class="ds-label">Today breakdown</div>
                    <div class="mt-3">
                        @if(empty($activities_today))
                            <div class="text-sm text-gray-500">No activity captured today.</div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-600">
                                            <th class="py-2 pr-4">Activity</th>
                                            <th class="py-2 text-right">Count</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-black/10">
                                        @foreach($activities_today as $k => $v)
                                            <tr>
                                                <td class="py-2 pr-4 text-gray-900 font-semibold">{{ $pretty($k) }}</td>
                                                <td class="py-2 text-right text-gray-900 font-extrabold">{{ (int)$v }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Deals table (light, BM-styled) --}}
        <div class="ds-section-header">Deals</div>
        <div class="ds-section-sub mb-4">Includes per-deal company income (ex VAT), agent share, retained.</div>

        <div class="card">
            <div class="rounded-2xl border border-black/10 bg-gray-50 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="ds-table min-w-full text-sm">
                        <thead class="bg-white border-b border-black/10">
                            <tr class="text-left text-gray-600">
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">File / Deal</th>
                                <th class="px-4 py-3">Address</th>
                                <th class="px-4 py-3">Side</th>
                                <th class="px-4 py-3 text-right">Value</th>
                                <th class="px-4 py-3 text-right">Commission (inc VAT)</th>
                                <th class="px-4 py-3 text-right">Company Income (ex VAT)</th>
                                <th class="px-4 py-3 text-right">Agent Income (ex VAT)</th>
                                <th class="px-4 py-3 text-right">Retained (ex VAT)</th>
                                <th class="px-4 py-3 text-right">Split / Cut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-black/10 bg-gray-50">
                            @foreach($deals as $d)
                                <tr class="hover:bg-black/5">
                                    <td class="px-4 py-3 text-gray-900">{{ $d->deal_date }}</td>
                                    <td class="px-4 py-3">
                                        <div class="font-extrabold text-gray-900">{{ $d->file_no }}</div>
                                        <div class="text-gray-500 text-xs font-semibold">{{ $d->deal_no }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-900">{{ $d->property_address }}</td>
                                    <td class="px-4 py-3 text-gray-900 font-semibold">{{ $d->side }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900 font-semibold">R {{ number_format($d->property_value,0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900 font-semibold">R {{ number_format($d->total_commission,0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900 font-extrabold">R {{ number_format($d->company_income_ex_vat ?? 0,0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900 font-extrabold">R {{ number_format($d->agent_income_ex_vat ?? 0,0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900 font-extrabold">R {{ number_format($d->company_retained_ex_vat ?? 0,0) }}</td>
                                    <td class="px-4 py-3 text-right text-gray-900">{{ (int)$d->agent_split_percent }}% / {{ (int)$d->agent_cut_percent }}%</td>
                                </tr>
                            @endforeach

                            @if(count($deals) === 0)
                                <tr>
                                    <td colspan="10" class="px-4 py-8 text-gray-500 font-semibold">No deals for this period.</td>
                                </tr>
                            @endif
                        </tbody>
                        <tfoot class="bg-white border-t border-black/10">
                            <tr>
                                <td class="px-4 py-3 font-extrabold text-gray-900" colspan="6">Totals</td>
                                <td class="px-4 py-3 text-right font-extrabold text-gray-900">R {{ number_format($dealsCompanyIncome,0) }}</td>
                                <td class="px-4 py-3 text-right font-extrabold text-gray-900">R {{ number_format($dealsAgentIncome,0) }}</td>
                                <td class="px-4 py-3 text-right font-extrabold text-gray-900">R {{ number_format($dealsRetained,0) }}</td>
                                <td class="px-4 py-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
