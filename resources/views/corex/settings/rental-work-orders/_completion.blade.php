{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 3's plug-in slot.
    Will hold: the completion-check settings section: tenant check on/off, response window days, notify owner on dispute, notify crew immediately (§17.10, §17.14).
    Receives: the variables RentalWorkOrderSettingsController::edit() passes to the page (the build adds its own).
    EMPTY in the foundation on purpose: the include already exists in the parent view, so the build that owns
    this slot edits only this file and never collides with the other two builds. Remove this comment when you fill it.
--}}
