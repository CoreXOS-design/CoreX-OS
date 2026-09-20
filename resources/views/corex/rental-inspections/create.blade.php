@extends('layouts.corex')

{{--
    2026-09-20 — the list screen's own entry point for starting an
    inspection, added alongside the property Rental Images tab's existing
    AJAX "Start In/Out-Inspection" buttons, never replacing them. Only
    properties with an active lease are offered (RentalInspectionController::
    create()) — RentalInspection::start() hard-requires one.
--}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4">
    <h1 class="text-lg font-semibold">Start an Inspection</h1>

    <form method="POST" action="{{ route('corex.rental-inspections.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @if ($errors->any())
            <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="text-xs font-medium">Property</label>
            <select name="property_id" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="">Select a property…</option>
                @foreach($properties as $p)
                    <option value="{{ $p->id }}" @selected(old('property_id') == $p->id)>{{ $p->buildDisplayAddress() }}</option>
                @endforeach
            </select>
            @if($properties->isEmpty())
                <p class="text-xs mt-1" style="color: var(--text-muted);">No rental property currently has an active lease — an inspection needs one to attach to.</p>
            @endif
        </div>

        <div>
            <label class="text-xs font-medium">Type</label>
            <select name="type" required class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
                <option value="{{ \App\Models\RentalInspection::TYPE_IN }}" @selected(old('type') === \App\Models\RentalInspection::TYPE_IN)>In-inspection — move-in condition</option>
                <option value="{{ \App\Models\RentalInspection::TYPE_OUT }}" @selected(old('type') === \App\Models\RentalInspection::TYPE_OUT)>Out-inspection — move-out condition</option>
                <option value="{{ \App\Models\RentalInspection::TYPE_AD_HOC }}" @selected(old('type') === \App\Models\RentalInspection::TYPE_AD_HOC)>Ad-hoc — a mid-tenancy check</option>
            </select>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-inspections.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Start Inspection</button>
        </div>
    </form>
</div>
@endsection
