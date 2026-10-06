{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 2's plug-in slot: the approval chips ("Approved to proceed
    (emergency)", per-line "Approved / Awaiting owner — do not start / Declined by owner" — §17.7, §17.8.4).

    Receives $block = CrewApprovalBlock::for($card, $ctx) — a flat array of plain values, [] until Build 2 lands.
    The crew sees the STATE only: never an owner name, contact, approval amount or selling.
    Empty in the foundation on purpose: the include exists so Build 2 edits only this file.
--}}
