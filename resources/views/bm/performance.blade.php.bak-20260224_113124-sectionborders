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

    $pointsActual = (float)($pts['actual'] ?? 0);
    $pointsTarget = (float)($pts['target'] ?? 0);
    $pointsPct = (float)($pts['pct'] ?? 0);
    $pointsStatus = (string)($pts['status'] ?? '—');
    $pointsRemaining = (float)($pts['remaining'] ?? 0);
    $pointsPerDayNeeded = (float)($pts['per_day_needed'] ?? 0);
    $todayPoints = (float)($pts['today_points'] ?? 0);

    $pointsBarClass = 'ds-bar-navy';
    if ($pointsTarget > 0) {
        if ($pointsActual >= $pointsTarget) $pointsBarClass = 'ds-bar-navy';
        elseif ($pointsStatus === 'Ahead' || $pointsStatus === 'On pace') $pointsBarClass = 'ds-bar-navy';
        elseif ($pointsPct >= 50) $pointsBarClass = 'ds-bar-amber';
        else $pointsBarClass = 'ds-bar-crimson';
    }

    // Branch target goal row (BM set)
    $bg = $branchGoal ?? null;
    $branchDeals = (int)($bg?->deals_target ?? 0);
    $branchListings = (int)($bg?->listings_target ?? 0);
    $branchValue = (float)($bg?->value_target ?? 0);

    $sumDeals = (int)($r["totals"]["targets"]["deals"] ?? 0);
    $sumListings = (int)($r["totals"]["targets"]["listings"] ?? 0);
    $sumValue = (float)($r["totals"]["targets"]["value"] ?? 0);

    $b = $budget ?? ['branch_budget'=>0,'projected_income'=>0,'short_amount'=>0,'short_pct'=>0,'commission_rate'=>0.05,'company_share'=>0.5];
    $branchBudget = (float)($b['branch_budget'] ?? 0);
    $projectedIncome = (float)($b['projected_income'] ?? 0);
    $shortAmount = (float)($b['short_amount'] ?? 0);
    $shortPct = (float)($b['short_pct'] ?? 0);


    $stageFilter = $stageFilter ?? ['pending'=>true,'granted'=>true,'registered'=>true];
    $marketAverages = $marketAverages ?? [];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">
                        Branch Dashboard — {{ $branchName ?? 'Branch' }}
                    </h2>
                    <div class="text-sm text-white/60">Branch Manager view (TV-ready)</div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-white/80 font-semibold hidden md:inline">{{ \Carbon\Carbon::createFromFormat('Y-m', $r['period'])->format('F Y') }}</span>
                    <form method="GET" action="{{ route('bm.performance') }}" class="flex items-center gap-2">
                        <input type="month" name="period" value="{{ $r['period'] }}" class="h-8 text-sm rounded border border-white/20 bg-white/10 text-white px-2" />
                        <button type="submit" class="px-3 py-1.5 text-sm font-semibold rounded bg-white/20 text-white hover:bg-white/30">Go</button>
                    </form>
                </div>
            </div>
            @if(isset($tvCode) && $tvCode)
                <div class="mt-3 flex items-center gap-3 border-t border-white/10 pt-3">
                    <span class="text-sm text-white/60 font-semibold">TV Code:</span>
                    <span class="font-mono text-lg font-black tracking-[0.3em] text-white select-all">{{ $tvCode->code }}</span>
                    <form method="POST" action="{{ route('bm.tv-code.generate') }}" class="inline">
                        @csrf
                        <button type="submit" class="px-2 py-1 text-xs font-semibold rounded border border-white/30 text-white hover:bg-white/10">New Code</button>
                    </form>
                    <form method="POST" action="{{ route('bm.tv-code.revoke') }}" class="inline">
                        @csrf
                        <button type="submit" class="px-2 py-1 text-xs font-semibold rounded border border-white/30 text-white hover:bg-white/10"
                                onclick="return confirm('Revoke this code? TVs using it will stop working.')">Revoke</button>
                    </form>
                </div>
            @else
                <div class="mt-3 flex items-center gap-3 border-t border-white/10 pt-3">
                    <span class="text-sm text-white/60 font-semibold">TV Code:</span>
                    <span class="text-sm text-white/40">No active code</span>
                    <form method="POST" action="{{ route('bm.tv-code.generate') }}" class="inline">
                        @csrf
                        <button type="submit" class="px-2 py-1 text-xs font-semibold rounded border border-white/30 text-white hover:bg-white/10">Generate</button>
                    </form>
                </div>
            @endif
        </div>
    </x-slot>

    <div class="space-y-6">


    {{-- BM_STATUS_TILES --}}

