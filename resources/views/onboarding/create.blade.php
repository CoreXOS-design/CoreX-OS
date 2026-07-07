{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header (UI_DESIGN_SYSTEM §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">New Agent Application</h1>
        <p class="text-sm text-white/60">Start the onboarding process for a new agent.</p>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('onboarding.store') }}" class="space-y-5">
        @csrf

        {{-- Section 1: Personal Details --}}
        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Personal Details</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">First Name *</label>
                        <input type="text" name="first_name" value="{{ old('first_name') }}" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Last Name *</label>
                        <input type="text" name="last_name" value="{{ old('last_name') }}" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Email *</label>
                        <input type="email" name="email" value="{{ old('email') }}" required
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Phone</label>
                        <input type="text" name="phone" value="{{ old('phone') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">ID Number</label>
                        <input type="text" name="id_number" value="{{ old('id_number') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 2: Professional --}}
        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Professional</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Designation *</label>
                        <select name="designation" required class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="property_practitioner" {{ old('designation') === 'property_practitioner' ? 'selected' : '' }}>Property Practitioner</option>
                            <option value="candidate_practitioner" {{ old('designation') === 'candidate_practitioner' ? 'selected' : '' }}>Candidate Practitioner</option>
                            <option value="intern" {{ old('designation') === 'intern' ? 'selected' : '' }}>Intern</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Years Experience</label>
                        <input type="number" name="years_experience" value="{{ old('years_experience', 0) }}" min="0" max="50"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Current Agency</label>
                        <input type="text" name="current_agency" value="{{ old('current_agency') }}" placeholder="If applicable"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 3: PPRA --}}
        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">PPRA & FFC</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FFC Number</label>
                        <input type="text" name="ffc_number" value="{{ old('ffc_number') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">FFC Expiry</label>
                        <input type="date" name="ffc_expiry" value="{{ old('ffc_expiry') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">PPRA Status</label>
                        <input type="text" name="ppra_status" value="{{ old('ppra_status') }}" placeholder="e.g. Registered"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 4: Motivation --}}
        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Motivation</h3>
            </div>
            <div class="p-5">
                <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Why do they want to join?</label>
                <textarea name="motivation" rows="4" class="w-full rounded-md px-3 py-2 text-sm"
                          style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">{{ old('motivation') }}</textarea>
            </div>
        </div>

        {{-- Section 5: Referral --}}
        <div class="rounded-md overflow-hidden" style="background:var(--surface); border:1px solid var(--border);">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Referral</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">How did they hear about us?</label>
                        <input type="text" name="referral_source" value="{{ old('referral_source') }}"
                               class="w-full rounded-md px-3 py-2 text-sm"
                               style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Referred by Agent</label>
                        <select name="referred_by_user_id" class="w-full rounded-md px-3 py-2 text-sm"
                                style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            <option value="">None</option>
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" {{ (int) old('referred_by_user_id') === $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('onboarding.index') }}" class="text-sm no-underline" style="color:var(--text-secondary);">Cancel</a>
            <button type="submit" class="corex-btn-primary text-sm px-6 py-2.5">Create Application</button>
        </div>
    </form>
</div>
@endsection
