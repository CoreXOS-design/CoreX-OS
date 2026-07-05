@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
    <style>
        .corex-input:focus,
        .corex-select:focus {
            outline: none;
            border-color: var(--brand-button, #0ea5e9);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button, #0ea5e9) 15%, transparent);
        }
        .corex-input[readonly] { opacity: 0.5; }
    </style>

    <div class="w-full space-y-6">

        {{-- Page Header (Pattern A) --}}
        <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Worksheet Market — Company</h1>
                    <p class="text-sm text-white/60">Set planned average sale price per agent</p>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    <input type="month" name="period" value="{{ $period }}"
                           class="rounded-md text-sm px-3 py-1.5 [&::-webkit-calendar-picker-indicator]:invert"
                           style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff;" />
                    <button type="submit" class="corex-btn-primary">Go</button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <div class="flex-1">{{ session('status') }}</div>
            </div>
        @endif

        @php
            $aw = $avgWindow ?? 'period';
            $sf = $stageFilter ?? ['pending'=>true,'granted'=>true,'registered'=>true];
            $mb = $marketByBranch ?? [];
            $amb = $agentMarketByBranch ?? [];
        @endphp

        {{-- Filters Panel --}}
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Deal Register Market Averages</h3>
                    <p class="text-xs mt-1" style="color: var(--text-muted);">
                        Window + stage filters apply.
                        @if(!empty($dateFrom) && !empty($dateTo))
                            <span class="ml-2"><strong>Window:</strong> {{ $dateFrom }} → {{ $dateTo }}</span>
                        @endif
                    </p>
                </div>

                <form method="GET" class="flex flex-wrap gap-3 items-end">
                    <input type="hidden" name="period" value="{{ $period }}" />
                    <div>
                        <label class="block mb-1 text-xs font-medium" style="color: var(--text-secondary);">Window</label>
                        <select name="avg_window"
                                class="corex-select rounded-md px-3 py-2 text-sm"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="period" {{ $aw==='period'?'selected':'' }}>This month</option>
                            <option value="3m" {{ $aw==='3m'?'selected':'' }}>Last 3 months</option>
                            <option value="6m" {{ $aw==='6m'?'selected':'' }}>Last 6 months</option>
                            <option value="all" {{ $aw==='all'?'selected':'' }}>All time</option>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-3 items-center pb-1">
                        <label class="text-sm flex items-center gap-1.5" style="color: var(--text-secondary);">
                            <input type="checkbox" name="st_pending" value="1" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);" {{ !empty($sf['pending'])?'checked':'' }}> Pending
                        </label>
                        <label class="text-sm flex items-center gap-1.5" style="color: var(--text-secondary);">
                            <input type="checkbox" name="st_granted" value="1" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);" {{ !empty($sf['granted'])?'checked':'' }}> Granted
                        </label>
                        <label class="text-sm flex items-center gap-1.5" style="color: var(--text-secondary);">
                            <input type="checkbox" name="st_registered" value="1" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);" {{ !empty($sf['registered'])?'checked':'' }}> Registered
                        </label>
                    </div>

                    <button class="corex-btn-primary">Apply</button>
                </form>
            </div>
        </div>

        {{-- Branch Sections --}}
        @php
            $agentsByBranch = $agents->groupBy('branch_id');
            $bm = $branchMarket ?? [];
        @endphp

        @forelse($agentsByBranch as $bid => $group)
            @php
                $branchName = $bid ? ($branches[$bid]->name ?? '-') : '-';
                $ma = $bm[(int)$bid] ?? ['deals_count'=>0,'avg_sale_price_inc_vat'=>0,'avg_sale_price_ex_vat'=>0,'effective_commission_percent_ex_vat'=>0];
            @endphp

            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">

                {{-- Branch Header --}}
                <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                        <div>
                            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">{{ $branchName }}</h3>
                            <p class="text-xs mt-0.5" style="color: var(--text-muted);">
                                Deal Register Market Averages
                                @if(!empty($dateFrom) && !empty($dateTo))
                                    <span class="ml-2"><strong>Window:</strong> {{ $dateFrom }} → {{ $dateTo }}</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Market Averages Stat Tiles --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-5">
                    <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Deals counted</div>
                        <div class="text-[1.625rem] font-semibold mt-1" style="color: var(--text-primary);">{{ number_format((int)($ma['deals_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Avg Sale Price (Incl VAT)</div>
                        <div class="text-[1.625rem] font-semibold mt-1" style="color: var(--text-primary);">R {{ number_format((float)($ma['avg_sale_price_inc_vat'] ?? 0), 0) }}</div>
                        <div class="text-xs mt-1" style="color: var(--text-muted);">Ex VAT: R {{ number_format((float)($ma['avg_sale_price_ex_vat'] ?? 0), 0) }}</div>
                    </div>
                    <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                        <div class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted);">Effective Comm % (Ex VAT)</div>
                        <div class="text-[1.625rem] font-semibold mt-1" style="color: var(--text-primary);">{{ number_format((float)($ma['effective_commission_percent_ex_vat'] ?? 0), 1) }}%</div>
                    </div>
                </div>

                {{-- Agent Table --}}
                <form method="POST" action="{{ route('admin.worksheet-market.store', request()->query()) }}">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}" />
                    <input type="hidden" name="branch_id" value="{{ $bid }}" />

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm ds-table">
                            <thead>
                                <tr style="background: var(--surface-2);">
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Avg Sales Override</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Comm % Override (Ex VAT)</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Lock</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actual Deals</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actual Avg Sale (Inc)</th>
                                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actual Eff Comm % (Ex)</th>
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
                                        <td class="px-4 py-3 whitespace-nowrap min-w-[220px]">
                                            <div class="flex items-center gap-2">
                                                <span class="font-semibold" style="color: var(--text-primary);">{{ $a->name }}</span>
                                                @if(($a->role ?? '') === 'branch_manager')
                                                    <span class="ds-badge ds-badge-info" title="Branch Manager">BM</span>
                                                @endif
                                            </div>
                                        </td>

                                        <td class="px-4 py-3">
                                            <input type="number" step="0.01" name="avg[{{ $a->id }}]" value="{{ old('avg.'.$a->id, $curAvg) }}"
                                                   class="corex-input rounded-md px-3 py-2 w-40 text-sm transition-all duration-300"
                                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                                   placeholder="e.g. 1200000" />
                                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                                Current: {{ $curAvg === null ? '—' : ('R ' . number_format((float)$curAvg, 0)) }}
                                            </div>
                                        </td>

                                        <td class="px-4 py-3">
                                            <input id="comm_{{ $a->id }}" type="number" step="0.01" name="comm[{{ $a->id }}]" value="{{ old('comm.'.$a->id, $curComm) }}"
                                                   class="corex-input rounded-md px-3 py-2 w-32 text-sm transition-all duration-300"
                                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                                   placeholder="e.g. 7.50" {{ $lockComm ? 'readonly' : '' }}
                                                   @if($lockComm) data-locked="1" @endif />
                                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                                Planned: {{ $plannedComm === null ? '—' : (number_format((float)$plannedComm, 1) . '%') }}
                                                — Current: {{ $curComm === null ? '—' : (number_format((float)$curComm, 1) . '%') }}
                                            </div>
                                        </td>

                                        <td class="px-4 py-3">
                                            <label class="inline-flex items-center gap-2 text-sm" style="color: var(--text-secondary);">
                                                <input type="checkbox" name="lock[{{ $a->id }}]" value="1" class="rounded"
                                                       style="accent-color: var(--brand-button, #0ea5e9);"
                                                       data-comm="#comm_{{ $a->id }}" {{ old('lock.'.$a->id, $lockComm ? 1 : 0) ? 'checked' : '' }}>
                                                Locked
                                            </label>
                                        </td>

                                        <td class="px-4 py-3" style="color: var(--text-secondary);">{{ number_format((int)($m['deals_count'] ?? 0)) }}</td>
                                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">R {{ number_format((float)($m['avg_sale_price_inc_vat'] ?? 0), 0) }}</td>
                                        <td class="px-4 py-3 font-medium" style="color: var(--text-primary);">{{ number_format((float)($m['effective_commission_percent_ex_vat'] ?? 0), 1) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="px-5 py-4 flex items-center justify-between gap-3" style="border-top: 1px solid var(--border);">
                        <span class="text-xs" style="color: var(--text-muted);">Saves only this branch's users.</span>
                        <button class="corex-btn-primary">Save {{ $branchName }}</button>
                    </div>
                </form>
            </div>
        @empty
            {{-- Empty state when no branches/agents exist --}}
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No branches with agents yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Add agents and assign them to a branch to start configuring market averages.</p>
            </div>
        @endforelse

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
    });
    </script>
@endsection
