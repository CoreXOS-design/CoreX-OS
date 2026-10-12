{{-- The "Create work order" form of a fault (Johan, 8 Oct 2026: the agent only chooses WHO does the work). Section 22: it is the step AFTER the owner has authorised
     (appoint the contractor). Before that, someone is sent only through Decision 1 ("Send <name> to inspect") in the fault page's own block.
     Expects the fault screen's variables: $faultReport, $latestDecision, $prefillSupplier, $prefillOwner, $decisionSummary, $contractorPicker. --}}
@php
    $ownersOwnContractor = $prefillOwner !== null || ($latestDecision && $latestDecision->decision === \App\Models\RentalApproval::DECISION_APPROVED && $faultReport->approval_route === \App\Models\RentalFaultReport::ROUTE_OWNER_HANDLES);
    $agencyAppoints = ! $ownersOwnContractor && $latestDecision && $latestDecision->decision === \App\Models\RentalApproval::DECISION_APPROVED;
    $defaultWho = $agencyAppoints ? \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER : \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL;
@endphp
<form id="raise-work-order-form" data-raise-work-order method="POST" action="{{ route('corex.rental-fault-reports.raise-work-order', $faultReport) }}"
      class="space-y-3 pt-2"
      x-data="{ q: '', who: '{{ $defaultWho }}', picked: '{{ $prefillSupplier?->id }}', busy: false, crewQ: '', crew: '{{ ($crewPicker ?? collect())->count() === 1 ? $crewPicker->first()->id : '' }}' }" x-on:submit="busy = true">
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
        <input type="hidden" name="rental_crew_id" x-bind:value="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL }}' ? crew : ''">
        <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm"><input type="radio" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL }}" x-model="who" data-who-internal> Our own maintenance crew <span class="text-xs" style="color: var(--text-muted);">(also makes the job card)</span></label>
            <label class="flex items-center gap-2 text-sm"><input type="radio" value="{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}" x-model="who" data-who-contractor> One of the agency's contractors</label>
        </div>

        {{-- Johan, 9 Oct 2026: on the internal-crew route the agent can pick the crew right here (optional), so the job card opens with the crew assigned. --}}
        <div x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL }}'" x-cloak class="space-y-2" data-crew-picker>
            <p class="text-xs font-medium">Which crew? <span class="font-normal" style="color: var(--text-muted);">(optional - you can choose later on the job card)</span></p>
            @if(($crewPicker ?? collect())->count() > 1)
                <input type="search" x-model="crewQ" placeholder="Search crews by name or member" class="w-full rounded-md px-3 py-2 text-sm" style="border: 1px solid var(--border);">
            @endif
            @forelse(($crewPicker ?? collect()) as $c)
                <label class="flex items-center gap-2 text-sm rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-crew-row="{{ $c->id }}"
                       data-search="{{ mb_strtolower($c->name . ' ' . $c->members->pluck('name')->implode(' ')) }}"
                       x-show="crewQ === '' || $el.dataset.search.indexOf(crewQ.toLowerCase()) !== -1">
                    <input type="radio" name="crew_pick" value="{{ $c->id }}" x-model="crew">
                    <span class="font-medium">{{ $c->name }}</span>
                    @if($c->members->isNotEmpty())<span class="text-xs" style="color: var(--text-muted);">{{ $c->members->pluck('name')->implode(', ') }}</span>@endif
                </label>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No crews are set up yet - the job card will open without one.</p>
            @endforelse
            @if(($crewPicker ?? collect())->count() > 1 || ($crewPicker ?? collect())->count() === 1)
                <label class="flex items-center gap-2 text-sm px-2"><input type="radio" name="crew_pick" value="" x-model="crew"> No crew yet</label>
            @endif
        </div>

        <div x-show="who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}'" x-cloak class="space-y-2" data-contractor-picker data-refresh-on-contractor-change="fault-picker-primary">
            @include('corex.rental-fault-reports._contractor-rows')
            {{-- §20 - shown the moment a contractor is chosen, before the agent confirms. No blocking. --}}
            @include('partials.contractor-inspect-choice', ['notices' => collect($contractorPicker)->mapWithKeys(fn ($c) => [(string) $c['id'] => ['name' => $c['name'], 'text' => $c['callout_notice'], 'status' => $c['callout_status']]])->all(), 'model' => 'picked'])
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

    <button type="submit" class="corex-btn-primary text-xs" data-raise-submit
            x-bind:disabled="!!(busy || (who === '{{ \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER }}' && !picked))"
            x-text="busy ? 'Creating...' : '{{ $ownersOwnContractor ? 'Confirm and create work order' : 'Create work order' }}'">{{ $ownersOwnContractor ? 'Confirm and create work order' : 'Create work order' }}</button>
</form>
@if(request()->boolean('create_work_order'))
    <script>document.addEventListener('DOMContentLoaded', function () { var f = document.getElementById('raise-work-order-form'); if (f && f.scrollIntoView) { f.scrollIntoView({ block: 'center' }); } });</script>
@endif
