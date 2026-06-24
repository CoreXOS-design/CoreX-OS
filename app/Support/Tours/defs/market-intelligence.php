<?php

/**
 * Guided tours — Market Intelligence sub-tabs (AT-41 full-coverage pass).
 *
 * One tour per sub-tab. The main "Work" tab is covered by a separate tour and
 * is intentionally NOT included here. Every step anchors on a real
 * data-tour anchor added to the corresponding Blade view, so a markup
 * refactor never silently drops a step.
 *
 * All five routes live under the `permission:access_prospecting` middleware
 * group in routes/web.php — that key gates the sidebar link and every route
 * here, so it is the honest `permission` for the directory.
 *
 * @return array<string,array<string,mixed>>
 */

return [

    // ── Opportunities ─────────────────────────────────────────────────────
    'mic-opportunities' => [
        'key'         => 'mic-opportunities',
        'title'       => 'Opportunities — tracked properties for your buyers',
        'description' => 'How to read the Opportunities list and find tracked properties that match the people on your books.',
        'route'       => 'market-intelligence.opportunities',
        'permission'  => 'access_prospecting',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="mic-opportunities-stats"]',
                'title'   => 'Your opportunity snapshot',
                'body'    => 'These tiles count every property CoreX is tracking, how many already have a strong-match buyer, and how many are still unclaimed. Tap a tile to filter the list below to just those.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-chips"]',
                'title'   => 'Narrow it down',
                'body'    => 'These chips filter the list — for example show only properties that already have a confirmed street address, or only ones that are now part of your agency stock.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-count"]',
                'title'   => 'What you are looking at',
                'body'    => 'This line tells you how many properties match your current filter, sorted with the strongest buyer matches at the top so the best leads are always first.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-list"]',
                'title'   => 'Each row is a real lead',
                'body'    => 'Every row is a tracked property — its address (or "Address pending" if it still needs one), suburb, and how many of your buyers are a strong match. Close this and open the top row to start working it.',
            ],
        ],
    ],

    // ── Market Pulse ──────────────────────────────────────────────────────
    'mic-market-pulse' => [
        'key'         => 'mic-market-pulse',
        'title'       => 'Market Pulse — what the portals are doing',
        'description' => 'Read the live portal activity for your patch: new listings, suburb prices, and price changes.',
        'route'       => 'market-intelligence.market-pulse',
        'permission'  => 'access_prospecting',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="mic-market-pulse-kpis"]',
                'title'   => 'The pulse at a glance',
                'body'    => 'These tiles show the health of the feed — when listings last imported, how many came in this month, the live count, and the average asking price across your area.',
            ],
            [
                'element' => '[data-tour="mic-market-pulse-suburbs"]',
                'title'   => 'Listings by suburb',
                'body'    => 'Every suburb on your patch with its listing count and price spread (min, average, max). Click any suburb row to open its deep-dive panel.',
            ],
            [
                'element' => '[data-tour="mic-market-pulse-price-changes"]',
                'title'   => 'Who just dropped their price',
                'body'    => 'The most recent asking-price changes from the portals. A price drop often means a motivated seller — a perfect reason to call. Close this and scan the suburb table for your area.',
            ],
        ],
    ],

    // ── Analyse ───────────────────────────────────────────────────────────
    'mic-analyse' => [
        'key'         => 'mic-analyse',
        'title'       => 'Analyse — market heat and where the demand is',
        'description' => 'Use the Analyse tools to see demand versus supply, market velocity, and the hottest pockets to prospect.',
        'route'       => 'market-intelligence.analyse',
        'permission'  => 'access_prospecting',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="mic-analyse-brief"]',
                'title'   => 'Ellie\'s weekly brief',
                'body'    => 'Ellie reads your whole patch and writes a short plain-English summary of what changed this week, with quick action buttons. Start here each Monday.',
            ],
            [
                'element' => '[data-tour="mic-analyse-stats"]',
                'title'   => 'The headline numbers',
                'body'    => 'A sticky strip of the key figures for your area so the most important counts stay in view as you scroll the analysis below.',
            ],
            [
                'element' => '[data-tour="mic-analyse-matrix"]',
                'title'   => 'Demand-vs-supply heat map',
                'body'    => 'Suburbs down the side, bedroom counts across the top. Green cells are hot (more buyers than stock), amber is balanced, grey is cold. Click a hot cell to see those buyers.',
            ],
            [
                'element' => '[data-tour="mic-analyse-pockets"]',
                'title'   => 'Where to prospect next',
                'body'    => 'The opportunity pockets and your agency share show exactly where demand is outpacing supply. Close this and click the hottest pocket to find listings to chase.',
            ],
        ],
    ],

    // ── Portal Alerts — awaiting address ──────────────────────────────────
    'mic-portal-alerts' => [
        'key'         => 'mic-portal-alerts',
        'title'       => 'Portal Alerts — listings still missing an address',
        'description' => 'Work the portal alerts that can\'t appear on the map yet because they have no street address or GPS.',
        'route'       => 'market-intelligence.portal-alerts',
        'permission'  => 'access_prospecting',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="mic-portal-alerts-counts"]',
                'title'   => 'What\'s waiting on an address',
                'body'    => 'These two counts are alerts CoreX has spotted but can\'t pin to the map yet — P24 email alerts with no address, and Chrome captures still waiting to be located.',
            ],
            [
                'element' => '[data-tour="mic-portal-alerts-table"]',
                'title'   => 'Each row needs a home',
                'body'    => 'Every row is a real listing from a portal — its reference, suburb, type, and asking price — but without a street address it can\'t become a map pin or a tracked property.',
            ],
            [
                'element' => '[data-tour="mic-portal-alerts-open"]',
                'title'   => 'Open, then capture',
                'body'    => 'Use this link to open the listing on the portal, then capture it with the CoreX Chrome extension. That fills in the address and promotes it to a proper tracked property. Close this and clear the top alert.',
            ],
        ],
    ],

    // ── Market reports library ────────────────────────────────────────────
    'mic-reports' => [
        'key'         => 'mic-reports',
        'title'       => 'Market reports — your CMA & sales-report library',
        'description' => 'Upload and track CMAs, Lightstone, and other market reports — the parsed data feeds Property Intelligence and the brief.',
        'route'       => 'market-intelligence.reports.index',
        'permission'  => 'access_prospecting',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="mic-reports-upload"]',
                'title'   => 'Add a report',
                'body'    => 'Upload a CMA, a Lightstone report, or any market document here. CoreX parses it automatically and feeds the figures into Property Intelligence and the Strategic Brief.',
            ],
            [
                'element' => '[data-tour="mic-reports-stats"]',
                'title'   => 'Library health',
                'body'    => 'A quick count of everything uploaded, how many parsed cleanly, how many are still pending, and how many were flagged for a closer look.',
            ],
            [
                'element' => '[data-tour="mic-reports-parse-col"]',
                'title'   => 'Reading the Parse column',
                'body'    => '"Parsed" means CoreX has pulled the data out and it\'s ready to use; "Pending" or "Parsing" means it\'s still working; "Failed" means it needs a re-upload or a manual check.',
            ],
            [
                'element' => '[data-tour="mic-reports-table"]',
                'title'   => 'Open a report',
                'body'    => 'Click any filename to open the report and see the figures CoreX extracted, plus any spot-check discrepancies. Close this and upload your latest CMA to get started.',
            ],
        ],
    ],

];
