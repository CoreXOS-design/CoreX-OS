@extends('layouts.corex')

@section('corex-content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Dev Settings</h1>
            <p class="text-sm text-white/60">System-wide developer overrides. Use with care — these affect production behaviour.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('success') }}
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
                <p class="text-xs mt-2" style="color: var(--ds-amber, #d97706);">
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
                           class="w-5 h-5 rounded">
                </label>
            </div>
        </div>

        <div class="flex justify-end pt-4" style="border-top: 1px solid var(--border);">
            <button type="submit" class="corex-btn-primary">Save Settings</button>
        </div>
    </form>

</div>
@endsection
