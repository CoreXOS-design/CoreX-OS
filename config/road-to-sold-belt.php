<?php

/**
 * Road to Sold v4 - the Get-stock belt (.ai/specs/road-to-sold.md section 23.4 / 23.13).
 *
 * One entry per station, in belt order. The tile row shows ONLY the stations listed here whose `built` is true; a station is switched on as its
 * data exists, so the row grows without a release of its own. A station with no source renders nothing.
 *
 *   key     the station key (stable, used in addresses)
 *   label   the full name (hover); short = the name on a narrow tile
 *   stages  the stored prospect stage keys that make the station (ProspectStages); the station counts a stage once it is a real stage
 *   source  where the count comes from TODAY (BeltService); prefer_stage = use the stage count as soon as the key is a real stage AND the lane that enters it is built (A1: claimed; A2: owner; A4: prepared; A7: draft, active - each flips its own line to true)
 *   tasks   the recipe task keys whose target the tile shows ("done today / target"); none = the tile shows the count (Stock loaded uses the weekday target)
 *   built   false = the tile is not drawn
 *
 * Labels are neutral wording; an agency renames them in Road to Sold settings (the stage labels, 22.2), which the tile reads when it can.
 */
return [
    // the master switch for "Start my Road to Sold" (23.13). The agency setting `agent_start_required` decides per agency; tests written before Start existed switch this off.
    'enforce_start' => true,

    // working days an open claim may sit with no work before the tile shows it red (until Lane A1's claim hold, 23.5, takes over)
    'claim_alert_days' => 7,
    // presentations with no answer older than this show red on the Prepared tile
    'presentation_alert_days' => 30,

    'stations' => [
        ['key' => 'loaded',     'label' => 'Stock loaded',          'short' => 'Loaded',    'stages' => ['found'],                              'source' => 'list_today',        'prefer_stage' => false, 'tasks' => [], 'built' => true],
        ['key' => 'claimed',    'label' => 'Claimed',               'short' => 'Claimed',   'stages' => ['claimed'],                            'source' => 'claims_open',       'prefer_stage' => true,  'tasks' => [], 'built' => true],
        ['key' => 'owner',      'label' => 'Owner found',           'short' => 'Owner',     'stages' => ['owner_found'],                        'source' => 'claims_owner',      'prefer_stage' => false,  'tasks' => ['owner-found'], 'built' => true],
        ['key' => 'contacted',  'label' => 'Contacted',             'short' => 'Contacted', 'stages' => ['contacted'],                          'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['call-outcome'], 'built' => true],
        ['key' => 'booked',     'label' => 'Evaluation booked',     'short' => 'Booked',    'stages' => ['appointment_set'],                    'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['evaluation-booked'], 'built' => true],
        ['key' => 'prepared',   'label' => 'Presentation prepared', 'short' => 'Prepared',  'stages' => ['presentation_prepared'],              'source' => 'presentations_open', 'prefer_stage' => false, 'tasks' => [], 'built' => true],
        ['key' => 'held',       'label' => 'Evaluation held',       'short' => 'Held',      'stages' => ['evaluation_done'],                    'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['evaluation-done'], 'built' => true],
        ['key' => 'won',        'label' => 'Result: won',           'short' => 'Won',       'stages' => ['mandate_signed'],                     'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['mandate-filed'], 'built' => true],
        ['key' => 'draft',      'label' => 'Draft',                 'short' => 'Draft',     'stages' => ['draft'],                              'source' => 'properties_draft',  'prefer_stage' => false,  'tasks' => [], 'built' => true],
        ['key' => 'active',     'label' => 'Active',                'short' => 'Active',    'stages' => ['active'],                             'source' => 'properties_active', 'prefer_stage' => false,  'tasks' => [], 'built' => true],
        ['key' => 'advertised', 'label' => 'Advertised',            'short' => 'Advertised', 'stages' => ['listing_live', 'advertising'],       'source' => 'properties_live',   'prefer_stage' => false, 'tasks' => ['listing-live'], 'built' => true],
    ],
];
