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
        else $pointsBarClass = 'ds-bar-amber';
    }

    // Branch target goal row (BM set)
    $bg = $branchGoal ?? null;
    $branchDeals = (int)($bg?->deals_target ?? 0);
    $branchListings = (int)($bg?->listings_target ?? 0);
    $branchValue = (float)($bg?->value_target ?? 0);

    $sumDeals = (int)($r["totals"]["targets"]["deals"] ?? 0);
    $sumListings = (int)($r["totals"]["targets"]["listings"] ?? 0);
    $sumValue = (float)($r["totals"]["targets"]["value"] ?? 0);

    $b = $budget ?? ['branch_budget'=>0,'projected_income'=>0,'short_amount'=>0,'short_pct'=>0,'commission_rate'=>0.075,'company_share'=>0.5];
    $branchBudget = (float)($b['branch_budget'] ?? 0);
    $projectedIncome = (float)($b['projected_income'] ?? 0);
    $shortAmount = (float)($b['short_amount'] ?? 0);
    $shortPct = (float)($b['short_pct'] ?? 0);


    $stageFilter = $stageFilter ?? ['pending'=>true,'granted'=>true,'registered'=>true];
    $marketAverages = $marketAverages ?? [];

    $bmExpiringCount = \App\Models\DocumentFiling::forBranch(auth()->user()->effectiveBranchId())
        ->expiringSoon(30)->count();
@endphp

