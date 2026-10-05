@props([
    'lease' => null,
    'property' => null,
    'current' => null,
])

{{--
    .ai/specs/leases.md §12.4 / rentals-foundation-at439.md §5 — ONE shared
    rental context bar, included everywhere a lease/property/tenancy-scoped
    screen needs "where am I in this tenancy" navigation: the Lease Hub, the
    property's Rental tab, and (by a one-line @include handed to the owning
    lane, not built here) the Inspection/Fault Report/Work Order show
    screens. Takes EITHER a Lease (the normal case) OR a bare Property with
    no active lease (the vacancy case — faults/work-orders can exist with no
    lease). No per-screen reimplementation — every consumer includes this
    same component.

    AT-440 brief note: rentals-foundation-at439.md §5 also claims this
    component as an AT-439 (Stage 1) deliverable. As of this build it had
    not been created in that lane's worktree (checked directly) and the
    AT-440 task brief explicitly assigns the context bar to this ticket —
    built here per that explicit assignment; flagged as a spec conflict in
    this feature's own report for Johan/cc1 to reconcile, not resolved
    unilaterally. Do NOT build a second copy of this file in AT-439 — adopt
    this one.
--}}
@php
    /** @var \App\Models\Lease|null $lease */
    /** @var \App\Models\Property|null $property */
    $property = $property ?? $lease?->property;
@endphp

@if($property)
    @php
        $inspectionsCount = $lease ? $lease->inspections()->count() : \App\Models\RentalInspection::where('property_id', $property->id)->count();
        $faultsCount = $lease ? $lease->faultReports()->count() : \App\Models\RentalFaultReport::where('property_id', $property->id)->where('lease_id', null)->count();
        $workOrdersCount = $lease ? $lease->workOrders()->count() : \App\Models\RentalWorkOrder::where('property_id', $property->id)->where('lease_id', null)->count();
        $inventoriesCount = $lease ? $lease->inventories()->count() : \App\Models\RentalInventory::where('property_id', $property->id)->count();
        $documentsCount = $property->files()->count();
        $tenantNames = $lease ? $lease->tenantNames() : 'No active tenant';
        $landlords = $lease ? $lease->landlordContacts() : $property->contactsForRole('landlord')->merge($property->contactsForRole('lessor'))->unique('id');
        $landlordNames = $landlords->isEmpty() ? 'Not linked' : $landlords->map(fn ($c) => $c->full_name)->implode(', ');
        // AT-439 follow-up — same "no dead end" treatment as the Lease Hub
        // (corex/leases/show.blade.php): an empty landlord names a concrete
        // next step instead of just "Not linked".
        $landlordLinkRoute = route('corex.properties.show', ['property' => $property, 'tab' => 'contacts']);

        $chips = [
            'property' => ['label' => 'Property', 'route' => route('corex.properties.show', $property), 'count' => null],
            'lease' => ['label' => 'Lease', 'route' => $lease ? route('corex.leases.show', $lease) : route('corex.leases.index', ['property_id' => $property->id]), 'count' => null],
            'application' => ['label' => 'Application', 'route' => $lease?->rental_application_id ? route('corex.rental-applications.show', $lease->rental_application_id) : null, 'count' => null],
            'inspections' => ['label' => 'Inspections', 'route' => route('corex.rental-inspections.index', array_filter(['lease_id' => $lease?->id, 'property_id' => $property->id])), 'count' => $inspectionsCount],
            'faults' => ['label' => 'Faults', 'route' => route('corex.rental-fault-reports.index', array_filter(['lease_id' => $lease?->id, 'property_id' => $property->id])), 'count' => $faultsCount],
            'work_orders' => ['label' => 'Work orders', 'route' => route('corex.rental-work-orders.index', array_filter(['lease_id' => $lease?->id, 'property_id' => $property->id])), 'count' => $workOrdersCount],
            'inventory' => ['label' => 'Inventory', 'route' => route('corex.properties.show', ['property' => $property, 'tab' => 'inventory']), 'count' => $inventoriesCount],
            'documents' => ['label' => 'Documents', 'route' => route('corex.properties.show', ['property' => $property, 'tab' => 'drive']), 'count' => $documentsCount],
            'tenant' => ['label' => $tenantNames, 'route' => null, 'count' => null],
            'landlord' => ['label' => $landlordNames, 'route' => null, 'count' => null],
        ];
    @endphp
    <div class="flex flex-wrap items-center gap-2 rounded-md p-2 text-xs mb-3" style="background: var(--surface-2); border: 1px solid var(--border);">
        @foreach($chips as $key => $chip)
            @continue(!$chip['route'] && !in_array($key, ['tenant', 'landlord'], true))
            @php $isCurrent = $current === $key; @endphp
            @if($chip['route'])
                <a href="{{ $chip['route'] }}"
                   class="rounded-full px-3 py-1 no-underline"
                   style="{{ $isCurrent ? 'background: var(--brand-button, #0ea5e9); color: #fff;' : 'background: var(--surface-1, #fff); border: 1px solid var(--border);' }}">
                    {{ $chip['label'] }}{{ $chip['count'] !== null ? ' (' . $chip['count'] . ')' : '' }}
                </a>
            @else
                <span class="rounded-full px-3 py-1" style="background: var(--surface-1, #fff); border: 1px dashed var(--border); color: var(--text-muted);">
                    @if($key === 'landlord' && $landlords->isEmpty())
                        No landlord linked
                        <a href="{{ $landlordLinkRoute }}" class="underline" style="color: var(--brand-icon, #0ea5e9);">Link landlord</a>
                    @else
                        {{ $chip['label'] }}
                    @endif
                </span>
            @endif
        @endforeach
    </div>
@endif
