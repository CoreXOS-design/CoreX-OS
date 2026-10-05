@extends('layouts.corex')

{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20

    .ai/specs/rentals-reports.md — AT-443. One screen: a report picker on the
    left, the chosen report on the right, every report sharing one shell
    (period/scope/status-ticks/group-by/search/sort/per-page/print/PDF/export)
    per the master spec §1.1. No Alpine on this screen (plain GET forms, same
    choice RentalCommandCentreController's screen made) — every control is a
    real link or a real form submit, nothing client-rendered.
--}}

@php
    $humanise = fn ($value) => $value ? ucwords(str_replace('_', ' ', (string) $value)) : '—';
    $baseParams = fn (array $overrides = []) => array_merge(request()->except('page'), $overrides);
    $sortLink = fn ($col) => route('corex.rentals.reports.index', $baseParams(['sort' => $col, 'direction' => ($params['sort'] === $col && $params['direction'] === 'asc') ? 'desc' : 'asc']));
    $sortIndicator = fn ($col) => $params['sort'] === $col ? ($params['direction'] === 'asc' ? ' ▲' : ' ▼') : '';
    $forwardPeriodReports = ['lease-expiries'];
    $noShellReports = []; // every built report uses the shared shell
    // 'total_' prefix added for the job cards report's cost/VAT columns
    // (total_cost, total_excl, total_vat, total_incl) — rand figures that
    // don't contain 'amount' in their key.
    $isMoneyCol = fn ($k) => str_contains($k, 'amount') || $k === 'rent' || str_starts_with($k, 'total_');
    $groupByOptions = match($reportKey) {
        'fault-reports' => ['property' => 'Property', 'landlord' => 'Landlord', 'agent' => 'Agent', 'fault_type' => 'Fault type'],
        'work-orders' => ['property' => 'Property', 'supplier' => 'Supplier', 'trade' => 'Trade'],
        'job-cards' => ['crew' => 'Crew member', 'property' => 'Property'],
        'lease-status' => ['branch' => 'Branch', 'agent' => 'Agent'],
        'lease-expiries' => ['month' => 'Month of expiry'],
        'inspections' => ['property' => 'Property', 'type' => 'Type', 'agent' => 'Inspecting agent'],
        default => [],
    };
@endphp

