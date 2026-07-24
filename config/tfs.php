<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-pass trust gate  (GUARDRAIL)
    |--------------------------------------------------------------------------
    | A clean screen ("no match") produces outcome=passed, and AUTO-CLEARS the FICA
    | approval gate when this is true. Johan confirmed (2026-07-24) the FIC UN Consolidated
    | list is THE TFS list FIC publishes, so auto-pass against it is APPROVED — this is TRUE.
    | The honest provenance label (list + version date) is shown on every result regardless,
    | as the legal defense. If an SA-domestic feed is ever added, revisit before assuming its
    | absence is covered. Exact ID/passport HITs and name→review_required block regardless.
    */
    'trust_auto_pass' => env('TFS_TRUST_AUTO_PASS', true),

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
