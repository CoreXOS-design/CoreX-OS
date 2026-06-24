<?php

/**
 * AT-41 Guided Tours — Agency Tracker & Agent Tools pack.
 *
 * Module: the agent's own performance & toolkit screens — worksheet (income →
 * sales → stock planner), agent dashboard, daily activity capture, listing
 * stock, deal register, and the standalone tools (commission calculator, CMA
 * certificate generator, Ad Manager, PDF Suite).
 *
 * Each tour is pure data merged by App\Support\Tours\TourRegistry::all().
 * Every `element` points at a real data-tour="…" anchor added to the matching
 * Blade view, so a markup refactor can never silently drop a step.
 *
 * Permission keys below are the EXACT route middleware keys from routes/web.php
 * (verified at build time), so the catalogue only lists a tour to a user who
 * can actually reach the screen.
 *
 * @return array<string,array<string,mixed>>
 */

return [

    // ── Worksheet (income → sales → stock planner) ───────────────────────────
    'at-worksheet' => [
        'key'         => 'at-worksheet',
        'title'       => 'Plan your month on the Worksheet',
        'description' => 'Turn the income you want into the sales and stock you need — your monthly budget planner.',
        'route'       => 'worksheet.index',
        'permission'  => 'view_worksheet',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="at-worksheet-header"]',
                'title'   => 'Your Worksheet',
                'body'    => 'This is your monthly money plan. It works backwards from the income you want to the number of sales and listings you need to get there. Each row is for one month.',
            ],
            [
                'element' => '[data-tour="at-worksheet-inputs"]',
                'title'   => 'Planning Inputs',
                'body'    => 'Pick the month here. Your current listing stock and correctly-priced percentage feed in automatically, so the plan is built on your real numbers — not a guess.',
            ],
            [
                'element' => '[data-tour="at-worksheet-net-targets"]',
                'title'   => 'Net Monthly Targets',
                'body'    => 'This is the heart of it: type in the income you want to take home this month — your personal, business and "want" amounts. Everything else calculates from these three figures.',
            ],
            [
                'element' => '[data-tour="at-worksheet-deal-summary"]',
                'title'   => 'Deal Register Summary',
                'body'    => 'A live read of what you have actually captured in your Deal Register this month — your real sales and commission, so you can see plan against reality at a glance.',
            ],
            [
                'element' => '[data-tour="at-worksheet-save"]',
                'title'   => 'Save to recalculate',
                'body'    => 'After you change any number, click Save Worksheet. CoreX recalculates the sales value and stock level you need to hit your income goal.',
            ],
            [
                'element' => '[data-tour="at-worksheet-requirements"]',
                'title'   => 'Plan vs Market Reality',
                'body'    => 'The final word: how many sales and listings your plan needs, side by side with what the current market is actually delivering. Close this and set your targets for the month.',
            ],
        ],
    ],

    // ── Agent Dashboard ──────────────────────────────────────────────────────
    'at-agent-dashboard' => [
        'key'         => 'at-agent-dashboard',
        'title'       => 'Read your Dashboard',
        'description' => 'See where you stand this month — points, sales value, listing stock, and how you compare to your branch and company.',
        'route'       => 'agent.dashboard',
        'permission'  => 'view_dashboard',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="at-agent-dashboard-header"]',
                'title'   => 'Your month at a glance',
                'body'    => 'This dashboard is always about the month shown in the heading. Use the Period picker on the right to look back at an earlier month.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-focus"]',
                'title'   => 'Your focus',
                'body'    => 'Your two big numbers: Points (your activity score) and Sales Value, each shown against target with a progress bar. Green means you are on track; amber means you have ground to make up.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-actuals"]',
                'title'   => 'Your Actuals',
                'body'    => 'The hard facts for the month — deals done, sales value, average sale price, your effective commission percentage and how many daily-activity entries you have logged.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-listing-stock"]',
                'title'   => 'Listing stock health',
                'body'    => 'Your active listings, average days on market, and counts of stale, expiring and expired mandates. Each number is a link — click it to see exactly those listings.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-comparison"]',
                'title'   => 'You vs Branch vs Company',
                'body'    => 'See how your numbers sit against your branch and the whole company — handy for knowing where you stand.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-daily-cta"]',
                'title'   => 'Log today\'s work',
                'body'    => 'Your points come from your daily activity. Tap Daily Activity to capture today\'s calls, viewings and WhatsApps. Close this and go log a strong day.',
            ],
        ],
    ],

    // ── Daily Activity capture ────────────────────────────────────────────────
    'at-agent-daily' => [
        'key'         => 'at-agent-daily',
        'title'       => 'Capture your Daily Activity',
        'description' => 'Log the calls, viewings and WhatsApps that earn your points — the engine behind your monthly target.',
        'route'       => 'agent.daily',
        'permission'  => 'access_daily_activity',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="at-agent-daily-header"]',
                'title'   => 'Daily Activity',
                'body'    => 'Everything here is for the date shown under the heading. Use the date picker on the right, or the day chips below, to move to another day.',
            ],
            [
                'element' => '[data-tour="at-agent-daily-stats"]',
                'title'   => 'Your month so far',
                'body'    => 'Your monthly Target, your points so far this month (MTD), and how many you have Remaining. This is the scoreboard your daily work feeds.',
            ],
            [
                'element' => '[data-tour="at-agent-daily-search"]',
                'title'   => 'Find an activity fast',
                'body'    => 'Start typing — "call", "viewing", "WhatsApp" — and the list filters instantly so you can jump straight to the row you need.',
            ],
            [
                'element' => '[data-tour="at-agent-daily-capture"]',
                'title'   => 'Capture your numbers',
                'body'    => 'Each row is an activity with a points weight. Enter how many you did today; tick-box rows are once-a-day. The Pts column shows what each one earns you.',
            ],
            [
                'element' => '[data-tour="at-agent-daily-save"]',
                'title'   => 'Save your day',
                'body'    => 'Click Save to lock in today\'s points. They flow straight into your dashboard and your monthly target. Close this and capture today.',
            ],
        ],
    ],

    // ── My Listing Stock ──────────────────────────────────────────────────────
    'at-agent-listings' => [
        'key'         => 'at-agent-listings',
        'title'       => 'Work your Listing Stock',
        'description' => 'Review your imported listings, spot stale and expiring mandates, and record a CMA price per listing.',
        'route'       => 'agent.listings',
        'permission'  => 'view_listings',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="at-agent-listings-header"]',
                'title'   => 'My Listing Stock',
                'body'    => 'These are your listings imported from Propcon. It is a read-only view of your stock — the place to keep an eye on the health of every mandate you hold.',
            ],
            [
                'element' => '[data-tour="at-agent-listings-kpis"]',
                'title'   => 'Stock at a glance',
                'body'    => 'Two headline numbers: how many active listings you hold, and the total Rand value of that stock.',
            ],
            [
                'element' => '[data-tour="at-agent-listings-filters"]',
                'title'   => 'Filter by mandate and type',
                'body'    => 'Tap any chip to narrow the list — by mandate (Sole, Open or Dual) or by property type. The number on each chip tells you how many fall into it.',
            ],
            [
                'element' => '[data-tour="at-agent-listings-table"]',
                'title'   => 'The detail',
                'body'    => 'For each listing: status, mandate, type, DOM (days on market) and Since edit. When either climbs into the amber it is a nudge to refresh that listing. Expiry warns you before a mandate lapses.',
            ],
            [
                'element' => '[data-tour="at-agent-listings-cma"]',
                'title'   => 'Record a CMA price',
                'body'    => 'Type your comparative market value for a listing and click Save. It is stored against the listing and feeds your correctly-priced percentage. Close this and price your stock.',
            ],
        ],
    ],

    // ── My Deals (Deal Register) ──────────────────────────────────────────────
    'at-agent-deals' => [
        'key'         => 'at-agent-deals',
        'title'       => 'Track your Deals',
        'description' => 'See every deal you are allocated on, its status and commission, and add remarks in the deal log.',
        'route'       => 'agent.deals.index',
        'permission'  => 'view_deals',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="at-agent-deals-header"]',
                'title'   => 'My Deals',
                'body'    => 'Every deal where you are allocated — on the listing side, the selling side, or both. This is your personal slice of the agency\'s deal register.',
            ],
            [
                'element' => '[data-tour="at-agent-deals-count"]',
                'title'   => 'How many you\'re on',
                'body'    => 'A quick count of the deals allocated to you, so you always know your live load at a glance.',
            ],
            [
                'element' => '[data-tour="at-agent-deals-register"]',
                'title'   => 'The Deal Register',
                'body'    => 'A read-only record of each deal. The coloured badges show two things: the acceptance status (Pending, Declined, Granted, Registered) and whether your commission is Paid, Not Paid or a Loss.',
            ],
            [
                'element' => '[data-tour="at-agent-deals-log"]',
                'title'   => 'Open the deal log',
                'body'    => 'Click Log on any deal to see its full history and add a remark. The figures themselves are managed by the office, but the log is where you keep your notes. Close this and check your live deals.',
            ],
        ],
    ],

    // ── Commission Calculator ─────────────────────────────────────────────────
    'tools-commission' => [
        'key'         => 'tools-commission',
        'title'       => 'Use the Commission Calculator',
        'description' => 'Work out the commission and owner pocket on a sale — VAT in or out — and print a clean summary.',
        'route'       => 'tools.commission',
        'permission'  => 'access_calculators',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="tools-header"]',
                'title'   => 'Tools',
                'body'    => 'A small workshop of agent tools. Right now you are on the Commission Calculator — the fastest way to answer "what does the seller actually pocket?".',
            ],
            [
                'element' => '[data-tour="tools-commission-tab"]',
                'title'   => 'Commission tab',
                'body'    => 'These tabs switch between the Commission Calculator, the CMA Certificate generator and your History. This tab is the calculator.',
            ],
            [
                'element' => '[data-tour="tools-commission-inputs"]',
                'title'   => 'Enter the numbers',
                'body'    => 'Type the price, your commission percentage and the VAT rate. The "VAT included in comm" tick tells CoreX whether your percentage already has VAT baked in — it changes the answer, so set it correctly.',
            ],
            [
                'element' => '[data-tour="tools-commission-results"]',
                'title'   => 'The answer',
                'body'    => 'Live as you type: the selling price, what the owner pockets, the commission including VAT, and how much you are discounting against the default rate. Great for a seller conversation.',
            ],
            [
                'element' => '[data-tour="tools-commission-print"]',
                'title'   => 'Print a summary',
                'body'    => 'Click Print Commission Summary for a branded, client-ready breakdown you can hand over or email. Close this and run your first calculation.',
            ],
        ],
    ],

    // ── CMA Certificate generator ─────────────────────────────────────────────
    'tools-cma' => [
        'key'         => 'tools-cma',
        'title'       => 'Generate a CMA Certificate',
        'description' => 'Produce a clean, branded comparative market analysis certificate for a seller from a few inputs.',
        'route'       => 'tools.cma',
        'permission'  => 'access_calculators',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="tools-header"]',
                'title'   => 'Tools',
                'body'    => 'You are on the CMA Certificate generator — for handing a seller a professional, branded estimate of what their property is worth.',
            ],
            [
                'element' => '[data-tour="tools-cma-tab"]',
                'title'   => 'CMA Certificate tab',
                'body'    => 'This tab builds the certificate. The neighbouring tabs are the Commission Calculator and your saved History.',
            ],
            [
                'element' => '[data-tour="tools-cma-value"]',
                'title'   => 'The estimate',
                'body'    => 'Enter your estimated market value in Rand, plus the beds, baths and parking. This is the figure the seller will remember, so base it on your comparable sales.',
            ],
            [
                'element' => '[data-tour="tools-cma-notes"]',
                'title'   => 'Key features',
                'body'    => 'Add the selling points that justify your figure — sea views, a renovated kitchen, walking distance to the beach. This is what turns a number into a credible valuation.',
            ],
            [
                'element' => '[data-tour="tools-cma-print"]',
                'title'   => 'Print the certificate',
                'body'    => 'Click Print CMA Certificate for a branded document to leave with the seller. Close this and build your first CMA.',
            ],
        ],
    ],

    // ── Ad Manager ────────────────────────────────────────────────────────────
    'tools-ad-manager' => [
        'key'         => 'tools-ad-manager',
        'title'       => 'Create ads with Ad Manager',
        'description' => 'Generate ready-to-post property ads — image plus a grounded AI description — for several listings at once.',
        'route'       => 'tools.ad-manager',
        'permission'  => 'access_ad_manager',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="tools-ad-manager-header"]',
                'title'   => 'Ad Manager',
                'body'    => 'Turn your live listings into ready-to-post ads — a sized image and a written description — without leaving CoreX. You can do several properties in one go.',
            ],
            [
                'element' => '[data-tour="tools-ad-manager-steps"]',
                'title'   => 'Three simple steps',
                'body'    => 'It runs in three steps: pick your properties, choose a template, then collect your finished ads. The tracker up here always shows where you are.',
            ],
            [
                'element' => '[data-tour="tools-ad-manager-size"]',
                'title'   => 'Pick the ad size first',
                'body'    => 'Where will you post these — Facebook, Instagram, WhatsApp? Your choice sets the image dimensions so each ad fits the platform perfectly.',
            ],
            [
                'element' => '[data-tour="tools-ad-manager-select"]',
                'title'   => 'Choose your properties',
                'body'    => 'Only your active listings that are live on the website, Property24 or Private Property show here. Tick the ones you want to advertise — or use Select all.',
            ],
            [
                'element' => '[data-tour="tools-ad-manager-next"]',
                'title'   => 'Move to templates',
                'body'    => 'Once you have ticked at least one property, click Next to choose a template and let CoreX write the ads. Close this and pick your first listings.',
            ],
        ],
    ],

    // ── PDF Suite hub ─────────────────────────────────────────────────────────
    'tools-pdf-suite' => [
        'key'         => 'tools-pdf-suite',
        'title'       => 'Find the right PDF tool',
        'description' => 'Nine tools for everyday PDF work — split, compress, merge, rotate, redact, enhance and more.',
        'route'       => 'tools.pdf_suite.hub',
        'permission'  => 'access_pdf_suite',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="tools-pdf-suite-header"]',
                'title'   => 'PDF Suite',
                'body'    => 'Everything you need to do to a PDF before sending it to a bank, an attorney or a client — all in one place, no other software needed.',
            ],
            [
                'element' => '[data-tour="tools-pdf-suite-grid"]',
                'title'   => 'Pick a tool',
                'body'    => 'Each card is one job — splitting a scanned pack into separate documents, shrinking a file for email, combining FICA papers into one packet, and more. Click a card to open that tool.',
            ],
            [
                'element' => '[data-tour="tools-pdf-suite-first-card"]',
                'title'   => 'Start here',
                'body'    => 'The PDF Splitter takes one big scanned pack and uses text recognition to break it into labelled files — a huge time-saver on a FICA bundle.',
            ],
            [
                'element' => '[data-tour="tools-pdf-suite-redact-card"]',
                'title'   => 'Redact for POPIA',
                'body'    => 'Redact blacks out ID numbers and bank details properly — the data is removed, not just hidden — so you stay POPIA-safe. Close this and open the tool you need.',
            ],
        ],
    ],

];
