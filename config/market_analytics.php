<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Evidence-First Source Policy
    |--------------------------------------------------------------------------
    | When a presentationId is supplied, presentation-uploaded evidence is
    | preferred over internal DB records if its comp count meets or exceeds
    | this threshold. Below the threshold the internal source is used (or
    | whichever has more rows when both are below threshold).
    */
    'min_comps_threshold' => (int) env('MA_MIN_COMPS_THRESHOLD', 6),
];
