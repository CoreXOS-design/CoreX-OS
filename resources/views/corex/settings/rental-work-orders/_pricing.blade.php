{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 1's plug-in slot.
    Will hold: the pricing settings section: default parts / labour markup %, and the estimate-term textarea with "Restore default" (§17.4.3, §17.11, §17.14).
    Receives: the variables RentalWorkOrderSettingsController::edit() passes to the page (the build adds its own).
    EMPTY in the foundation on purpose: the include already exists in the parent view, so the build that owns
    this slot edits only this file and never collides with the other two builds. Remove this comment when you fill it.
--}}
