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
                'section' => 'Reading the numbers',
                'do'      => ['action' => 'click', 'say' => 'Click the With address or In stock tile to filter the list.'],
                'title'   => 'Your opportunity snapshot',
                'body'    => 'These tiles count every property CoreX is tracking, how many already have a strong-match buyer, and how many are still unclaimed. Tap a tile to filter the list below to just those.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-chips"]',
                'section' => 'Filtering the list',
                'do'      => ['action' => 'click', 'say' => 'Click a chip to filter the list.'],
                'title'   => 'Narrow it down',
                'body'    => 'These chips filter the list — for example show only properties that already have a confirmed street address, or only ones that are now part of your agency stock.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-filters"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Pick a suburb, a source or a status.'],
                'title'   => 'Narrow it further',
                'body'    => 'Choose a suburb, where the lead came from, or its status. Nothing changes until you press Apply.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-search"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Type an address, erf number or reference, or press Skip this step.'],
                'title'   => 'Search',
                'body'    => 'Know the address or erf number? Type it here to jump straight to that property.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-apply"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Apply.'],
                'title'   => 'Apply the filters',
                'body'    => 'The list reloads with just the properties that match. Clear filters takes you back to everything.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-count"]',
                'section' => 'Opening a lead',
                'title'   => 'What you are looking at',
                'body'    => 'This line tells you how many properties match your current filter, sorted with the strongest buyer matches at the top so the best leads are always first.',
            ],
            [
                'element' => '[data-tour="mic-opportunities-list"]',
                'do'      => ['action' => 'click', 'say' => 'Click the top row to open it.'],
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
                'section' => 'Reading the pulse',
                'title'   => 'The pulse at a glance',
                'body'    => 'These tiles show the health of the feed — when listings last imported, how many came in this month, the live count, and the average asking price across your area.',
            ],
            [
                'element' => '[data-tour="mic-market-pulse-suburbs"]',
                'section' => 'Suburb deep-dive',
                'do'      => ['action' => 'click', 'say' => 'Click a suburb row to open its deep-dive.', 'until' => '[data-tour="mic-slideover-panel"]'],
                'title'   => 'Listings by suburb',
                'body'    => 'Every suburb on your patch with its listing count and price spread (min, average, max). Click any suburb row to open its deep-dive panel.',
            ],
            [
                'element' => '[data-tour="mic-slideover-panel"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Read the suburb\'s figures, then click the cross to close the panel.', 'target' => 'button'],
                'title'   => 'The suburb deep-dive',
                'body'    => 'Listings, price spread and recent movement for that one suburb, all in one panel. Close it when you\'re done to carry on.',
            ],
            [
                'element' => '[data-tour="mic-market-pulse-listings"]',
                'section' => 'Listings and price drops',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click a listing number to open it on Property24.', 'target' => 'tbody a'],
                'title'   => 'The newest listings',
                'body'    => 'The latest listings from the portal feed, newest first. Each listing number opens the live advert on Property24 in a new tab.',
            ],
            [
                'element' => '[data-tour="mic-market-pulse-price-changes"]',
                'do'      => ['action' => 'click', 'say' => 'Click a listing number to see that advert on Property24.', 'target' => 'tbody a'],
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
                'section' => 'Reading the brief',
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
                'section' => 'Finding demand',
                'do'      => ['action' => 'click', 'say' => 'Click a green cell or a suburb name to open its detail.', 'until' => '[data-tour="mic-slideover-panel"]'],
                'title'   => 'Demand-vs-supply heat map',
                'body'    => 'Suburbs down the side, bedroom counts across the top. Green cells are hot (more buyers than stock), amber is balanced, grey is cold. Click a hot cell to see those buyers.',
            ],
            [
                'element' => '[data-tour="mic-slideover-panel"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Read the detail, then click the cross to close the panel.', 'target' => 'button'],
                'title'   => 'The detail panel',
                'body'    => 'Who the buyers are and how much stock there is for that suburb or bedroom count. Close it when you\'re done to carry on.',
            ],
            [
                'element' => '[data-tour="mic-analyse-pockets"]',
                'section' => 'Where to prospect',
                'do'      => ['action' => 'click', 'say' => 'Click the top pocket to start canvassing it.', 'target' => 'a'],
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
                'section' => 'Reading the alerts',
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
                'section' => 'Clearing an alert',
                'title'   => 'Open, then capture',
                'body'    => 'Use this link to open the listing on the portal, then capture it with the CoreX Chrome extension. That fills in the address and promotes it to a proper tracked property. Close this and clear the top alert.',
            ],
            [
                'element' => '[data-tour="mic-portal-alerts-open-link"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Open to see this listing on the portal — it opens in a new tab.'],
                'title'   => 'Open the top alert',
                'body'    => 'Once the listing is open, capture it with the CoreX Chrome extension. That adds the street address and turns it into a proper tracked property.',
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
                'section' => 'Uploading a report',
                'do'      => ['action' => 'click', 'say' => 'Click Upload a report, or press Skip this step to look at your library first.'],
                'title'   => 'Add a report',
                'body'    => 'Upload a CMA, a Lightstone report, or any market document here. CoreX parses it automatically and feeds the figures into Property Intelligence and the Strategic Brief.',
            ],
            [
                'element' => '[data-tour="mic-reports-stats"]',
                'section' => 'Reading the library',
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
                'section' => 'Opening a report',
                'do'      => ['action' => 'click', 'say' => 'Click a filename to open that report.', 'target' => 'tbody a'],
                'title'   => 'Open a report',
                'body'    => 'Click any filename to open the report and see the figures CoreX extracted, plus any spot-check discrepancies. Close this and upload your latest CMA to get started.',
            ],
        ],
    ],

];
