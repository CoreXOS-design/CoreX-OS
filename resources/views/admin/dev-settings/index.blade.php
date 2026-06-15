@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Dev Settings</h1>
                <p class="text-sm text-white/60">System-wide developer overrides. Use with care — these affect production behaviour.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: var(--ds-green, #059669);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.dev-settings.update') }}"
          class="rounded-md p-6 space-y-6"
          style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @method('PUT')

        <div class="flex items-start justify-between gap-6">
            <div class="flex-1">
                <label for="compliance_checks_disabled" class="block font-semibold" style="color: var(--text-primary);">
                    Disable property compliance checks
                </label>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">
                    When enabled, properties bypass the marketing readiness gates (mandate / FICA / photos / details)
                    and can be uploaded and syndicated without compliance being completed.
                </p>
                <p class="text-xs mt-2" style="color: var(--ds-amber, #f59e0b);">
                    Warning: this is a global override intended for development and bulk imports. Disable as soon as you are done.
                </p>
            </div>
            <div class="pt-1">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="hidden" name="compliance_checks_disabled" value="0">
                    <input type="checkbox"
                           id="compliance_checks_disabled"
                           name="compliance_checks_disabled"
                           value="1"
                           {{ $complianceChecksDisabled ? 'checked' : '' }}
                           class="w-5 h-5 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                </label>
            </div>
        </div>

        <div class="flex items-start justify-between gap-6 pt-6" style="border-top: 1px solid var(--border);">
            <div class="flex-1">
                <label for="demo_mode_enabled" class="block font-semibold" style="color: var(--text-primary);">
                    Enable demo mode (bypass login)
                </label>
                <p class="text-sm mt-1" style="color: var(--text-secondary);">
                    When enabled, the login screen is replaced with four role buttons (Admin, Branch Manager, Agent, Viewer).
                    Clicking a button signs the visitor in as a demo user with that role — no password required.
                    For use on demo.corexos.co.za only.
                </p>
                <p class="text-xs mt-2" style="color: var(--ds-crimson, #c41e3a);">
                    DANGER: This is an authentication bypass. It is hard-disabled on production (APP_ENV=production) regardless of this toggle.
                    @if($isProduction)
                        <strong>This server is running APP_ENV=production — the toggle has no effect here.</strong>
                    @endif
                </p>
            </div>
            <div class="pt-1">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="hidden" name="demo_mode_enabled" value="0">
                    <input type="checkbox"
                           id="demo_mode_enabled"
                           name="demo_mode_enabled"
                           value="1"
                           {{ $demoModeEnabled ? 'checked' : '' }}
                           class="w-5 h-5 rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                </label>
            </div>
        </div>

        <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary">Save Settings</button>
        </div>
    </form>

</div>
@endsection
