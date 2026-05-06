@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Add Employee to Payroll" :back-route="route('payroll.employees.index')" back-label="Employees" :flush="true" />

    <div class="p-4 lg:p-6">
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:3px; color:#ef4444;">{{ session('error') }}</div>
        @endif

        @if($eligibleUsers->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                All active users are already on payroll. Add a new user from User Management first.
            </div>
        @else
        <form method="POST" action="{{ route('payroll.employees.store') }}">
            @csrf

            @php
                $usersArray = $eligibleUsers->map(fn($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'designation' => $u->designation,
                    'id_number' => $u->id_number,
                    'date_of_birth' => optional($u->date_of_birth)->format('Y-m-d'),
                    'branch_id' => $u->branch_id,
                ])->values()->all();
            @endphp

            <div class="max-w-3xl space-y-6" x-data="{
                selectedUserId: '{{ old('user_id', '') }}',
                selectedUser: null,
                showBanking: {{ old('bank_name') ? 'true' : 'false' }},
                users: {{ Js::from($usersArray) }},
                init() {
                    if (this.selectedUserId) {
                        this.selectedUser = this.users.find(u => u.id == this.selectedUserId) || null;
                    }
                },
                selectUser() {
                    this.selectedUser = this.users.find(u => u.id == this.selectedUserId) || null;
                }
            }">
                {{-- SECTION 1: Select User --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em; font-family:'Plus Jakarta Sans',sans-serif;">1. Select User</h4>

                    <select name="user_id" x-model="selectedUserId" @change="selectUser()" required
                            class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        <option value="">-- Choose a user --</option>
                        @foreach($eligibleUsers as $user)
                            <option value="{{ $user->id }}" {{ old('user_id') == $user->id ? 'selected' : '' }}>
                                {{ $user->name }} ({{ $user->designation ?? 'No designation' }}) — {{ $user->email }}
                            </option>
                        @endforeach
                    </select>
                    @error('user_id') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

                    {{-- Preview card --}}
                    <div x-show="selectedUser" x-cloak class="mt-3 p-3 flex items-center gap-3" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:3px;">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white" style="background:#00d4aa;">
                            <span x-text="selectedUser ? selectedUser.name.charAt(0).toUpperCase() : ''"></span>
                        </div>
                        <div>
                            <p class="text-sm font-semibold" style="color:var(--text-primary, #0f172a);" x-text="selectedUser?.name"></p>
                            <p class="text-[11px]" style="color:var(--text-secondary, #94a3b8);">
                                <span x-text="selectedUser?.designation || 'No designation'"></span> &middot;
                                <span x-text="selectedUser?.email"></span>
                            </p>
                            <p class="text-[11px]" style="color:var(--text-secondary, #94a3b8);" x-show="selectedUser?.id_number">
                                ID: <span x-text="selectedUser?.id_number"></span>
                            </p>
                        </div>
                    </div>
                </div>

                {{-- SECTION 2: Employment Details (revealed after user selected) --}}
                <div x-show="selectedUserId" x-cloak class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em; font-family:'Plus Jakarta Sans',sans-serif;">2. Employment Details</h4>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Employment Date <span class="text-red-500">*</span></label>
                            <input type="date" name="employment_date" value="{{ old('employment_date', date('Y-m-d')) }}" required
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                            @error('employment_date') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Designation <span class="text-red-500">*</span></label>
                            <input type="text" name="designation_snapshot" required maxlength="100"
                                   :value="selectedUser?.designation || '{{ old('designation_snapshot', '') }}'"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;"
                                   placeholder="e.g. Office Administrator">
                            @error('designation_snapshot') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Date of Birth</label>
                            <input type="date" name="date_of_birth"
                                   :value="selectedUser?.date_of_birth || '{{ old('date_of_birth', '') }}'"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                            <p class="text-[10px] mt-0.5" style="color:var(--text-secondary, #94a3b8);">Used for PAYE age rebate. Auto-derived from ID number if available.</p>
                            @error('date_of_birth') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Tax Reference Number</label>
                            <input type="text" name="tax_reference_number" value="{{ old('tax_reference_number', '') }}" maxlength="20"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;"
                                   placeholder="e.g. 0123456789">
                            @error('tax_reference_number') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Pay Day of Month <span class="text-red-500">*</span></label>
                            <input type="number" name="pay_day_of_month" value="{{ old('pay_day_of_month', 25) }}" required min="1" max="31"
                                   class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                            @error('pay_day_of_month') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Notes</label>
                        <textarea name="notes" rows="2" maxlength="2000"
                                  class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;"
                                  placeholder="Optional notes about this employee">{{ old('notes', '') }}</textarea>
                    </div>
                </div>

                {{-- SECTION 3: Banking (collapsible, optional) --}}
                <div x-show="selectedUserId" x-cloak class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em; font-family:'Plus Jakarta Sans',sans-serif;">3. Banking Details</h4>
                        <label class="relative inline-flex items-center cursor-pointer gap-2">
                            <input type="checkbox" x-model="showBanking" class="sr-only peer">
                            <div class="w-9 h-5 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-4" style="background:var(--border, #cbd5e1); border-radius:10px; transition:background 0.2s;" :style="showBanking ? 'background:#00d4aa' : ''"></div>
                            <span class="text-xs" style="color:var(--text-secondary, #6b7280);">Add now (optional)</span>
                        </label>
                    </div>

                    <div x-show="showBanking" x-cloak class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Account Holder</label>
                            <input type="text" name="account_holder"
                                   :value="selectedUser?.name || '{{ old('account_holder', '') }}'"
                                   maxlength="150"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Bank</label>
                            <select name="bank_name" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                                <option value="">-- Select bank --</option>
                                @foreach(['ABSA', 'African Bank', 'Bidvest Bank', 'Capitec', 'Discovery Bank', 'FNB', 'Investec', 'Nedbank', 'Standard Bank', 'TymeBank', 'Other'] as $bank)
                                    <option value="{{ $bank }}" {{ old('bank_name') === $bank ? 'selected' : '' }}>{{ $bank }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Branch Code</label>
                            <input type="text" name="branch_code" value="{{ old('branch_code', '') }}" maxlength="10"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;"
                                   placeholder="e.g. 250655">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Account Number</label>
                            <input type="text" name="account_number" value="{{ old('account_number', '') }}" maxlength="30"
                                   class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Account Type</label>
                            <div class="flex gap-4 mt-1">
                                @foreach(['cheque' => 'Cheque', 'savings' => 'Savings', 'transmission' => 'Transmission'] as $val => $lbl)
                                    <label class="flex items-center gap-1.5 text-sm cursor-pointer" style="color:var(--text-primary, #0f172a);">
                                        <input type="radio" name="account_type" value="{{ $val }}" {{ old('account_type', 'cheque') === $val ? 'checked' : '' }}
                                               style="accent-color:#00d4aa;">
                                        {{ $lbl }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                {{-- SECTION 4: Default Earnings --}}
                <div x-show="selectedUserId" x-cloak class="p-3 text-xs" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:3px; color:var(--text-secondary, #6b7280);">
                    <strong style="color:#00d4aa;">Earnings:</strong> Basic Salary will be added at R0. You can update it and add more earnings on the next screen.
                </div>

                {{-- SECTION 5: Default Deductions --}}
                <div x-show="selectedUserId" x-cloak class="p-3 text-xs" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:3px; color:var(--text-secondary, #6b7280);">
                    <strong style="color:#00d4aa;">Deductions:</strong> PAYE and UIF will be auto-calculated each run. You can add custom deductions on the next screen.
                </div>

                {{-- Actions --}}
                <div x-show="selectedUserId" x-cloak class="flex items-center gap-3 pt-2">
                    <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                        Add to Payroll
                    </button>
                    <a href="{{ route('payroll.employees.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px;">Cancel</a>
                </div>
            </div>
        </form>
        @endif
    </div>
</div>
@endsection
