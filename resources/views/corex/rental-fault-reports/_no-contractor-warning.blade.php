{{-- AT-452 item 9 (.ai/specs/rentals-faults-work-orders.md §18.6) - shown to the AGENT before sending: no active contractor covers this type of work.
     $noContractor comes from RentalFaultContractorService::hasContractorFor() - the SAME test the owner portal's "no contractor on file" note uses.
     The two controls go to the existing Supplier Directory (no new contractor form) with a way back to this fault; they show only to users who
     may manage suppliers. Everyone else sees the warning line only. --}}
@if(!empty($noContractor))
    <input type="hidden" name="send_without_contractor" value="1">
    <div class="rounded-md px-3 py-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, var(--surface)); border: 1px solid var(--ds-amber, #f59e0b);" data-no-contractor-warning role="status">
        <span class="font-semibold">No contractor loaded for {{ $typeName }}.</span>
        @if(!empty($canManageContractors))
            <a class="font-semibold underline" href="{{ route('deals-v2.suppliers.index', ['fault' => $faultReport->id, 'mode' => 'add']) }}" data-no-contractor-add>Add a contractor</a>
            <a class="font-semibold underline" href="{{ route('deals-v2.suppliers.index', ['fault' => $faultReport->id, 'mode' => 'types']) }}" data-no-contractor-types>Add {{ $typeName }} to an existing contractor</a>
        @endif
    </div>
@endif
