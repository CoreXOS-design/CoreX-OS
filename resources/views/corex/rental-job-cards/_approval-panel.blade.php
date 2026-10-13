{{--
    .ai/specs/rental-work-orders.md §17.6-§17.8 + rentals-faults-work-orders.md §22.6 - the job card's approval slot. ONE section: "Approve the cost" (the same box as on the
    work order - status, the owner's limits, the amount [our crew's work-order selling price, never the cost], the buttons, and below them the records), then the variation
    panel. The old separate "Owner approval" chips / "Work cannot start yet" line are gone: they said the same thing a second time.
    Receives $jobCard, $isOpen and everything show.blade.php has in scope. Owner-facing amounts only - nothing here shows cost, markup or margin.
--}}
@php $jaWorkOrder = $jobCard->workOrder; @endphp
@if($jaWorkOrder)
    <div id="jc-approval-panel" class="space-y-3">
        @include('corex.rental-work-orders._cost-decision', ['workOrder' => $jaWorkOrder, 'isOpen' => $isOpen])
    </div>
    @include('corex.rental-work-orders._variation-panel', ['workOrder' => $jaWorkOrder])
@endif
