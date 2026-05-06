@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="{{ $employee->user->name ?? 'Employee' }}" :back-route="route('payroll.employees.index')" back-label="Employees" :flush="true">
        <x-slot:actions>
            <a href="{{ route('payroll.employees.edit', $employee) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold transition" style="color:var(--text-primary, #0f172a); border:1px solid var(--border, #e5e7eb); border-radius:6px;">Edit Profile</a>
            @if($employee->is_active && !$employee->termination_date)
                <form method="POST" action="{{ route('payroll.employees.deactivate', $employee) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-3 py-2 text-xs font-semibold transition" style="color:#eab308; border:1px solid rgba(234,179,8,0.3); border-radius:6px; background:none; cursor:pointer;" onclick="return confirm('Deactivate this employee?')">Deactivate</button>
                </form>
            @elseif(!$employee->termination_date)
                <form method="POST" action="{{ route('payroll.employees.reactivate', $employee) }}" class="inline">
                    @csrf
                    <button type="submit" class="px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px; cursor:pointer;">Reactivate</button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:6px; color:#ef4444;">{{ session('error') }}</div>
        @endif

        <div class="flex flex-col lg:flex-row gap-6">
            {{-- â•â•â• LEFT COLUMN (1/3) â•â•â• --}}
            <div class="lg:w-1/3 space-y-4">
                {{-- Card 1: Employee header --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold text-white" style="background:#00d4aa;">
                            {{ strtoupper(substr($employee->user->name ?? '?', 0, 1)) }}
                        </div>
                        <div>
                            <h3 class="text-sm font-bold" style="color:var(--text-primary, #0f172a);">{{ $employee->user->name }}</h3>
                            <p class="text-[11px]" style="color:var(--text-secondary, #94a3b8);">{{ $employee->designation_snapshot }}</p>
                            <p class="text-[11px]" style="color:var(--text-secondary, #94a3b8);">{{ $employee->user->branch->name ?? '-' }}</p>
                        </div>
                    </div>
                    @if($employee->termination_date)
                        <span class="px-2 py-0.5 text-[10px] font-semibold" style="background:rgba(239,68,68,0.1); color:#ef4444; border-radius:6px;">Terminated {{ $employee->termination_date->format('d M Y') }}</span>
                    @elseif($employee->is_active)
                        <span class="px-2 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Active</span>
                    @else
                        <span class="px-2 py-0.5 text-[10px] font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Inactive</span>
                    @endif
                </div>

                {{-- Card 2: Employment details --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employment Details</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Employment Date</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $employee->employment_date?->format('d M Y') ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">ID Number</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $employee->user->id_number ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Date of Birth</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $employee->user->date_of_birth?->format('d M Y') ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Tax Reference</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $employee->user->tax_reference_number ?? '-' }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Pay Day</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $employee->pay_day_of_month }}th</dd></div>
                    </dl>
                </div>

                {{-- Card 3: Banking --}}
                @php $banking = $employee->user->bankingDetail; @endphp
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;" x-data="{ editBanking: false }">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Banking</h4>
                        <button type="button" @click="editBanking = !editBanking" class="text-[10px] font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">
                            {{ $banking ? 'Edit' : '+ Add Banking' }}
                        </button>
                    </div>

                    @if($banking && !$banking->trashed())
                        <dl class="space-y-1.5 text-xs" x-show="!editBanking">
                            <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Bank</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $banking->bank_name }}</dd></div>
                            <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Account</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a); font-family:monospace;">{{ $banking->masked_account_number }}</dd></div>
                            <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Type</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ ucfirst($banking->account_type) }}</dd></div>
                            @if($banking->verified_at)
                                <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Verified</dt><dd class="font-semibold" style="color:#00d4aa;">{{ $banking->verified_at->format('d M Y') }}</dd></div>
                            @endif
                        </dl>
                    @else
                        <p class="text-xs" style="color:var(--text-secondary, #94a3b8);" x-show="!editBanking">No banking details on file.</p>
                    @endif

                    {{-- Inline banking form --}}
                    <form method="POST" action="{{ $banking ? route('payroll.employees.banking.update', $employee) : route('payroll.employees.banking.store', $employee) }}" x-show="editBanking" x-cloak class="space-y-3 mt-2">
                        @csrf
                        @if($banking) @method('PATCH') @endif
                        <input type="text" name="account_holder" value="{{ old('account_holder', $banking->account_holder ?? $employee->user->name) }}" required maxlength="150" placeholder="Account holder"
                               class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                        <select name="bank_name" required class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                            <option value="">-- Bank --</option>
                            @foreach(['ABSA', 'African Bank', 'Bidvest Bank', 'Capitec', 'Discovery Bank', 'FNB', 'Investec', 'Nedbank', 'Standard Bank', 'TymeBank', 'Other'] as $bank)
                                <option value="{{ $bank }}" {{ ($banking->bank_name ?? '') === $bank ? 'selected' : '' }}>{{ $bank }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="branch_code" value="{{ old('branch_code', $banking->branch_code ?? '') }}" required maxlength="10" placeholder="Branch code"
                               class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                        <input type="text" name="account_number" value="{{ $banking->account_number ?? '' }}" required maxlength="30" placeholder="Account number"
                               class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                        <select name="account_type" required class="w-full px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                            @foreach(['cheque' => 'Cheque', 'savings' => 'Savings', 'transmission' => 'Transmission'] as $val => $lbl)
                                <option value="{{ $val }}" {{ ($banking->account_type ?? 'cheque') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                            @endforeach
                        </select>
                        <div class="flex gap-2">
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Save</button>
                            <button type="button" @click="editBanking = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                        </div>
                    </form>
                </div>

                {{-- Card 4: Quick stats --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Quick Stats</h4>
                    <dl class="space-y-1.5 text-xs">
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">Total Payslips</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">{{ $ytdStats->payslip_count ?? 0 }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">YTD Gross</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($ytdStats->ytd_gross ?? 0, 2) }}</dd></div>
                        <div class="flex justify-between"><dt style="color:var(--text-secondary, #6b7280);">YTD PAYE</dt><dd class="font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($ytdStats->ytd_paye ?? 0, 2) }}</dd></div>
                    </dl>
                </div>
            </div>

            {{-- â•â•â• RIGHT COLUMN (2/3) â•â•â• --}}
            <div class="lg:w-2/3" x-data="{ tab: 'setup' }">
                {{-- Tab bar --}}
                <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
                    <button @click="tab = 'setup'" class="px-3 py-1.5 text-xs font-semibold transition" :style="tab === 'setup' ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);'" style="background:none; border:none; cursor:pointer;">Current Setup</button>
                    <button @click="tab = 'history'" class="px-3 py-1.5 text-xs font-semibold transition" :style="tab === 'history' ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);'" style="background:none; border:none; cursor:pointer;">History <span class="text-[10px] opacity-60">{{ $payslips->count() }}</span></button>
                    <button @click="tab = 'audit'" class="px-3 py-1.5 text-xs font-semibold transition" :style="tab === 'audit' ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);'" style="background:none; border:none; cursor:pointer;">Audit Log</button>
                </div>

                {{-- â•â• TAB 1: Current Setup â•â• --}}
                <div x-show="tab === 'setup'">
                    {{-- EARNINGS TABLE --}}
                    <div class="mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Earnings</h4>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm" style="border-collapse:collapse;">
                                <thead>
                                    <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Type</th>
                                        <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Amount</th>
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Effective From</th>
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Notes</th>
                                        <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                                    </tr>
                                </thead>
                                    @forelse($currentEarnings as $earning)
                                <tbody x-data="{ editing: false }">
                                    <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                        <td class="px-3 py-2.5 font-semibold text-xs" style="color:var(--text-primary, #0f172a);">
                                            {{ $earning->earningType->label ?? 'Unknown' }}
                                            @if($earning->earningType?->is_system)
                                                <span class="ml-1 text-[9px] px-1 py-0.5 font-bold uppercase" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:2px;">System</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">
                                            <span x-show="!editing">R {{ number_format($earning->amount, 2) }}</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">
                                            <span x-show="!editing">{{ $earning->effective_from?->format('d M Y') }}</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">
                                            <span x-show="!editing">{{ $earning->notes ?? '-' }}</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            <div class="flex items-center justify-end gap-2" x-show="!editing">
                                                <button @click="editing = true" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">Edit</button>
                                                <form method="POST" action="{{ route('payroll.employees.earnings.destroy', [$employee, $earning]) }}" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-xs font-semibold" style="color:#ef4444; background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this earning?')">Remove</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    {{-- Inline edit row --}}
                                    <tr x-show="editing" x-cloak style="border-bottom:1px solid var(--border, #e5e7eb); background:rgba(0,212,170,0.02);">
                                        <td colspan="5" class="px-3 py-2">
                                            <form method="POST" action="{{ route('payroll.employees.earnings.update', [$employee, $earning]) }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                @method('PATCH')
                                                <div>
                                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">New Amount (R)</label>
                                                    <input type="number" name="amount" value="{{ $earning->amount }}" step="0.01" min="0" required class="w-32 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Effective From</label>
                                                    <input type="date" name="effective_from" value="{{ date('Y-m-d') }}" required class="w-36 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Notes</label>
                                                    <input type="text" name="notes" value="{{ $earning->notes }}" maxlength="500" class="w-40 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                                </div>
                                                <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Save</button>
                                                <button type="button" @click="editing = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                                            </form>
                                        </td>
                                    </tr>
                                </tbody>
                                    @empty
                                <tbody>
                                    <tr><td colspan="5" class="px-3 py-4 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No earnings configured.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Add earning form --}}
                        <div x-data="{ adding: false }" class="mt-2">
                            <button @click="adding = true" x-show="!adding" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">+ Add Earning</button>
                            <form method="POST" action="{{ route('payroll.employees.earnings.store', $employee) }}" x-show="adding" x-cloak
                                  class="flex flex-wrap items-end gap-3 p-3 mt-1" style="background:rgba(0,212,170,0.03); border:1px solid rgba(0,212,170,0.15); border-radius:6px;">
                                @csrf
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Earning Type</label>
                                    <select name="earning_type_id" required class="w-48 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                        <option value="">-- Select --</option>
                                        @foreach($earningTypes as $et)
                                            <option value="{{ $et->id }}">{{ $et->label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Amount (R)</label>
                                    <input type="number" name="amount" step="0.01" min="0" required class="w-32 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Effective From</label>
                                    <input type="date" name="effective_from" value="{{ date('Y-m-d') }}" required class="w-36 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Notes</label>
                                    <input type="text" name="notes" maxlength="500" class="w-40 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Save</button>
                                <button type="button" @click="adding = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                            </form>
                        </div>
                    </div>

                    {{-- DEDUCTIONS TABLE --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Deductions</h4>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm" style="border-collapse:collapse;">
                                <thead>
                                    <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Type</th>
                                        <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Amount</th>
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Effective From</th>
                                        <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Notes</th>
                                        <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                                    </tr>
                                </thead>
                                    @forelse($currentDeductions as $deduction)
                                <tbody x-data="{ editing: false }">
                                    <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                        <td class="px-3 py-2.5 font-semibold text-xs" style="color:var(--text-primary, #0f172a);">
                                            {{ $deduction->deductionType->label ?? 'Unknown' }}
                                            @if($deduction->deductionType?->is_statutory)
                                                @if($deduction->override_statutory)
                                                    <span class="ml-1 text-[9px] px-1 py-0.5 font-bold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:2px;">Override</span>
                                                @else
                                                    <span class="ml-1 text-[9px] px-1 py-0.5 font-bold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:2px;">Auto-calculated</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">
                                            <span x-show="!editing">
                                                @if($deduction->deductionType?->is_statutory && !$deduction->override_statutory)
                                                    <span style="color:var(--text-secondary, #94a3b8);">Auto</span>
                                                @else
                                                    R {{ number_format($deduction->amount, 2) }}
                                                @endif
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">
                                            <span x-show="!editing">{{ $deduction->effective_from?->format('d M Y') }}</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">
                                            <span x-show="!editing">{{ $deduction->notes ?? '-' }}</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            <div class="flex items-center justify-end gap-2" x-show="!editing">
                                                <button @click="editing = true" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">Edit</button>
                                                @if(!$deduction->deductionType?->is_statutory)
                                                    <form method="POST" action="{{ route('payroll.employees.deductions.destroy', [$employee, $deduction]) }}" class="inline">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-xs font-semibold" style="color:#ef4444; background:none; border:none; cursor:pointer;" onclick="return confirm('Remove this deduction?')">Remove</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                    {{-- Inline edit row --}}
                                    <tr x-show="editing" x-cloak style="border-bottom:1px solid var(--border, #e5e7eb); background:rgba(0,212,170,0.02);">
                                        <td colspan="5" class="px-3 py-2">
                                            <form method="POST" action="{{ route('payroll.employees.deductions.update', [$employee, $deduction]) }}" class="flex flex-wrap items-end gap-3">
                                                @csrf
                                                @method('PATCH')
                                                <div>
                                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Amount (R)</label>
                                                    <input type="number" name="amount" value="{{ $deduction->amount }}" step="0.01" min="0" required class="w-32 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Effective From</label>
                                                    <input type="date" name="effective_from" value="{{ date('Y-m-d') }}" required class="w-36 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                                </div>
                                                @if($deduction->deductionType?->is_statutory)
                                                <div>
                                                    <label class="flex items-center gap-2 text-xs cursor-pointer mt-3" style="color:var(--text-primary, #0f172a);">
                                                        <input type="hidden" name="override_statutory" value="0">
                                                        <input type="checkbox" name="override_statutory" value="1" {{ $deduction->override_statutory ? 'checked' : '' }} style="accent-color:#00d4aa;">
                                                        Override auto-calculation
                                                    </label>
                                                </div>
                                                @endif
                                                <div>
                                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Notes</label>
                                                    <input type="text" name="notes" value="{{ $deduction->notes }}" maxlength="500" class="w-40 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                                </div>
                                                <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Save</button>
                                                <button type="button" @click="editing = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                                            </form>
                                        </td>
                                    </tr>
                                </tbody>
                                    @empty
                                <tbody>
                                    <tr><td colspan="5" class="px-3 py-4 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No deductions configured.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{-- Add deduction form --}}
                        <div x-data="{ adding: false }" class="mt-2">
                            <button @click="adding = true" x-show="!adding" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">+ Add Deduction</button>
                            <form method="POST" action="{{ route('payroll.employees.deductions.store', $employee) }}" x-show="adding" x-cloak
                                  class="flex flex-wrap items-end gap-3 p-3 mt-1" style="background:rgba(0,212,170,0.03); border:1px solid rgba(0,212,170,0.15); border-radius:6px;">
                                @csrf
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Deduction Type</label>
                                    <select name="deduction_type_id" required class="w-48 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                        <option value="">-- Select --</option>
                                        @foreach($deductionTypes as $dt)
                                            <option value="{{ $dt->id }}">{{ $dt->label }}{{ $dt->is_statutory ? ' (statutory)' : '' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Amount (R)</label>
                                    <input type="number" name="amount" step="0.01" min="0" required value="0" class="w-32 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Effective From</label>
                                    <input type="date" name="effective_from" value="{{ date('Y-m-d') }}" required class="w-36 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold mb-0.5" style="color:var(--text-secondary, #6b7280);">Notes</label>
                                    <input type="text" name="notes" maxlength="500" class="w-40 px-2 py-1.5 text-xs focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-primary, #0f172a);">
                                </div>
                                <button type="submit" class="px-3 py-1.5 text-xs font-semibold text-white" style="background:#00d4aa; border-radius:6px;">Save</button>
                                <button type="button" @click="adding = false" class="px-3 py-1.5 text-xs font-semibold" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:6px; background:none; cursor:pointer;">Cancel</button>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- â•â• TAB 2: History â•â• --}}
                <div x-show="tab === 'history'" x-cloak>
                    @if($payslips->isEmpty())
                        <div class="py-8 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No payslips yet. They will appear here after the first payroll run is finalised.</div>
                    @else
                        <table class="w-full text-sm" style="border-collapse:collapse;">
                            <thead>
                                <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                                    <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Period</th>
                                    <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Pay Date</th>
                                    <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Gross</th>
                                    <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">Net</th>
                                    <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8);">PDF</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($payslips as $ps)
                                <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                                    <td class="px-3 py-2.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ $ps->period_month?->format('M Y') }}</td>
                                    <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $ps->pay_date?->format('d M Y') }}</td>
                                    <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($ps->total_earnings, 2) }}</td>
                                    <td class="px-3 py-2.5 text-right text-xs font-semibold" style="color:var(--text-primary, #0f172a);">R {{ number_format($ps->net_pay, 2) }}</td>
                                    <td class="px-3 py-2.5 text-right">
                                        @if($ps->document_id)
                                            <span class="text-xs font-semibold" style="color:#00d4aa;">PDF</span>
                                        @else
                                            <span class="text-xs" style="color:var(--text-secondary, #94a3b8);">-</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                {{-- â•â• TAB 3: Audit Log â•â• --}}
                <div x-show="tab === 'audit'" x-cloak>
                    @php
                        $auditItems = collect();
                        foreach ($auditEarnings as $ae) {
                            $auditItems->push([
                                'date' => $ae->created_at,
                                'user' => $ae->createdBy->name ?? 'System',
                                'desc' => ($ae->deleted_at ? 'Removed ' : ($ae->effective_to && !$ae->deleted_at ? 'Changed ' : 'Added '))
                                    . ($ae->earningType->label ?? '?')
                                    . ' R ' . number_format($ae->amount, 2)
                                    . ' effective ' . ($ae->effective_from?->format('d M Y') ?? '-'),
                                'type' => 'earning',
                            ]);
                        }
                        foreach ($auditDeductions as $ad) {
                            $auditItems->push([
                                'date' => $ad->created_at,
                                'user' => $ad->createdBy->name ?? 'System',
                                'desc' => ($ad->deleted_at ? 'Removed ' : ($ad->effective_to && !$ad->deleted_at ? 'Changed ' : 'Added '))
                                    . ($ad->deductionType->label ?? '?')
                                    . ' R ' . number_format($ad->amount, 2)
                                    . ' effective ' . ($ad->effective_from?->format('d M Y') ?? '-'),
                                'type' => 'deduction',
                            ]);
                        }
                        $auditItems = $auditItems->sortByDesc('date');
                    @endphp

                    @if($auditItems->isEmpty())
                        <div class="py-8 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No audit entries yet.</div>
                    @else
                        <div class="space-y-2">
                            @foreach($auditItems as $item)
                                <div class="flex items-start gap-3 py-2" style="border-bottom:1px solid var(--border, #e5e7eb);">
                                    <div class="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style="background:{{ $item['type'] === 'earning' ? '#00d4aa' : '#94a3b8' }};"></div>
                                    <div>
                                        <p class="text-xs" style="color:var(--text-primary, #0f172a);">{{ $item['desc'] }}</p>
                                        <p class="text-[10px]" style="color:var(--text-secondary, #94a3b8);">{{ $item['user'] }} &middot; {{ $item['date']?->format('d M Y H:i') }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
