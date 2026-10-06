{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 3's plug-in slot.
    Will hold: the tenant-check status, the Dispute panel with the tenant's note and photos, "Send back to crew" (§17.10).
    Receives: $jobCard, $isOpen and everything show.blade.php has in scope.
    EMPTY in the foundation on purpose: the include already exists in the parent view, so the build that owns
    this slot edits only this file and never collides with the other two builds. Remove this comment when you fill it.
--}}