<div class="space-y-3">
    {{-- Set 1: Period (counts) --}}
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

          <a href="/admin/deals?status=Declined&period={{ $r['period'] }}" class="block">
              <div class="ds-status-card ds-status-declined">
                  <div class="ds-label mb-2">Declined</div>
                  <div class="grid grid-cols-2 gap-3">
                      <div>
                          <div class="ds-label">Period</div>
                          <div class="ds-value-lg">{{ $statusSummary['declined_period'] ?? 0 }}</div>
                      </div>
                      <div class="text-right">
                          <div class="ds-label">All time</div>
                          <div class="ds-value-lg" style="opacity:0.6">—</div>
                      </div>
                  </div>
              </div>
          </a>

          <a href="/admin/deals?status=Pending&period={{ $r['period'] }}" class="block">
              <div class="ds-status-card ds-status-pending">
                  <div class="ds-label mb-2">Pending</div>
                  <div class="grid grid-cols-2 gap-3">
                      <div>
                          <div class="ds-label">Period</div>
                          <div class="ds-value-lg">{{ $statusSummary['pending_period'] ?? 0 }}</div>
                      </div>
                      <div class="text-right">
                          <div class="ds-label">All time</div>
                          <div class="ds-value-lg">{{ $statusSummary['pending_total'] ?? 0 }}</div>
                      </div>
                  </div>
              </div>
          </a>

          <a href="/admin/deals?status=Granted&period={{ $r['period'] }}" class="block">
              <div class="ds-status-card ds-status-granted">
                  <div class="ds-label mb-2">Granted</div>
                  <div class="grid grid-cols-2 gap-3">
                      <div>
                          <div class="ds-label">Period</div>
                          <div class="ds-value-lg">{{ $statusSummary['granted_period'] ?? 0 }}</div>
                      </div>
                      <div class="text-right">
                          <div class="ds-label">All time</div>
                          <div class="ds-value-lg">{{ $statusSummary['granted_total'] ?? 0 }}</div>
                      </div>
                  </div>
              </div>
          </a>

          <a href="/admin/deals?status=Registered&period={{ $r['period'] }}" class="block">
              <div class="ds-status-card ds-status-registered">
                  <div class="ds-label mb-2">Registered</div>
                  <div class="grid grid-cols-2 gap-3">
                      <div>
                          <div class="ds-label">Period</div>
                          <div class="ds-value-lg">{{ $statusSummary['registered_period'] ?? 0 }}</div>
                      </div>
                      <div class="text-right">
                          <div class="ds-label">All time</div>
                          <div class="ds-value-lg" style="opacity:0.6">—</div>
                      </div>
                  </div>
              </div>
          </a>

      </div>

