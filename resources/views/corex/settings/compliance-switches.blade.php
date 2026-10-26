@extends('layouts.corex')

{{-- AT-509 — Settings → Agency → Compliance Switches (.ai/specs/property-compliance-gate-settings.md §7.1). --}}

@section('corex-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Compliance Switches</h1>
                <p class="text-sm text-white/60">Choose which compliance checks a property must pass before it can be marketed.</p>
            </div>
            <a href="{{ route('corex.settings') }}" class="corex-btn-outline text-xs">Back to Settings</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any() && !$errors->has('compliance_disclaimer'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.compliance-switches.update') }}" class="space-y-4">
        @csrf
        @include('corex.settings.partials.compliance-switches-form')
        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Save</button>
        </div>
    </form>
</div>
@endsection
