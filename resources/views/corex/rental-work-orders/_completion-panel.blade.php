{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 3's plug-in slot.
    Will hold: "Contractor reports done", the tenant-check status and rounds history, the Dispute panel (§17.9.6, §17.10).
    Receives: $workOrder, $isOpen and everything show.blade.php has in scope.
    EMPTY in the foundation on purpose: the include already exists in the parent view, so the build that owns
    this slot edits only this file and never collides with the other two builds. Remove this comment when you fill it.
--}}
