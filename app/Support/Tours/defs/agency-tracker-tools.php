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
                'section' => 'Choosing the month',
                'title'   => 'Your Worksheet',
                'body'    => 'This is your monthly money plan. It works backwards from the income you want to the number of sales and listings you need to get there. Each row is for one month.',
            ],
            [
                'element' => '[data-tour="at-worksheet-inputs"]',
                'do'      => ['action' => 'fill', 'target' => '#wks-period', 'say' => 'Pick the month you are planning — or press Skip this step if it is already right.'],
                'title'   => 'Planning Inputs',
                'body'    => 'Pick the month here. Your current listing stock and correctly-priced percentage feed in automatically, so the plan is built on your real numbers — not a guess.',
            ],
            [
                'element' => '[data-tour="at-worksheet-net-targets"]',
                'section' => 'Setting your income',
                'do'      => ['action' => 'fill', 'target' => '[data-tour="at-worksheet-personal"]', 'say' => 'Type the amount you want to take home personally this month.'],
                'title'   => 'Net Monthly Targets',
                'body'    => 'This is the heart of it: type in the income you want to take home this month — your personal, business and "want" amounts. Everything else calculates from these three figures.',
            ],
            [
                'element'       => '[data-tour="at-worksheet-business"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type what your business costs you this month — fuel, marketing and the like.'],
                'title'         => 'Business costs',
                'body'          => 'What it costs you to run your business each month — fuel, marketing, phone. Your plan has to earn this on top of your take-home.',
            ],
            [
                'element'       => '[data-tour="at-worksheet-want"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type what you want to put away — savings, a holiday or a buffer.'],
                'title'         => 'Your "want" amount',
                'body'          => 'The extra you are working towards — savings, a holiday, a buffer for a quiet month. It is part of the goal, so be honest with yourself.',
            ],
            [
                'element' => '[data-tour="at-worksheet-deal-summary"]',
                'section' => 'Setting your commission',
                'title'   => 'Deal Register Summary',
                'body'    => 'A live read of what you have actually captured in your Deal Register this month — your real sales and commission, so you can see plan against reality at a glance.',
            ],
            [
                'element'       => '[data-tour="at-worksheet-commission"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type the commission percentage you plan to charge — or press Skip this step to keep it.'],
                'title'         => 'Planning commission %',
                'body'          => 'The commission percentage (excluding VAT) your plan assumes. Compare it with your actual rate next to it — if you are discounting, your plan needs more sales.',
            ],
            [
                'element' => '[data-tour="at-worksheet-save"]',
                'section' => 'Saving your plan',
                'do'      => ['action' => 'click', 'say' => 'Click Save Worksheet to recalculate your plan.'],
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
                'section' => 'Choosing the month',
                'title'   => 'Your month at a glance',
                'body'    => 'This dashboard is always about the month shown in the heading. Use the Period picker on the right to look back at an earlier month.',
            ],
            [
                'element'       => '[data-tour="at-agent-dashboard-period"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Pick the month you want to look at — or press Skip this step to stay on this month.'],
                'title'         => 'Pick a month',
                'body'          => 'Choose any earlier month to see how you did then. Leave it as it is to stay on the current month.',
            ],
            [
                'element'       => '[data-tour="at-agent-dashboard-go"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Go to load that month.'],
                'title'         => 'Load the month',
                'body'          => 'Go reloads the dashboard for the month you picked. Everything below then shows that month\'s numbers.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-focus"]',
                'section' => 'Reading your numbers',
                'title'   => 'Your focus',
                'body'    => 'Your two big numbers: Points (your activity score) and Sales Value, each shown against target with a progress bar. Green means you are on track; amber means you have ground to make up.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-actuals"]',
                'title'   => 'Your Actuals',
                'body'    => 'The hard facts for the month — deals done, sales value, average sale price, your effective commission percentage and how many daily-activity entries you have logged.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-comparison"]',
                'title'   => 'You vs Branch vs Company',
                'body'    => 'See how your numbers sit against your branch and the whole company — handy for knowing where you stand.',
            ],
            [
                'element' => '[data-tour="at-agent-dashboard-daily-cta"]',
                'section' => 'Logging today\'s work',
                'do'      => ['action' => 'click', 'say' => 'Click Daily Activity to log today\'s calls, viewings and WhatsApps.'],
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
                'section' => 'Choosing the day',
                'title'   => 'Daily Activity',
                'body'    => 'Everything here is for the date shown under the heading. Use the date picker on the right, or the day chips below, to move to another day.',
            ],
            [
                // The date box reloads the page as soon as a day is picked, so this
                // is a click (open the picker) rather than a fill the guide waits on.
                'element'       => '[data-tour="at-agent-daily-date"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the date and pick the day you are capturing — or press Skip this step for today.'],
                'title'         => 'Pick the day',
                'body'          => 'Capturing for an earlier day? Pick it here and the page reloads on that day. For today, just carry on.',
            ],
            [
                'element' => '[data-tour="at-agent-daily-stats"]',
                'title'   => 'Your month so far',
                'body'    => 'Your monthly Target, your points so far this month (MTD), and how many you have Remaining. This is the scoreboard your daily work feeds.',
            ],
            [
                'element'       => '[data-tour="at-agent-daily-checklist"]',
                'section'       => 'Capturing your activities',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Tick each once-a-day activity you did today.'],
                'title'         => 'Once-a-day checklist',
                'body'          => 'These count once per day — tick the ones you have done. The number on the right is what each tick earns you.',
            ],
            [
                'element' => '[data-tour="at-agent-daily-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an activity, like "call" or "viewing" — or press Skip this step.'],
                'title'   => 'Find an activity fast',
                'body'    => 'Start typing — "call", "viewing", "WhatsApp" — and the list filters instantly so you can jump straight to the row you need.',
            ],
            [
                // `choose`, not `fill`: any row's Qty box (or its − / + buttons)
                // counts, not just the first row's.
                'element' => '[data-tour="at-agent-daily-capture"]',
                'do'      => ['action' => 'choose', 'say' => 'Type how many you did next to each activity, or use the + button.'],
                'title'   => 'Capture your numbers',
                'body'    => 'Each row is an activity with a points weight. Enter how many you did today; tick-box rows are once-a-day. The Pts column shows what each one earns you.',
            ],
            [
                'element' => '[data-tour="at-agent-daily-save"]',
                'section' => 'Saving your day',
                'do'      => ['action' => 'click', 'say' => 'Click Save to lock in today\'s points.'],
                'title'   => 'Save your day',
                'body'    => 'Click Save to lock in today\'s points. They flow straight into your dashboard and your monthly target. Close this and capture today.',
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
                'section' => 'Reading your deals',
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
                'section' => 'Adding a remark',
                'do'      => ['action' => 'click', 'say' => 'Click Log to open this deal\'s history — you add your remark there.'],
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
                'section' => 'Entering the sale',
                'title'   => 'Tools',
                'body'    => 'A small workshop of agent tools. Right now you are on the Commission Calculator — the fastest way to answer "what does the seller actually pocket?".',
            ],
            [
                'element' => '[data-tour="tools-commission-tab"]',
                'do'      => ['action' => 'click', 'say' => 'Click Commission Calculator to open the calculator.', 'until' => '[data-tour="tools-commission-inputs"]'],
                'skip_if' => '[data-tour="tools-commission-inputs"]',
                'title'   => 'Commission tab',
                'body'    => 'These tabs switch between the Commission Calculator, the CMA Certificate generator and your History. This tab is the calculator.',
            ],
            [
                'element'       => '[data-tour="tools-commission-address"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type the property address.'],
                'title'         => 'Property address',
                'body'          => 'The address prints on the summary and labels this calculation in your History, so you can find it again.',
            ],
            [
                'element'       => '[data-tour="tools-commission-type"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick the property type — or press Skip this step if Residential is right.'],
                'title'         => 'Property type',
                'body'          => 'The type sets the default commission rate the calculator measures your discount against — 7.5% for residential, 10% for vacant land and commercial.',
            ],
            [
                'element' => '[data-tour="tools-commission-inputs"]',
                'do'      => ['action' => 'fill', 'target' => '#price', 'say' => 'Type the advertised price.'],
                'title'   => 'Enter the numbers',
                'body'    => 'Type the price, your commission percentage and the VAT rate. The "VAT included in comm" tick tells CoreX whether your percentage already has VAT baked in — it changes the answer, so set it correctly.',
            ],
            [
                'element'       => '[data-tour="tools-commission-inputs"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => '#commPct', 'say' => 'Type your commission percentage — or press Skip this step if 7.5% is right.'],
                'title'         => 'Your commission %',
                'body'          => 'The percentage you and the seller agreed on. The answer updates as you type.',
            ],
            [
                'element'       => '[data-tour="tools-commission-vat"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Tick VAT included if your percentage already has VAT in it — otherwise press Skip this step.'],
                'title'         => 'VAT in or out',
                'body'          => 'Tick this only when the percentage you quoted already includes VAT. Getting it wrong changes what the seller pockets.',
            ],
            [
                'element' => '[data-tour="tools-commission-results"]',
                'section' => 'Reading the answer',
                'title'   => 'The answer',
                'body'    => 'Live as you type: the selling price, what the owner pockets, the commission including VAT, and how much you are discounting against the default rate. Great for a seller conversation.',
            ],
            [
                'element' => '[data-tour="tools-commission-print"]',
                'section' => 'Printing the summary',
                'do'      => ['action' => 'click', 'say' => 'Click Print Commission Summary — it saves a copy to your History and opens the print view.'],
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
                'section' => 'Describing the property',
                'title'   => 'Tools',
                'body'    => 'You are on the CMA Certificate generator — for handing a seller a professional, branded estimate of what their property is worth.',
            ],
            [
                'element' => '[data-tour="tools-cma-tab"]',
                'do'      => ['action' => 'click', 'say' => 'Click CMA Certificate to open the certificate builder.', 'until' => '[data-tour="tools-cma-value"]'],
                'skip_if' => '[data-tour="tools-cma-value"]',
                'title'   => 'CMA Certificate tab',
                'body'    => 'This tab builds the certificate. The neighbouring tabs are the Commission Calculator and your saved History.',
            ],
            [
                'element'       => '[data-tour="tools-cma-find"]',
                'advanced_only' => true,
                'do'            => ['action' => 'appear', 'say' => 'Search for one of your listings and pick it — or press Skip this step to type the details yourself.', 'until' => '[data-tour="tools-cma-linked"]'],
                'title'         => 'Find the property',
                'body'          => 'Picking one of your listings fills in the address and details for you, and links the certificate to that property.',
            ],
            [
                'element'       => '[data-tour="tools-cma-address"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type the property address — or press Skip this step if it is already filled in.'],
                'title'         => 'Property address',
                'body'          => 'Required — the certificate cannot be saved without it. This is the address the seller will see on the certificate.',
            ],
            [
                'element'       => '[data-tour="tools-cma-type"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick the property type.'],
                'title'         => 'Property type',
                'body'          => 'House, townhouse, apartment, vacant land… It prints on the certificate next to your estimate.',
            ],
            [
                'element' => '[data-tour="tools-cma-value"]',
                'section' => 'Setting your estimate',
                'do'      => ['action' => 'fill', 'say' => 'Type your estimated market value in Rand.'],
                'title'   => 'The estimate',
                'body'    => 'Enter your estimated market value in Rand, plus the beds, baths and parking. This is the figure the seller will remember, so base it on your comparable sales.',
            ],
            [
                'element'       => '[data-tour="tools-cma-value"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => '.field:nth-child(2) input', 'say' => 'Type the number of bedrooms.'],
                'title'         => 'Bedrooms',
                'body'          => 'How many bedrooms the property has.',
            ],
            [
                'element'       => '[data-tour="tools-cma-value"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => '.field:nth-child(3) input', 'say' => 'Type the number of bathrooms.'],
                'title'         => 'Bathrooms',
                'body'          => 'How many bathrooms the property has.',
            ],
            [
                'element'       => '[data-tour="tools-cma-value"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => '.field:nth-child(4) input', 'say' => 'Type the number of parking bays — or press Skip this step if there are none.'],
                'title'         => 'Parking',
                'body'          => 'Garages and parking bays together.',
            ],
            [
                'element' => '[data-tour="tools-cma-notes"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the selling points that back up your figure.'],
                'title'   => 'Key features',
                'body'    => 'Add the selling points that justify your figure — sea views, a renovated kitchen, walking distance to the beach. This is what turns a number into a credible valuation.',
            ],
            [
                'element'       => '[data-tour="tools-cma-contact"]',
                'advanced_only' => true,
                'do'            => ['action' => 'appear', 'say' => 'Search for the seller and pick them — or press Skip this step.', 'until' => '[data-tour="tools-cma-contact-picked"]'],
                'title'         => 'Link the client',
                'body'          => 'Optional. Linking the seller files the certificate against their contact record, so it shows up on their history.',
            ],
            [
                'element' => '[data-tour="tools-cma-print"]',
                'section' => 'Saving and printing',
                'do'      => ['action' => 'click', 'target' => '[data-tour="tools-cma-save"]', 'say' => 'Click Save evaluation.', 'until' => '[data-tour="tools-cma-print-btn"]'],
                'title'   => 'Print the certificate',
                'body'    => 'Click Print CMA Certificate for a branded document to leave with the seller. Close this and build your first CMA.',
            ],
            [
                'element'       => '[data-tour="tools-cma-print-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Print to open the certificate in a new tab.'],
                'title'         => 'Print it',
                'body'          => 'Your evaluation is saved. Print opens the branded certificate so you can print it or save it as a PDF for the seller.',
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
                'section' => 'Choosing your properties',
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
                'do'      => ['action' => 'choose', 'say' => 'Pick where you will post the ads — or press Skip this step to keep the size shown.'],
                'title'   => 'Pick the ad size first',
                'body'    => 'Where will you post these — Facebook, Instagram, WhatsApp? Your choice sets the image dimensions so each ad fits the platform perfectly.',
            ],
            [
                // Moves on once at least one property is ticked — Next only
                // enables then, whether they ticked cards or used Select all.
                'element' => '[data-tour="tools-ad-manager-select"]',
                'do'      => ['action' => 'appear', 'say' => 'Tick the properties you want to advertise, or click Select all.', 'until' => '[data-tour="tools-ad-manager-next"]:not([disabled])'],
                'title'   => 'Choose your properties',
                'body'    => 'Only your active listings that are live on the website, Property24 or Private Property show here. Tick the ones you want to advertise — or use Select all.',
            ],
            [
                'element' => '[data-tour="tools-ad-manager-next"]',
                'do'      => ['action' => 'click', 'say' => 'Click Next: Choose template.', 'until' => '[data-tour="tools-ad-manager-template-step"]'],
                'title'   => 'Move to templates',
                'body'    => 'Once you have ticked at least one property, click Next to choose a template and let CoreX write the ads. Close this and pick your first listings.',
            ],
            [
                'element'       => '[data-tour="tools-ad-manager-emojis"]',
                'section'       => 'Picking a template',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Tick Include emojis if you want them in the descriptions — otherwise press Skip this step.'],
                'title'         => 'Emojis or not',
                'body'          => 'Emojis suit Facebook and WhatsApp posts; leave them off for a more formal tone.',
            ],
            [
                'element'       => '[data-tour="tools-ad-manager-templates"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click the template you want to use.'],
                'title'         => 'Choose a template',
                'body'          => 'Each preview uses your first selected property, so you can see exactly how the ad will look. The one you pick gets a coloured border.',
            ],
            [
                'element'       => '[data-tour="tools-ad-manager-generate"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Generate — CoreX builds each ad and writes its description.', 'until' => '[data-tour="tools-ad-manager-results"]'],
                'title'         => 'Generate your ads',
                'body'          => 'CoreX sizes the image and writes a description from each listing\'s real details. It takes a few seconds per property.',
            ],
            [
                'element'       => '[data-tour="tools-ad-manager-results"]',
                'section'       => 'Collecting your ads',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click Download on an ad to save the image, or Copy description to grab the words.'],
                'title'         => 'Your finished ads',
                'body'          => 'Each ad has its image and description side by side. Download the image, copy the description, and post them together.',
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
                'section' => 'Picking a tool',
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
                'section' => 'Splitting a scanned pack',
                'do'      => ['action' => 'click', 'say' => 'Click PDF Splitter to split a scanned pack — or press Skip this step to see another tool.'],
                'title'   => 'Start here',
                'body'    => 'The PDF Splitter takes one big scanned pack and uses text recognition to break it into labelled files — a huge time-saver on a FICA bundle.',
            ],
            [
                'element' => '[data-tour="tools-pdf-suite-redact-card"]',
                'section' => 'Redacting for POPIA',
                'do'      => ['action' => 'click', 'say' => 'Click Redact to black out ID numbers and bank details.'],
                'title'   => 'Redact for POPIA',
                'body'    => 'Redact blacks out ID numbers and bank details properly — the data is removed, not just hidden — so you stay POPIA-safe. Close this and open the tool you need.',
            ],
        ],
    ],

];
