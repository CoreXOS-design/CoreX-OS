@extends('layouts.corex-app')

@section('title', 'Buyers Report')

@section('corex-content')
@php
    // 2026-08-20 (Johan) — first pass: Needs Attention list + tiles + per-agent
    // table, real data throughout. Second pass (same day): tile-click
    // drill-down, mirroring the ROI report's #9 modal (contract: {title,
    // total, columns, rows}, fetched from BuyersReportController::drilldown()
    // — never a page navigation). Agent/branch dedicated pages and period
    // comparison follow.
    $m = $report['company'];
    $stateLabel = fn ($s) => match ($s) { 'warm' => 'Hot', 'new' => 'New', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won', default => ucfirst((string) $s) };
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $drilldownBase = url('/corex/buyers-report/drilldown') . '?' . http_build_query([
        'scope' => $scope->level, 'branch_id' => $scope->branchId, 'user_id' => $scope->userId, 'period' => $preset,
    ]);
@endphp

<div class="max-w-6xl mx-auto px-4 py-6" x-data="buyersReport({ drilldownBase: @js($drilldownBase) })">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold" style="color: var(--text-primary);">Buyers Report</h1>
            <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                {{ match($scope->level) { 'own' => 'Your buyers', 'branch' => 'Your branch', default => 'Whole agency' } }}
                · {{ ucfirst(str_replace('_', ' ', $preset)) }}
            </p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <select name="period" onchange="this.form.submit()" class="text-xs rounded-md px-2 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @foreach($presets as $p)
                    <option value="{{ $p }}" @selected($preset === $p)>{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- ══════ NEEDS ATTENTION — problems first ══════ --}}
    <div class="rounded-md mb-8" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Needs Attention</h2>
        </div>

        {{-- Group 1: cold/lost buyers, longest-stuck first --}}
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Cold &amp; Lost buyers — longest-stuck first</div>
            @if(empty($attention['attention']))
                <p class="text-xs" style="color: var(--text-muted);">Nothing here right now.</p>
            @else
                <div class="space-y-1">
                    @foreach($attention['attention'] as $row)
                        <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                            <span>
                                <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                                <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }}</span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span class="ds-badge {{ $row['state'] === 'lost' ? 'ds-badge-danger' : 'ds-badge-warning' }}">{{ $stateLabel($row['state']) }}</span>
                                <span style="color: var(--text-muted);">{{ $row['days_in_state'] !== null ? $row['days_in_state'] . 'd' : '—' }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Group 2: manually parked — separate, so it's not mistaken for neglect --}}
        @if(!empty($attention['parked']))
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Parked on purpose — not neglected</div>
            <div class="space-y-1">
                @foreach($attention['parked'] as $row)
                    <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2); opacity: 0.8;">
                        <span>
                            <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                            <span style="color: var(--text-muted);"> — Parked by {{ $row['agent_name'] }}</span>
                        </span>
                        <span style="color: var(--text-muted);">{{ $row['days_in_state'] !== null ? $row['days_in_state'] . 'd' : '—' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Group 3: viewings held with no feedback captured --}}
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Viewed, no feedback captured</div>
            @if(empty($attention['no_feedback']))
                <p class="text-xs" style="color: var(--text-muted);">Nothing here right now.</p>
            @else
                <div class="space-y-1">
                    @foreach($attention['no_feedback'] as $row)
                        <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                            <span>
                                <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                                <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }}</span>
                            </span>
                            <span style="color: var(--text-muted);">{{ $row['days_ago'] }}d ago</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Group 4: recent losses, reason + value --}}
        <div class="px-4 py-3">
            <div class="text-xs font-medium mb-2" style="color: var(--text-muted);">Recently Lost — reason &amp; value</div>
            @if(empty($attention['recent_losses']))
                <p class="text-xs" style="color: var(--text-muted);">No losses recorded for this cohort.</p>
            @else
                <div class="space-y-1">
                    @foreach($attention['recent_losses'] as $row)
                        <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-md" style="background: var(--surface-2);">
                            <span>
                                <span class="font-medium" style="color: var(--text-primary);">{{ $row['name'] }}</span>
                                <span style="color: var(--text-muted);"> — {{ $row['agent_name'] }} · {{ $row['reason'] }}</span>
                            </span>
                            <span style="color: var(--text-muted);">{{ $row['value'] > 0 ? $money($row['value']) : '—' }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ══════ THE NUMBERS ══════ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        @foreach([
            ['buyers', 'Buyers held'],
            ['buyers_added', 'Buyers added'],
            ['buyers_won', 'Buyers won'],
            ['appointments', 'Appointments'],
            ['comms_email', 'Emails'],
            ['comms_whatsapp', 'WhatsApps'],
            ['lost', 'Buyers lost'],
            ['lost_value', 'Value lost'],
        ] as [$key, $label])
            @php $drillMetric = $key === 'lost_value' ? 'lost' : $key; @endphp
            <button type="button" @click="drill('{{ $drillMetric }}', @js($label))"
                    class="rounded-md px-3 py-3 text-left" style="background: var(--surface); border: 1px solid var(--border); cursor: pointer;"
                    title="Click to see the detail">
                <div class="text-[11px]" style="color: var(--text-muted);">{{ $label }}</div>
                <div class="text-lg font-semibold mt-0.5" style="color: var(--text-primary);">
                    {{ $key === 'lost_value' ? $money($m[$key] ?? 0) : number_format((float) ($m[$key] ?? 0)) }}
                </div>
            </button>
        @endforeach
    </div>
    <p class="text-[11px] mb-6" style="color: var(--text-muted);">
        Emails/WhatsApps are a floor, not a true count — only messages sent through a connected device or mailbox are captured.
    </p>

    {{-- ══════ PER-AGENT TABLE ══════ --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-4 py-3" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">By agent</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr style="color: var(--text-muted); border-bottom: 1px solid var(--border);">
                        <th class="text-left px-3 py-2">Agent</th>
                        <th class="text-left px-3 py-2">Branch</th>
                        <th class="text-right px-3 py-2">Held</th>
                        <th class="text-right px-3 py-2">Added</th>
                        <th class="text-right px-3 py-2">Won</th>
                        <th class="text-right px-3 py-2">Appts</th>
                        <th class="text-right px-3 py-2">Lost</th>
                        <th class="text-right px-3 py-2">Value lost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report['agents'] as $a)
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td class="px-3 py-2 font-medium" style="color: var(--text-primary);">{{ $a['name'] }}</td>
                            <td class="px-3 py-2" style="color: var(--text-muted);">{{ $a['branch_label'] }}</td>
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('buyers', @js($a['name'] . ' — Buyers held'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['buyers'] ?? 0 }}</button></td>
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('buyers_added', @js($a['name'] . ' — Buyers added'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['buyers_added'] ?? 0 }}</button></td>
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('buyers_won', @js($a['name'] . ' — Buyers won'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['buyers_won'] ?? 0 }}</button></td>
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('appointments', @js($a['name'] . ' — Appointments'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['appointments'] ?? 0 }}</button></td>
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lost', @js($a['name'] . ' — Buyers lost'), {{ (int) $a['user_id'] }})">{{ $a['metrics']['lost'] ?? 0 }}</button></td>
                            <td class="px-3 py-2 text-right"><button type="button" class="underline" @click="drill('lost', @js($a['name'] . ' — Buyers lost'), {{ (int) $a['user_id'] }})">{{ $money($a['metrics']['lost_value'] ?? 0) }}</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center" style="color: var(--text-muted);">No agents in this scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- DRILLDOWN MODAL — click to see. Fetches BuyersReportController::drilldown()
         and renders columns+rows. Mirrors the ROI report's #9 modal. --}}
    <div x-show="drillOpen" x-cloak @keydown.escape.window="closeDrill()"
         class="fixed inset-0 z-50 flex items-start justify-center p-4 md:p-10 print:hidden"
         style="background:rgba(0,0,0,.45);" @click.self="closeDrill()">
        <div class="w-full max-w-4xl rounded shadow-lg max-h-[85vh] overflow-hidden flex flex-col"
             style="background: var(--surface-1, #fff); border:1px solid var(--border);" role="dialog" aria-modal="true" aria-label="Detail">
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
                                    <td class="px-3 py-2" :class="c.align === 'right' ? 'text-right' : 'text-left'" style="color:var(--text-primary);" x-text="cell(row, c)"></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function buyersReport(cfg) {
    return {
        drilldownBase: cfg.drilldownBase,
        drillOpen: false, drillLoading: false, drillError: '', drillTitle: '',
        drillColumns: [], drillRows: [],
        drill(metric, title, agentId) {
            this.drillOpen = true; this.drillLoading = true; this.drillError = '';
            this.drillTitle = title || metric; this.drillColumns = []; this.drillRows = [];
            const sep = this.drilldownBase.includes('?') ? '&' : '?';
            let url = this.drilldownBase + sep + 'metric=' + encodeURIComponent(metric);
            if (agentId !== null && agentId !== undefined && agentId !== '') url += '&agent_id=' + encodeURIComponent(agentId);
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => { if (!r.ok) throw new Error('Could not load the detail (' + r.status + ').'); return r.json(); })
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
