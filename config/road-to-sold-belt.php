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
 *   source  where the count comes from TODAY (BeltService); prefer_stage = use the stage count as soon as the key is a real stage AND the lane that enters it is built (A1: claimed; A2: owner (done); A4: prepared (done); A7: draft, active (done) - each flips its own line to true)
 *   tasks   the recipe task keys whose target the tile shows ("done today / target"); none = the tile shows the count (Stock loaded uses the weekday target)
 *   built   false = the tile is not drawn
 *
 * Labels are neutral wording; an agency renames them in Road to Sold settings (the stage labels, 22.2), which the tile reads when it can.
 */
return [
    // the master switch for "Start my Road to Sold" (23.13). The agency setting `agent_start_required` decides per agency; tests written before Start existed switch this off.
    'enforce_start' => true,
    // v4 A2 (road-to-sold.md 23.6): no contact outcome / send until the owner is found. The test suite sets RTS_ENFORCE_OWNER_GATE=false (phpunit.xml) because the tests written before the gate record outcomes on properties with no owner; OwnerStationTest switches it on.
    // v4 A3 (23.7): every contact outcome names its channel. The test suite sets RTS_REQUIRE_CHANNEL=false (phpunit.xml) for the tests written before channels; ChannelOutcomeTest switches it on.
    'require_channel' => env('RTS_REQUIRE_CHANNEL', true),
    'enforce_owner_gate' => env('RTS_ENFORCE_OWNER_GATE', true),

    // working days an open claim may sit with no work before the tile shows it red (until Lane A1's claim hold, 23.5, takes over)
    'claim_alert_days' => 7,
    // presentations with no answer older than this show red on the Prepared tile
    'presentation_alert_days' => 30,

    // the recipe task keys behind a summary-bar step whose "should be" is not already a monthly target (SummaryBarService; 23.13)
    'summary_tasks' => [
        'evaluations_booked' => ['evaluation-booked'],
        'evaluations_done'   => ['evaluation-done'],
        'mandates_signed'    => ['mandate-filed'],
        'listings_live'      => ['listing-live'],
    ],

    'stations' => [
        ['key' => 'loaded',     'label' => "Today's work",          'short' => "Today's work",    'stages' => ['found'],                              'source' => 'list_today',        'prefer_stage' => false, 'tasks' => [], 'built' => true],
        ['key' => 'claimed',    'label' => 'Claimed',               'short' => 'Claimed',   'stages' => ['claimed'],                            'source' => 'claims_open',       'prefer_stage' => true,  'tasks' => [], 'built' => true],
        ['key' => 'owner',      'label' => 'Owner found',           'short' => 'Owner found',     'stages' => ['owner_found'],                        'source' => 'claims_owner',      'prefer_stage' => true,  'tasks' => ['owner-found'], 'built' => true],
        ['key' => 'contacted',  'label' => 'Contacted',             'short' => 'Contacted', 'stages' => ['contacted'],                          'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['call-outcome'], 'built' => true],
        ['key' => 'booked',     'label' => 'Evaluation booked',     'short' => 'Booked',    'stages' => ['appointment_set'],                    'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['evaluation-booked'], 'built' => true],
        ['key' => 'prepared',   'label' => 'Presentation prepared', 'short' => 'Prepared',  'stages' => ['presentation_prepared'],              'source' => 'presentations_open', 'prefer_stage' => true,  'tasks' => [], 'built' => true],
        ['key' => 'held',       'label' => 'Evaluation held',       'short' => 'Held',      'stages' => ['evaluation_done'],                    'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['evaluation-done'], 'built' => true],
        ['key' => 'won',        'label' => 'Result: won',           'short' => 'Won',       'stages' => ['mandate_signed'],                     'source' => 'stage',             'prefer_stage' => true,  'tasks' => ['mandate-filed'], 'built' => true, 'no_tile' => true],
        ['key' => 'draft',      'label' => 'Draft',                 'short' => 'Draft',     'stages' => ['draft'],                              'source' => 'properties_draft',  'prefer_stage' => true,   'tasks' => [], 'built' => true],
        ['key' => 'active',     'label' => 'Active',                'short' => 'Active',    'stages' => ['active'],                             'source' => 'properties_active', 'prefer_stage' => true,   'tasks' => [], 'built' => true],
        ['key' => 'advertised', 'label' => 'Advertised',            'short' => 'Advertised', 'stages' => ['listing_live', 'advertising'],       'source' => 'properties_live',   'prefer_stage' => false, 'tasks' => ['listing-live'], 'built' => true],
    ],

    // Page layout v2 (road-to-sold.md 23.31): the three belts of the Road to Sold page. Get stock reads `stations` above; Maintain stock and Sell stock render from the
    // lists here - no tile markup is hard-coded. `source` names the data (BeltsService::maintainFigures) or is null where the station's data is a GAP in 23.26 / 23.27
    // (the tile shows 0 and its list says so - no invented number); `gap` is the plain-language reason shown on that list.
    'belts' => [
        'get'      => ['label' => 'Get stock'],
        'maintain' => ['label' => 'Maintain stock', 'stations' => [
            ['key' => 'portals',    'label' => 'Syndicated to portals',  'short' => 'Syndicated',  'source' => 'portals'],
            ['key' => 'social',     'label' => 'Shared on social',       'short' => 'On social',   'source' => 'social'],
            ['key' => 'sellerlink', 'label' => 'Seller live link sent',  'short' => 'Seller link', 'source' => 'sellerlink'],
        ]],
        'sell'     => ['label' => 'Sell stock', 'stations' => [
            ['key' => 'lead',             'label' => 'Lead',                  'short' => 'Lead in',      'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'core_match',       'label' => 'Core Match updated',    'short' => 'Core Match',   'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'wish_list',        'label' => 'Wish list shared',      'short' => 'Wish list',    'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'buyer_moved',      'label' => 'Moved in pipeline',     'short' => 'In pipeline',  'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'viewing',          'label' => 'Appointment',           'short' => 'Appointment',  'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'viewing_pack',     'label' => 'Viewing pack',          'short' => 'Viewing pack', 'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'viewing_feedback', 'label' => 'Appointment feedback',  'short' => 'Feedback',     'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'deal',             'label' => 'Deal registered',       'short' => 'Deal',         'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'bond',             'label' => 'Bond granted',          'short' => 'Bond',         'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
            ['key' => 'transfer',         'label' => 'Transfer registered',   'short' => 'Transfer',     'source' => null, 'gap' => 'Buyer leads are not tracked per buyer yet, so this station has nothing to list.'],
        ]],
    ],
];
