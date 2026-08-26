@extends('layouts.corex-app')

@section('title', 'Agency Performance & ROI')

@section('corex-content')
@php
    // Shape cc6's rollup for the client-side sort/filter/drilldown component (AT-366 frontend, cc1).
    $branchRows = collect($report['branches'])
        ->map(fn ($b, $k) => ['key' => (string) $k, 'label' => $b['label'], 'metrics' => $b['metrics'], 'deal_status' => $b['deal_status'] ?? null])
        ->values()->all();
    $agentRows  = $report['agents'];
    $metricMeta = $report['metrics'];
    $branchUrlBase = route('performance.agency-report.branch', ['branch' => '__KEY__', 'period' => $preset]);
    $agentUrlBase  = route('performance.agency-report.agent', ['user' => '__UID__', 'period' => $preset]);
    // Custom-range params flow through to the drilldown endpoint (contract §B). Built as a
    // plain path (not route()) so the view never depends on cc6's endpoint being registered
    // yet — the modal degrades to "coming soon" on 404 until cc6 lands the route.
    $drillQuery = array_filter(['period' => $preset, 'start' => request('start'), 'end' => request('end')]);
    $drilldownBase = url('/corex/performance/agency-report/drilldown') . '?' . http_build_query($drillQuery);
    $hasCustomDates = request()->filled('start') || request()->filled('end');
