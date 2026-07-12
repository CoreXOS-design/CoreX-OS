{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
{{-- Full-width container (no extra px-*/py-* — the <main> wrapper already applies
     p-4 / lg:p-6), so the branded header spans the page like the Properties,
     Contacts and Core Matches pages. --}}
<div class="w-full space-y-5">

    {{-- Page header (UI_DESIGN_SYSTEM §2.4 Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">New Agent Application</h1>
                <p class="text-sm text-white/60">
                    Start the onboarding process for a new agent — the onboarding checklist is seeded automatically.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('onboarding.index') }}" class="corex-btn-outline no-underline">Back to Pipeline</a>
            </div>
        </div>
    </div>

    {{-- Validation summary (UI_DESIGN_SYSTEM §3.9 — danger variant) --}}
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24"
                 stroke-width="1.8" stroke="currentColor" style="color: var(--ds-crimson, #c41e3a);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">
                <strong>This application could not be created.</strong>
                <ul class="list-disc list-inside space-y-1 mt-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('onboarding.store') }}" class="space-y-5">
        @csrf

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

            {{-- ── Left column: the applicant's own details ── --}}
            <div class="xl:col-span-2 space-y-5">

                {{-- Personal Details (UI_DESIGN_SYSTEM §3.3 card) --}}
                <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Personal Details</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="first_name" class="prop-label">First Name <span class="prop-required">*</span></label>
                            <input id="first_name" type="text" name="first_name" value="{{ old('first_name') }}" required
                                   placeholder="e.g. John" class="prop-input">
                            @error('first_name')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="last_name" class="prop-label">Last Name <span class="prop-required">*</span></label>
                            <input id="last_name" type="text" name="last_name" value="{{ old('last_name') }}" required
                                   placeholder="e.g. Smith" class="prop-input">
                            @error('last_name')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="email" class="prop-label">Email <span class="prop-required">*</span></label>
                            <input id="email" type="email" name="email" value="{{ old('email') }}" required
                                   placeholder="e.g. john@example.com" class="prop-input">
                            @error('email')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="prop-label">Phone</label>
                            <input id="phone" type="text" name="phone" value="{{ old('phone') }}"
                                   placeholder="e.g. 082 123 4567" class="prop-input">
                            @error('phone')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="id_number" class="prop-label">ID Number</label>
                            <input id="id_number" type="text" name="id_number" value="{{ old('id_number') }}"
                                   inputmode="numeric" maxlength="13" placeholder="e.g. 7610025020081" class="prop-input">
                            <p class="mt-1 text-[11px]" style="color: var(--text-muted);">SA ID — 13 digits. Leave blank if not known.</p>
                            @error('id_number')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                {{-- Professional --}}
                <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Professional</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="designation" class="prop-label">Designation <span class="prop-required">*</span></label>
                            <select id="designation" name="designation" required class="prop-select">
                                @foreach(\App\Models\AgentApplication::DESIGNATION_LABELS as $key => $label)
                                    <option value="{{ $key }}" {{ old('designation') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('designation')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="years_experience" class="prop-label">Years Experience</label>
                            <input id="years_experience" type="number" name="years_experience"
                                   value="{{ old('years_experience', 0) }}" min="0" max="50" step="1" class="prop-input">
                            @error('years_experience')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="current_agency" class="prop-label">Current Agency</label>
                            <input id="current_agency" type="text" name="current_agency" value="{{ old('current_agency') }}"
                                   placeholder="If applicable" class="prop-input">
                            @error('current_agency')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                {{-- PPRA & FFC --}}
                <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="text-lg font-semibold mb-4" style="color: var(--text-primary);">PPRA &amp; FFC</div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="ffc_number" class="prop-label">FFC Number</label>
                            <input id="ffc_number" type="text" name="ffc_number" value="{{ old('ffc_number') }}" class="prop-input">
                            @error('ffc_number')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="ffc_expiry" class="prop-label">FFC Expiry</label>
                            <input id="ffc_expiry" type="date" name="ffc_expiry" value="{{ old('ffc_expiry') }}" class="prop-input">
                            @error('ffc_expiry')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="ppra_status" class="prop-label">PPRA Status</label>
                            <input id="ppra_status" type="text" name="ppra_status" value="{{ old('ppra_status') }}"
                                   placeholder="e.g. Registered" class="prop-input">
                            @error('ppra_status')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Right column: context on the application ── --}}
            <div class="space-y-5">

                {{-- Motivation --}}
                <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Motivation</div>
                    <label for="motivation" class="prop-label">Why do they want to join?</label>
                    <textarea id="motivation" name="motivation" rows="6" maxlength="5000"
                              class="prop-textarea">{{ old('motivation') }}</textarea>
                    @error('motivation')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                </div>

                {{-- Referral --}}
                <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Referral</div>
                    <div class="space-y-4">
                        <div>
                            <label for="referral_source" class="prop-label">How did they hear about us?</label>
                            <input id="referral_source" type="text" name="referral_source" value="{{ old('referral_source') }}"
                                   class="prop-input">
                            @error('referral_source')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="referred_by_user_id" class="prop-label">Referred by Agent</label>
                            <select id="referred_by_user_id" name="referred_by_user_id" class="prop-select">
                                <option value="">None</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}" {{ (int) old('referred_by_user_id') === $agent->id ? 'selected' : '' }}>{{ $agent->name }}</option>
                                @endforeach
                            </select>
                            @error('referred_by_user_id')<p class="mt-1 text-[11px]" style="color: var(--ds-crimson, #c41e3a);">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="rounded-md px-4 py-3 flex flex-wrap items-center justify-end gap-2"
             style="background: var(--surface); border: 1px solid var(--border);">
            <a href="{{ route('onboarding.index') }}" class="corex-btn-outline no-underline">Cancel</a>
            <button type="submit" class="corex-btn-primary">Create Application</button>
        </div>
    </form>
</div>
@endsection
