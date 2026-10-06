{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 2's plug-in slot.
    Will hold: "Why was this approved?", the Variation panel, the Emergency approval chip (§17.6–§17.8).
    Receives: $jobCard, $isOpen and everything show.blade.php has in scope.
    EMPTY in the foundation on purpose: the include already exists in the parent view, so the build that owns
    this slot edits only this file and never collides with the other two builds. Remove this comment when you fill it.
--}}