@endphp
<div class="p-4 lg:p-6 space-y-5 max-w-full"
     x-data="agencyReport({
        branches: {{ Illuminate\Support\Js::from($branchRows) }},
        agents: {{ Illuminate\Support\Js::from($agentRows) }},
        metrics: {{ Illuminate\Support\Js::from($metricMeta) }},
        company: {{ Illuminate\Support\Js::from($report['company']) }},
        companyStatus: {{ Illuminate\Support\Js::from($report['company']['deal_status'] ?? null) }},
        comparison: {{ Illuminate\Support\Js::from($comparison) }},
        branchUrlBase: @js($branchUrlBase),
        agentUrlBase: @js($agentUrlBase),
        drilldownBase: @js($drilldownBase),
        currentPreset: @js($preset),
        hasCustomDates: {{ $hasCustomDates ? 'true' : 'false' }},
        defaultUrl: @js(route('performance.agency-report'))
     })">

    {{-- 2026-08-19 (Johan, Phase 2) — "the page should open with the answer, not
         the data." Commission is the agency's actual return (an ROI report is
         about money, not activity volume), so it's the hero figure — same source
         (report.company.commission_gross_ex_vat) as the Company tile below, never
         a new/derived number. Plain-language sentence, arrow + colour + words
         together (never colour alone), same $comparisonMeta['phrase'] used
         everywhere else so the reader is never left to guess what it's compared
         to. print:hidden is NOT used here — this is exactly what should survive
         to print, unlike the toolbar buttons. --}}
    @php
        $heroValue = (float) ($report['company']['commission_gross_ex_vat'] ?? 0);
        $heroComp  = $comparison['company']['commission_gross_ex_vat'] ?? null;
        $compactRand = function (float $v): string {
            $sign = $v < 0 ? '-' : '';
            $v = abs($v);
            if ($v >= 1000000) return $sign . 'R' . rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'm';
            if ($v >= 1000)    return $sign . 'R' . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k';
            return $sign . 'R' . number_format($v);
        };
    @endphp
    <div class="rounded p-4 lg:p-5" style="background:var(--surface-2); border:1px solid var(--border);">
        <p class="text-lg lg:text-xl font-semibold" style="color:var(--text-primary);">
            {{ $report['period']['label'] }}, the agency earned
            <span style="color:var(--brand-icon, #0ea5e9);">{{ $compactRand($heroValue) }}</span>
            in commission
            @if($heroComp && !($heroComp['value'] == 0 && $heroComp['previous'] == 0))
                @php
                    $up = $heroComp['delta'] > 0;
                    $heroClass = $heroComp['good'] === null ? 'report-delta-neutral' : ($heroComp['good'] ? 'report-delta-good' : 'report-delta-bad');
                @endphp
                — <span class="{{ $heroClass }}" style="font-weight:600;">
                    {{ $up ? '▲' : ($heroComp['delta'] < 0 ? '▼' : '') }}
                    {{ $up ? 'up' : ($heroComp['delta'] < 0 ? 'down' : 'flat') }}
                    {{ $heroComp['delta_pct'] !== null ? abs($heroComp['delta_pct']) . '%' : $compactRand(abs($heroComp['delta'])) }}
                </span> {{ $comparisonMeta['phrase'] ?? '' }}
            @endif
            .
        </p>
    </div>

    {{-- STICKY TOP BLOCK — period selector + deal-status toggles stay pinned while the long
         agent list scrolls beneath (fix #2). Everything from here down to (not incl.) Company. --}}
    <div x-ref="topBlock" class="sticky top-0 z-30 -mx-4 lg:-mx-6 px-4 lg:px-6 pt-1 pb-3 space-y-3"
         style="background:var(--bg,#f4f6fb); border-bottom:1px solid var(--border);">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="min-w-0">
                <h1 class="text-lg lg:text-xl font-bold truncate" style="color:var(--text-primary);">Agency Performance &amp; ROI</h1>
                <p class="text-xs" style="color:var(--text-muted);">
                    {{ $report['period']['label'] }} · {{ ucfirst($report['scope']['level']) }} view
                </p>
            </div>

            <div class="flex items-center gap-2 flex-wrap print:hidden">
                {{-- #3 Reset view — back to default period (this month) + clear sort/filter/toggles --}}
                <button type="button" @click="resetView()"
                        class="text-[11px] px-3 py-2 rounded"
                        style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                        title="Reset period, sort, filters and toggles to default">↺ Reset view</button>
                <a href="{{ route('performance.agency-report.print', ['period' => $preset]) }}" target="_blank"
                   class="text-[11px] px-3 py-2 rounded no-underline"
                   style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);"
                   title="Print the whole-company report">🖨 Print report</a>
                {{-- Period selector (cc5-owned partial — DO NOT edit here, only include it) --}}
                @include('performance.agency-report._period-selector', ['preset' => $preset, 'presets' => $presets, 'compareMode' => $compareMode, 'compareModes' => $compareModes])
            </div>
        </div>

        @if(session('period_error'))
            <div class="text-xs px-3 py-2 rounded" style="background:#fee; color:#900;">{{ session('period_error') }}</div>
        @endif
        @if(session('compare_error'))
            <div class="text-xs px-3 py-2 rounded" style="background:#fee; color:#900;">Comparison range: {{ session('compare_error') }}</div>
        @endif
        {{-- 2026-08-19 (Johan, period-comparison) — "unequal-length ranges are stated
             plainly rather than silently proceeding as if the ranges matched." --}}
        @if($comparisonMeta)
            <div class="text-xs px-3 py-2 rounded flex items-center justify-between gap-3 flex-wrap"
                 style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                <span>Comparing to <strong style="color:var(--text-primary);">{{ $comparisonMeta['period']['label'] }}</strong></span>
                @if($comparisonMeta['unequal_length'])
                    <span style="color:var(--ds-amber, #f59e0b);">
                        ⚠ Unequal-length ranges — comparing {{ $comparisonMeta['period_days'] }} days to {{ $comparisonMeta['comparison_days'] }} days. Totals are not like-for-like.
                    </span>
                @endif
            </div>
        @endif

        {{-- #6 DEAL-STATUS TOGGLES — live QTY + VALUE recompute from cc6's per-status data.
             Hidden gracefully until cc6 ships `deal_status` on the rollup (contract §A). --}}
        <div x-show="hasDealStatus" x-cloak class="rounded p-3" style="background:var(--surface-2); border:1px solid var(--border);">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="flex items-center gap-3 flex-wrap" role="group" aria-label="Deal status filter">
                    <span class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Deals</span>
                    <label class="text-[11px] flex items-center gap-1" style="color:var(--text-primary);">
                        <input type="checkbox" @change="toggleAll($event.target.checked)" :checked="allStatusesOn()"> All
                    </label>
                    <template x-for="st in statusKeys" :key="st">
                        <label class="text-[11px] flex items-center gap-1 capitalize" style="color:var(--text-primary);">
                            <input type="checkbox" x-model="statusOn[st]"> <span x-text="st"></span>
                        </label>
                    </template>
                </div>
                <div class="flex items-center gap-6 flex-wrap">
                    <div class="text-right">
                        <div class="text-xl font-bold" style="color:var(--text-primary);" x-text="statusQty().toLocaleString()"></div>
                        <div class="text-[10px]" style="color:var(--text-muted);">deals (selected)</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-bold" style="color:var(--text-primary);" x-text="'R ' + statusValue().toLocaleString()"></div>
                        <div class="text-[10px]" style="color:var(--text-muted);">value (selected)</div>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-bold" style="color:var(--text-primary);" x-text="fmtMoney(statusGrossCommission())"></div>
                        <div class="text-[10px]" style="color:var(--text-muted);">gross commission (selected)</div>
                        <div class="text-[10px] mt-0.5" style="color:var(--text-secondary);">
                            Agent <span x-text="fmtMoney(statusCommission())"></span>
                            &middot; Company <span class="font-semibold" x-text="fmtMoney(statusCompanyCommission())"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Company totals — each card drills into its detail (contract §B) --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">Company</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            @foreach($report['metrics'] as $m)
                @php $c = $comparison['company'][$m['key']] ?? null; @endphp
                <button type="button" @click="drill('{{ $m['key'] }}', 'company', null, @js($m['label']))"
                        class="rounded p-3 text-left" style="background:var(--surface-2); border:1px solid var(--border); cursor:pointer;"
                        title="Click to see the detail">
                    @if($m['key'] === 'commission_gross_ex_vat')
                        @php
                            $companySrv = (float) ($report['company']['commission_gross_ex_vat_company'] ?? 0);
                            $agentSrv   = (float) ($report['company'][$m['key']] ?? 0);
                            $grossSrv   = $agentSrv + $companySrv;
                        @endphp
                        {{-- 2026-08 (company-share refinement) — three numbers, not one: Gross
                             (agent+company), Agent share, and the COMPANY share Johan needs for
                             the meeting. Moves in LOCKSTEP with the deal-status ticks exactly like
                             before: when cc6's per-status data is present (hasDealStatus) all three
                             bind to the SAME reactive selected-deals figures the toggle-bar tile
                             uses, so ticking a status recomputes both tiles together and they can
                             never show two different numbers for the same selection. With no
                             per-status data, falls back to the server-rendered period totals. --}}
                        <div class="text-xl font-bold" style="color:var(--text-primary);"
                             x-text="fmtMoney(hasDealStatus ? statusGrossCommission() : {{ $grossSrv }})">{{ $grossSrv }}</div>
                        <div class="text-[11px] mt-0.5" style="color:var(--text-secondary);">
                            Agent share <span x-text="fmtMoney(hasDealStatus ? statusCommission() : {{ $agentSrv }})">{{ $agentSrv }}</span>
                        </div>
                        <div class="text-[11px]" style="color:var(--text-primary); font-weight:600;">
                            Company share <span x-text="fmtMoney(hasDealStatus ? statusCompanyCommission() : {{ $companySrv }})">{{ $companySrv }}</span>
                        </div>
                    @else
                        <div class="text-xl font-bold" style="color:var(--text-primary);">{{ $report['company'][$m['key']] ?? 0 }}</div>
                    @endif
                    <div class="text-[11px]" style="color:var(--text-muted);">{{ $m['label'] }}</div>
                    <x-performance-delta :c="$c" :phrase="$comparisonMeta['phrase'] ?? ''" :money="$m['key'] === 'commission_gross_ex_vat'" />
                </button>
            @endforeach
        </div>
    </div>

    {{-- AT-366-E — company buyer-activity summary --}}
    @includeWhen(isset($buyer), 'performance.agency-report._buyer-summary')

    {{-- Branch rollup (sortable, drillable) — contained horizontal scroll (fix #1) --}}
    <div>
        <h2 class="text-xs font-bold uppercase tracking-widest mb-2" style="color:var(--text-muted);">By branch</h2>
        {{-- 2026-08-19 (Johan, Phase 2) — "comparing branches wants bars." Single
             axis (Rand), never dual — one metric (commission, matching the hero,
             not a new one) per branch, current vs previous as two fixed-role
             series (never re-coloured by sort/filter — see initBranchChart()).
             Not charted: By agent — same reasoning as branches would apply, but
             at ~30 real agents a bar chart adds noise, not clarity; the table
             (with the same arrow+colour+delta treatment) stays the agent view.
             This is a scoping call, flagged here rather than made silently. --}}
        <div class="corex-chart-container mb-3" style="height:220px; background:var(--surface-2); border:1px solid var(--border); border-radius:6px; padding:0.75rem;">
            <canvas x-ref="branchChart" role="img" aria-label="Commission by branch, current vs comparison period"></canvas>
        </div>
        @if($comparisonMeta)
            {{-- 2026-08-19 (Johan, Phase 2) — a dense table repeating "vs previous
                 period" in every cell would be unreadable; stated ONCE here instead,
                 so a cell's arrow+colour+number is never ambiguous about what it's
                 compared to even though the phrase isn't repeated per cell. --}}
            <p class="text-[10px] mb-1.5" style="color:var(--text-muted);">Δ figures below are {{ $comparisonMeta['phrase'] }}.</p>
        @endif
        <div class="overflow-x-auto rounded max-w-full" style="border:1px solid var(--border);">
            <table class="w-full text-[11px] report-metric-table" style="border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-2 py-1.5 cursor-pointer select-none text-[10px] uppercase tracking-wide" @click="sortBranch('label')" :aria-sort="ariaBranch('label')" style="color:var(--text-muted);">
                            Branch <span x-text="branchArrow('label')"></span>
                        </th>
                        <template x-for="m in metrics" :key="m.key">
                            <th :class="isMoney(m.key) ? 'metric-th metric-money' : 'metric-th metric-qty'" @click="sortBranch(m.key)" :aria-sort="ariaBranch(m.key)">
                                <div :class="isMoney(m.key) ? '' : 'th-rot'"><span x-text="m.label"></span> <span x-text="branchArrow(m.key)"></span></div>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="b in branchDisplay()" :key="b.key">
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-2 py-1.5 whitespace-nowrap">
                                <a :href="branchUrl(b.key)" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="b.label"></a>
                            </td>
                            <template x-for="m in metrics" :key="m.key">
                                <td :class="isMoney(m.key) ? 'num-money' : 'num-qty'"
                                    @click="drill(m.key, 'branch', b.key, m.label + ' — ' + b.label)">
                                    <span x-text="isMoney(m.key) ? fmtMoney(b.metrics[m.key]) : fmt(b.metrics[m.key])"></span>
                                    <template x-if="hasComparison">
                                        <span class="report-delta" :class="deltaClass(compBranch(b.key, m.key))" x-text="fmtDelta(compBranch(b.key, m.key), m.key)"></span>
                                    </template>
                                </td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="branchDisplay().length === 0">
                        <td class="px-2 py-3 text-center" style="color:var(--text-muted);" :colspan="metrics.length + 1">No branches in scope.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Per-agent table with filter toolbar (sortable, drillable) --}}
    <div>
        @if($comparisonMeta)
            <p class="text-[10px] mb-1.5" style="color:var(--text-muted);">Δ figures below are {{ $comparisonMeta['phrase'] }}.</p>
        @endif
        <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
            <h2 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">By agent</h2>
            <div class="flex items-center gap-2 flex-wrap print:hidden" role="group" aria-label="Agent filters">
                <input type="search" x-model="search" placeholder="Filter agent…" aria-label="Filter by agent name"
                       class="text-[11px] px-2 py-1 rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                <select x-model="branchFilter" aria-label="Filter by branch"
                        class="text-[11px] px-2 py-1 rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    <option value="">All branches</option>
                    <template x-for="opt in branchOptions()" :key="opt.id">
                        <option :value="opt.id" x-text="opt.label"></option>
                    </template>
                </select>
                <label class="text-[11px] flex items-center gap-1" style="color:var(--text-muted);">
                    Min activity
                    <input type="number" min="0" x-model.number="minActivity" aria-label="Minimum total activity"
                           class="text-[11px] px-2 py-1 rounded w-16" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </label>
                <button type="button" @click="resetFilters()" x-show="search || branchFilter || minActivity > 0"
                        class="text-[11px] px-2 py-1 rounded" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-muted);">Clear</button>
                <span class="text-[11px]" style="color:var(--text-muted);" x-text="agentDisplay().length + ' / ' + agents.length"></span>
            </div>
        </div>
        {{-- Sticky column headers: the by-agent grid is a bounded box that pins flush BELOW the
             sticky top block (top = the measured --report-top-h CSS var, no magic number) and
             scrolls internally, so the column-header row (sticky top:0 inside the box, theme-aware
             --surface-2 bg) stays visible while the agent rows scroll. Contained horizontal scroll too. --}}
        <style>
            /* Sticky by-agent column headers (pin under the top block) */
            .report-agent-scroll thead th { position: sticky; top: 0; z-index: 10; background: var(--surface-2); }
            /* Column-fit (both tables): narrow, vertically-rotated QTY headers so ~5-digit counts
               fit on a standard desktop width. Identity + money columns stay horizontal + wide. */
            .report-metric-table .metric-th { cursor: pointer; user-select: none; vertical-align: bottom; color: var(--text-muted); font-size: 10px; letter-spacing: .02em; text-transform: uppercase; }
            .report-metric-table .metric-qty { width: 2.5rem; min-width: 2.5rem; padding: .375rem .25rem; text-align: center; }
            .report-metric-table .metric-qty .th-rot { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; display: inline-block; text-align: left; line-height: 1.1; }
            .report-metric-table .metric-money { min-width: 6rem; padding: .375rem .5rem; text-align: right; white-space: normal; }
            .report-metric-table td.num-qty { text-align: right; white-space: nowrap; padding: .375rem .25rem; color: var(--text-primary); cursor: pointer; }
            .report-metric-table td.num-money { text-align: right; white-space: nowrap; padding: .375rem .5rem; color: var(--text-primary); cursor: pointer; font-variant-numeric: tabular-nums; }
            /* 2026-08-19 (Johan, period-comparison) — colour follows the metric's OWN
               declared direction-of-good (PeriodComparison::compute()'s `good` field),
               never the raw sign of the delta. Never invert. */
            .report-delta { display: block; font-size: 9px; line-height: 1.3; font-weight: 500; white-space: nowrap; }
            .report-delta-good { color: var(--ds-green, #059669); }
            .report-delta-bad { color: var(--ds-crimson, #c41e3a); }
            .report-delta-neutral { color: var(--text-muted); }
            /* 2026-08-19 (Johan, Phase 2) — "must print/PDF cleanly... charts must
               render sensibly in print, not vanish or overflow." The interactive
               page has sticky headers and internal overflow:auto scrollers
               (report-agent-scroll, the topBlock) so a long agent list doesn't
               push the page around on screen — exactly the wrong behaviour for
               print, where a scroller just clips everything past one page-height
               to invisible. Print unpins them so the full table flows onto as
               many printed pages as it needs, and gives the chart canvas an
               explicit height (percentage/vh heights collapse to 0 in most
               browsers' print engines). */
            @media print {
                .sticky { position: static !important; }
                .report-agent-scroll { position: static !important; max-height: none !important; overflow: visible !important; }
                .corex-chart-container { height: 220px !important; page-break-inside: avoid; }
                .report-metric-table thead { display: table-header-group; } /* repeat header per printed page where supported */
            }
        </style>
        <div class="report-agent-scroll rounded max-w-full"
             style="border:1px solid var(--border); background:var(--bg,#f4f6fb);
                    position:sticky; top:var(--report-top-h, 0px); z-index:20;
                    max-height:calc(100vh - var(--report-top-h, 0px) - 1.5rem); overflow:auto;">
            <table class="w-full text-[11px] report-metric-table" style="border-collapse:collapse;">
                <thead>
                    <tr style="background:var(--surface-2);">
                        <th class="text-left px-2 py-1.5 cursor-pointer select-none text-[10px] uppercase tracking-wide" @click="sortAgent('name')" :aria-sort="ariaAgent('name')" style="color:var(--text-muted);">
                            Agent <span x-text="agentArrow('name')"></span>
                        </th>
                        <th class="text-left px-2 py-1.5 cursor-pointer select-none text-[10px] uppercase tracking-wide" @click="sortAgent('branch')" :aria-sort="ariaAgent('branch')" style="color:var(--text-muted);">
                            Branch <span x-text="agentArrow('branch')"></span>
                        </th>
                        <template x-for="m in metrics" :key="m.key">
                            <th :class="isMoney(m.key) ? 'metric-th metric-money' : 'metric-th metric-qty'" @click="sortAgent(m.key)" :aria-sort="ariaAgent(m.key)">
                                <div :class="isMoney(m.key) ? '' : 'th-rot'"><span x-text="m.label"></span> <span x-text="agentArrow(m.key)"></span></div>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="a in agentDisplay()" :key="a.user_id">
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-2 py-1.5 whitespace-nowrap">
                                <a :href="agentUrl(a.user_id)" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="a.name"></a>
                            </td>
                            <td class="px-2 py-1.5 whitespace-nowrap" style="color:var(--text-muted);" x-text="a.branch_label"></td>
                            <template x-for="m in metrics" :key="m.key">
                                <td :class="isMoney(m.key) ? 'num-money' : 'num-qty'"
                                    @click="drill(m.key, 'agent', a.user_id, m.label + ' — ' + a.name)">
                                    <span x-text="isMoney(m.key) ? fmtMoney(a.metrics[m.key]) : fmt(a.metrics[m.key])"></span>
                                    <template x-if="hasComparison">
                                        <span class="report-delta" :class="deltaClass(compAgent(a.user_id, m.key))" x-text="fmtDelta(compAgent(a.user_id, m.key), m.key)"></span>
                                    </template>
                                </td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="agentDisplay().length === 0">
                        <td class="px-2 py-3 text-center" style="color:var(--text-muted);" :colspan="metrics.length + 2">No agents match the current filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-[10px]" style="color:var(--text-muted);">
        Agency Performance &amp; ROI — {{ count($report['metrics']) }} metrics rolled up agent → branch → company for the selected period, with point-in-time branch attribution.
    </p>

    {{-- #9 DRILLDOWN MODAL — "click to see". Fetches cc6's endpoint (contract §B) and renders columns+rows. --}}
    <div x-show="drillOpen" x-cloak @keydown.escape.window="closeDrill()"
         class="fixed inset-0 z-50 flex items-start justify-center p-4 md:p-10 print:hidden"
         style="background:rgba(0,0,0,.45);" @click.self="closeDrill()">
        <div class="w-full max-w-4xl rounded shadow-lg max-h-[85vh] overflow-hidden flex flex-col"
             style="background:var(--surface-1, #fff); border:1px solid var(--border);" role="dialog" aria-modal="true" aria-label="Detail">
            <div class="flex items-center justify-between px-4 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);" x-text="drillTitle || 'Detail'"></h3>
                <button type="button" @click="closeDrill()" class="text-lg leading-none px-2" style="color:var(--text-muted);" aria-label="Close">&times;</button>
            </div>
            <div class="overflow-auto p-4">
                <div x-show="drillLoading" class="text-xs py-8 text-center" style="color:var(--text-muted);">Loading…</div>
                <div x-show="!drillLoading && drillError" class="text-xs py-8 text-center" style="color:var(--text-muted);">
                    <span x-text="drillError"></span>
                </div>
                <div x-show="!drillLoading && !drillError && drillRows.length === 0" class="text-xs py-8 text-center" style="color:var(--text-muted);">
                    Nothing to show for this figure in the selected period.
                </div>
                <table x-show="!drillLoading && !drillError && drillRows.length > 0" class="w-full text-xs">
                    <thead>
                        <tr style="background:var(--surface-2);">
                            <template x-for="c in drillColumns" :key="c.key">
                                <th class="px-3 py-2" :class="c.align === 'right' ? 'text-right' : 'text-left'" style="color:var(--text-muted);" x-text="c.label"></th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in drillRows" :key="i">
                            <tr style="border-top:1px solid var(--border);">
                                <template x-for="c in drillColumns" :key="c.key">
                                    <td class="px-3 py-2" :class="c.align === 'right' ? 'text-right' : 'text-left'" style="color:var(--text-primary);">
                                        <template x-if="c.key === drillColumns[0].key && row.href">
                                            <a :href="row.href" class="no-underline" style="color:var(--brand, #3b82f6);" x-text="cell(row, c)"></a>
                                        </template>
                                        <template x-if="!(c.key === drillColumns[0].key && row.href)">
                                            <span x-text="cell(row, c)"></span>
                                        </template>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- 2026-08-19 (Johan, Phase 2) — same Chart.js build already loaded by
     commission/dashboard.blade.php and commission/principal-dashboard.blade.php
     (v4.4.1 via cdnjs) — reusing the established precedent, not a new
     charting dependency. --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
/* AT-366 report frontend (cc1) — sort/filter (#7), status toggles (#6), drilldown (#9),
   sticky header + reset view (layout fixes). Pure presentation over cc6's already-agency-scoped
   rollup. Endpoints per .ai/specs/at366-report-frontend-contract.md; degrades gracefully. */
function agencyReport(cfg) {
    return {
        branches: cfg.branches || [],
        agents: cfg.agents || [],
        metrics: cfg.metrics || [],
        company: cfg.company || {},
        companyStatus: cfg.companyStatus || null,
        // 2026-08-19 (Johan, period-comparison) — comparison is a SEPARATE, parallel
        // structure (ReportPeriodComparator output), never merged into
        // company/branches/agents above, so their existing sort/filter/status-toggle
        // math is completely untouched whether comparison is on or off. Every field
        // read here (value/previous/delta/delta_pct/direction/good) is already fully
        // computed server-side (PeriodComparison::compute) — this file only looks
        // values up and formats them, no math, no direction logic.
        comparison: cfg.comparison || null,
        _compAgentsByUser: null,
        get hasComparison() { return !!this.comparison; },
        compCompany(key) { return this.comparison?.company?.[key] || null; },
        compBranch(branchKey, metricKey) { return this.comparison?.branches?.[branchKey]?.metrics?.[metricKey] || null; },
        compAgent(userId, metricKey) {
            if (!this.comparison) return null;
            if (!this._compAgentsByUser) {
                this._compAgentsByUser = {};
                this.comparison.agents.forEach(a => { this._compAgentsByUser[a.user_id] = a; });
            }
            return this._compAgentsByUser[userId]?.metrics?.[metricKey] || null;
        },
        // key is optional — pass the metric key so money metrics (commission, lost
        // value, deal value) format with 'R ' via the SAME isMoney(key) check the
        // raw-value column already uses, so a metric never renders inconsistently
        // formatted between its value and its own delta.
        // 2026-08-19 (Johan, Phase 2) — arrow reflects the actual numeric
        // direction (up/down); deltaClass() (below) reflects c.good, the
        // metric's OWN declared direction-of-good. "Arrow + colour + label,
        // never colour alone" — this is the arrow half of that pair.
        fmtDelta(c, key) {
            if (!c) return '';
            if (c.value === 0 && c.previous === 0) return '—';
            const arrow = c.delta > 0 ? '▲ ' : (c.delta < 0 ? '▼ ' : '');
            const sign = c.delta > 0 ? '+' : '';
            const abs = key && this.isMoney(key) ? this.fmtMoney(Math.abs(c.delta)) : this.fmt(Math.abs(c.delta));
            const signedAbs = arrow + (c.delta < 0 ? '-' : sign) + abs;
            if (c.delta_pct === null) return signedAbs;
            return signedAbs + ' (' + (c.delta_pct > 0 ? '+' : '') + c.delta_pct + '%)';
        },
        deltaClass(c) {
            if (!c || c.good === null) return 'report-delta-neutral';
            return c.good ? 'report-delta-good' : 'report-delta-bad';
        },
        branchUrlBase: cfg.branchUrlBase,
        agentUrlBase: cfg.agentUrlBase,
        drilldownBase: cfg.drilldownBase,
        currentPreset: cfg.currentPreset || 'this_month',
        hasCustomDates: !!cfg.hasCustomDates,
        defaultUrl: cfg.defaultUrl,

        // Measure the sticky top block's height into a CSS var (--report-top-h) so the by-agent
        // column headers pin flush directly beneath it at every scroll position / viewport /
        // light-or-dark mode — driven by the real measured height, never a brittle magic number.
        init() {
            const root = this.$el;
            const measure = () => {
                const tb = this.$refs.topBlock;
                if (tb && root) root.style.setProperty('--report-top-h', tb.offsetHeight + 'px');
            };
            this.$nextTick(measure);
            if (window.ResizeObserver) {
                this._ro = new ResizeObserver(measure);
                this.$nextTick(() => { if (this.$refs.topBlock) this._ro.observe(this.$refs.topBlock); });
            }
            window.addEventListener('resize', measure);
            this.$nextTick(() => this.initBranchChart());
        },

        // 2026-08-19 (Johan, Phase 2) — "comparing branches wants bars." Reads
        // theme colours live via getComputedStyle, same pattern already
        // established in commission/dashboard.blade.php and
        // commission/principal-dashboard.blade.php — no new convention. Fixed
        // colour PER SERIES ROLE (Current = brand, Previous = neutral border
        // tone), assigned once here and never recomputed from array position,
        // so table sort/filter interactions elsewhere on the page can never
        // repaint this chart's bars. Single axis (Rand) — never dual.
        _branchChart: null,
        initBranchChart() {
            const canvas = this.$refs.branchChart;
            if (!canvas || typeof Chart === 'undefined') return;
            const style = getComputedStyle(document.documentElement);
            const textColor = style.getPropertyValue('--text-muted').trim() || '#9ca3af';
            const gridColor = style.getPropertyValue('--border').trim() || '#e5e7eb';
            const brandIcon = style.getPropertyValue('--brand-icon').trim() || '#0ea5e9';

            // Fixed order: alphabetical by label, independent of the By-branch
            // table's current sort — the chart's bar order never follows the
            // table's interactive sort state.
            const branches = [...this.branches].sort((a, b) => a.label.localeCompare(b.label));
            const labels = branches.map(b => b.label);
            const current = branches.map(b => Number(b.metrics?.commission_gross_ex_vat ?? 0));
            const datasets = [{
                label: 'Current',
                data: current,
                backgroundColor: brandIcon,
                borderRadius: 2,
                maxBarThickness: 28,
            }];
            if (this.hasComparison) {
                const previous = branches.map(b => Number(this.comparison?.branches?.[b.key]?.metrics?.commission_gross_ex_vat?.previous ?? 0));
                datasets.push({
                    label: 'Previous',
                    data: previous,
                    backgroundColor: gridColor,
                    borderRadius: 2,
                    maxBarThickness: 28,
                });
            }

            if (this._branchChart) this._branchChart.destroy();
            if (labels.length === 0) return;
            this._branchChart = new Chart(canvas, {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    // 2px gap between adjacent fills within a group.
                    categoryPercentage: 0.7,
                    barPercentage: 0.9,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: {
                        // Legend always present for 2+ series; a single series (comparison
                        // off) needs none — the section title already names it.
                        legend: {
                            display: this.hasComparison,
                            position: 'top', align: 'end',
                            labels: { color: textColor, font: { size: 10 }, boxWidth: 10, boxHeight: 10, padding: 12 },
                        },
                        tooltip: {
                            callbacks: { label: (ctx) => ctx.dataset.label + ': R ' + Number(ctx.parsed.y).toLocaleString() },
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: textColor, font: { size: 10 } } },
                        y: {
                            beginAtZero: true,
                            grid: { color: gridColor + '40' }, // recessive gridlines
                            border: { display: false },
                            ticks: {
                                color: textColor, font: { size: 10 },
                                callback: v => v >= 1000 ? 'R' + (v / 1000).toFixed(0) + 'k' : 'R' + v,
                            },
                        },
                    },
                },
            });
        },

        // ---- #7 sort / filter ----
        bSort: 'label', bDir: 'asc', aSort: 'name', aDir: 'asc',
        search: '', branchFilter: '', minActivity: 0,

        fmt(v) { return Number(v ?? 0).toLocaleString(); },
        // Money columns (commission / any deal value) stay wide + horizontal; everything else is a
        // narrow rotated qty column. Key-based so it needs no extra flag from cc6's metric metadata.
        isMoney(key) { return /commission|value|gross|amount|revenue|rand|_zar/i.test(key || ''); },
        fmtMoney(v) { return 'R ' + Number(v ?? 0).toLocaleString(); },
        branchUrl(key) { return this.branchUrlBase.replace('__KEY__', encodeURIComponent(key)); },
        agentUrl(id)  { return this.agentUrlBase.replace('__UID__', encodeURIComponent(id)); },
        _val(row, key, labelKey) {
            if (key === labelKey) return (row[labelKey] || '').toString().toLowerCase();
            if (key === 'branch') return (row.branch_label || '').toString().toLowerCase();
            return Number(row.metrics ? (row.metrics[key] ?? 0) : 0);
        },
        _cmp(a, b, key, dir, labelKey) {
            const va = this._val(a, key, labelKey), vb = this._val(b, key, labelKey);
            let r = (typeof va === 'string' || typeof vb === 'string') ? String(va).localeCompare(String(vb)) : va - vb;
            return dir === 'asc' ? r : -r;
        },
        _toggle(cur, key, curDir, textKeys) {
            if (cur === key) return [key, curDir === 'asc' ? 'desc' : 'asc'];
            return [key, textKeys.includes(key) ? 'asc' : 'desc'];
        },
        sortBranch(key) { [this.bSort, this.bDir] = this._toggle(this.bSort, key, this.bDir, ['label']); },
        branchArrow(key) { return this.bSort === key ? (this.bDir === 'asc' ? '▲' : '▼') : ''; },
        ariaBranch(key) { return this.bSort === key ? (this.bDir === 'asc' ? 'ascending' : 'descending') : 'none'; },
        branchDisplay() { return [...this.branches].sort((a, b) => this._cmp(a, b, this.bSort, this.bDir, 'label')); },
        sortAgent(key) { [this.aSort, this.aDir] = this._toggle(this.aSort, key, this.aDir, ['name', 'branch']); },
        agentArrow(key) { return this.aSort === key ? (this.aDir === 'asc' ? '▲' : '▼') : ''; },
        ariaAgent(key) { return this.aSort === key ? (this.aDir === 'asc' ? 'ascending' : 'descending') : 'none'; },
        _agentTotal(a) { return this.metrics.reduce((s, m) => s + Number(a.metrics ? (a.metrics[m.key] ?? 0) : 0), 0); },
        agentFiltered() {
            const q = this.search.trim().toLowerCase(), bf = String(this.branchFilter), min = Number(this.minActivity) || 0;
            return this.agents.filter(a => {
                if (q && !(a.name || '').toLowerCase().includes(q)) return false;
                if (bf !== '' && String(a.branch_id ?? '') !== bf) return false;
                if (min > 0 && this._agentTotal(a) < min) return false;
                return true;
            });
        },
        agentDisplay() { return this.agentFiltered().sort((a, b) => this._cmp(a, b, this.aSort, this.aDir, 'name')); },
        branchOptions() {
            const seen = new Map();
            this.agents.forEach(a => { if (a.branch_id != null && !seen.has(String(a.branch_id))) seen.set(String(a.branch_id), a.branch_label || '—'); });
            return [...seen.entries()].map(([id, label]) => ({ id, label })).sort((x, y) => x.label.localeCompare(y.label));
        },
        resetFilters() { this.search = ''; this.branchFilter = ''; this.minActivity = 0; },

        // ---- #3 reset view: default period (this month) + clear all sort/filter/toggle state ----
        resetView() {
            this.bSort = 'label'; this.bDir = 'asc'; this.aSort = 'name'; this.aDir = 'asc';
            this.resetFilters();
            this.statusKeys.forEach(k => this.statusOn[k] = true);
            this.closeDrill();
            // Default period is "this month". If we're on any other period (or a custom range),
            // reload the report at its default URL so the server-rendered period resets too.
            if (this.currentPreset !== 'this_month' || this.hasCustomDates) {
                window.location.href = this.defaultUrl;
            }
        },

        // ---- #6 deal-status toggles ----
        statusKeys: ['pending', 'granted', 'registered', 'declined'],
        statusOn: { pending: true, granted: true, registered: true, declined: true },
        get hasDealStatus() { return !!this.companyStatus; },
        allStatusesOn() { return this.statusKeys.every(k => this.statusOn[k]); },
        toggleAll(on) { this.statusKeys.forEach(k => this.statusOn[k] = on); },
        statusQty() {
            if (!this.companyStatus) return 0;
            return this.statusKeys.reduce((s, k) => s + (this.statusOn[k] ? Number(this.companyStatus[k]?.qty ?? 0) : 0), 0);
        },
        statusValue() {
            if (!this.companyStatus) return 0;
            return this.statusKeys.reduce((s, k) => s + (this.statusOn[k] ? Number(this.companyStatus[k]?.value ?? 0) : 0), 0);
        },
        statusCommission() {
            if (!this.companyStatus) return 0;
            return this.statusKeys.reduce((s, k) => s + (this.statusOn[k] ? Number(this.companyStatus[k]?.commission ?? 0) : 0), 0);
        },
        // 2026-08 (company-share refinement) — mirrors statusCommission() exactly, but sums
        // company_commission (deal_money_lines.company_gross_ex_vat) instead of the agent's
        // own commission. Same selected-status reduction, so it never double-counts a
        // co-agent deal and always reconciles: statusCommission() + statusCompanyCommission()
        // == statusGrossCommission() for the same selection.
        statusCompanyCommission() {
            if (!this.companyStatus) return 0;
            return this.statusKeys.reduce((s, k) => s + (this.statusOn[k] ? Number(this.companyStatus[k]?.company_commission ?? 0) : 0), 0);
        },
        statusGrossCommission() { return this.statusCommission() + this.statusCompanyCommission(); },

        // ---- #9 drilldown modal ----
        drillOpen: false, drillLoading: false, drillError: '', drillTitle: '',
        drillColumns: [], drillRows: [],
        drill(metric, level, id, title) {
            this.drillOpen = true; this.drillLoading = true; this.drillError = '';
            this.drillTitle = title || metric; this.drillColumns = []; this.drillRows = [];
            const sep = this.drilldownBase.includes('?') ? '&' : '?';
            let url = this.drilldownBase + sep + 'metric=' + encodeURIComponent(metric) + '&level=' + encodeURIComponent(level);
            if (id !== null && id !== undefined && id !== '') url += '&id=' + encodeURIComponent(id);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (!r.ok) throw new Error(r.status === 404 ? 'Detail view is coming soon.' : 'Could not load the detail (' + r.status + ').'); return r.json(); })
                .then(d => { this.drillTitle = d.title || this.drillTitle; this.drillColumns = d.columns || []; this.drillRows = d.rows || []; })
                .catch(e => { this.drillError = e.message || 'Could not load the detail.'; })
                .finally(() => { this.drillLoading = false; });
        },
        closeDrill() { this.drillOpen = false; },
        cell(row, c) {
            const v = row[c.key];
            if (v === null || v === undefined || v === '') return '—';
            if (c.format === 'currency') return 'R ' + Number(v).toLocaleString();
            if (c.format === 'number') return Number(v).toLocaleString();
            return v;
        },
    };
}
</script>
@endsection
