{{--
    .ai/specs/rental-work-orders.md §17.21.1 — BUILD 1's plug-in slot: the crew's "Parts & labour" panel
    (price-request banner, the crew's own lines with state chips, "Send to office" — §17.5).

    Receives $block = CrewPricingBlock::for($card, $ctx) — a flat array of plain values, [] until Build 1 lands.
    NEVER render selling, markup, margin or an owner amount here (CrewPayloadNeverCarriesSellingTest).
    Empty in the foundation on purpose: the include exists so Build 1 edits only this file.
--}}
