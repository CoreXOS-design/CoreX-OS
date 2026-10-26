@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §7/§0a/§15 — the entry point for starting an
    inventory. Every property is offered (RentalInventoryController::
    create()) — a property with an active lease gets a lease-attached
    inventory (RentalInventory::start()), any other property (a sale, or a
    rental between tenancies) gets a property-level one (RentalInventory::
    startForProperty()). No "type" picker (unlike Start an Inspection) — an
    inventory is produced once, not a repeated in/out event.
--}}

@section('corex-content')
@include('corex.rentals.partials._page-styles')
<div class="rx-page w-full space-y-5">
    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Start an Inventory</h1>
            <p class="text-xs" style="color: var(--text-muted);">Pick the property. A property with an active lease gets an inventory attached to that lease.</p>
        </div>
    </div>

    <form method="POST" action="{{ route('corex.rental-inventories.store') }}" class="max-w-2xl space-y-4 rounded-md px-5 pb-5 pt-1" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf

        @if ($errors->any())
            <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-red, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-red, #dc2626) 30%, transparent); color: var(--text-primary);">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label class="prop-label">Property</label>
            <select name="property_id" required class="prop-select">
                <option value="">Select a property…</option>
                @foreach($properties as $p)
                    <option value="{{ $p->id }}" @selected(old('property_id') == $p->id)>{{ $p->buildDisplayAddress() }}</option>
                @endforeach
            </select>
            @if($properties->isEmpty())
                <p class="text-xs mt-1" style="color: var(--text-muted);">No properties exist yet.</p>
            @endif
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-inventories.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Start Inventory</button>
        </div>
    </form>
</div>
@endsection
