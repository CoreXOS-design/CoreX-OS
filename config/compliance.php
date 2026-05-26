<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Whistleblower Compliance Reporting
    |--------------------------------------------------------------------------
    |
    | Controls the PPRA complaint email pipeline.
    |
    | ppra_live_send = false (default):
    |   Emails route to demo_recipient with [DEMO] subject prefix.
    |   Audit log records everything as if sent to PPRA.
    |
    | ppra_live_send = true:
    |   Emails route to the real PPRA complaints address configured
    |   per-agency (or default complaints@theppra.org.za).
    |   Only flip to true after lawyer signs off on templates.
    |
    */

    'whistleblow' => [
        'ppra_live_send'  => (bool) env('WHISTLEBLOW_PPRA_LIVE_SEND', false),
        'demo_recipient'  => env('WHISTLEBLOW_DEMO_RECIPIENT', 'johan@hfcoastal.co.za'),
    ],

];
