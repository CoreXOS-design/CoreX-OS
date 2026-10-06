{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 3's slot on the job card screen: the tenant-check status, the Dispute
    panel with the tenant's note and photos, and "Send back to crew" (§17.10). One shared panel so the work order and the
    job card can never differ. Receives: $jobCard and everything show.blade.php has in scope.
--}}
@if(isset($jobCard) && $jobCard && $jobCard->workOrder)
    @include('corex.rental-completion._panel', ['workOrder' => $jobCard->workOrder, 'context' => 'job_card'])
@endif
