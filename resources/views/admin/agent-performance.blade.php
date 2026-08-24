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

    // Bars — ds colours: >= 80 navy, >= 50 amber, else crimson
    $valueBar  = $valuePct >= 80 ? 'ds-bar-navy' : ($valuePct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
    $dealsBar  = $dealsPct >= 80 ? 'ds-bar-navy' : ($dealsPct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');

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
        <div class="rounded-md px-6 py-5 corex-page-banner">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                        Agent Performance — {{ $agent->name }}
                    </h2>
                    <div class="text-xs" style="color: var(--text-muted);">
                        {{ $agent->email }} @if($branchName) — {{ $branchName }} @endif — {{ $period }}
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.performance', ['period' => $period]) }}" class="corex-btn-outline text-xs shrink-0">Back</a>
                    <form method="GET" action="{{ route('admin.agent.performance', ['userId' => $agent->id]) }}" class="flex flex-wrap items-center gap-2">
                        <input type="month" name="period" value="{{ $period }}" class="list-header-filter" />
                        <button type="submit" class="corex-btn-primary text-xs">Go</button>
                    </form>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        {{-- HERO: Money (agent) --}}
        <h3 class="ds-section-header">Agent focus — Money</h3>
        <p class="ds-section-sub mb-4">
            Business truth (ex VAT) from Deal Register &rarr; side share/external flags &rarr; agent split.
        </p>

        <div class="ds-status-card" style="border-left-color: var(--border);">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                <div class="rounded-lg p-4" style="background:var(--surface-2); border:1px solid var(--border)">
                    <div class="ds-label">COMPANY RETAINED</div>
                    <div class="text-sm mt-1" style="color:var(--text-secondary)">Company retained (ex VAT)</div>
                    <div class="ds-value-xl leading-tight">R {{ number_format($moneyCompanyRetained, 0) }}</div>

                    <div class="mt-3 grid grid-cols-3 gap-2 text-xs" style="color:var(--text-secondary)">
                        <div class="rounded-md p-2" style="background:var(--surface); border:1px solid var(--border)">
                            <div class="ds-label">Side Pool</div>
                            <div style="font-weight:700">R {{ number_format($moneyCompanyIncome, 0) }}</div>
                        </div>
                        <div class="rounded-md p-2" style="background:var(--surface); border:1px solid var(--border)">
                            <div class="ds-label">Agent Income</div>
                            <div style="font-weight:700">R {{ number_format($moneyAgentIncome, 0) }}</div>
                        </div>
                        <div class="rounded-md p-2" style="background:var(--surface); border:1px solid var(--border)">
                            <div class="ds-label">Company Retained</div>
                            <div style="font-weight:700">R {{ number_format($moneyCompanyRetained, 0) }}</div>
                        </div>
                    </div>

                    <div class="mt-3 text-xs" style="color:var(--text-secondary)">
                        Deals table totals: Side Pool <span class="font-bold">R {{ number_format($dealsCompanyIncome, 0) }}</span>,
                        Agent <span class="font-bold">R {{ number_format($dealsAgentIncome, 0) }}</span>,
                        Retained <span class="font-bold">R {{ number_format($dealsRetained, 0) }}</span>
                    </div>
                </div>

                {{-- Value --}}
                <div class="rounded-lg p-4" style="background:var(--surface-2); border:1px solid var(--border)">
                    <div class="ds-label">VALUE</div>
                    <div class="text-sm mt-1" style="color:var(--text-secondary)">Sales Value (Actual / Target)</div>
                    <div class="ds-value-xl leading-tight">
                        R {{ number_format($valueActual, 0) }}
                        <span class="font-bold" style="color:var(--text-faint)">/ R {{ number_format($valueTarget, 0) }}</span>
                    </div>
                    <div class="ds-progress-track mt-2">
                        <div class="ds-progress-bar {{ $valueBar }}" style="width: {{ min(100, max(0, $valuePct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-sm ds-value font-semibold">Progress {{ number_format($valuePct, 1) }}%</div>

                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs" style="color:var(--text-secondary)">
                        <div class="rounded-md p-2" style="background:var(--surface); border:1px solid var(--border)">
                            <div class="ds-label">Deals</div>
                            <div style="font-weight:700">{{ number_format($dealsActual, 0) }} / {{ number_format($dealsTarget, 0) }}</div>
                        </div>
                        <div class="rounded-md p-2" style="background:var(--surface); border:1px solid var(--border)">
                            <div class="ds-label">Listings target</div>
                            <div style="font-weight:700">{{ number_format((int)($targets['listings'] ?? 0), 0) }}</div>
                        </div>
                    </div>
                </div>

                {{-- Pace --}}
                <div class="rounded-lg p-4" style="background:var(--surface-2); border:1px solid var(--border)">
                    <div class="ds-label">PACE</div>
                    <div class="text-sm mt-1" style="color:var(--text-secondary)">Today: <span class="ds-value" style="font-weight:700">{{ number_format($todayPoints, 0) }}</span> pts</div>
                    <div class="text-sm mt-1" style="color:var(--text-secondary)">Status: <span class="ds-value" style="font-weight:700">{{ $pointsStatus }}</span></div>
                    <div class="text-sm mt-1" style="color:var(--text-secondary)">Need <span class="ds-value" style="font-weight:700">{{ number_format($pointsPerDayNeeded, 1) }}</span>/day</div>

                    <div class="mt-3 text-sm font-semibold" style="color:var(--text-secondary)">Points progress</div>
                    <div class="ds-progress-track mt-2">
                        <div class="ds-progress-bar {{ $pointsBar }}" style="width: {{ min(100, max(0, $pointsPct)) }}%"></div>
                    </div>
                    <div class="mt-2 text-xs" style="color:var(--text-secondary)">
                        {{ number_format($pointsActual, 0) }} / {{ number_format($pointsTarget, 0) }} ({{ number_format($pointsPct, 1) }}%)
                        @if($pointsTarget > 0 && $pointsActual >= $pointsTarget) <span title="Target achieved">&#127942;</span> @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Momentum + today breakdown (matches BM style) --}}
        <h3 class="ds-section-header">Activity focus — Momentum</h3>
        <p class="ds-section-sub mb-4">Last 7 days points + today breakdown (agent scoped).</p>

        <div class="ds-status-card" style="border-left-color: var(--border);">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="rounded-lg p-4" style="background:var(--surface-2); border:1px solid var(--border)">
                    <div class="text-sm font-semibold" style="color:var(--text-secondary)">Momentum (last 7 days)</div>
                    <div class="mt-3 grid grid-cols-7 gap-2">
                        @foreach($m7 as $d)
                            @php
                                $v = (float)($d['points'] ?? 0);
                                $h = min(80, max(10, $v)); // keep it TV-friendly
                            @endphp
                            <div class="rounded-md p-2 text-center" style="background:var(--surface); border:1px solid var(--border)">
                                <div class="text-[10px]" style="color:var(--text-muted)">{{ \Carbon\Carbon::parse($d['date'])->format('D') }}</div>
                                <div class="mt-2 h-20 flex items-end justify-center">
                                    <div class="w-4 rounded {{ $v > 0 ? 'ds-bar-navy' : '' }}" style="height: {{ $h }}px; {{ $v > 0 ? '' : 'background:var(--surface-2)' }}"></div>
                                </div>
                                <div class="text-xs ds-value mt-1" style="font-weight:700">{{ number_format($v, 0) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg p-4" style="background:var(--surface-2); border:1px solid var(--border)">
                    <div class="text-sm font-semibold" style="color:var(--text-secondary)">Today breakdown</div>
                    <div class="mt-3">
                        @if(empty($activities_today))
                            <div class="text-sm" style="color:var(--text-muted)">No activity captured today.</div>
                        @else
                            <div class="overflow-x-auto">
                                <table class="ds-table min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left" style="color:var(--text-secondary)">
                                            <th class="py-2 pr-4">Activity</th>
                                            <th class="py-2 text-right">Count</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[color:var(--border)]">
                                        @foreach($activities_today as $k => $v)
                                            <tr>
                                                <td class="py-2 pr-4 font-semibold" style="color:var(--text-primary)">{{ $pretty($k) }}</td>
                                                <td class="py-2 text-right ds-value" style="font-weight:700">{{ (int)$v }}</td>
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
        <h3 class="ds-section-header">Deals</h3>
        <p class="ds-section-sub mb-4">Includes per-deal company income (ex VAT), agent share, retained.</p>

        <div class="ds-status-card" style="border-left-color: var(--border);">
            <div class="rounded-lg overflow-hidden" style="background:var(--surface-2); border:1px solid var(--border)">
                <div class="overflow-x-auto">
                    <table class="ds-table min-w-full text-sm">
                        <thead style="background:var(--surface-2); border-bottom:1px solid var(--border)">
                            <tr class="text-left" style="color:var(--text-secondary)">
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">File / Deal</th>
                                <th class="px-4 py-3">Address</th>
                                <th class="px-4 py-3">Side</th>
                                <th class="px-4 py-3 text-right">Value</th>
                                <th class="px-4 py-3 text-right">Commission (inc VAT)</th>
                                <th class="px-4 py-3 text-right">Side Pool (ex VAT)</th>
                                <th class="px-4 py-3 text-right">Agent Income (ex VAT)</th>
                                <th class="px-4 py-3 text-right">Company Retained (ex VAT)</th>
                                <th class="px-4 py-3 text-right">Split / Cut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[color:var(--border)]" style="background:var(--surface)">
                            @foreach($deals as $d)
                                <tr>
                                    <td class="px-4 py-3" style="color:var(--text-primary)">{{ $d->deal_date }}</td>
                                    <td class="px-4 py-3">
                                        <div class="ds-value" style="font-weight:700">{{ $d->file_no }}</div>
                                        <div class="text-xs font-semibold" style="color:var(--text-muted)">{{ $d->deal_no }}</div>
                                    </td>
                                    <td class="px-4 py-3" style="color:var(--text-primary)">{{ $d->property_address }}</td>
                                    <td class="px-4 py-3 font-semibold" style="color:var(--text-primary)">{{ $d->side }}</td>
                                    <td class="px-4 py-3 text-right font-semibold" style="color:var(--text-primary)">R {{ number_format($d->property_value,0) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold" style="color:var(--text-primary)">R {{ number_format($d->total_commission,0) }}</td>
                                    <td class="px-4 py-3 text-right ds-value" style="font-weight:700">R {{ number_format($d->company_income_ex_vat ?? 0,0) }}</td>
                                    <td class="px-4 py-3 text-right ds-value" style="font-weight:700">R {{ number_format($d->agent_income_ex_vat ?? 0,0) }}</td>
                                    <td class="px-4 py-3 text-right ds-value" style="font-weight:700">R {{ number_format($d->company_retained_ex_vat ?? 0,0) }}</td>
                                    <td class="px-4 py-3 text-right" style="color:var(--text-primary)">{{ (int)$d->agent_split_percent }}% / {{ (int)$d->agent_cut_percent }}%</td>
                                </tr>
                            @endforeach

                            @if(count($deals) === 0)
                                <tr>
                                    <td colspan="10" class="px-4 py-8 font-semibold" style="color:var(--text-muted)">No deals for this period.</td>
                                </tr>
                            @endif
                        </tbody>
                        <tfoot style="background:var(--surface-2); border-top:1px solid var(--border)">
                            <tr>
                                <td class="px-4 py-3 ds-value" style="font-weight:700" colspan="6">Totals</td>
                                <td class="px-4 py-3 text-right ds-value" style="font-weight:700">R {{ number_format($dealsCompanyIncome,0) }}</td>
                                <td class="px-4 py-3 text-right ds-value" style="font-weight:700">R {{ number_format($dealsAgentIncome,0) }}</td>
                                <td class="px-4 py-3 text-right ds-value" style="font-weight:700">R {{ number_format($dealsRetained,0) }}</td>
                                <td class="px-4 py-3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>
