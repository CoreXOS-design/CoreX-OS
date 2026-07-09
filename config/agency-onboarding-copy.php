<?php

use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Commission\CommissionSettingsController;
use App\Http\Controllers\CoreX\SettingsController;

/**
 * Agency Onboarding Setup Wizard — content + control map (single source of truth).
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §5.
 *
 * Hand-written, reviewed copy — NOT AI-generated at view time (must be accurate
 * + stable). Each step declares:
 *   - key/title/intro   — plain-English framing (agent-facing, STANDARDS F.8)
 *   - what              — OPTIONAL explainer card: "What is X?" rendered above the
 *                         controls. Define the feature BEFORE asking anyone to
 *                         configure it. No jargon, no CoreX-internal codenames.
 *   - controls[]        — live fields; each names the store it reads from and
 *                         the control type. WRITES go through `savers` below.
 *       · explain       — what the setting is, in a full sentence.
 *       · affects       — rendered as "What this changes:" — a concrete,
 *                         observable consequence the admin can picture. Never a
 *                         tautology ("whether matches are computed").
 *   - savers[]          — [controller, method] pairs the wizard INVOKES on save,
 *                         so the write path is IDENTICAL to the settings page
 *                         (spec §3.1/§6 — no drift). ValidationException from a
 *                         saver bubbles to the step; a 403 (missing per-section
 *                         permission) is absorbed and the control is skipped.
 *       · pass_agency   — saver signature is (Request, Agency) rather than (Request).
 *   - partial           — rich form fields rendered INSIDE the wizard form.
 *   - aux_partial       — collection editor rendered OUTSIDE it (own sub-forms).
 *
 * Control `source`:  'agency' → Agency column | 'perf' → PerformanceSetting key
 * Control `type`:    text | textarea | number | toggle | select
 */
