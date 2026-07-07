{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="{{ $employee->user->name }} — Leave Balances" :back-route="route('payroll.leave.balances.index')" back-label="Balances" :flush="true">
        <x-slot:actions>
            <form method="POST" action="{{ route('payroll.leave.balances.recalculate', $employee) }}" class="inline">
                @csrf
                <button type="submit" class="corex-btn-outline text-sm" onclick="return confirm('Recalculate all balances from transaction ledger?')">Recalculate</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 rounded-md px-4 py-3 text-sm flex items-start gap-3"
                 style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                <div class="flex-1">{{ session('success') }}</div>
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6">
            {{-- Left: Employee summary --}}
            <div class="lg:w-1/3 space-y-4">
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color: var(--text-secondary); letter-spacing: 0.05em;">Employee</h4>
                    <p class="text-sm font-semibold" style="color: var(--text-primary);">{{ $employee->user->name }}</p>
                    <p class="text-xs" style="color: var(--text-secondary);">{{ $employee->designation_snapshot }} | {{ $employee->user->branch->name ?? '—' }}</p>
                    <p class="text-xs mt-1" style="color: var(--text-secondary);">Employed: {{ $employee->employment_date?->format('d M Y') }}</p>
                    <p class="text-xs" style="color: var(--text-secondary);">Pattern: {{ $employee->working_days_per_week ?? 5 }}-day week</p>
                </div>

                @if($takeOn)
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color: var(--text-secondary); letter-spacing: 0.05em;">Take-On Status</h4>
                    @if($takeOn->isComplete())
                        <span class="ds-badge ds-badge-success">Completed</span>
                        <p class="text-xs mt-1.5" style="color: var(--text-muted);">{{ $takeOn->completed_at->format('d M Y') }}</p>
                    @else
                        <span class="ds-badge ds-badge-warning">In Progress · {{ number_format($takeOn->progressPercentage()) }}%</span>
                    @endif
                </div>
                @endif
            </div>

            {{-- Right: Balances per type --}}
            <div class="lg:w-2/3" x-data="{ activeType: {{ $leaveTypes->first()?->id ?? 0 }} }">
                {{-- Type tabs --}}
                <div class="flex flex-wrap gap-1 mb-4" style="border-bottom: 1px solid var(--border);">
                    @foreach($leaveTypes as $type)
                        <button @click="activeType = {{ $type->id }}" class="px-3 py-1.5 text-xs font-semibold transition-all duration-300"
                                :style="activeType === {{ $type->id }} ? 'border-bottom: 2px solid var(--brand-icon, #0ea5e9); color: var(--brand-icon, #0ea5e9);' : 'color: var(--text-secondary);'"
                                style="background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer;">{{ $type->label }}</button>
                    @endforeach
                </div>

                @foreach($leaveTypes as $type)
                @php $bal = $balances[$type->id] ?? []; @endphp
                <div x-show="activeType === {{ $type->id }}" x-cloak>
                    {{-- Balance summary tiles --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 mb-4">
                        @foreach([
                            'Entitlement' => $bal['entitlement_days'] ?? '0',
                            'Accrued' => $bal['accrued_days'] ?? '0',
                            'Carryover' => $bal['carryover_from_previous_cycle'] ?? '0',
                            'Taken' => $bal['taken_days'] ?? '0',
                            'Pending' => $bal['pending_days'] ?? '0',
                            'Available' => $bal['available_days'] ?? '0',
                        ] as $lbl => $val)
                            <div class="rounded-md p-2 text-center"
                                 style="background: {{ $lbl === 'Available' ? 'color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, transparent)' : 'var(--surface-2)' }};
                                        border: 1px solid {{ $lbl === 'Available' ? 'color-mix(in srgb, var(--brand-icon, #0ea5e9) 25%, transparent)' : 'var(--border)' }};">
                                <p class="text-[10px] font-semibold uppercase" style="color: var(--text-secondary);">{{ $lbl }}</p>
                                <p class="text-sm font-bold" style="color: {{ $lbl === 'Available' ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-primary)' }};">{{ number_format((float)$val, 2) }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p class="text-[11px] mb-3" style="color: var(--text-muted);">
                        Cycle: {{ isset($bal['cycle_start_date']) ? $bal['cycle_start_date']->format('d M Y') : '—' }} — {{ isset($bal['cycle_end_date']) ? $bal['cycle_end_date']->format('d M Y') : '—' }}
                    </p>

                    {{-- Transaction history --}}
                    <h5 class="text-xs font-bold uppercase mb-2" style="color: var(--text-secondary); letter-spacing: 0.05em;">Transaction History</h5>
                    @if(isset($transactions[$type->id]) && $transactions[$type->id]->count() > 0)
                        <div class="rounded-md overflow-hidden mb-3" style="background: var(--surface); border: 1px solid var(--border);">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm ds-table">
                                    <thead>
                                        <tr style="background: var(--surface-2);">
                                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Type</th>
                                            <th class="text-right px-3 py-2 text-[10px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Days</th>
                                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Description</th>
                                            <th class="text-left px-3 py-2 text-[10px] font-semibold uppercase tracking-wider" style="color: var(--text-muted);">By</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($transactions[$type->id] as $txn)
                                        <tr>
                                            <td class="px-3 py-2 text-xs" style="color: var(--text-secondary);">{{ $txn->effective_date?->format('d M Y') }}</td>
                                            <td class="px-3 py-2 text-xs" style="color: var(--text-secondary);">{{ ucfirst(str_replace('_', ' ', $txn->transaction_type)) }}</td>
                                            <td class="px-3 py-2 text-right text-xs font-semibold" style="color: {{ (float)$txn->days_delta >= 0 ? 'var(--ds-green)' : 'var(--text-primary)' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 2) }}</td>
                                            <td class="px-3 py-2 text-xs" style="color: var(--text-primary);">{{ \Illuminate\Support\Str::limit($txn->description, 50) }}</td>
                                            <td class="px-3 py-2 text-xs" style="color: var(--text-muted);">{{ $txn->createdBy->name ?? 'System' }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        {{ $transactions[$type->id]->links() }}
                    @else
                        <p class="text-xs py-4" style="color: var(--text-muted);">No transactions in this cycle.</p>
                    @endif

                    {{-- Manual adjust form --}}
                    @permission('adjust_leave_balances')
                    <div x-data="{ showAdjust: false }" class="mt-3">
                        <button @click="showAdjust = !showAdjust" class="text-xs font-semibold" style="color: var(--brand-icon); background: none; border: none; cursor: pointer;">Manual Adjustment</button>
                        <form method="POST" action="{{ route('payroll.leave.balances.adjust', $employee) }}" x-show="showAdjust" x-cloak class="mt-2 rounded-md p-3 space-y-3"
                              style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 4%, transparent); border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent);">
                            @csrf
                            <input type="hidden" name="leave_type_id" value="{{ $type->id }}">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-[10px] font-semibold mb-1" style="color: var(--text-secondary);">Days (+ or -)</label>
                                    <input type="number" name="days_delta" step="0.5" required class="w-full rounded-md px-2 py-1.5 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-1" style="color: var(--text-secondary);">Effective Date</label>
                                    <input type="date" name="effective_date" value="{{ date('Y-m-d') }}" class="w-full rounded-md px-2 py-1.5 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div class="sm:col-span-3">
                                    <label class="block text-[10px] font-semibold mb-1" style="color: var(--text-secondary);">Reason (min 10 chars)</label>
                                    <textarea name="reason" required minlength="10" rows="2" class="w-full rounded-md px-2 py-1.5 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);" placeholder="Explain why this adjustment is necessary…"></textarea>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="corex-btn-primary text-xs">Save Adjustment</button>
                                <button type="button" @click="showAdjust = false" class="corex-btn-outline text-xs">Cancel</button>
                            </div>
                        </form>
                    </div>
                    @endpermission
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
