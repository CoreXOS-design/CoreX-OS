<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AT-366 — count untouched imports?
    |--------------------------------------------------------------------------
    | The Performance & ROI report counts GENUINE agent activity only. Purely
    | imported contacts/properties/FICA (the 2026-06 bulk data migration) are
    | excluded from contacts_created, properties_created and fica_submissions
    | UNLESS the agent has worked the record on CoreX since import.
    |
    | Set true (PERFORMANCE_COUNT_UNTOUCHED_IMPORTS=true) to restore the RAW,
    | with-imports counts for audit / reconciliation. Default false = corrected.
    */
    'count_untouched_imports' => env('PERFORMANCE_COUNT_UNTOUCHED_IMPORTS', false),
];
