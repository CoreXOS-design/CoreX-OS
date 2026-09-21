@extends('layouts.corex')

{{--
    .ai/specs/rental-inventory.md §7 — the entry point for starting an
    inventory. Only properties with an active lease are offered
    (RentalInventoryController::create()) — RentalInventory::start() hard-
    requires one. No "type" picker (unlike Start an Inspection) — an
    inventory is produced once at move-in, not a repeated in/out event.
--}}

@section('content')
<div class="p-6 max-w-2xl mx-auto space-y-4">
    <h1 class="text-lg font-semibold">Start an Inventory</h1>

    <form method="POST" action="{{ route('corex.rental-inventories.store') }}" class="space-y-4 rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
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
                <p class="text-xs mt-1" style="color: var(--text-muted);">No rental property currently has an active lease — an inventory needs one to attach to.</p>
            @endif
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-inventories.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Start Inventory</button>
        </div>
    </form>
</div>
@endsection
