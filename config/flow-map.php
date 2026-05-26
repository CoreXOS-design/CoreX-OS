<?php

/*
|--------------------------------------------------------------------------
| Flow Map — Curated Backbone (full CoreX guide)
|--------------------------------------------------------------------------
| Spec: .ai/specs/flows-map.md
|
| This is the human-authored "everything in CoreX" guide: every module a
| new user might need, grouped into categories, with what each part is for,
| the steps to use it, and what it connects to next. It is configuration
| (version-controlled, code-reviewed documentation) — NOT per-agency
| terminology, so it lives in config (SYSTEM.md §3 intent preserved).
|
| The live event-catalogue layer (FlowMapBuilder) enriches each node's
| `emits` automatically by reflecting app/Events/** — new domain events
| surface here with no edit.
|
| RULE (flagged to Johan): every future major feature spec adds its node
| here as part of its done-checklist, so the guide stays complete.
|
| Labels & step text are plain English (STANDARDS F.8). No jargon.
|
| Node fields:
|   key, label, description
|   category   which section it appears under (see `categories`)
|   pillar     property | contact | deal | agent | tool | admin | concept
|   route      named route (null = concept / not yet a page → not clickable)
|   permission permission key gating visibility (null = everyone). Mirrors
|              the route's own middleware so a user never sees a section
|              they cannot open.
|   icon       icon key (mapped to an SVG in the view)
|   steps      optional ordered "how to use it" mini-flow
|   emits      curated domain-event short-names this sets in motion
|   next       node keys this flows into ("what comes next")
*/

