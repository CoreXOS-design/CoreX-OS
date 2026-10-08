{{-- The "Create work order" form of a fault (Johan, 8 Oct 2026: the agent only chooses WHO does the work). Included twice, never together:
     as the PRIMARY step once the owner has approved (appoint contractor), and as the confirmed SECONDARY step before the owner has decided.
     Expects the fault screen's variables: $faultReport, $latestDecision, $prefillSupplier, $prefillOwner, $decisionSummary, $contractorPicker. --}}
@php
    $ownersOwnContractor = $prefillOwner !== null || ($latestDecision && $latestDecision->decision === \App\Models\RentalApproval::DECISION_APPROVED && $faultReport->approval_route === \App\Models\RentalFaultReport::ROUTE_OWNER_HANDLES);
    $agencyAppoints = ! $ownersOwnContractor && $latestDecision && $latestDecision->decision === \App\Models\RentalApproval::DECISION_APPROVED;
    $defaultWho = $agencyAppoints ? \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER : \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL;
@endphp
<form id="raise-work-order-form" data-raise-work-order method="POST" action="{{ route('corex.rental-fault-reports.raise-work-order', $faultReport) }}"
      class="{{ ($secondary ?? false) || ! request()->boolean('create_work_order') ? 'hidden' : '' }} space-y-3 pt-2"
      x-data="{ q: '', who: '{{ $defaultWho }}', picked: '{{ $prefillSupplier?->id }}', busy: false }" x-on:submit="busy = true">
    @csrf
    <p class="text-xs" style="color: var(--text-muted);">The work order is made from this fault - the title, description and photos the owner saw, the property and the tenant. You only choose who does the work.</p>

    @if($ownersOwnContractor)
        <input type="hidden" name="assignment_type" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR }}">
        <input type="hidden" name="contractor_name" value="{{ $latestDecision?->contractor_name }}">
        <input type="hidden" name="contractor_phone" value="{{ $latestDecision?->contractor_phone }}">
        <div class="rounded-md p-3 text-sm" style="background: var(--surface-2);" data-owner-contractor>
            <div class="text-xs" style="color: var(--text-muted);">Owner's contractor &mdash; the owner approved and chose their own</div>
            <div class="font-medium">{{ $latestDecision?->contractor_name ?: 'Name not given' }}{{ $latestDecision?->contractor_phone ? ' - ' . $latestDecision->contractor_phone : '' }}</div>
        </div>
    @else
        <input type="hidden" name="assignment_type" x-bind:value="who">
        <input type="hidden" name="agency_service_provider_id" value="{{ $prefillSupplier?->id }}" x-bind:value="who === 'outside_supplier' ? picked : ''">
        <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm"><input type="radio" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL }}" x-model="who" data-who-internal> Our own maintenance crew <span class="text-xs" style="color: var(--text-muted);">(also makes the job card)</span></label>
            @if(! $decisionSummary)
                <label class="flex items-center gap-2 text-sm"><input type="radio" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR }}" x-model="who"> The owner's own contractor <span class="text-xs" style="color: var(--text-muted);">(no owner decision recorded yet)</span></label>
            @endif
            <label class="flex items-center gap-2 text-sm"><input type="radio" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}" x-model="who" data-who-contractor> One of the agency's contractors</label>
        </div>

        <div x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}'" x-cloak class="space-y-2" data-contractor-picker>
            <input type="search" x-model="q" placeholder="Search contractors by name, trade or phone" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            @forelse($contractorPicker as $c)
                <label class="flex items-center gap-2 text-sm rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-contractor-row="{{ $c['id'] }}"
                       data-search="{{ mb_strtolower($c['name'] . ' ' . $c['trades'] . ' ' . $c['phone']) }}"
                       x-show="q === '' || $el.dataset.search.indexOf(q.toLowerCase()) !== -1">
                    <input type="radio" name="contractor_pick" value="{{ $c['id'] }}" x-model="picked">
                    <span class="font-medium">{{ $c['name'] }}</span>
                    @if($c['trades'])<span class="text-xs" style="color: var(--text-muted);">{{ $c['trades'] }}</span>@endif
                    @if($c['phone'])<span class="text-xs" style="color: var(--text-muted);">{{ $c['phone'] }}</span>@endif
                    @if($c['suits'])<span class="ds-badge ds-badge-success">suits this fault</span>@endif
                </label>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No contractors are set up yet. Add one under Suppliers, or use the internal crew.</p>
            @endforelse
        </div>

        <div x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR }}'" x-cloak class="grid grid-cols-2 gap-3">
            <div>
                <label class="text-xs font-medium">Owner's contractor &mdash; name <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                <input type="text" name="contractor_name" maxlength="191" value="{{ old('contractor_name') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Phone <span class="font-normal" style="color: var(--text-muted);">(optional)</span></label>
                <input type="text" name="contractor_phone" maxlength="40" value="{{ old('contractor_phone') }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
        </div>
    @endif

    @if($secondary ?? false)
        {{-- before the owner has decided: a deliberate, confirmed step --}}
        <x-confirm-submit title="Create a work order before the owner has decided?"
            message="Only do this for an emergency, or for a small job inside this property's no-approval spend limit (R{{ number_format($noApprovalLimit, 2) }}). Anything above the limit still needs the owner's approval before work starts, and emergency work needs the owner's agreement recorded on the work order."
            confirm-label="Create work order" data-raise-submit
            x-bind:disabled="!!(who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}' && !picked)">Create work order</x-confirm-submit>
    @else
        <button type="submit" class="corex-btn-primary text-xs" data-raise-submit
                x-bind:disabled="!!(busy || (who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}' && !picked))"
                x-text="busy ? 'Creating...' : '{{ $ownersOwnContractor ? 'Confirm and create work order' : 'Create work order' }}'">{{ $ownersOwnContractor ? 'Confirm and create work order' : 'Create work order' }}</button>
    @endif
</form>
@if(! ($secondary ?? false) && request()->boolean('create_work_order'))
    <script>document.addEventListener('DOMContentLoaded', function () { var f = document.getElementById('raise-work-order-form'); if (f && f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); } });</script>
@endif
