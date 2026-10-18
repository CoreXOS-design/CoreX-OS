{{--
    .ai/specs/rental-work-orders.md §17.6–§17.8 + rentals-faults-work-orders.md §22.6 - the work-order slot for everything about approval. ONE section, "Approve the cost"
    (_cost-decision: status, the owner's limits, the amount, the buttons, and below them the authorisation records), then the variation panel and - history only -
    earlier emergency agreements. (The separate "Approval / Why was this approved? / Work cannot start yet" section is gone: it said the same thing twice.)
    Receives $workOrder, $isOpen, $proceed, $ownerContacts and everything show.blade.php has in scope. Owner-facing amounts only; no cost, markup or margin.
--}}
@include('corex.rental-work-orders._cost-decision')
@include('corex.rental-work-orders._variation-panel')
@include('corex.rental-work-orders._emergency-panel')
