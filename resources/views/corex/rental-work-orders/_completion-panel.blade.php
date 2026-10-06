{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 3's slot on the work order screen: "Contractor reports done", the
    tenant-check status, the Dispute panel and every completion round (§17.9.6, §17.10). One shared panel so the work
    order and the job card can never differ. Receives: $workOrder and everything show.blade.php has in scope.
--}}
@include('corex.rental-completion._panel', ['workOrder' => $workOrder, 'context' => 'work_order'])
