{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 2's plug-in slot.
    Will hold: the approvals settings section: variation tolerance %, notify-owner-on-auto-approved-extra, the external-quote fee type/value (§17.9.1a, §17.14).
    Receives: the variables RentalWorkOrderSettingsController::edit() passes to the page (the build adds its own).
    EMPTY in the foundation on purpose: the include already exists in the parent view, so the build that owns
    this slot edits only this file and never collides with the other two builds. Remove this comment when you fill it.
--}}