@section('content')
<div class="p-6 space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <h1 class="text-lg font-semibold">Rental Reports</h1>
        <div class="flex items-center gap-3">
            <a href="{{ route('corex.rentals.reports.print', request()->query()) }}" target="_blank" class="corex-btn-outline text-xs">Print</a>
            <a href="{{ route('corex.rentals.reports.pdf', request()->query()) }}" class="corex-btn-outline text-xs">PDF</a>
            <a href="{{ route('corex.rentals.reports.export', array_merge(request()->query(), ['format' => 'xlsx'])) }}" class="corex-btn-outline text-xs">Export XLSX</a>
            <a href="{{ route('corex.rentals.reports.export', array_merge(request()->query(), ['format' => 'csv'])) }}" class="corex-btn-outline text-xs">Export CSV</a>
        </div>
    </div>

    <div class="flex gap-4 items-start">
        {{-- Report picker --}}
        <div class="w-48 flex-shrink-0 rounded-md" style="background: var(--surface); border: 1px solid var(--border);">
            @foreach($reports as $key => $label)
            <a href="{{ route('corex.rentals.reports.index', ['report' => $key]) }}"
               class="block px-3 py-2.5 text-sm no-underline"
               style="border-bottom: 1px solid var(--border); {{ $reportKey === $key ? 'background:color-mix(in srgb, var(--brand-icon,#6366f1) 10%, var(--surface)); font-weight:600;' : 'color: var(--text-muted);' }}">{{ $label }}</a>
            @endforeach
            <a href="{{ route('corex.rentals.reports.property-history') }}"
               class="block px-3 py-2.5 text-sm no-underline" style="color: var(--text-muted);">Property history ↗</a>
        </div>

        {{-- Chosen report --}}
        <div class="flex-1 min-w-0 space-y-3">
            <form method="GET" action="{{ route('corex.rentals.reports.index') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="report" value="{{ $reportKey }}">

                @if(count($scopeOptions) > 1)
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Showing</label><br>
                    <div class="inline-flex rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                        @foreach($scopeOptions as $i => $sc)
                        <a href="{{ route('corex.rentals.reports.index', $baseParams(['scope' => $sc])) }}"
                           class="px-3 py-1.5 text-xs font-semibold"
                           style="{{ $i > 0 ? 'border-left: 1px solid var(--border);' : '' }} {{ ($params['scope'] ?? $scopeOptions[0]) === $sc ? 'background: var(--brand-icon, #0ea5e9); color: #fff;' : 'background: var(--surface); color: var(--text-muted);' }}">{{ ucfirst($sc) }}</a>
                        @endforeach
                    </div>
                </div>
                @endif

                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Period</label><br>
                    <select name="period" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        @if(in_array($reportKey, $forwardPeriodReports, true))
                            @foreach(['next_30' => 'Next 30 days', 'next_60' => 'Next 60 days', 'next_90' => 'Next 90 days', 'this_month' => 'This month', 'custom' => 'Custom range', 'any' => 'Any period'] as $p => $l)
                                <option value="{{ $p }}" @selected(($params['period'] ?? 'next_30') === $p)>{{ $l }}</option>
                            @endforeach
                        @else
                            @foreach(['this_month' => 'This month', 'last_month' => 'Last month', 'last_30' => 'Last 30 days', 'last_60' => 'Last 60 days', 'last_90' => 'Last 90 days', 'this_year' => 'This year', 'custom' => 'Custom range', 'any' => 'Any period'] as $p => $l)
                                <option value="{{ $p }}" @selected(($params['period'] ?? 'this_month') === $p)>{{ $l }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">From</label><br>
                    <input type="date" name="date_from" value="{{ $params['date_from'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">To</label><br>
                    <input type="date" name="date_to" value="{{ $params['date_to'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                </div>

                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Search</label><br>
                    <input type="text" name="q" value="{{ $params['q'] ?? '' }}" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                </div>

                @if(!empty($groupByOptions))
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Group by</label><br>
                    <select name="group_by" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        <option value="">None</option>
                        @foreach($groupByOptions as $g => $l)
                            <option value="{{ $g }}" @selected(($params['group_by'] ?? '') === $g)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                @if($reportKey === 'work-orders')
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Trade</label><br>
                    <input type="text" name="trade_type" value="{{ $params['trade_type'] ?? '' }}" placeholder="e.g. plumber" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                </div>
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Done by</label><br>
                    <select name="done_by" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        <option value="">Any</option>
                        <option value="supplier" @selected(($params['done_by'] ?? '') === 'supplier')>Supplier</option>
                        <option value="own_team" @selected(($params['done_by'] ?? '') === 'own_team')>Own team</option>
                    </select>
                </div>
                @endif

                @if($reportKey === 'lease-status')
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Agent</label><br>
                    <select name="agent_id" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        <option value="">All</option>
                        @foreach($agents as $a)
                            <option value="{{ $a->id }}" @selected((string) ($params['agent_id'] ?? '') === (string) $a->id)>{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Branch</label><br>
                    <select name="branch_id" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        <option value="">All</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((string) ($params['branch_id'] ?? '') === (string) $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                @if($reportKey === 'inspections')
                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Type</label><br>
                    <select name="type" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        <option value="">All</option>
                        <option value="in" @selected(($params['type'] ?? '') === 'in')>In</option>
                        <option value="out" @selected(($params['type'] ?? '') === 'out')>Out</option>
                        <option value="ad_hoc" @selected(($params['type'] ?? '') === 'ad_hoc')>Ad hoc</option>
                    </select>
                </div>
                @endif

                <div>
                    <label class="text-xs" style="color: var(--text-muted);">Per page</label><br>
                    <select name="per_page" onchange="this.form.submit()" class="rounded-md px-3 py-2 text-xs" style="border: 1px solid var(--border);">
                        @foreach([10, 25, 50, 100] as $pp)
                            <option value="{{ $pp }}" @selected($result['paginated']->perPage() === $pp)>{{ $pp }}</option>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="corex-btn-outline text-xs">Filter</button>
                <a href="{{ route('corex.rentals.reports.index', ['report' => $reportKey]) }}" class="corex-btn-outline text-xs">Clear</a>
            </form>

            @if(!empty($result['buckets']))
            <form method="GET" action="{{ route('corex.rentals.reports.index') }}" class="flex flex-wrap items-center gap-3 text-xs">
                <input type="hidden" name="report" value="{{ $reportKey }}">
                @foreach(request()->except(['buckets', 'page']) as $k => $v)
                    @if(is_array($v))
                        @foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach
                    @else
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endif
                @endforeach
                <span style="color: var(--text-muted);">Include:</span>
                @foreach($result['buckets'] as $bk => $bucket)
                <label class="inline-flex items-center gap-1">
                    <input type="checkbox" name="buckets[]" value="{{ $bk }}" onchange="this.form.submit()" @checked(in_array($bk, $result['selectedBuckets'], true))>
                    {{ is_array($bucket) ? $bucket['label'] : $bucket }}
                </label>
                @endforeach
            </form>
            @endif

            {{-- Result grid --}}
            <div class="rounded-md overflow-x-auto" style="background: var(--surface); border: 1px solid var(--border);">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border);">
                            @foreach($result['columns'] as $colKey => $colLabel)
                            <th class="text-left px-4 py-2"><a href="{{ $sortLink($colKey) }}" style="color: var(--text-muted);">{{ $colLabel }}{{ $sortIndicator($colKey) }}</a></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($result['paginated'] as $row)
                            @if($loop->first || $row['_group'] !== ($prevGroup ?? null))
                                @if(!empty($row['_group']))
                                <tr style="background: var(--bg-subtle, #f8fafc);"><td colspan="{{ count($result['columns']) }}" class="px-4 py-1.5 text-xs font-semibold">{{ $row['_group'] }} ({{ $result['groups'][$row['_group']] ?? '' }})</td></tr>
                                @endif
                            @endif
                            @php($prevGroup = $row['_group'])
                            <tr style="border-bottom: 1px solid var(--border);">
                                @foreach($result['columns'] as $colKey => $colLabel)
                                <td class="px-4 py-2">{{ (is_numeric($row[$colKey] ?? null) && $isMoneyCol($colKey)) ? 'R ' . number_format((float) $row[$colKey], 2) : ($row[$colKey] ?? '—') }}</td>
                                @endforeach
                            </tr>
                        @empty
                        <tr><td colspan="{{ count($result['columns']) }}" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">
                            No {{ strtolower($reports[$reportKey]) }} in this period for this scope.
                        </td></tr>
                        @endforelse
                    </tbody>
                    @if($result['count'] > 0 && !empty($result['sumKeys']))
                    <tfoot>
                        <tr style="border-top: 2px solid var(--border); font-weight:600;">
                            @foreach($result['columns'] as $colKey => $colLabel)
                                @if($loop->first)
                                    <td class="px-4 py-2">Total ({{ $result['count'] }})</td>
                                @elseif(in_array($colKey, $result['sumKeys'], true))
                                    @php($sum = $result['rows']->sum(fn($r) => (float) ($r[$colKey] ?? 0)))
                                    <td class="px-4 py-2">{{ $isMoneyCol($colKey) ? 'R ' . number_format($sum, 2) : number_format($sum, 0) }}</td>
                                @else
                                    <td class="px-4 py-2"></td>
                                @endif
                            @endforeach
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>

            {{ $result['paginated']->links() }}
        </div>
    </div>
</div>
@endsection