@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6">

    {{-- PAGE HEADER (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight tracking-tight">
                    Branch Dashboard — {{ $branchName ?? 'Branch' }}
                </h1>
                <p class="text-sm text-white/60">Branch Manager view (TV-ready) — {{ \Carbon\Carbon::createFromFormat('Y-m', $r['period'])->format('F Y') }}</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="GET" action="{{ route('bm.performance') }}" class="flex items-center gap-2">
                    <input type="month" name="period" value="{{ $r['period'] }}"
                           class="h-9 text-sm rounded-md border border-white/20 bg-white/10 text-white px-2 transition-all duration-300" />
                    <button type="submit"
                            class="px-3 py-1.5 text-sm font-semibold rounded-md bg-white/20 text-white hover:bg-white/30 transition-all duration-300">
                        Go
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- TV CODE CARD --}}
    <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-3 flex-wrap">
                <span class="ds-label">TV Code</span>
                @if(isset($tvCode) && $tvCode)
                    <span class="font-mono text-lg font-bold tracking-[0.3em] select-all" style="color: var(--text-primary);">{{ $tvCode->code }}</span>
                    <span class="ds-badge ds-badge-success">Active</span>
                @else
                    <span class="text-sm" style="color: var(--text-muted);">No active code</span>
                    <span class="ds-badge ds-badge-default">Inactive</span>
                @endif
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if(isset($tvCode) && $tvCode)
                    <form method="POST" action="{{ route('bm.tv-code.generate') }}" class="inline">
                        @csrf
                        <button type="submit" class="corex-btn-outline">New Code</button>
                    </form>
                    <form method="POST" action="{{ route('bm.tv-code.revoke') }}" class="inline"
                          onsubmit="return confirm('Revoke this code? TVs using it will stop working.')">
                        @csrf
                        <button type="submit" class="corex-btn-outline">Revoke</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('bm.tv-code.generate') }}" class="inline">
                        @csrf
                        <button type="submit" class="corex-btn-primary">Generate</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    {{-- EXPIRING MANDATES ALERT (§3.9 alert pattern) --}}
    @if($bmExpiringCount > 0)
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">
                <strong>{{ number_format($bmExpiringCount) }} mandate{{ $bmExpiringCount === 1 ? '' : 's' }} expiring across your branch in the next 30 days.</strong>
            </div>
            <a href="{{ route('filing-register.index', ['status' => 'Expiring']) }}"
               class="text-xs font-semibold whitespace-nowrap" style="color: var(--ds-amber);">View</a>
        </div>
    @endif

    {{-- FLASH MESSAGES (§3.9 alert pattern) --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                 stroke="currentColor" class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">{{ implode(', ', $errors->all()) }}</div>
        </div>
    @endif

    {{-- DEAL STATUS --}}
    <div class="space-y-3">
        <h2 class="ds-section-header">Deal Status</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <a href="/admin/deals?status=Declined&period={{ $r['period'] }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                <div class="ds-status-card ds-status-declined">
                    <div class="ds-label mb-2">Declined</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="ds-label">Period</div>
                            <div class="ds-value-lg">{{ number_format($statusSummary['declined_period'] ?? 0) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="ds-label">All time</div>
                            <div class="ds-value-lg" style="opacity:0.6">—</div>
                        </div>
                    </div>
                </div>
            </a>

            <a href="/admin/deals?status=Pending&period={{ $r['period'] }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                <div class="ds-status-card ds-status-pending">
                    <div class="ds-label mb-2">Pending</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="ds-label">Period</div>
                            <div class="ds-value-lg">{{ number_format($statusSummary['pending_period'] ?? 0) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="ds-label">All time</div>
                            <div class="ds-value-lg">{{ number_format($statusSummary['pending_total'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </a>

            <a href="/admin/deals?status=Granted&period={{ $r['period'] }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                <div class="ds-status-card ds-status-granted">
                    <div class="ds-label mb-2">Granted</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="ds-label">Period</div>
                            <div class="ds-value-lg">{{ number_format($statusSummary['granted_period'] ?? 0) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="ds-label">All time</div>
                            <div class="ds-value-lg">{{ number_format($statusSummary['granted_total'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>
            </a>

            <a href="/admin/deals?status=Registered&period={{ $r['period'] }}&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                <div class="ds-status-card ds-status-registered">
                    <div class="ds-label mb-2">Registered</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <div class="ds-label">Period</div>
                            <div class="ds-value-lg">{{ number_format($statusSummary['registered_period'] ?? 0) }}</div>
                        </div>
                        <div class="text-right">
                            <div class="ds-label">All time</div>
                            <div class="ds-value-lg" style="opacity:0.6">—</div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <h2 class="ds-section-header">Outstanding Commission</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <a href="/admin/deals?status=Pending&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                <div class="ds-status-card ds-money-pending">
                    <div class="ds-label">Pending (Not Paid) — Company ex VAT</div>
                    <div class="ds-value-xl">
                        R {{ number_format((float)($statusSummary['pending_unpaid_company_ex_vat'] ?? 0), 0) }}
                    </div>
                </div>
            </a>

            <a href="/admin/deals?status=Granted&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                <div class="ds-status-card ds-money-granted">
                    <div class="ds-label">Granted (Not Paid) — Company ex VAT</div>
                    <div class="ds-value-xl">
                        R {{ number_format((float)($statusSummary['granted_unpaid_company_ex_vat'] ?? 0), 0) }}
                    </div>
                    <div class="ds-label mt-1">
                        Paid this period: R {{ number_format((float)($statusSummary['granted_paid_company_ex_vat_period'] ?? 0), 0) }}
                    </div>
                </div>
            </a>

            <a href="/admin/deals?status=Registered&commission_status=Not%20Paid&branch_id={{ (int)($r['branch_id'] ?? 0) }}" class="block">
                <div class="ds-status-card ds-money-registered">
                    <div class="ds-label">Registered (Not Paid) — Company ex VAT</div>
                    <div class="ds-value-xl">
                        R {{ number_format((float)($statusSummary['registered_unpaid_company_ex_vat'] ?? 0), 0) }}
                    </div>
                    <div class="ds-label mt-1">
                        Paid this period: R {{ number_format((float)($statusSummary['registered_paid_company_ex_vat_period'] ?? 0), 0) }}
                    </div>
                </div>
            </a>
        </div>
    </div>

    {{-- LISTING STOCK --}}
    <div class="space-y-3">
        <div>
            <h2 class="ds-section-header">Listing Stock (Branch)</h2>
            <div class="ds-section-sub">Active Propcon listings for this branch. Click a metric to drill in.</div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <a href="{{ route('bm.listings', ['filter' => 'active']) }}" class="ds-status-card block">
                <div class="ds-label">Active</div>
                <div class="ds-value-lg">{{ number_format((int)($listingStats['total'] ?? 0)) }}</div>
            </a>

            <a href="{{ route('bm.listings', ['filter' => 'dom']) }}" class="ds-status-card block">
                <div class="ds-label">Avg DOM</div>
                <div class="ds-value-lg">{{ number_format((int)($listingStats['avg_days_on_market'] ?? 0)) }}</div>
            </a>

            <a href="{{ route('bm.listings', ['filter' => 'stale']) }}" class="ds-status-card block">
                <div class="ds-label">Stale (14d)</div>
                <div class="ds-value-lg">{{ number_format((int)($listingStats['stale'] ?? 0)) }}</div>
            </a>

            <a href="{{ route('bm.listings', ['filter' => 'expiring']) }}" class="ds-status-card block">
                <div class="ds-label">Expiring (14d)</div>
                <div class="ds-value-lg">{{ number_format((int)($listingStats['expiring_soon'] ?? 0)) }}</div>
            </a>

            <a href="{{ route('bm.listings', ['filter' => 'expired']) }}" class="ds-status-card block">
                <div class="ds-label">Expired</div>
                <div class="ds-value-lg">{{ number_format((int)($listingStats['expired'] ?? 0)) }}</div>
            </a>
        </div>
    </div>

    {{-- DEAL REGISTER AVERAGES --}}
    @php
        $avgCount = (int)($marketAverages['deals_count'] ?? 0);
        $avgSaleInc = (float)($marketAverages['avg_sale_price_inc_vat'] ?? 0);
        $avgSaleEx  = (float)($marketAverages['avg_sale_price_ex_vat'] ?? 0);
        $effCommPct = (float)($marketAverages['effective_commission_percent_ex_vat'] ?? 0);
    @endphp

    <div class="space-y-3">
        <div>
            <h2 class="ds-section-header">Deal Register Averages (selected statuses)</h2>
            <div class="ds-section-sub">Use these to set smarter planned budgets and planned avg sale prices for agents.</div>
        </div>

        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-end justify-between gap-4 flex-wrap mb-4">
                <div class="ds-label">
                    Deals counted: <span class="ds-value">{{ number_format($avgCount) }}</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="ds-status-card">
                    <div class="ds-label">Avg Sale Price (Inc VAT)</div>
                    <div class="ds-value-lg">R {{ number_format($avgSaleInc, 0) }}</div>
                    <div class="ds-label mt-1">Ex VAT: R {{ number_format($avgSaleEx, 0) }}</div>
                </div>

                <div class="ds-status-card">
                    <div class="ds-label">Effective Commission % (Ex VAT)</div>
                    <div class="ds-value-lg">{{ number_format($effCommPct, 2) }}%</div>
                    <div class="ds-label mt-1">Derived from Deal Register totals (ex VAT basis).</div>
                </div>

                <div class="ds-status-card">
                    <div class="ds-label">Filter</div>
                    <div class="text-sm mt-1" style="color: var(--text-secondary);">
                        Pending: <span class="font-bold">{{ ($stageFilter['pending'] ?? true) ? 'Yes' : 'No' }}</span> &middot;
                        Granted: <span class="font-bold">{{ ($stageFilter['granted'] ?? true) ? 'Yes' : 'No' }}</span> &middot;
                        Registered: <span class="font-bold">{{ ($stageFilter['registered'] ?? true) ? 'Yes' : 'No' }}</span>
                    </div>
                    <div class="ds-label mt-2">Tip: Un-tick Pending if you want "closed/advanced" averages only.</div>
                </div>
            </div>
        </div>
    </div>

    {{-- BRANCH FOCUS — MONEY --}}
    @php
        $branchValueTarget_agentsum = (float)($r['totals']['targets']['value'] ?? 0);
        $branchDealsTarget_agentsum = (int)($r['totals']['targets']['deals'] ?? 0);

        $branchValueActual = 0.0;
        foreach (($r['rows'] ?? []) as $__row) {
            $branchValueActual += (float)($__row['actuals']['value'] ?? $__row['actuals']['sales_value'] ?? 0);
        }
        $branchDealsActual = (int)($r['totals']['actuals']['deals'] ?? $r['totals']['actuals']['deals_count'] ?? 0);

        $valuePct = $branchValueTarget_agentsum > 0 ? (($branchValueActual / $branchValueTarget_agentsum) * 100) : 0;
        $dealsPct = $branchDealsTarget_agentsum > 0 ? (($branchDealsActual / $branchDealsTarget_agentsum) * 100) : 0;

        $valueBar = $valuePct >= 80 ? 'ds-bar-navy' : 'ds-bar-amber';
        $dealsBar = $dealsPct >= 80 ? 'ds-bar-navy' : 'ds-bar-amber';
    @endphp

    <div class="space-y-3">
        <div>
            <h2 class="ds-section-header">Branch focus &mdash; Money</h2>
            <div class="ds-section-sub">Value is priority. Targets below are based on what agents planned for the month (agent target sum).</div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="ds-status-card">
                <div class="ds-label">Branch Value (Actual / Agent-Sum Target)</div>
                <div class="ds-value-xl leading-tight">
                    R {{ number_format($branchValueActual, 0) }}
                    <span class="font-bold" style="color: var(--text-muted);">/ R {{ number_format($branchValueTarget_agentsum, 0) }}</span>
                </div>
                <div class="mt-2 ds-progress-track">
                    <div class="ds-progress-bar {{ $valueBar }}" style="width: {{ min(100, max(0, $valuePct)) }}%"></div>
                </div>
                <div class="mt-2 ds-label">Progress {{ number_format($valuePct, 1) }}%</div>
            </div>

            <div class="ds-status-card">
                <div class="ds-label">Branch Deals (Actual / Agent-Sum Target)</div>
                <div class="ds-value-xl leading-tight">
                    {{ number_format($branchDealsActual) }}
                    <span class="font-bold" style="color: var(--text-muted);">/ {{ number_format($branchDealsTarget_agentsum) }}</span>
                </div>
                <div class="mt-2 ds-progress-track">
                    <div class="ds-progress-bar {{ $dealsBar }}" style="width: {{ min(100, max(0, $dealsPct)) }}%"></div>
                </div>
                <div class="mt-2 ds-label">Progress {{ number_format($dealsPct, 1) }}%</div>
            </div>
        </div>
    </div>

    {{-- BRANCH BUDGET --}}
    @php
        $missingValueTargets = array_values(array_filter(($r['rows'] ?? []), function ($row) {
            $vt = (float)($row['targets']['value'] ?? 0);
            return $vt <= 0;
        }));
        $missingValueTargetsCount = count($missingValueTargets);
    @endphp

    <div class="space-y-3">
        <div>
            <h2 class="ds-section-header">Branch Budget (income target)</h2>
            <div class="ds-section-sub">
                Agents set budgets &rarr; system derives their targets. This dashboard checks whether the branch budget is achievable based on agent targets.
                <span style="color: var(--text-muted);">(Income projection = Agent Value Target Sum &times; commission rate &times; company share)</span>
            </div>
        </div>

        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <form method="POST" action="{{ route('bm.performance.save') }}" class="flex items-end justify-end gap-2 flex-wrap">
                @csrf
                <input type="hidden" name="period" value="{{ $r['period'] }}">
                <div>
                    <label for="branch_budget" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch budget (R)</label>
                    <input id="branch_budget" type="number" step="0.01" name="branch_budget" value="{{ $branchBudget }}"
                           class="w-48 rounded-md text-sm px-3 py-2"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           min="0">
                </div>
                <button type="submit" class="corex-btn-primary">Save Budget</button>
            </form>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="ds-status-card">
                    <div class="ds-label">Branch Budget</div>
                    <div class="ds-value-xl">R {{ number_format($branchBudget, 0) }}</div>
                </div>

                <div class="ds-status-card">
                    <div class="ds-label">Projected Income (from agent targets)</div>
                    <div class="ds-value-xl">R {{ number_format($projectedIncome, 0) }}</div>
                    <div class="ds-label mt-1">
                        rate {{ number_format(($b['commission_rate'] ?? 0.075) * 100, 2) }}% &times; share {{ number_format(($b['company_share'] ?? 0.5) * 100, 0) }}%
                    </div>
                </div>

                <div class="ds-status-card {{ $shortAmount > 0 ? 'ds-status-pending' : 'ds-status-granted' }}">
                    <div class="ds-label">Status</div>
                    @if($branchBudget > 0 && $shortAmount <= 0)
                        <div class="ds-value-lg" style="color: var(--ds-green);">On track</div>
                        <div class="text-sm mt-1" style="color: var(--text-secondary);">No increases needed.</div>
                    @elseif($branchBudget > 0 && $shortAmount > 0)
                        <div class="ds-value-lg" style="color: var(--ds-amber);">Short by {{ number_format($shortPct, 1) }}%</div>
                        <div class="text-sm mt-1" style="color: var(--text-secondary);">
                            Shortfall: <span class="font-bold">R {{ number_format($shortAmount, 0) }}</span>
                        </div>

                        @if($missingValueTargetsCount > 0)
                            <div class="mt-3 rounded-md p-3" style="border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); background: color-mix(in srgb, var(--ds-amber) 10%, transparent);">
                                <div class="text-sm font-bold" style="color: var(--ds-amber);">Targets incomplete &mdash; set missing agent targets</div>
                                <div class="text-xs mt-1" style="color: var(--text-secondary);">
                                    Some users still have <span class="font-bold">Value target = 0</span> for this period.
                                    Projected Income is not reliable until these are set.
                                </div>

                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    @foreach($missingValueTargets as $u)
                                        <div class="rounded-md p-3 flex items-center justify-between gap-3" style="border: 1px solid var(--border); background: var(--surface);">
                                            <div>
                                                <div class="text-sm font-bold" style="color: var(--text-primary);">{{ $u['name'] }}</div>
                                                <div class="text-xs" style="color: var(--text-muted);">Value target currently: <span class="font-bold">0</span></div>
                                            </div>

                                            <form method="POST" action="{{ route('bm.performance.alignAgentToCompany') }}">
                                                @csrf
                                                <input type="hidden" name="period" value="{{ $r['period'] }}">
                                                <input type="hidden" name="user_id" value="{{ (int)($u['user_id'] ?? 0) }}">
                                                <button type="submit" class="corex-btn-primary whitespace-nowrap">Auto align</button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="text-xs mt-2" style="color: var(--text-muted);">
                                    "Set targets" will copy the agent's most recent non-zero targets into this period (safe default), then Projected Income updates immediately.
                                </div>
                            </div>
                        @endif

                        <div class="ds-label mt-2">
                            This scales agent targets (listings/deals/value/points) for the period to align with budget.
                        </div>
                    @else
                        <div class="ds-value-lg" style="color: var(--text-secondary);">Set budget</div>
                        <div class="text-sm mt-1" style="color: var(--text-muted);">Enter branch budget to activate alignment.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- AGENTS TABLE --}}
    @php
        $branchTotalSalesValueActual = 0.0;
        foreach (($r['rows'] ?? []) as $__row) {
            $branchTotalSalesValueActual += (float)($__row['actuals']['value'] ?? $__row['actuals']['sales_value'] ?? 0);
        }
    @endphp

    <div class="space-y-3">
        <div>
            <h2 class="ds-section-header">Agents (targets vs actuals)</h2>
            <div class="ds-section-sub">This is the management view: who is on pace, who is behind, and where to intervene.</div>
        </div>

        <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Deals (A/T)</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Sales Value (A/T)</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Points (A/T)</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Company Retained</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Branch totals row --}}
                        <tr style="background: var(--surface-2); border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-bold" style="color: var(--text-primary);">BRANCH TOTAL</td>
                            <td class="px-4 py-3 text-right font-semibold">
                                {{ number_format((int)($r['totals']['actuals']['deals'] ?? 0)) }} / {{ number_format((int)($r['totals']['targets']['deals'] ?? 0)) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold">
                                R {{ number_format((float)($branchTotalSalesValueActual ?? 0), 0) }}
                                / R {{ number_format((float)($r['totals']['targets']['value'] ?? 0), 0) }}
                            </td>
                            <td class="px-4 py-3 text-right font-semibold">
                                {{ number_format((float)($r['totals']['actuals']['points'] ?? 0), 0) }}
                                / {{ number_format((float)($r['totals']['targets']['points'] ?? 0), 0) }}
                            </td>
                            <td class="px-4 py-3 text-right font-bold">
                                R {{ number_format((float)($r['totals']['actuals']['team_company_retained'] ?? 0), 0) }}
                                <div class="text-xs font-medium" style="color: var(--text-muted);">
                                    Ledger: R {{ number_format((float)($r['totals']['actuals']['ledger_company_retained'] ?? 0), 0) }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <span class="ds-badge ds-badge-default">&mdash;</span>
                            </td>
                        </tr>

                        @forelse($r['rows'] ?? [] as $row)
                            @php
                                $pointsTargetRow = (float)($row['targets']['points'] ?? 0);
                                $pointsActualRow = (float)($row['actuals']['points'] ?? 0);
                                $pct = ($pointsTargetRow > 0) ? round(($pointsActualRow/$pointsTargetRow)*100, 1) : 0;
                                $status = (string)($row['progress']['points_status'] ?? '—');

                                $badgeClass = 'ds-badge-default';
                                if ($status === 'Behind') $badgeClass = 'ds-badge-warning';
                                elseif ($status === 'On pace') $badgeClass = 'ds-badge-success';
                                elseif ($status === 'Ahead') $badgeClass = 'ds-badge-info';
                                elseif ($status === 'Achieved') $badgeClass = 'ds-badge-success';

                                $barClass = $pct >= 80 ? 'ds-bar-navy' : 'ds-bar-amber';

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
                                        @if(!empty($row['is_archived']))
                                            <span class="ml-1 inline-block px-1.5 py-0.5 rounded text-[10px] font-semibold align-middle"
                                                  style="background: var(--surface-muted, #f1f5f9); color: var(--text-muted, #64748b);"
                                                  title="This team member has been archived. Their commission from this period still counts toward the branch total.">Archived</span>
                                        @endif
                                    </div>
                                    <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                        Per-day needed: {{ number_format((float)($row['progress']['points_per_day_needed'] ?? 0), 1) }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right font-semibold">
                                    {{ number_format((int)($row['actuals']['deals'] ?? 0)) }} / {{ number_format((int)($row['targets']['deals'] ?? 0)) }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold">
                                    R {{ number_format($valueActual, 0) }} / R {{ number_format($valueTarget, 0) }}
                                </td>

                                <td class="px-4 py-3 text-right font-semibold">
                                    {{ number_format($pointsActualRow, 0) }} / {{ number_format($pointsTargetRow, 0) }}
                                    <div class="mt-1 ds-progress-track">
                                        <div class="ds-progress-bar {{ $barClass }}"
                                             style="width: {{ min(100, max(0, $pct)) }}%"></div>
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right font-bold">
                                    R {{ number_format($retained, 0) }}
                                    <div class="text-xs font-medium" style="color: var(--text-muted);">
                                        Agent: R {{ number_format($agentIncome, 0) }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <span class="ds-badge {{ $badgeClass }}">{{ $status }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                                    No agents in this branch for the selected period.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="text-xs" style="color: var(--text-muted);">
        Privacy: This page shows derived targets + activity + deal actuals. No worksheet net-income fields are exposed.
    </div>
</div>
@endsection
