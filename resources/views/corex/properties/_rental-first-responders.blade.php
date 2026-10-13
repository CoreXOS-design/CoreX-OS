{{--
    .ai/specs/rentals-faults-work-orders.md section 21.3 - "Who looks at faults first", on a SETTLED rental property's Rental tab, under the work terms. Up to three
    people or companies, in order, each for all faults or only some types, told to inspect or for information only. The owner's own default (set on the owner's
    contact) applies where the property has nothing of its own; the line below says which one is in effect and where it comes from. Own form, own permission
    (rental_work_orders.manage_work_terms), change log underneath. Receives: $property.
--}}
@php
    $frSvc = app(\App\Services\Rentals\FirstResponderService::class);
    $frEffect = $frSvc->inEffect($property);
    $frData = $frSvc->editorData((int) $property->agency_id);
    $frChanges = \App\Models\RentalFirstResponderChange::query()->where('property_id', $property->id)->with('changedByUser')->orderByDesc('changed_at')->orderByDesc('id')->limit(30)->get();
    $frOwnerName = $frEffect['owner'] ? trim(($frEffect['owner']->first_name ?? '') . ' ' . ($frEffect['owner']->last_name ?? '')) : '';
    $frCanEdit = auth()->user()?->hasPermission('rental_work_orders.manage_work_terms');
    $frLine = fn ($entries) => $entries->map(fn ($e) => ($e->supplier?->name ?? 'a removed supplier') . ' (' . \App\Models\DealV2\AgencyServiceProvider::kindLabel($e->supplier?->kind) . ')'
        . ($e->fault_scope === \App\Models\RentalFirstResponder::SCOPE_TYPES ? ' - only some types of fault' : ''))->implode(', then ');
@endphp
<div id="first-responders-panel" x-data class="rounded-md p-4 text-sm space-y-3 mt-4" style="background: var(--surface-2); border: 1px solid var(--border);">
    <div>
        <strong>Who looks at faults first</strong>
        <p class="text-xs mt-1" style="color: var(--text-muted);">Who the agent sees first on a fault for this property. Nothing is sent automatically.</p>
    </div>

    <div class="text-xs rounded-md px-3 py-2" style="background: var(--surface); border: 1px solid var(--border);" data-first-responders-in-effect="{{ $frEffect['source'] }}">
        @if($frEffect['source'] === 'property')
            <strong>In effect: set for this property.</strong> {{ $frLine($frEffect['property_entries']) }}.
            @if($frEffect['owner_fills_rest'])
                <div class="mt-1">For the other types of fault: from the owner's default ({{ $frOwnerName ?: 'the owner' }}) - {{ $frLine($frEffect['owner_entries']) }}.</div>
            @endif
        @elseif($frEffect['source'] === 'owner')
            <strong>In effect: from the owner's default ({{ $frOwnerName ?: 'the owner' }}).</strong> {{ $frLine($frEffect['owner_entries']) }}.
            @if($frCanEdit)
                <button type="button" class="underline ml-1" data-first-responders-change-here
                        @click="$dispatch('first-responders-copy', { rows: {{ \Illuminate\Support\Js::from($frEffect['owner_entries']->map(fn ($e) => $frSvc->editorRow($e))->values()->all()) }} })">Change for this property</button>
            @endif
        @else
            <strong>Nobody is set up yet.</strong> Faults on this property go to the agent as usual.
        @endif
    </div>

    @include('corex.rentals._first-responders-editor', [
        'frPrefix' => 'prop-fr', 'frAction' => route('corex.properties.rental-first-responders.update', $property), 'frEntries' => $frEffect['property_entries'],
        'frSuppliers' => $frData['suppliers'], 'frFaultTypes' => $frData['faultTypes'], 'frCanEdit' => $frCanEdit, 'frChanges' => $frChanges,
    ])
    @if(! $frCanEdit && $frEffect['property_entries']->isEmpty() && $frEffect['source'] === 'none')
        <p class="text-xs" style="color: var(--text-muted);">Only someone who manages the owner's work terms can set this up.</p>
    @endif
</div>
@if($frCanEdit && auth()->user()?->hasPermission('deals_v2.manage_suppliers'))
    @include('partials.contractor-popups')
@endif
