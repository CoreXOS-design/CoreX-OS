<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Presentation PDF pagination
    |--------------------------------------------------------------------------
    |
    | Thresholds for the document-wide pagination policy applied in
    | App\Services\Presentations\PresentationPdfService::buildHtml (R1–R6,
    | introduced a18ec023). Configurable here so they are not hardcoded inline;
    | per-agency override is the natural extension (add a
    | `presentations_min_table_*` column following the existing
    | `presentations_default_*` pattern on the agencies table).
    |
    */

    'pagination' => [

        // Minimum number of data rows a splittable table must keep together at
        // the HEAD and TAIL of a page break, so a fragment never strands fewer
        // than this many rows (and the repeating header never sits above a lone
        // orphan row). CSS orphans/widows do NOT apply to table rows, so this is
        // enforced via generated `tr:nth-child` break rules. Default 2.
        'min_table_lead_rows' => max(1, (int) env('PRESENTATION_MIN_TABLE_LEAD_ROWS', 2)),

        // Minimum card-grid rows kept glued to the grid heading and at the grid
        // tail, so a heading is never separated from a lone trailing card row.
        // The card grids are laid out as <table> rows, so the table rule above
        // already governs them; this is the explicit, separately-tunable knob.
        // Default 2.
        'min_card_grid_tail_rows' => max(1, (int) env('PRESENTATION_MIN_CARD_GRID_TAIL_ROWS', 2)),

    ],

];
