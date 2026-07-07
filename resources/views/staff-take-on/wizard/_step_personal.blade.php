{{-- Step 2: Personal Details --}}
<form method="POST" action="{{ route('staff-take-on.save-step', [$takeOn, 'personal']) }}">
    @csrf
    @method('PATCH')

    <div class="space-y-4">
        <div class="p-4 rounded-md" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb);">
            <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">2. Personal Details</h4>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Date of Birth</label>
                    <input type="date" name="date_of_birth" value="{{ old('date_of_birth', $takeOn->user->date_of_birth?->format('Y-m-d')) }}" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Phone</label>
                    <input type="text" name="phone" value="{{ old('phone', $takeOn->user->phone) }}" maxlength="30" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Home Address</label>
                    <textarea name="home_address" rows="2" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">{{ old('home_address', $takeOn->user->home_address) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Marital Status</label>
                    <select name="marital_status" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                        <option value="">--</option>
                        @foreach(['single'=>'Single','married'=>'Married','divorced'=>'Divorced','widowed'=>'Widowed','life_partner'=>'Life Partner','other'=>'Other'] as $v => $l)
                            <option value="{{ $v }}" {{ old('marital_status', $takeOn->user->marital_status) === $v ? 'selected' : '' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Dependents</label>
                    <input type="number" name="dependents_count" min="0" value="{{ old('dependents_count', $takeOn->user->dependents_count ?? 0) }}" class="w-32 px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);">
                </div>
            </div>
        </div>

        <div class="p-4 rounded-md" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb);">
            <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Emergency Contact & Next of Kin</h4>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach([['emergency_contact', 'Emergency Contact'], ['next_of_kin', 'Next of Kin']] as [$prefix, $label])
                    <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">{{ $label }} Name</label>
                        <input type="text" name="{{ $prefix }}_name" value="{{ old("{$prefix}_name", $takeOn->user->{"{$prefix}_name"}) }}" maxlength="150" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);"></div>
                    <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">{{ $label }} Phone</label>
                        <input type="text" name="{{ $prefix }}_phone" value="{{ old("{$prefix}_phone", $takeOn->user->{"{$prefix}_phone"}) }}" maxlength="30" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);"></div>
                    <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">{{ $label }} Relationship</label>
                        <input type="text" name="{{ $prefix }}_relationship" value="{{ old("{$prefix}_relationship", $takeOn->user->{"{$prefix}_relationship"}) }}" maxlength="50" class="w-full px-3 py-2 text-sm rounded-md focus:outline-none" style="background:var(--surface, #fff); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a);"></div>
                @endforeach
            </div>
        </div>

        <button type="submit" class="corex-btn-primary">Save & Continue</button>
    </div>
</form>
