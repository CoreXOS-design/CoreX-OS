{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 1's plug-in slot.
    Will hold: the Pricing panel (All lines % / Parts % / Labour %), "Ask crew to price this job", the "Added by crew — awaiting office" block (§17.4–§17.5).
    Receives: $jobCard, $isOpen and everything show.blade.php has in scope.
    EMPTY in the foundation on purpose: the include already exists in the parent view, so the build that owns
    this slot edits only this file and never collides with the other two builds. Remove this comment when you fill it.
--}}
