<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-pass trust gate  (GUARDRAIL)
    |--------------------------------------------------------------------------
    | A clean screen ("no match") produces outcome=passed, but it only AUTO-CLEARS
    | the FICA approval gate when this is true. It stays FALSE until list completeness
    | (UN + any SA-domestic feed) is confirmed and signed off — so we can never silently
    | clear someone the country has actually designated. Exact ID/passport HITs and
    | name→review_required always work regardless of this flag.
    */
    'trust_auto_pass' => env('TFS_TRUST_AUTO_PASS', false),

    /*
    |--------------------------------------------------------------------------
    | Staleness guard
    |--------------------------------------------------------------------------
    | The XML carries no version stamp, so we version by fetch-time + content SHA.
    | If the newest successful import for a feed is older than this many days, a clean
    | result degrades to review_required (reason=list_stale) — never an auto-pass.
    */
    'max_staleness_days' => env('TFS_MAX_STALENESS_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Source feeds (MULTI-SOURCE)
    |--------------------------------------------------------------------------
    | Each feed is one import source. Adding a second feed (e.g. an SA-domestic list)
    | is a new entry here — no schema change. `operative` feeds are the ones screening
    | actually consults; a feed can be ingested but not yet trusted for screening.
    */
    'feeds' => [
        'fic_un_consolidated' => [
            'label'   => 'FIC UN Consolidated Sanctions List (XML)',
            'url'     => 'https://tfs.fic.gov.za/Pages/TFSListDownload?fileType=xml',
            'method'  => 'http_post',   // POST with empty body (Content-Length: 0)
            'format'  => 'fic_xml',
            'operative' => true,        // consulted by screening
        ],
    ],

    // HTTP timeout (seconds) for a live feed fetch.
    'fetch_timeout' => env('TFS_FETCH_TIMEOUT', 90),
];
