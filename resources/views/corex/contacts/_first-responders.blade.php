{{--
    .ai/specs/rentals-faults-work-orders.md section 21.3 - "This owner's default for all their properties": up to three people or companies who look at a fault first on
    every rental property this contact is the landlord / owner of, unless that property has its own list (it then overrides this one, for the fault types it
    covers). Shown only when the contact is a landlord-side contact of a property (or already has a default) and rentals are on. Receives: $contact.
--}}
@if(feature('rentals'))
@php
    $ofSvc = app(\App\Services\Rentals\FirstResponderService::class);
    $ofEntries = $ofSvc->ownerEntries($contact);
    $ofProps = $contact->properties()->get()->filter(fn ($p) => in_array(strtolower(trim((string) ($p->pivot->role ?? ''))), ['landlord', 'owner', 'seller', 'lessor'], true)
        && strtolower((string) $p->listing_type) === 'rental');
@endphp
@if($ofProps->isNotEmpty() || $ofEntries->isNotEmpty())
@php
    $ofData = $ofSvc->editorData((int) $contact->agency_id);
    $ofChanges = \App\Models\RentalFirstResponderChange::query()->where('contact_id', $contact->id)->with('changedByUser')->orderByDesc('changed_at')->orderByDesc('id')->limit(30)->get();
    $ofCanEdit = auth()->user()?->hasPermission('contacts.edit') && auth()->user()?->hasPermission('rental_work_orders.manage_work_terms');
    $ofOverrides = \App\Models\RentalFirstResponder::query()->whereIn('property_id', $ofProps->pluck('id'))->whereNull('deleted_at')->pluck('property_id')->unique();
@endphp
<div id="owner-first-responders-panel" class="rounded-md p-4 text-sm space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-owner-first-responders>
    <div>
        <h3 class="text-sm font-semibold" style="color: var(--text-primary);">This owner's default for all their properties - who looks at faults first</h3>
        <p class="text-xs mt-1" style="color: var(--text-muted);">
            Up to three people or companies, in order, whom the agent sees first on a fault at any rental property this owner owns. A property with its own list uses that one instead
            (for the types of fault it covers). Nothing is sent by itself.
        </p>
    </div>
    @if(session('owner_first_responders_saved') && session('success'))
        <div class="rounded-md px-3 py-2 text-xs font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);" data-owner-first-responders-flash>{{ session('success') }}</div>
    @endif
    @if($ofProps->isNotEmpty())
        <div class="text-xs" data-owner-first-responders-properties>
            <strong>Their rental properties:</strong>
            @foreach($ofProps as $op)
                <a href="{{ route('corex.properties.show', $op) }}?tab=rental" class="underline">{{ $op->buildDisplayAddress() }}</a>@if($ofOverrides->contains($op->id)) <span class="ds-badge ds-badge-info">has its own list</span>@endif{{ $loop->last ? '' : ',' }}
            @endforeach
        </div>
    @endif
    @include('corex.rentals._first-responders-editor', [
        'frPrefix' => 'owner-fr', 'frAction' => route('corex.contacts.first-responders.update', $contact), 'frEntries' => $ofEntries,
        'frSuppliers' => $ofData['suppliers'], 'frFaultTypes' => $ofData['faultTypes'], 'frCanEdit' => $ofCanEdit, 'frChanges' => $ofChanges,
    ])
    @if(! $ofCanEdit && $ofEntries->isEmpty())
        <p class="text-xs" style="color: var(--text-muted);">Nobody is set. Only someone who can edit contacts and manage work terms can change this.</p>
    @endif
</div>
@if($ofCanEdit && auth()->user()?->hasPermission('deals_v2.manage_suppliers'))
    @include('partials.contractor-popups')
@endif
@endif
@endif
