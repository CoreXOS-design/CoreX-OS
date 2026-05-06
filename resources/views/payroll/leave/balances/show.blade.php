@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="{{ $employee->user->name }} â€” Leave Balances" :back-route="route('payroll.leave.balances.index')" back-label="Balances" :flush="true">
        <x-slot:actions>
            <form method="POST" action="{{ route('payroll.leave.balances.recalculate', $employee) }}" class="inline">
                @csrf
                <button type="submit" class="px-3 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;" onclick="return confirm('Recalculate all balances from transaction ledger?')">Recalculate</button>
            </form>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6">
            {{-- Left: Employee summary --}}
            <div class="lg:w-1/3 space-y-4">
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</h4>
                    <p class="text-sm font-semibold" style="color:var(--text-primary, #0f172a);">{{ $employee->user->name }}</p>
                    <p class="text-xs" style="color:var(--text-secondary, #6b7280);">{{ $employee->designation_snapshot }} | {{ $employee->user->branch->name ?? '-' }}</p>
                    <p class="text-xs mt-1" style="color:var(--text-secondary, #6b7280);">Employed: {{ $employee->employment_date?->format('d M Y') }}</p>
                    <p class="text-xs" style="color:var(--text-secondary, #6b7280);">Pattern: {{ $employee->working_days_per_week ?? 5 }}-day week</p>
                </div>

                @if($takeOn)
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Take-On Status</h4>
                    @if($takeOn->isComplete())
                        <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Completed {{ $takeOn->completed_at->format('d M Y') }}</span>
                    @else
                        <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">In Progress ({{ $takeOn->progressPercentage() }}%)</span>
                    @endif
                </div>
                @endif
            </div>

            {{-- Right: Balances per type --}}
            <div class="lg:w-2/3" x-data="{ activeType: {{ $leaveTypes->first()?->id ?? 0 }} }">
                {{-- Type tabs --}}
                <div class="flex flex-wrap gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
                    @foreach($leaveTypes as $type)
                        <button @click="activeType = {{ $type->id }}" class="px-3 py-1.5 text-xs font-semibold transition" :style="activeType === {{ $type->id }} ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);'" style="background:none; border:none; cursor:pointer;">{{ $type->label }}</button>
                    @endforeach
                </div>

                @foreach($leaveTypes as $type)
                @php $bal = $balances[$type->id] ?? []; @endphp
                <div x-show="activeType === {{ $type->id }}" x-cloak>
                    {{-- Balance card --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 mb-4">
                        @foreach([
                            'Entitlement' => $bal['entitlement_days'] ?? '0',
                            'Accrued' => $bal['accrued_days'] ?? '0',
                            'Carryover' => $bal['carryover_from_previous_cycle'] ?? '0',
                            'Taken' => $bal['taken_days'] ?? '0',
                            'Pending' => $bal['pending_days'] ?? '0',
                            'Available' => $bal['available_days'] ?? '0',
                        ] as $lbl => $val)
                            <div class="p-2 text-center" style="background:{{ $lbl === 'Available' ? 'rgba(0,212,170,0.04)' : 'var(--surface-2, #f8fafc)' }}; border:1px solid {{ $lbl === 'Available' ? 'rgba(0,212,170,0.15)' : 'var(--border, #e5e7eb)' }}; border-radius:6px;">
                                <p class="text-[9px] font-semibold uppercase" style="color:var(--text-secondary, #94a3b8);">{{ $lbl }}</p>
                                <p class="text-sm font-bold" style="color:{{ $lbl === 'Available' ? '#00d4aa' : 'var(--text-primary, #0f172a)' }};">{{ number_format((float)$val, 2) }}</p>
                            </div>
                        @endforeach
                    </div>

                    <p class="text-[10px] mb-3" style="color:var(--text-secondary, #94a3b8);">
                        Cycle: {{ isset($bal['cycle_start_date']) ? $bal['cycle_start_date']->format('d M Y') : '-' }} â€” {{ isset($bal['cycle_end_date']) ? $bal['cycle_end_date']->format('d M Y') : '-' }}
                    </p>

                    {{-- Transaction history --}}
                    <h5 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Transaction History</h5>
                    @if(isset($transactions[$type->id]) && $transactions[$type->id]->count() > 0)
                        <table class="w-full text-sm mb-3" style="border-collapse:collapse;">
                            <thead>
                                <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                    <th class="text-left px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Date</th>
                                    <th class="text-left px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Type</th>
                                    <th class="text-right px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Days</th>
                                    <th class="text-left px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Description</th>
                                    <th class="text-left px-2 py-1.5 text-[10px] font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">By</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($transactions[$type->id] as $txn)
                                <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                    <td class="px-2 py-1.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $txn->effective_date?->format('d M Y') }}</td>
                                    <td class="px-2 py-1.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ ucfirst(str_replace('_', ' ', $txn->transaction_type)) }}</td>
                                    <td class="px-2 py-1.5 text-right text-xs font-semibold" style="color:{{ (float)$txn->days_delta >= 0 ? '#00d4aa' : '#ef4444' }};">{{ $txn->days_delta > 0 ? '+' : '' }}{{ number_format((float)$txn->days_delta, 2) }}</td>
                                    <td class="px-2 py-1.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ \Illuminate\Support\Str::limit($txn->description, 50) }}</td>
                                    <td class="px-2 py-1.5 text-xs" style="color:var(--text-secondary, #94a3b8);">{{ $txn->createdBy->name ?? 'System' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        {{ $transactions[$type->id]->links() }}
                    @else
                        <p class="text-xs py-4" style="color:var(--text-secondary, #94a3b8);">No transactions in this cycle.</p>
                    @endif

                    {{-- Manual adjust form --}}
                    @permission('adjust_leave_balances')
                    <div x-data="{ showAdjust: false }" class="mt-3">
                        <button @click="showAdjust = !showAdjust" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">Manual Adjustment</button>
                        <form method="POST" action="{{ route('payroll.leave.balances.adjust', $employee) }}" x-show="showAdjust" x-cloak class="mt-2 p-3 space-y-3" style="background:rgba(0,212,170,0.03); border:1px solid rgba(0,212,170,0.15); border-radius:6px;">
                            @csrf
                            <input type="hidden" name="leave_type_id" value="{{ $type->id }}">
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Days (+ or -)</label>
                                    <input type="number" name="days_delta" step="0.5" required class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Effective Date</label>
                                    <input type="date" name="effective_date" value="{{ date('Y-m-d') }}" class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <div class="sm:col-span-3">
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Reason (min 10 chars)</label>
                                    <textarea name="reason" required minlength="10" rows="2" class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);" placeholder="Explain why this adjustment is necessary..."></textarea>
                                </div>
                            </div>
                            <div class="flex gap-2">
                                <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Save Adjustment</button>
                                <button type="button" @click="showAdjust = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
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
