{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Rental Application Settings</h1>
        <p class="text-xs" style="color: var(--text-muted);">
            The supporting-document checklist shown per employment type. Nothing here is ever
            enforced at submission — it only drives what shows as outstanding on a returned application.
        </p>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--ds-emerald-soft, #ecfdf5); color: var(--ds-emerald, #059669);">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('corex.settings.rental-applications.update') }}" class="space-y-4">
        @csrf

        @foreach(\App\Models\RentalApplication::EMPLOYMENT_TYPES as $type)
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold" style="color: var(--text-primary);">{{ str_replace('_', ' ', ucfirst($type)) }}</h2>
                @if($isConfigured[$type])
                    <span class="ds-badge ds-badge-info">Saved{{ empty($checklists[$type]) ? ' — none required' : '' }}</span>
                @else
                    <span class="ds-badge ds-badge-default" title="Showing the standard checklist — not yet saved for this agency">Default (not yet saved)</span>
                @endif
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach($documentTypes as $dt)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="checklists[{{ $type }}][]" value="{{ $dt->id }}"
                               @checked(in_array($dt->id, $checklists[$type]))>
                        {{ $dt->label }}
                    </label>
                @endforeach
            </div>
        </div>
        @endforeach

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-xs">Save Checklist</button>
        </div>
    </form>
</div>
@endsection