return [

    'identity' => [
        'title' => 'Welcome — your agency identity',
        'intro' => "Let's start with who you are. These details appear on your documents, "
            . 'letterheads, email signatures and your public listings. Fill in what you '
            . 'have — nothing here is locked, and you can refine it any time.',
        'what' => [
            'title' => 'Why we ask for this up front',
            'body'  => 'CoreX generates real legal documents for you — mandates, offers to purchase, '
                . 'lease agreements, FICA packs. Every one of them carries your registered details in '
                . 'its header and footer. Capturing them once here means you never re-type them onto '
                . 'a document again, and it means the documents CoreX produces are compliant from day one.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateAgency'],
        ],
        'controls' => [
            ['key' => 'trading_name', 'source' => 'agency', 'type' => 'text', 'label' => 'Trading name',
             'explain' => 'The name your agency trades under. If you trade as something different to your registered company name, put the trading name here.',
             'affects' => 'The name printed at the top of every document, in agent email signatures, and on your public property pages.'],
            ['key' => 'tagline', 'source' => 'agency', 'type' => 'text', 'label' => 'Tagline',
             'explain' => 'A short strapline that sits under your agency name — for example "The Mandate Company".',
             'affects' => 'Appears beneath your name on letterheads and your public profile. Leave blank if you don\'t use one.'],
            ['key' => 'email', 'source' => 'agency', 'type' => 'text', 'label' => 'Agency email',
             'explain' => 'The main email address the public should use to reach your office — not an individual agent\'s address.',
             'affects' => 'Shown in document footers and as the contact address on your public listings.'],
            ['key' => 'phone', 'source' => 'agency', 'type' => 'text', 'label' => 'Phone',
             'explain' => 'Your main office contact number.',
             'affects' => 'Shown in document footers and as the contact number on your public listings.'],
            ['key' => 'address', 'source' => 'agency', 'type' => 'textarea', 'label' => 'Physical address',
             'explain' => 'The physical address of your registered office.',
             'affects' => 'Printed on letterheads and on legal documents that require your business address, such as mandates and lease agreements.'],
            ['key' => 'reg_no', 'source' => 'agency', 'type' => 'text', 'label' => 'Company registration no.',
             'explain' => 'Your CIPC company registration number, in the format 2017/431318/07.',
             'affects' => 'Printed on legal documents and stored against your compliance record.'],
            ['key' => 'vat_no', 'source' => 'agency', 'type' => 'text', 'label' => 'VAT number',
             'explain' => 'Your SARS VAT registration number. Leave blank if your agency is not VAT registered.',
             'affects' => 'Printed on invoices and commission documents. Commission in CoreX is always captured including VAT and calculated excluding it, so this number is what appears on the paperwork.'],
            ['key' => 'ffc_no', 'source' => 'agency', 'type' => 'text', 'label' => 'Agency Fidelity Fund Certificate (FFC) number',
             'explain' => 'The FFC issued to your agency by the PPRA. Every agency and every practitioner must hold a valid one to trade legally.',
             'affects' => 'Printed on mandates and compliance documents, and used by CoreX to flag when your certificate is approaching expiry.'],
            ['key' => 'ppra_number', 'source' => 'agency', 'type' => 'text', 'label' => 'PPRA reference number',
             'explain' => 'Your reference with the Property Practitioners Regulatory Authority — the body that regulates estate agents in South Africa.',
             'affects' => 'Printed on compliance documents where your regulator reference is required.'],
            ['key' => 'fic_no', 'source' => 'agency', 'type' => 'text', 'label' => 'FIC registration number',
             'explain' => 'Your registration with the Financial Intelligence Centre. Estate agencies are accountable institutions under FICA and must register.',
             'affects' => 'Stored against your FICA compliance record and printed where a FIC reference is required.'],
            ['key' => 'email_disclaimer', 'source' => 'agency', 'type' => 'textarea', 'label' => 'Email disclaimer',
             'explain' => 'The legal wording appended to the bottom of every email your agents send from CoreX — typically a confidentiality and POPIA notice.',
             'affects' => 'Added to the footer of every outgoing email signature, for every agent.'],
        ],
    ],

    'branding' => [
        'title' => 'Your logo & agency colours',
        'intro' => 'Upload your logo and CoreX will read your brand colours straight out of it. '
            . 'Adjust anything you like and watch the preview update as you go.',
        'what' => [
            'title' => 'How CoreX uses your colours',
            'body'  => 'Rather than one blunt "brand colour", CoreX uses four, each with a single job. '
                . 'That keeps the system readable: buttons always look like buttons, links always look '
                . 'like links, and nothing disappears against its background. Your colours carry through '
                . 'the app, your documents, and your public property pages — so what your team sees and '
                . 'what your sellers receive look like the same agency.',
        ],
        'partial' => 'agency-setup.steps.branding',
        'savers' => [
            // CompanySettingsController@update is the canonical branding save
            // (it is explicitly designed for sibling forms — only validated,
            // present keys reach $agency->update(), so posting just the logo +
            // colours never wipes the company fields). Takes (Request, Agency).
            ['controller' => CompanySettingsController::class, 'method' => 'update', 'pass_agency' => true],
        ],
    ],

    'branches' => [
        'title' => 'Your branches',
        'intro' => 'Add each office you trade from. If you run a single office, one branch is all you need — '
            . 'you can always add more later.',
        'what' => [
            'title' => 'What a branch is used for',
            'body'  => 'A branch is one of your physical offices. Every agent, property and deal in CoreX is '
                . 'filed against a branch, which is what lets your performance dashboards compare one office '
                . 'against another, and what decides which office a deal\'s commission is credited to. The short '
                . 'code you give each branch appears on deal references and reports.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateSplitBranches'],
        ],
        'controls' => [
            ['key' => 'split_branches_enabled', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Keep each branch\'s data separate',
             'explain' => 'Turn this on if your offices should operate as separate books — an agent in one branch will not see another branch\'s properties, contacts or deals. Leave it off if your team works one shared pool of stock.',
             'affects' => 'What an agent standing in one office can see of another office\'s work. Turning it on later will hide records people are used to seeing, so decide this with your principal.'],
        ],
        'aux_partial' => 'agency-setup.steps.branches',
    ],

    'commission' => [
        'title' => 'Commission & revenue share',
        'intro' => 'This is the engine room. Everything here feeds the number an agent is actually paid. '
            . 'It ships with sensible defaults — review them carefully.',
        'what' => [
            'title' => 'What these numbers drive',
            'body'  => 'When a deal registers, CoreX takes the gross commission, strips VAT out of it, splits '
                . 'it between the agent and the agency, subtracts any fees, and produces the figure that lands '
                . 'on the agent\'s payslip and in the Agency Tracker. The annual cap is the point at which an '
                . 'agent has contributed enough to the agency for the year and starts keeping (almost) all of '
                . 'their commission. Revenue share is optional: it pays agents a slice of company revenue from '
                . 'the agents they recruit and support. Get these wrong and every payout after today is wrong, '
                . 'so it is worth ten minutes now.',
        ],
        'partial' => 'agency-setup.steps.commission',
        'savers' => [
            ['controller' => CommissionSettingsController::class, 'method' => 'update'],
        ],
    ],

    'properties' => [
        'title' => 'Properties & listings',
        'intro' => 'How your property lists behave, where your listings get published, and the dropdown '
            . 'options your agents pick from when they capture a property.',
        'what' => [
            'title' => 'What syndication means',
            'body'  => 'Syndication is CoreX pushing your listings out to the public property portals — '
                . 'Property24 and Private Property — automatically, so your agents capture a property once '
                . 'instead of re-typing it into each portal. It only works once your portal credentials are '
                . 'saved against the agency, so leave these off until those are in place.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updatePropertiesPerPage'],
            ['controller' => SettingsController::class, 'method' => 'updateMarketingEnabled'],
            ['controller' => SettingsController::class, 'method' => 'updateSyndicationPortals'],
        ],
        'controls' => [
            ['key' => 'properties_per_page', 'source' => 'perf', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 200,
             'label' => 'Properties per page',
             'explain' => 'How many listings load at a time on the Properties page. A smaller number loads faster on a phone in the field; a larger number means less clicking at a desk.',
             'affects' => 'How many properties an agent scrolls through before paging to the next set.'],
            ['key' => 'marketing_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Marketing tools',
             'explain' => 'Switches on the marketing panel attached to each property — social posts, brochures and campaign tracking.',
             'affects' => 'Whether agents see the marketing tab and its buttons when they open a property. Turning it off hides the tools; it does not delete anything already created.'],
            ['key' => 'syndication_p24_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'label' => 'Publish listings to Property24',
             'explain' => 'When on, a listing you mark for syndication is sent to Property24 automatically, and updates to it are pushed through as you make them.',
             'affects' => 'Whether your stock appears on Property24. Needs your P24 username and password saved against the agency first — without them, nothing sends.'],
            ['key' => 'syndication_pp_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'label' => 'Publish listings to Private Property',
             'explain' => 'The same as above, for the Private Property portal.',
             'affects' => 'Whether your stock appears on Private Property. Needs your PP credentials saved against the agency first.'],
        ],
        'aux_partial' => 'agency-setup.steps.properties-collections',
    ],

    'presentations' => [
        'title' => 'Presentations & CMA',
        'intro' => 'These settings decide how CoreX builds the valuation you put in front of a seller.',
        'what' => [
            'title' => 'What a CMA is',
            'body'  => 'A CMA — Comparative Market Analysis — is how you justify a price to a seller. CoreX finds '
                . 'recent sales of similar properties near theirs ("comparables", or comps), and uses them to '
                . 'produce a defensible price range. The settings below tell it how far to look, how far back to '
                . 'go, and how many comparables it needs before it will call the evidence strong. More comps means '
                . 'a more confident valuation — but search too wide and you start comparing a beachfront house to '
                . 'one three suburbs inland.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updatePresentations'],
        ],
        'controls' => [
            ['key' => 'presentations_coverage_rich_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 999,
             'label' => 'Comparables needed for "strong evidence"',
             'explain' => 'Find at least this many comparable sales and CoreX marks the valuation as strongly evidenced. Must be the highest of the three thresholds.',
             'affects' => 'The confidence badge your seller sees on the presentation. A "strong" badge is the one that wins mandates.'],
            ['key' => 'presentations_coverage_moderate_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 6, 'min' => 1, 'max' => 999,
             'label' => 'Comparables needed for "moderate evidence"',
             'explain' => 'The middle tier. Must be less than or equal to the strong threshold above.',
             'affects' => 'The confidence badge on the presentation, and the wording CoreX uses to caveat the price range.'],
            ['key' => 'presentations_coverage_thin_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 999,
             'label' => 'Comparables needed for "thin evidence"',
             'explain' => 'The floor. Below this, CoreX warns you there is not enough recent evidence to price confidently. Must be less than or equal to the moderate threshold.',
             'affects' => 'When CoreX warns an agent that a valuation is under-evidenced before they present it to a seller.'],
            ['key' => 'presentations_default_period_months', 'source' => 'agency', 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 60,
             'label' => 'How far back to look (months)',
             'explain' => 'Only sales concluded within this many months count as comparables. Twelve months suits most markets; stretch it in a quiet suburb where little sells, shorten it in a fast-moving one.',
             'affects' => 'Which past sales are allowed into the valuation. Too long and you are pricing off a different market.'],
            ['key' => 'presentations_default_comp_scope', 'source' => 'agency', 'type' => 'select', 'default' => 'radius_all',
             'options' => ['radius_all' => 'A radius around the property', 'suburb_only' => 'The same suburb only'],
             'label' => 'Where to look for comparables',
             'explain' => 'Search a straight-line radius around the property, or restrict to sales inside the same suburb boundary. Suburb-only is safer where suburbs differ sharply in value across a road.',
             'affects' => 'Which sales are eligible as comparables before any other filter is applied.'],
            ['key' => 'presentations_default_radius_m', 'source' => 'agency', 'type' => 'number', 'default' => 1000, 'min' => 50, 'max' => 5000,
             'label' => 'Search radius (metres)',
             'explain' => 'Only used when the search area above is set to a radius. 1 000 m is roughly a ten-minute walk.',
             'affects' => 'How far from the seller\'s property CoreX will reach for a comparable sale.'],
        ],
    ],

    'matches' => [
        'title' => 'Core Matches',
        'intro' => 'Set up how CoreX connects new listings to the buyers already sitting in your database.',
        'what' => [
            'title' => 'What Core Matches is',
            'body'  => 'Every buyer you speak to has a wishlist — a suburb, a price range, a number of bedrooms. '
                . 'CoreX remembers it. Core Matches is the engine that watches that wishlist against your stock: '
                . 'the moment a property is loaded that fits a buyer\'s criteria, that buyer surfaces as a match, '
                . 'with a one-tap WhatsApp button to call them. It works in both directions — open a new listing '
                . 'and you immediately see who to phone; open a buyer and you see everything that fits them. '
                . 'It is the difference between a listing sitting for a week and a listing sold on day one.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateMatchesEnabled'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesShowOnProperties'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesVisibilityScope'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesWaMessage'],
        ],
        'controls' => [
            ['key' => 'matches_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Turn Core Matches on',
             'explain' => 'The master switch. With it on, CoreX scores every new property against every buyer wishlist in your database, in the background, as properties are captured.',
             'affects' => 'Whether your agents get told who to call when a new listing lands. Off, and buyer wishlists are stored but never acted on.'],
            ['key' => 'matches_show_on_properties', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Show matching buyers on the property page',
             'explain' => 'Adds a panel to each property listing the buyers whose wishlist it fits, best fit first.',
             'affects' => 'Whether an agent opening a property sees the buyers to call right there, or has to go looking for them.'],
            ['key' => 'matches_visibility_scope', 'source' => 'perf', 'type' => 'select', 'default' => 'agency',
             'options' => ['agent' => 'Only the agent who owns the buyer', 'branch' => 'Everyone in that branch', 'agency' => 'Everyone in the agency'],
             'label' => 'Who can see a buyer match',
             'explain' => 'A match links someone else\'s buyer to your listing. This decides how far that information travels. "Everyone in the agency" sells the most stock; "only the agent who owns the buyer" protects each agent\'s client relationships.',
             'affects' => 'Whether one agent can see — and act on — another agent\'s buyer. This is a commission-sensitive decision; agree it with your team before you change it.'],
            ['key' => 'matches_wa_message', 'source' => 'perf', 'type' => 'textarea', 'default' => '',
             'label' => 'WhatsApp message template',
             'explain' => 'The message that pre-fills when an agent taps WhatsApp on a match, so they are not writing the same opener forty times a week. Leave blank to let agents write their own each time.',
             'affects' => 'The text sitting in the WhatsApp box when an agent contacts a matched buyer. They can always edit it before sending.'],
        ],
    ],

    'contacts' => [
        'title' => 'Contacts',
        'intro' => 'Your contacts are the people behind every deal — buyers, sellers, landlords, '
            . 'tenants, attorneys. Set how the list behaves, then add the lead sources you actually use.',
        'what' => [
            'title' => 'What a lead source is',
            'body'  => 'A lead source records how a contact first found you — a walk-in, a referral, a portal '
                . 'enquiry, a show day. It takes a second to capture and it answers the question every principal '
                . 'eventually asks: which of our marketing is actually producing business? Add the channels you '
                . 'genuinely use; a short honest list beats a long aspirational one.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateContactsPerPage'],
        ],
        'controls' => [
            ['key' => 'contacts_per_page', 'source' => 'perf', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 200,
             'label' => 'Contacts per page',
             'explain' => 'How many contacts load at a time on the Contacts page.',
             'affects' => 'How far an agent scrolls before paging to the next set of contacts.'],
        ],
        'aux_partial' => 'agency-setup.steps.contacts-collections',
    ],

    'compliance' => [
        'title' => 'Compliance',
        'intro' => 'Tell CoreX who carries compliance responsibility in your agency, and where reports go.',
        'what' => [
            'title' => 'What you are being asked for',
            'body'  => 'South African property practice sits under three regimes. FICA obliges you to verify who '
                . 'your clients are and to report suspicious transactions. POPIA governs how you handle their '
                . 'personal information. The PPRA licenses you to trade at all. Each requires a named person to '
                . 'be accountable, and a channel through which someone can raise a concern — including anonymously. '
                . 'This step records who that person is and where those reports land, so CoreX can route them to '
                . 'a human instead of an inbox nobody reads.',
        ],
        'partial' => 'agency-setup.steps.compliance',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'saveWhistleblowSettings'],
        ],
    ],

    'notifications' => [
        'title' => 'Notifications & dashboard',
        'intro' => 'Decide what CoreX chases your team about, and who controls those settings.',
        'what' => [
            'title' => 'Why CoreX nudges people',
            'body'  => 'Deals die quietly. A mandate expires, a FICA document is never collected, a lease renewal '
                . 'passes, a property sits untouched for three weeks. None of these announce themselves. CoreX '
                . 'watches for them and nudges the responsible agent before they become a problem. The toggles '
                . 'below decide which of those nudges fire and how they reach people. Turn on what your agency '
                . 'will genuinely act on — reminders everyone ignores are worse than no reminders.',
        ],
        'partial' => 'agency-setup.steps.notifications',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateDashboardMode'],
            ['controller' => SettingsController::class, 'method' => 'updateAgencyDashboardSettings'],
        ],
    ],

    'access' => [
        'title' => 'Access & finish',
        'intro' => 'One last decision, then you are set up.',
        'what' => [
            'title' => 'Who can enter your agency',
            'body'  => 'CoreX platform owners — the people who build and support the system — can switch into an '
                . 'agency to diagnose a problem or help with a migration. Your data is never visible to another '
                . 'agency, only to the platform team. If you would rather they ask first, switch the setting below '
                . 'on: they will have to request your consent, and you will see the request.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateRemoteAccess'],
        ],
        'controls' => [
            ['key' => 'require_external_access_authorization', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Ask my permission before a platform owner enters my agency',
             'explain' => 'With this on, a CoreX platform owner must send you a consent request and wait for you to approve it before they can switch into your agency. With it off, they can enter directly to support you.',
             'affects' => 'Whether support can act on an urgent problem immediately, or has to wait for someone at your agency to approve the request first.'],
        ],
    ],
];