return [

    // Ordered sections of the guide. Each renders as its own block.
    'categories' => [
        ['key' => 'start',     'label' => 'Start Here',                 'description' => 'Where every day begins — your cockpit and what needs doing.'],
        ['key' => 'lifecycle', 'label' => 'The Property Lifecycle',     'description' => 'From spotting a property to getting paid. Each step flows into the next.'],
        ['key' => 'rentals',   'label' => 'Rentals',                    'description' => 'Leases under management — the cycle that repeats.'],
        ['key' => 'tools',     'label' => 'Everyday Tools',             'description' => 'Helpers you reach for across the job.'],
        ['key' => 'admin',     'label' => 'Administration',             'description' => 'Running the agency — people, roles, recruitment.'],
        ['key' => 'config',    'label' => 'Configuration & Settings',   'description' => 'Set CoreX up the way the agency works. Set once, used everywhere.'],
        ['key' => 'system',    'label' => 'System & Developer',         'description' => 'Owner-level plumbing — integrations, imports, diagnostics.'],
    ],

    'nodes' => [

        // ── Start Here ─────────────────────────────────────────────────────
        [
            'key' => 'dashboard', 'label' => 'Dashboard',
            'description' => 'Your cockpit — active work, alerts and what needs attention today. Start here every login.',
            'category' => 'start', 'pillar' => 'agent',
            'route' => 'corex.dashboard', 'permission' => null, 'icon' => 'dashboard',
            'steps' => [], 'emits' => [], 'next' => ['calendar', 'properties', 'contacts'],
        ],
        [
            'key' => 'calendar', 'label' => 'Calendar',
            'description' => 'Appointments, viewings and reminders — many created automatically as the system reacts to your work.',
            'category' => 'start', 'pillar' => 'tool',
            'route' => 'command-center.calendar', 'permission' => null, 'icon' => 'calendar',
            'steps' => [], 'emits' => [], 'next' => ['tasks'],
        ],
        [
            'key' => 'tasks', 'label' => 'Tasks',
            'description' => 'Your to-do board. CoreX raises tasks for you when something needs a follow-up.',
            'category' => 'start', 'pillar' => 'tool',
            'route' => 'command-center.tasks', 'permission' => null, 'icon' => 'tasks',
            'steps' => [], 'emits' => [], 'next' => [],
        ],

        // ── The Property Lifecycle ────────────────────────────────────────
        [
            'key' => 'prospecting', 'label' => 'Market Intelligence',
            'description' => 'See every property in the area, who is already on the market, and where the gaps are.',
            'category' => 'lifecycle', 'pillar' => 'property',
            'route' => 'market-intelligence.index', 'permission' => 'access_prospecting', 'icon' => 'radar',
            'steps' => [
                'Pick a suburb or filter the area',
                'Spot properties not yet on the market',
                'Claim one to work it',
            ],
            'emits' => ['ProspectingListingCreated', 'TrackedPropertyCreated'],
            'next' => ['tracked-properties', 'contacts'],
        ],
        [
            'key' => 'tracked-properties', 'label' => 'Tracked Properties',
            'description' => 'Every property CoreX has intelligence on — built up automatically as you work.',
            'category' => 'lifecycle', 'pillar' => 'property',
            'route' => 'corex.tracked-properties.index', 'permission' => 'access_prospecting', 'icon' => 'layers',
            'steps' => [],
            'emits' => ['TrackedPropertyCreated', 'TrackedPropertyEnriched', 'TrackedPropertyPromotedToStock'],
            'next' => ['presentations', 'properties'],
        ],
        [
            'key' => 'contacts', 'label' => 'Contacts',
            'description' => 'Every person — owners, buyers, tenants, landlords. One of the four pillars of CoreX.',
            'category' => 'lifecycle', 'pillar' => 'contact',
            'route' => 'corex.contacts.index', 'permission' => 'access_contacts', 'icon' => 'contacts',
            'steps' => [
                'Add a person or open an existing one',
                'Set their type (buyer, seller, tenant…)',
                'For buyers, add a wishlist so matching works',
            ],
            'emits' => ['ContactCreated', 'ContactBuyerStatusChanged'],
            'next' => ['core-matches', 'presentations'],
        ],
        [
            'key' => 'presentations', 'label' => 'Presentations',
            'description' => 'Build the listing presentation / CMA you take to the owner to win the mandate.',
            'category' => 'lifecycle', 'pillar' => 'property',
            'route' => 'presentations.index', 'permission' => 'access_presentations', 'icon' => 'presentation',
            'steps' => [
                'Start a presentation for the property',
                'Pull in comparable sales & pricing',
                'Generate the PDF and present it',
            ],
            'emits' => ['PresentationFieldsExtracted'],
            'next' => ['seller-outreach', 'docuperfect'],
        ],
        [
            'key' => 'seller-outreach', 'label' => 'Seller Outreach',
            'description' => 'Pitch the owner and track every touchpoint until they say yes.',
            'category' => 'lifecycle', 'pillar' => 'contact',
            'route' => null, 'permission' => 'access_prospecting', 'icon' => 'send',
            'steps' => [],
            'emits' => ['PitchSent', 'PitchClicked', 'OutreachOutcomeUpdated', 'OptOutRecorded'],
            'next' => ['docuperfect'],
        ],
        [
            'key' => 'docuperfect', 'label' => 'DocuPerfect (E-Sign)',
            'description' => 'Generate and e-sign the mandate, OTP, lease and every legal document.',
            'category' => 'lifecycle', 'pillar' => 'deal',
            'route' => 'docuperfect.dashboard', 'permission' => 'access_docuperfect', 'icon' => 'signature',
            'steps' => [
                'Choose a document template',
                'Pick the property & the people signing',
                'Send for signature and track it',
            ],
            'emits' => ['MandateSigned'],
            'next' => ['mandate-signed'],
        ],
        [
            'key' => 'mandate-signed', 'label' => 'Mandate Signed',
            'description' => 'The owner has signed. The Tracked Property is promoted to Agency Stock — automatically.',
            'category' => 'lifecycle', 'pillar' => 'concept',
            'route' => null, 'permission' => null, 'icon' => 'milestone',
            'steps' => [],
            'emits' => ['MandateSigned', 'TrackedPropertyPromotedToStock'],
            'next' => ['properties'],
        ],
        [
            'key' => 'properties', 'label' => 'Properties (Agency Stock)',
            'description' => 'The formal listings HFC works. The Property pillar — the spine of CoreX.',
            'category' => 'lifecycle', 'pillar' => 'property',
            'route' => 'corex.properties.index', 'permission' => 'access_properties', 'icon' => 'home',
            'steps' => [
                'Open the property record',
                'Complete details, photos & pricing',
                'Publish to portals (P24 / Private Property)',
            ],
            'emits' => ['PropertyCreated', 'PropertyStatusChanged', 'PropertySuburbLinked'],
            'next' => ['core-matches', 'deals', 'rentals'],
        ],
        [
            'key' => 'core-matches', 'label' => 'Buyer Matching',
            'description' => 'CoreX matches active buyers to the property automatically — no manual searching.',
            'category' => 'lifecycle', 'pillar' => 'contact',
            'route' => 'corex.core-matches.index', 'permission' => 'access_contacts', 'icon' => 'match',
            'steps' => [],
            'emits' => ['BuyerWishlistCreated', 'ProspectingListingMatched'],
            'next' => ['deals'],
        ],
        [
            'key' => 'deals', 'label' => 'Deal Register',
            'description' => 'The transaction record — offer to acceptance to registration. The Deal pillar.',
            'category' => 'lifecycle', 'pillar' => 'deal',
            'route' => 'deals-v2.index', 'permission' => 'access_deal_register_v2', 'icon' => 'handshake',
            'steps' => [
                'Open a deal when an offer is accepted',
                'Work the pipeline steps to registration',
                'Settle commission and pay the agent',
            ],
            'emits' => ['DealCreated', 'DealRegistered'],
            'next' => ['compliance', 'commission'],
        ],
        [
            'key' => 'compliance', 'label' => 'Compliance (FICA)',
            'description' => 'FICA, POPIA and PPRA checks that must clear before a deal can proceed.',
            'category' => 'lifecycle', 'pillar' => 'contact',
            'route' => 'compliance.fica.index', 'permission' => 'access_compliance', 'icon' => 'shield',
            'steps' => [],
            'emits' => ['FicaApproved', 'FicaRejected'],
            'next' => ['commission'],
        ],
        [
            'key' => 'commission', 'label' => 'Commission & Earnings',
            'description' => 'When the deal registers, commission is calculated and the agent is paid.',
            'category' => 'lifecycle', 'pillar' => 'agent',
            'route' => 'commission.dashboard', 'permission' => null, 'icon' => 'cash',
            'steps' => [], 'emits' => ['DealRegistered'], 'next' => [],
        ],

        // ── Rentals ────────────────────────────────────────────────────────
        [
            'key' => 'rentals', 'label' => 'Rentals',
            'description' => 'Leases under management — renewals, exits and re-listing restart the cycle.',
            'category' => 'rentals', 'pillar' => 'property',
            'route' => 'rentals.index', 'permission' => 'view_rentals', 'icon' => 'key',
            'steps' => [
                'Sign a rental mandate & list it',
                'Vet a tenant and sign the lease',
                'Manage renewals, exits and re-list',
            ],
            'emits' => [], 'next' => ['prospecting'],
        ],

        // ── Everyday Tools ─────────────────────────────────────────────────
        [
            'key' => 'pdf-suite', 'label' => 'PDF Suite',
            'description' => 'Split, merge, compress, rotate, redact — everything you do with a PDF.',
            'category' => 'tools', 'pillar' => 'tool',
            'route' => 'tools.pdf_suite.hub', 'permission' => 'access_pdf_suite', 'icon' => 'file',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'filing', 'label' => 'Filing Register',
            'description' => 'Every document filed against a property, contact or deal — the audit trail.',
            'category' => 'tools', 'pillar' => 'tool',
            'route' => 'filing-register.index', 'permission' => 'access_filing_register', 'icon' => 'folder',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'knowledge-base', 'label' => 'Knowledge Base',
            'description' => 'Searchable agency know-how — policies, how-tos and legal references.',
            'category' => 'tools', 'pillar' => 'tool',
            'route' => 'admin.knowledge.index', 'permission' => 'access_knowledge_base', 'icon' => 'book',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'calculators', 'label' => 'Calculators',
            'description' => 'Commission, bond and deposit-interest calculators for quick answers.',
            'category' => 'tools', 'pillar' => 'tool',
            'route' => 'calculators.index', 'permission' => 'access_calculators', 'icon' => 'cash',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'ellie', 'label' => 'Ellie AI',
            'description' => 'Your in-house property AI — ask about listings, agents, compliance and SA law.',
            'category' => 'tools', 'pillar' => 'tool',
            'route' => 'ellie.index', 'permission' => 'access_ellie', 'icon' => 'sparkle',
            'steps' => [], 'emits' => [], 'next' => [],
        ],

        // ── Administration ─────────────────────────────────────────────────
        [
            'key' => 'role-manager', 'label' => 'Role Manager',
            'description' => 'Decide what each role can see and do across CoreX.',
            'category' => 'admin', 'pillar' => 'admin',
            'route' => 'corex.role-manager', 'permission' => 'access_role_manager', 'icon' => 'shield-user',
            'steps' => [
                'Pick a role (or add a new one)',
                'Tick the permissions that role should have',
                'Set the data scope — own / branch / all',
                'Save — it applies to every user in that role',
            ],
            'emits' => [], 'next' => ['staff-take-on'],
        ],
        [
            'key' => 'staff-take-on', 'label' => 'Staff Take-On',
            'description' => 'Add a new staff member and walk them through everything needed to start.',
            'category' => 'admin', 'pillar' => 'admin',
            'route' => 'staff-take-on.index', 'permission' => 'manage_staff_take_on', 'icon' => 'user-plus',
            'steps' => [
                'Capture the new person’s details',
                'Assign their role & branch',
                'Collect documents (FFC, ID, contract)',
            ],
            'emits' => [], 'next' => [],
        ],
        [
            'key' => 'onboarding', 'label' => 'Onboarding',
            'description' => 'Review and approve agent applications coming into the agency.',
            'category' => 'admin', 'pillar' => 'admin',
            'route' => 'onboarding.index', 'permission' => 'manage_staff_take_on', 'icon' => 'user-plus',
            'steps' => [], 'emits' => [], 'next' => ['staff-take-on'],
        ],
        [
            'key' => 'company-settings', 'label' => 'Company Settings',
            'description' => 'Branches, company details and the agency profile.',
            'category' => 'admin', 'pillar' => 'admin',
            'route' => 'admin.company-settings', 'permission' => 'access_settings', 'icon' => 'building',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'training-mgmt', 'label' => 'Training Management',
            'description' => 'Build courses and lessons new agents must complete.',
            'category' => 'admin', 'pillar' => 'admin',
            'route' => 'training.manage', 'permission' => 'training.manage', 'icon' => 'book',
            'steps' => [], 'emits' => [], 'next' => [],
        ],

        // ── Configuration & Settings ──────────────────────────────────────
        [
            'key' => 'settings', 'label' => 'Settings',
            'description' => 'The control room — property types, statuses, deal types, document categories. Set once, used everywhere (no hardcoding).',
            'category' => 'config', 'pillar' => 'admin',
            'route' => 'corex.settings', 'permission' => 'access_settings', 'icon' => 'cog',
            'steps' => [
                'Pick the area you want to configure',
                'Add or edit the dropdown / type / status',
                'Save — every screen uses the new value',
            ],
            'emits' => [], 'next' => ['role-manager'],
        ],
        [
            'key' => 'finance-engine', 'label' => 'Finance Engine',
            'description' => 'Define the commission, split and payout rules the deal money runs on.',
            'category' => 'config', 'pillar' => 'admin',
            'route' => 'admin.finance.definitions', 'permission' => 'access_finance_engine', 'icon' => 'cash',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'pipeline-setup', 'label' => 'Deal Pipeline Setup',
            'description' => 'Configure the step-by-step workflow every deal follows.',
            'category' => 'config', 'pillar' => 'admin',
            'route' => 'deals-v2.pipeline.index', 'permission' => 'deals_v2.manage_pipeline', 'icon' => 'flow',
            'steps' => [
                'Create or edit a pipeline template',
                'Add the steps and their order',
                'Assign it to a deal type',
            ],
            'emits' => [], 'next' => ['deals'],
        ],
        [
            'key' => 'contact-governance', 'label' => 'Contact Governance',
            'description' => 'Who can see whose contacts, and the leave-visibility rules.',
            'category' => 'config', 'pillar' => 'admin',
            'route' => 'command-center.settings.contact-governance', 'permission' => 'access_settings', 'icon' => 'contacts',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'mi-settings', 'label' => 'Market Intelligence Settings',
            'description' => 'Tune the suburb mapping, price bands and bedroom segments prospecting uses.',
            'category' => 'config', 'pillar' => 'admin',
            'route' => 'command-center.settings.market-intelligence', 'permission' => 'access_settings', 'icon' => 'radar',
            'steps' => [],
            'emits' => ['SuburbMappingChanged', 'PriceBandConfigured', 'BedroomSegmentConfigured', 'TownConfigured', 'PropertyTypeConfigured'],
            'next' => ['prospecting'],
        ],

        // ── System & Developer ─────────────────────────────────────────────
        [
            'key' => 'agency-management', 'label' => 'Agency Management',
            'description' => 'System-owner control of every agency on CoreX and their brand colours.',
            'category' => 'system', 'pillar' => 'admin',
            'route' => 'agencies.index', 'permission' => 'manage_agency_switching', 'icon' => 'building',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'api-catalog', 'label' => 'API Catalog',
            'description' => 'Every CoreX API endpoint, auto-listed and discoverable.',
            'category' => 'system', 'pillar' => 'admin',
            'route' => 'admin.api.catalog', 'permission' => 'manage_agency_switching', 'icon' => 'code',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
        [
            'key' => 'importer', 'label' => 'Property Importer',
            'description' => 'Bulk-import and review properties from P24 and other sources.',
            'category' => 'system', 'pillar' => 'admin',
            'route' => 'admin.importer.index', 'permission' => 'manage_agency_switching', 'icon' => 'import',
            'steps' => [], 'emits' => [], 'next' => ['properties'],
        ],
        [
            'key' => 'dev-settings', 'label' => 'Dev Settings',
            'description' => 'Feature flags and low-level system configuration.',
            'category' => 'system', 'pillar' => 'admin',
            'route' => 'admin.dev-settings.index', 'permission' => 'manage_agency_switching', 'icon' => 'cog',
            'steps' => [], 'emits' => [], 'next' => [],
        ],
    ],
];
