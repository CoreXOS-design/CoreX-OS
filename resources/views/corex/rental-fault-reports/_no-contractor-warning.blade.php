{{-- AT-452 item 9 + round 2 (H) - shown to the AGENT before sending: no active contractor covers this type of work.
     $noContractor comes from RentalFaultContractorService::hasContractorFor() - the SAME test the owner portal's "no contractor on file" note uses.
     The two controls open a POPUP over this screen (resources/views/partials/contractor-popups.blade.php): nothing typed on the page is lost, and when
     a contractor now covers the work the warning clears by itself (the card's Alpine state `noContractor`). They show only to users who may manage
     suppliers; everyone else sees the warning line only. Used inside #owner-version-card (x-data noContractor). --}}
@if(!empty($noContractor))
    <input type="hidden" name="send_without_contractor" value="1" x-bind:disabled="!noContractor">
    <div class="rounded-md px-3 py-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, var(--surface)); border: 1px solid var(--ds-amber, #f59e0b);" data-no-contractor-warning role="status" x-show="noContractor">
        <span class="font-semibold">No contractor loaded for {{ $typeName }}.</span>
        @if(!empty($canManageContractors))
            <button type="button" class="font-semibold underline" data-contractor-popup="add" data-fault-id="{{ $faultReport->id }}" data-type="{{ $typeName }}" data-trade-code="{{ $tradeCode ?? '' }}" data-no-contractor-add>Add a contractor</button>
            <button type="button" class="font-semibold underline" data-contractor-popup="types" data-fault-id="{{ $faultReport->id }}" data-type="{{ $typeName }}" data-no-contractor-types>Add {{ $typeName }} to an existing contractor</button>
        @endif
    </div>
@endif