{{-- Set 2: Outstanding (Not Paid) — Company ex VAT --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="/admin/deals?status=Pending&commission_status=Not%20Paid" class="block">
            <div class="ds-status-card ds-money-pending">
                <div class="ds-label">Pending (Not Paid) — Company ex VAT</div>
                <div class="ds-value-xl" style="color:#0b2a4a">
                    R {{ number_format((float)($statusSummary['pending_unpaid_company_ex_vat'] ?? 0), 0) }}
                </div>
            </div>
        </a>

        <a href="/admin/deals?status=Granted&commission_status=Not%20Paid" class="block">
            <div class="ds-status-card ds-money-granted">
                <div class="ds-label">Granted (Not Paid) — Company ex VAT</div>
                <div class="ds-value-xl" style="color:#0b2a4a">
                    R {{ number_format((float)($statusSummary['granted_unpaid_company_ex_vat'] ?? 0), 0) }}
                </div>
                <div class="ds-label mt-1">
                    Paid this period: R {{ number_format((float)($statusSummary['granted_paid_company_ex_vat_period'] ?? 0), 0) }}
                </div>
            </div>
        </a>

        <a href="/admin/deals?status=Registered&commission_status=Not%20Paid" class="block">
            <div class="ds-status-card ds-money-registered">
                <div class="ds-label">Registered (Not Paid) — Company ex VAT</div>
                <div class="ds-value-xl" style="color:#0b2a4a">
                    R {{ number_format((float)($statusSummary['registered_unpaid_company_ex_vat'] ?? 0), 0) }}
                </div>
                <div class="ds-label mt-1">
                    Paid this period: R {{ number_format((float)($statusSummary['registered_paid_company_ex_vat_period'] ?? 0), 0) }}
                </div>
            </div>
        </a>
    </div>
</div>




  {{-- Branch Listing Stock (Propcon) --}}
  <div class="ds-section-header">Listing Stock (Branch)</div>
  <div class="ds-section-sub mb-3">Active Propcon listings for this branch. Click a metric to drill in.</div>

  <div class="card">
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
          <a href="{{ route('bm.listings', ['filter' => 'active']) }}" class="ds-status-card hover:shadow-md transition block">
              <div class="ds-label">Active</div>
              <div class="ds-value-lg">{{ (int)($listingStats['total'] ?? 0) }}</div>
          </a>

          <a href="{{ route('bm.listings', ['filter' => 'dom']) }}" class="ds-status-card hover:shadow-md transition block">
              <div class="ds-label">Avg DOM</div>
              <div class="ds-value-lg">{{ (int)($listingStats['avg_days_on_market'] ?? 0) }}</div>
          </a>

          <a href="{{ route('bm.listings', ['filter' => 'stale']) }}" class="ds-status-card hover:shadow-md transition block">
              <div class="ds-label">Stale (14d)</div>
              <div class="ds-value-lg">{{ (int)($listingStats['stale'] ?? 0) }}</div>
          </a>

          <a href="{{ route('bm.listings', ['filter' => 'expiring']) }}" class="ds-status-card hover:shadow-md transition block">
              <div class="ds-label">Expiring (14d)</div>
              <div class="ds-value-lg">{{ (int)($listingStats['expiring_soon'] ?? 0) }}</div>
          </a>

          <a href="{{ route('bm.listings', ['filter' => 'expired']) }}" class="ds-status-card hover:shadow-md transition block">
              <div class="ds-label">Expired</div>
              <div class="ds-value-lg">{{ (int)($listingStats['expired'] ?? 0) }}</div>
          </a>
      </div>
  </div>


{{-- Deal Register averages (selected statuses) --}}
        @php
            $avgCount = (int)($marketAverages['deals_count'] ?? 0);
            $avgSaleInc = (float)($marketAverages['avg_sale_price_inc_vat'] ?? 0);
            $avgSaleEx  = (float)($marketAverages['avg_sale_price_ex_vat'] ?? 0);
            $effCommPct = (float)($marketAverages['effective_commission_percent_ex_vat'] ?? 0);
        @endphp

        <div class="ds-section-header">Deal Register Averages (selected statuses)</div>
        <div class="ds-section-sub mb-3">
            Use these to set smarter planned budgets and planned avg sale prices for agents.
        </div>

        <div class="card">
            <div class="flex items-end justify-between gap-4 flex-wrap mb-4">
                <div class="ds-label">
                    Deals counted: <span class="ds-value">{{ $avgCount }}</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="ds-status-card">
                    <div class="ds-label">Avg Sale Price (Inc VAT)</div>
                    <div class="ds-value-lg">
                        R {{ number_format($avgSaleInc, 0) }}
                    </div>
                    <div class="ds-label mt-1">Ex VAT: R {{ number_format($avgSaleEx, 0) }}</div>
                </div>

                <div class="ds-status-card">
                    <div class="ds-label">Effective Commission % (Ex VAT)</div>
                    <div class="ds-value-lg">
                        {{ number_format($effCommPct, 2) }}%
                    </div>
                    <div class="ds-label mt-1">
                        Derived from Deal Register totals (ex VAT basis).
                    </div>
                </div>

                <div class="ds-status-card">
                    <div class="ds-label">Filter</div>
                    <div class="text-sm text-gray-800 mt-1">
                        Pending: <span class="font-bold">{{ ($stageFilter['pending'] ?? true) ? 'Yes' : 'No' }}</span> •
                        Granted: <span class="font-bold">{{ ($stageFilter['granted'] ?? true) ? 'Yes' : 'No' }}</span> •
                        Registered: <span class="font-bold">{{ ($stageFilter['registered'] ?? true) ? 'Yes' : 'No' }}</span>
                    </div>
                    <div class="ds-label mt-2">
                        Tip: Un-tick Pending if you want "closed/advanced" averages only.
                    </div>
                </div>
            </div>
        </div>




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


        {{-- HERO: Money (branch) --}}
        @php
            $branchValueTarget_agentsum = (float)($r['totals']['targets']['value'] ?? 0);
            $branchDealsTarget_agentsum = (int)($r['totals']['targets']['deals'] ?? 0);

            // Split-correct branch value: must match agent rows (handles cross-branch deals)
            $branchValueActual = 0.0;
            foreach (($r['rows'] ?? []) as $__row) {
                $branchValueActual += (float)($__row['actuals']['value'] ?? $__row['actuals']['sales_value'] ?? 0);
            }
            $branchDealsActual = (int)($r['totals']['actuals']['deals'] ?? $r['totals']['actuals']['deals_count'] ?? 0);

            $valuePct = $branchValueTarget_agentsum > 0 ? (($branchValueActual / $branchValueTarget_agentsum) * 100) : 0;
            $dealsPct = $branchDealsTarget_agentsum > 0 ? (($branchDealsActual / $branchDealsTarget_agentsum) * 100) : 0;

            $valueBar = $valuePct >= 80 ? 'ds-bar-navy' : ($valuePct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
            $dealsBar = $dealsPct >= 80 ? 'ds-bar-navy' : ($dealsPct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');
        @endphp

        <div class="ds-section-header">Branch focus — Money</div>
        <div class="ds-section-sub mb-3">
            Value is priority. Targets below are based on what agents planned for the month (agent target sum).
        </div>

        <div class="card">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="ds-status-card">
                    <div class="ds-label">Branch Value (Actual / Agent-Sum Target)</div>
                    <div class="ds-value-xl leading-tight" style="color:#0b2a4a">
                        R {{ number_format($branchValueActual, 0) }}
                        <span class="text-gray-400 font-bold">/ R {{ number_format($branchValueTarget_agentsum, 0) }}</span>
                    </div>
                    <div class="mt-2 ds-progress-track">
                        <div class="ds-progress-bar {{ $valueBar }}" style="width: {{ min(100, max(0, $valuePct)) }}%"></div>
                    </div>
                    <div class="mt-2 ds-label">Progress {{ number_format($valuePct, 1) }}%</div>
                </div>


                    </div>
                    <div class="mt-2 ds-label">Progress {{ number_format($dealsPct, 1) }}%</div>
                </div>
            </div>
        </div>

        {{-- Branch Budget (BM input) --}}
        @php
            /* BM_BUDGET_TARGETS_READINESS */
            // Magic alignment is only safe when every active branch user has a non-zero VALUE target for this period.
            $missingValueTargets = array_values(array_filter(($r['rows'] ?? []), function ($row) {
                $vt = (float)($row['targets']['value'] ?? 0);
                return $vt <= 0;
            }));
            $missingValueTargetsCount = count($missingValueTargets);
            /* BM_BUDGET_TARGETS_READINESS_END */
        @endphp

        <div class="ds-section-header">Branch Budget (income target)</div>
        <div class="ds-section-sub mb-3">
            Agents set budgets → system derives their targets. This dashboard checks whether the branch budget is achievable based on agent targets.
            <span class="text-gray-500">(Income projection = Agent Value Target Sum × commission rate × company share)</span>
        </div>

        <div class="card">
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div></div>

                <form method="POST" action="{{ route('bm.performance.save') }}" class="flex items-end gap-2 flex-wrap">
                    @csrf
                    <input type="hidden" name="period" value="{{ $r['period'] }}">
                    <div>
                        <div class="ds-label mb-1">Branch budget (R)</div>
                        <input type="number" step="0.01" name="branch_budget" value="{{ $branchBudget }}" class="w-48" min="0">
                    </div>
                    <button class="btn-primary px-5 py-2">Save Budget</button>
                </form>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                <div class="ds-status-card">
                    <div class="ds-label">Branch Budget</div>
                    <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format($branchBudget, 0) }}</div>
                </div>

                <div class="ds-status-card">
                    <div class="ds-label">Projected Income (from agent targets)</div>
                    <div class="ds-value-xl" style="color:#0b2a4a">R {{ number_format($projectedIncome, 0) }}</div>
                    <div class="ds-label mt-1">
                        rate {{ number_format(($b['commission_rate'] ?? 0.05) * 100, 2) }}% × share {{ number_format(($b['company_share'] ?? 0.5) * 100, 0) }}%
                    </div>
                </div>

                <div class="p-4 rounded-2xl border border-black/10 {{ $shortAmount > 0 ? 'bg-red-50' : 'bg-green-50' }}">
                    <div class="ds-label">Status</div>
                    @if($branchBudget > 0 && $shortAmount <= 0)
                        <div class="ds-value-lg text-green-700">On track</div>
                        <div class="text-sm text-gray-700 mt-1">No increases needed.</div>
                    @elseif($branchBudget > 0 && $shortAmount > 0)
                        <div class="ds-value-lg text-red-700">Short by {{ number_format($shortPct, 1) }}%</div>
                        <div class="text-sm text-gray-700 mt-1">
                            Shortfall: <span class="font-bold">R {{ number_format($shortAmount, 0) }}</span>
                        </div>

                        @if(($missingValueTargetsCount ?? 0) > 0)
                            <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3">
                                <div class="text-sm font-extrabold text-amber-900">Targets incomplete — set missing agent targets</div>
                                <div class="text-xs text-amber-800 mt-1">
                                    Some users still have <span class="font-bold">Value target = 0</span> for this period.
                                    Projected Income is not reliable until these are set.
                                </div>

                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    @foreach($missingValueTargets as $u)
                                        <div class="rounded-lg border border-amber-200 bg-white p-3 flex items-center justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-bold text-amber-950">{{ $u['name'] }}</div>
                                                <div class="text-xs text-amber-800">Value target currently: <span class="font-bold">0</span></div>
                                            </div>

                                            <form method="POST" action="{{ route('bm.performance.alignAgentToCompany') }}">
                                                @csrf
                                                <input type="hidden" name="period" value="{{ $r['period'] }}">
                                                <input type="hidden" name="user_id" value="{{ (int)($u['user_id'] ?? 0) }}">
                                                <button class="btn-primary px-4 py-2 whitespace-nowrap">Auto align</button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="text-[11px] text-amber-900 mt-2">
                                    "Set targets" will copy the agent's most recent non-zero targets into this period (safe default), then Projected Income updates immediately.
                                </div>
                            </div>
                        @endif
@elseif($branchBudget > 0 && $shortAmount > 0)
                        <div class="ds-value-lg text-red-700">Short by {{ number_format($shortPct, 1) }}%</div>
                        <div class="text-sm text-gray-700 mt-1">Shortfall: <span class="font-bold">R {{ number_format($shortAmount, 0) }}</span></div>                        @if(($missingValueTargetsCount ?? 0) === 0)
@else
                            <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3">
                                <div class="text-sm font-extrabold text-amber-900">Targets incomplete — Magic disabled</div>
                                <div class="text-xs text-amber-800 mt-1">
                                    One or more users still have <span class="font-bold">Value target = 0</span> for this period.
                                    Set their budgets/targets first so Projected Income is real.
                                </div>
                                <div class="mt-2 text-xs text-amber-900 font-semibold">Missing Value targets:</div>
                                <ul class="mt-1 text-xs text-amber-900">
                                    @foreach($missingValueTargets as $u)
                                        <li>• {{ $u['name'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
<div class="ds-label mt-2">
                            This scales agent targets (listings/deals/value/points) for the period to align with budget.
                        </div>
                    @else
                        <div class="ds-value-lg text-gray-700">Set budget</div>
                        <div class="text-sm text-gray-600 mt-1">Enter branch budget to activate alignment.</div>
                    @endif
                </div>
            </div>
        </div>


                </div>
                    </div>

                </div>
                    </div>

                </div>
            </div>
        </div>

        </div>

        {{-- Excel-style Agents table --}}
        <div class="ds-section-header">Agents (targets vs actuals)</div>
        <div class="ds-section-sub mb-3">This is the management view: who is on pace, who is behind, and where to intervene.</div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                @php
    // BRANCH TOTAL Sales Value must match the agent rows (split-correct).
    // This avoids counting full deal values when cross-branch deals exist.
    $branchTotalSalesValueActual = 0.0;
    foreach (($r['rows'] ?? []) as $__row) {
        $branchTotalSalesValueActual += (float)($__row['actuals']['value'] ?? $__row['actuals']['sales_value'] ?? 0);
    }
@endphp

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
                        {{-- Branch totals row (ties to the top tiles) --}}
                        <tr class="border-t border-black/10 bg-gray-50">
                            <td class="px-4 py-3 font-extrabold">BRANCH TOTAL</td>
                            <td class="px-4 py-3 text-right font-bold">
                                {{ (int)($r['totals']['actuals']['deals'] ?? 0) }} / {{ (int)($r['totals']['targets']['deals'] ?? 0) }}
                            </td>
                            <td class="px-4 py-3 text-right font-bold">
                                R {{ number_format((float)($branchTotalSalesValueActual ?? 0), 0) }}
                                / R {{ number_format((float)($r['totals']['targets']['value'] ?? 0), 0) }}
                            </td>
                            <td class="px-4 py-3 text-right font-bold">
                                {{ number_format((float)($r['totals']['actuals']['points'] ?? 0), 0) }}
                                / {{ number_format((float)($r['totals']['targets']['points'] ?? 0), 0) }}
                            </td>
                            <td class="px-4 py-3 text-right font-extrabold">
                                R {{ number_format((float)($r['totals']['actuals']['team_company_retained'] ?? 0), 0) }}
                                <div class="text-[11px] text-gray-500 font-semibold">
                                    Ledger: R {{ number_format((float)($r['totals']['actuals']['ledger_company_retained'] ?? 0), 0) }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-bold">
                                <span class="ds-badge ds-badge-default">—</span>
                            </td>
                        </tr>

                        @foreach($r['rows'] as $row)
                            @php
                                $pointsTargetRow = (float)($row['targets']['points'] ?? 0);
                                $pointsActualRow = (float)($row['actuals']['points'] ?? 0);
                                $pct = ($pointsTargetRow > 0) ? round(($pointsActualRow/$pointsTargetRow)*100, 1) : 0;
                                $status = (string)($row['progress']['points_status'] ?? '—');

                                $badgeClass = 'ds-badge-default';
                                if ($status === 'Behind') $badgeClass = 'ds-badge-behind';
                                elseif ($status === 'On pace') $badgeClass = 'ds-badge-ontrack';
                                elseif ($status === 'Ahead') $badgeClass = 'ds-badge-ahead';
                                elseif ($status === 'Achieved') $badgeClass = 'ds-badge-achieved';

                                $barClass = $pct >= 80 ? 'ds-bar-navy' : ($pct >= 50 ? 'ds-bar-amber' : 'ds-bar-crimson');

                                $retained = (float)($row['actuals']['company_retained'] ?? 0);
                                $agentIncome = (float)($row['actuals']['agent_income'] ?? 0);
                                $valueActual = (float)($row['actuals']['value'] ?? $row['actuals']['sales_value'] ?? 0);
                                $valueTarget = (float)($row['targets']['value'] ?? 0);
                            @endphp

                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-semibold">
                                        <a class="ds-agent-link"
                                           href="{{ route('bm.agent.performance', ['userId' => $row['user_id'], 'period' => $r['period']]) }}">
                                            {{ $row['name'] }}
                                        </a>
                                    </div>
                                    <div class="ds-label" style="text-transform:none; letter-spacing:normal; font-weight:500">
                                        Per-day needed: {{ number_format((float)($row['progress']['points_per_day_needed'] ?? 0), 1) }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right font-semibold">
                                    {{ (int)($row['actuals']['deals'] ?? 0) }} / {{ (int)($row['targets']['deals'] ?? 0) }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold">
                                    R {{ number_format($valueActual, 0) }} / R {{ number_format($valueTarget, 0) }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold">
                                    {{ number_format($pointsActualRow, 0) }} / {{ number_format($pointsTargetRow, 0) }}
                                    <div class="mt-1 ds-progress-track" style="height:6px">
                                        <div class="ds-progress-bar {{ $barClass }}"
                                             style="width: {{ min(100, max(0, $pct)) }}%"></div>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right font-extrabold">
                                    R {{ number_format($retained, 0) }}
                                    <div class="text-[11px] text-gray-500 font-semibold">
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

        <div class="text-xs text-gray-500">
            Privacy: This page shows derived targets + activity + deal actuals. No worksheet net-income fields are exposed.
        </div>
    </div>
</x-app-layout>
