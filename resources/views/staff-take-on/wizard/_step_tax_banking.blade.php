{{-- Step 3: Tax & Banking --}}
@php $banking = $takeOn->user->bankingDetail; @endphp
<form method="POST" action="{{ route('staff-take-on.save-step', [$takeOn, 'tax_banking']) }}">
    @csrf
    @method('PATCH')

    <div class="space-y-4">
        <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">3. Tax Details</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Tax Reference Number</label>
                    <input type="text" name="tax_reference_number" value="{{ old('tax_reference_number', $takeOn->user->tax_reference_number) }}" maxlength="20" placeholder="e.g. 0123456789" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                </div>
            </div>
        </div>

        <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Banking</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Account Holder</label>
                    <input type="text" name="account_holder" value="{{ old('account_holder', $banking->account_holder ?? $takeOn->user->name) }}" maxlength="150" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"></div>
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Bank</label>
                    <select name="bank_name" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                        <option value="">-- Select --</option>
                        @foreach(['ABSA','African Bank','Bidvest Bank','Capitec','Discovery Bank','FNB','Investec','Nedbank','Standard Bank','TymeBank','Other'] as $b)
                            <option value="{{ $b }}" {{ ($banking->bank_name ?? '') === $b ? 'selected' : '' }}>{{ $b }}</option>
                        @endforeach
                    </select></div>
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Branch Code</label>
                    <input type="text" name="branch_code" value="{{ old('branch_code', $banking->branch_code ?? '') }}" maxlength="10" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"></div>
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Account Number</label>
                    <input type="text" name="account_number" value="{{ old('account_number', $banking->account_number ?? '') }}" maxlength="30" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"></div>
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Account Type</label>
                    <div class="flex gap-4 mt-1">
                        @foreach(['cheque'=>'Cheque','savings'=>'Savings','transmission'=>'Transmission'] as $v => $l)
                            <label class="flex items-center gap-1.5 text-sm cursor-pointer"><input type="radio" name="account_type" value="{{ $v }}" {{ ($banking->account_type ?? 'cheque') === $v ? 'checked' : '' }} style="accent-color:var(--brand-icon);"> {{ $l }}</label>
                        @endforeach
                    </div></div>
            </div>
        </div>

        <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Medical Aid (optional)</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Provider</label>
                    <input type="text" name="medical_aid_provider" value="{{ old('medical_aid_provider', $takeOn->user->medical_aid_provider) }}" maxlength="100" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"></div>
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Member Number</label>
                    <input type="text" name="medical_aid_number" value="{{ old('medical_aid_number', $takeOn->user->medical_aid_number) }}" maxlength="50" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"></div>
                <div><label class="flex items-center gap-2 text-sm cursor-pointer mt-3"><input type="hidden" name="medical_aid_main_member" value="0"><input type="checkbox" name="medical_aid_main_member" value="1" {{ old('medical_aid_main_member', $takeOn->user->medical_aid_main_member) ? 'checked' : '' }} style="accent-color:var(--brand-icon);"> Main member</label></div>
                <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Dependents on medical aid</label>
                    <input type="number" name="medical_aid_dependents_count" min="0" value="{{ old('medical_aid_dependents_count', $takeOn->user->medical_aid_dependents_count ?? 0) }}" class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;"></div>
            </div>
        </div>

        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:var(--brand-icon); border-radius:6px;">Save & Continue</button>
    </div>
</form>
