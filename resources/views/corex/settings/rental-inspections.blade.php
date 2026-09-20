@extends('layouts.corex')

{{--
    .ai/specs/agency-onboarding-rentals-step.md — Johan's standing rule: any
    threshold is agency-configurable with a sensible default, never
    hardcoded, and the control ships with the feature.
--}}

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Rental Inspection Settings</h1>
            <p class="text-sm text-white/60">How long a tenant has to report a fault, and how long they have to sign an out-inspection.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.rental-inspections.update') }}" class="space-y-4"
          x-data="{ presets: {{ Js::from(collect($refusalReasonPresets)->reject(fn($p) => $p['key'] === 'other')->values()) }} }">
        @csrf

        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Inspection windows</h3>
            </div>
            <div class="p-5 space-y-5">
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Days a tenant has to report a fault after moving in</label>
                    <input type="number" name="fault_report_window_days" value="{{ old('fault_report_window_days', $faultReportWindowDays) }}"
                           min="1" max="90" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $defaultFaultReportDays }} days. A report after this window still
                        reaches the agent — it is just their call whether to accept it.
                    </p>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-muted);">Days a tenant has to sign the out-inspection</label>
                    <input type="number" name="out_inspection_signing_window_days" value="{{ old('out_inspection_signing_window_days', $signingWindowDays) }}"
                           min="1" max="60" required
                           class="w-full max-w-[160px] rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                    <p class="text-xs mt-2" style="color: var(--text-muted);">
                        Default is {{ $defaultSigningDays }} days. After this, an agent may sign on the
                        tenant's behalf, with a note recording why.
                    </p>
                </div>
            </div>
        </div>

        {{-- §15.5/§15.6 — the one-tap preset list an agent picks a refusal
             reason from. "Other" is always available and is never listed
             here — it can't be removed or reworded, so there's nothing to
             edit about it. --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border); background:color-mix(in srgb, var(--brand-icon, #0ea5e9) 5%, transparent);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary);">Refusal reasons</h3>
            </div>
            <div class="p-5 space-y-3">
                <p class="text-xs" style="color: var(--text-muted);">
                    When a tenant or landlord refuses to sign an inspection, the agent picks one of
                    these reasons (plus "Other", always available, not editable here).
                </p>
                <template x-for="(preset, i) in presets" :key="i">
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="preset.label" :name="`refusal_reason_presets[${i}][label]`"
                               maxlength="191" required
                               class="flex-1 rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
                        <input type="hidden" :name="`refusal_reason_presets[${i}][key]`" :value="preset.key">
                        <button type="button" @click="presets.splice(i, 1)"
                                class="text-xs font-semibold px-2 py-1 rounded-md" style="color: var(--ds-crimson);">Remove</button>
                    </div>
                </template>
                <button type="button"
                        @click="presets.push({ key: 'custom_' + Date.now(), label: '' })"
                        class="corex-btn-outline text-xs">+ Add a reason</button>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
