<x-app-layout>

<x-slot name="header">
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold text-white leading-tight">Worksheet Market &mdash; Company</h2>
                <div class="text-sm text-white/60">Set planned average sale price per agent</div>
            </div>
            <div class="flex items-center gap-2">
                <form method="GET" class="flex items-center gap-2">
                    <input type="month" name="period" value="{{ $period }}" class="h-8 text-sm rounded border border-white/20 bg-white/10 text-white px-2" />
                    <button type="submit" class="px-3 py-1.5 text-sm font-semibold rounded bg-white/20 text-white hover:bg-white/30">Go</button>
                </form>
            </div>
        </div>
    </div>
</x-slot>

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

    @if (session('status'))
        <div class="mb-4 p-3 rounded-lg bg-green-100 text-green-800 font-medium text-sm">
            {{ session('status') }}
        </div>
    @endif

    @php
        $aw = $avgWindow ?? 'period';
        $sf = $stageFilter ?? ['pending'=>true,'granted'=>true,'registered'=>true];
        $mb = $marketByBranch ?? [];
        $amb = $agentMarketByBranch ?? [];
    @endphp

    {{-- Filters --}}
    <div class="ds-status-card" style="border-left-color: var(--ds-cyan);">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
            <div>
                <h3 class="ds-section-header" style="margin-bottom:0;">Deal Register Market Averages (per branch)</h3>
                <div class="text-xs text-gray-600 mt-1">
                    Window + stage filters apply.
                    @if(!empty($dateFrom) && !empty($dateTo))
                        <span class="ml-2"><b>Window:</b> {{ $dateFrom }} &rarr; {{ $dateTo }}</span>
                    @endif
                </div>
            </div>

            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <input type="hidden" name="period" value="{{ $period }}" />
                <div>
                    <label class="ds-label block mb-1">Window</label>
                    <select name="avg_window" class="border rounded-lg p-2 text-sm">
                        <option value="period" {{ $aw==='period'?'selected':'' }}>This month</option>
                        <option value="3m" {{ $aw==='3m'?'selected':'' }}>Last 3 months</option>
                        <option value="6m" {{ $aw==='6m'?'selected':'' }}>Last 6 months</option>
                        <option value="all" {{ $aw==='all'?'selected':'' }}>All time</option>
                    </select>
                </div>

                <div class="flex gap-3">
                    <label class="text-sm flex items-center gap-2">
                        <input type="checkbox" name="st_pending" value="1" {{ !empty($sf['pending'])?'checked':'' }}> Pending
                    </label>
                    <label class="text-sm flex items-center gap-2">
                        <input type="checkbox" name="st_granted" value="1" {{ !empty($sf['granted'])?'checked':'' }}> Granted
                    </label>
                    <label class="text-sm flex items-center gap-2">
                        <input type="checkbox" name="st_registered" value="1" {{ !empty($sf['registered'])?'checked':'' }}> Registered
                    </label>
                </div>

                <button class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:#0b2a4a;">Apply</button>
            </form>
        </div>
    </div>

    {{-- Branch Sections --}}
    @php
        $agentsByBranch = $agents->groupBy('branch_id');
        $bm = $branchMarket ?? [];
    @endphp

    @foreach($agentsByBranch as $bid => $group)
        @php
            $branchName = $bid ? ($branches[$bid]->name ?? '-') : '-';
            $ma = $bm[(int)$bid] ?? ['deals_count'=>0,'avg_sale_price_inc_vat'=>0,'avg_sale_price_ex_vat'=>0,'effective_commission_percent_ex_vat'=>0];
        @endphp

        <div class="ds-status-card" style="border-left-color: var(--ds-navy);">
            {{-- Branch Header --}}
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4">
                <div>
                    <div class="text-lg font-bold" style="color:#0b2a4a;">{{ $branchName }}</div>
                    <div class="text-xs text-gray-600 mt-1">
                        Deal Register Market Averages
                        @php
                            $st = [];
                            if (!empty($sf['pending'])) $st[] = 'Pending';
                            if (!empty($sf['granted'])) $st[] = 'Granted';
                            if (!empty($sf['registered'])) $st[] = 'Registered';
                        @endphp
                        @if(!empty($dateFrom) && !empty($dateTo))
                            <span class="ml-2"><b>Window:</b> {{ $dateFrom }} &rarr; {{ $dateTo }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Market Averages KPIs --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="bg-gray-50 border rounded-lg p-3">
                    <div class="ds-label">Deals counted</div>
                    <div class="ds-value-lg">{{ (int)($ma['deals_count'] ?? 0) }}</div>
                </div>
                <div class="bg-gray-50 border rounded-lg p-3">
                    <div class="ds-label">Avg Sale Price (Incl VAT)</div>
                    <div class="ds-value-lg">R {{ number_format((float)($ma['avg_sale_price_inc_vat'] ?? 0), 2) }}</div>
                    <div class="text-xs text-gray-500 mt-1">Ex VAT: R {{ number_format((float)($ma['avg_sale_price_ex_vat'] ?? 0), 2) }}</div>
                </div>
                <div class="bg-gray-50 border rounded-lg p-3">
                    <div class="ds-label">Effective Comm % (Ex VAT)</div>
                    <div class="ds-value-lg">{{ number_format((float)($ma['effective_commission_percent_ex_vat'] ?? 0), 2) }}%</div>
                </div>
            </div>

            {{-- Agent Table --}}
            <form method="POST" action="{{ route('admin.worksheet-market.store', request()->query()) }}">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}" />
                <input type="hidden" name="branch_id" value="{{ $bid }}" />

                <div class="table-scroll overflow-x-auto">
                    <table class="w-full text-sm table-sticky ds-table">
                        <thead>
                            <tr>
                                <th class="text-left p-2">Agent</th>
                                <th class="text-left p-2">Avg Sales Override</th>
                                <th class="text-left p-2">Comm % Override (Ex VAT)</th>
                                <th class="text-left p-2">Lock</th>
                                <th class="text-left p-2">Actual Deals</th>
                                <th class="text-left p-2">Actual Avg Sale (Inc)</th>
                                <th class="text-left p-2">Actual Eff Comm % (Ex)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group as $a)
                                @php
                                    $w = $worksheets->get($a->id);
                                    $curAvg = $w->avg_sale_price_admin ?? null;

                                    $plannedComm = $w->commission_percent ?? null;
                                    $curComm = $w->commission_percent_admin ?? null;
                                    $lockComm = (bool)($w->commission_percent_locked ?? false);

                                    $m = ($amb[(int)$bid][(int)$a->id] ?? ['deals_count'=>0,'avg_sale_price_inc_vat'=>0,'effective_commission_percent_ex_vat'=>0]);
                                @endphp

                                <tr>
                                    <td class="p-2 font-medium whitespace-nowrap min-w-[220px]">
                                        <div class="flex items-center gap-2">
                                            <div class="font-semibold" style="color:#0b2a4a;">{{ $a->name }}</div>
                                            @if(($a->role ?? '') === 'branch_manager')
                                                <span class="ds-badge ds-badge-ahead text-[10px]">BM</span>
                                            @endif
                                        </div>
                                    </td>

                                    <td class="p-2">
                                        <input type="number" step="0.01" name="avg[{{ $a->id }}]" value="{{ old('avg.'.$a->id, $curAvg) }}"
                                               class="w-40 border rounded-lg p-2 text-sm" placeholder="e.g. 1200000" />
                                        <div class="text-xs text-gray-500 mt-1">
                                            Current: {{ $curAvg === null ? 'NULL' : ('R ' . number_format((float)$curAvg, 2)) }}
                                        </div>
                                    </td>

                                    <td class="p-2">
                                        <input id="comm_{{ $a->id }}" type="number" step="0.01" name="comm[{{ $a->id }}]" value="{{ old('comm.'.$a->id, $curComm) }}"
                                               class="w-32 border rounded-lg p-2 text-sm" placeholder="e.g. 7.50" {{ $lockComm ? 'readonly' : '' }} />
                                        <div class="text-xs text-gray-500 mt-1">
                                            Planned: {{ $plannedComm === null ? 'NULL' : (number_format((float)$plannedComm, 2) . '%') }}
                                            - Current: {{ $curComm === null ? 'NULL' : (number_format((float)$curComm, 2) . '%') }}
                                        </div>
                                    </td>

                                    <td class="p-2">
                                        <label class="inline-flex items-center gap-2 text-sm">
                                            <input type="checkbox" name="lock[{{ $a->id }}]" value="1"
                                                   data-comm="#comm_{{ $a->id }}" {{ old('lock.'.$a->id, $lockComm ? 1 : 0) ? 'checked' : '' }}>
                                            Locked
                                        </label>
                                    </td>

                                    <td class="p-2 text-gray-700">{{ (int)($m['deals_count'] ?? 0) }}</td>
                                    <td class="p-2 ds-value">R {{ number_format((float)($m['avg_sale_price_inc_vat'] ?? 0), 2) }}</td>
                                    <td class="p-2 ds-value">{{ number_format((float)($m['effective_commission_percent_ex_vat'] ?? 0), 2) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex items-center justify-between">
                    <div class="text-xs text-gray-500">Saves only this branch's users.</div>
                    <button class="px-6 py-3 rounded-lg font-bold text-white" style="background:#059669;">
                        Save {{ $branchName }}
                    </button>
                </div>
            </form>
        </div>
    @endforeach

</div>

<script>
document.addEventListener('change', function (e) {
  const el = e.target;
  if (!el || el.type !== 'checkbox' || !el.name || el.name.indexOf('lock[') !== 0) return;
  const sel = el.getAttribute('data-comm');
  if (!sel) return;
  const input = document.querySelector(sel);
  if (!input) return;
  input.readOnly = !!el.checked;
  if (el.checked) { input.classList.add('bg-gray-100'); } else { input.classList.remove('bg-gray-100'); }
});
</script>

</x-app-layout>
