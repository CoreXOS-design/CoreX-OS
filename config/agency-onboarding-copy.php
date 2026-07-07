<?php

use App\Http\Controllers\CoreX\SettingsController;

/**
 * Agency Onboarding Setup Wizard — content + control map (single source of truth).
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §5.
 *
 * Hand-written, reviewed copy — NOT AI-generated at view time (must be accurate
 * + stable). Each step declares:
 *   - key/title/intro   — plain-English framing (agent-facing, STANDARDS F.8)
 *   - controls[]        — live fields; each names the store it reads from and
 *                         the control type. WRITES go through `savers` below.
 *   - savers[]          — [controller, method] pairs the wizard INVOKES on save,
 *                         so the write path is IDENTICAL to the settings page
 *                         (spec §3.1/§6 — no drift). ValidationException from a
 *                         saver bubbles to the step; a 403 (missing per-section
 *                         permission) is absorbed and the control is skipped.
 *   - links[]           — deep links into the full settings editor for complex
 *                         collections we deliberately do not rebuild in-wizard
 *                         (tier tables, type lists, officer appointments).
 *
 * Control `source`:  'agency' → Agency column | 'perf' → PerformanceSetting key
 * Control `type`:    text | textarea | number | toggle | select
 */
return [

    'identity' => [
        'title' => 'Welcome — your agency identity',
        'intro' => "Let's start with who you are. These details appear on your documents, "
            . 'letterheads, email signatures and your public listings. Fill in what you '
            . 'have — you can always refine it later.',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateAgency'],
        ],
        'controls' => [
            ['key' => 'trading_name', 'source' => 'agency', 'type' => 'text', 'label' => 'Trading name',
             'explain' => 'The name your agency trades under, as it should read on documents.',
             'affects' => 'Document headers, email signatures, public property pages.'],
            ['key' => 'tagline', 'source' => 'agency', 'type' => 'text', 'label' => 'Tagline',
             'explain' => 'A short strapline shown under your name.',
             'affects' => 'Letterheads and public profile.'],
            ['key' => 'email', 'source' => 'agency', 'type' => 'text', 'label' => 'Agency email',
             'explain' => 'Your main contact email address.',
             'affects' => 'Document footers and public contact details.'],
            ['key' => 'phone', 'source' => 'agency', 'type' => 'text', 'label' => 'Phone',
             'explain' => 'Your main contact number.',
             'affects' => 'Document footers and public contact details.'],
            ['key' => 'address', 'source' => 'agency', 'type' => 'textarea', 'label' => 'Physical address',
             'explain' => 'Your office address.',
             'affects' => 'Letterheads and legal documents.'],
            ['key' => 'reg_no', 'source' => 'agency', 'type' => 'text', 'label' => 'Company registration no.',
             'explain' => 'Your CIPC company registration number.',
             'affects' => 'Legal documents and compliance records.'],
            ['key' => 'vat_no', 'source' => 'agency', 'type' => 'text', 'label' => 'VAT number',
             'explain' => 'Your SARS VAT registration number, if registered.',
             'affects' => 'Invoices and commission documents.'],
            ['key' => 'ffc_no', 'source' => 'agency', 'type' => 'text', 'label' => 'Agency FFC number',
             'explain' => 'Your agency Fidelity Fund Certificate number (PPRA).',
             'affects' => 'Compliance and mandate documents.'],
            ['key' => 'ppra_number', 'source' => 'agency', 'type' => 'text', 'label' => 'PPRA number',
             'explain' => 'Your Property Practitioners Regulatory Authority reference.',
             'affects' => 'Compliance documents.'],
            ['key' => 'fic_no', 'source' => 'agency', 'type' => 'text', 'label' => 'FIC number',
             'explain' => 'Your Financial Intelligence Centre registration, if applicable.',
             'affects' => 'FICA compliance records.'],
            ['key' => 'email_disclaimer', 'source' => 'agency', 'type' => 'textarea', 'label' => 'Email disclaimer',
             'explain' => 'The legal disclaimer appended to agent email signatures.',
             'affects' => 'Every outgoing email signature.'],
        ],
    ],

    'commission' => [
        'title' => 'Commission & revenue share',
        'intro' => 'This is the engine room. Your commission split, annual cap, fees and '
            . 'revenue-share tiers drive every payout calculation and the Agency Tracker. '
            . 'It ships with sensible defaults — review them carefully, then open the full '
            . 'editor to set your exact splits and tier table.',
        'mode' => 'guide',
        'links' => [
            ['label' => 'Open Commission & Revenue Share editor', 'route' => 'corex.settings', 'params' => ['s' => 'commission'],
             'explain' => 'Set agent split %, annual cap, deal fees, the revenue-share pool and the sliding tier table.'],
        ],
    ],

    'properties' => [
        'title' => 'Properties & listings',
        'intro' => 'How your property lists behave and where they syndicate.',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updatePropertiesPerPage'],
            ['controller' => SettingsController::class, 'method' => 'updateMarketingEnabled'],
            ['controller' => SettingsController::class, 'method' => 'updateSyndicationPortals'],
        ],
        'controls' => [
            ['key' => 'properties_per_page', 'source' => 'perf', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 200,
             'label' => 'Properties per page', 'explain' => 'How many listings show per page in the Properties list.',
             'affects' => 'The Properties list view.'],
            ['key' => 'marketing_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Marketing tools', 'explain' => 'Enable the marketing tools for listings.',
             'affects' => 'The marketing options on each property.'],
            ['key' => 'syndication_p24_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'label' => 'Syndicate to Property24', 'explain' => 'Push your listings to Property24 (requires P24 credentials on the agency).',
             'affects' => 'Where your listings publish.'],
            ['key' => 'syndication_pp_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'label' => 'Syndicate to Private Property', 'explain' => 'Push your listings to Private Property (requires PP credentials on the agency).',
             'affects' => 'Where your listings publish.'],
        ],
        'links' => [
            ['label' => 'Manage property types, statuses & mandate types', 'route' => 'corex.settings', 'params' => ['s' => 'feature-properties'],
             'explain' => 'Configure the dropdown lists CoreX uses for property type, listing status, mandate type and condition.'],
        ],
    ],

    'presentations' => [
        'title' => 'Presentations & CMA',
        'intro' => 'Your CMA (Comparative Market Analysis) engine decides how much evidence a '
            . 'valuation needs before it is considered strong, and how far to look for '
            . 'comparable sales. These defaults suit most South Coast markets.',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updatePresentations'],
        ],
        'controls' => [
            ['key' => 'presentations_coverage_rich_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 999,
             'label' => 'Strong evidence — comps needed', 'explain' => 'At or above this many comparable sales, a CMA is flagged "rich / strong evidence".',
             'affects' => 'The confidence badge on every presentation.'],
            ['key' => 'presentations_coverage_moderate_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 6, 'min' => 1, 'max' => 999,
             'label' => 'Moderate evidence — comps needed', 'explain' => 'At or above this many comparables, evidence is "moderate". Must be ≤ the strong threshold.',
             'affects' => 'The confidence badge on every presentation.'],
            ['key' => 'presentations_coverage_thin_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 999,
             'label' => 'Thin evidence — comps needed', 'explain' => 'Below the moderate threshold and at/above this, evidence is "thin". Must be ≤ moderate.',
             'affects' => 'The confidence badge on every presentation.'],
            ['key' => 'presentations_default_period_months', 'source' => 'agency', 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 60,
             'label' => 'Look-back period (months)', 'explain' => 'How far back to pull comparable sales by default.',
             'affects' => 'Which sales appear as comparables.'],
            ['key' => 'presentations_default_comp_scope', 'source' => 'agency', 'type' => 'select', 'default' => 'radius_all',
             'options' => ['radius_all' => 'Radius around the property', 'suburb_only' => 'Same suburb only'],
             'label' => 'Comparable search area', 'explain' => 'Where to look for comparable sales by default.',
             'affects' => 'Which sales appear as comparables.'],
            ['key' => 'presentations_default_radius_m', 'source' => 'agency', 'type' => 'number', 'default' => 1000, 'min' => 50, 'max' => 5000,
             'label' => 'Search radius (metres)', 'explain' => 'Radius used when the search area is "radius around the property".',
             'affects' => 'Which sales appear as comparables.'],
        ],
    ],

    'matches' => [
        'title' => 'Matches',
        'intro' => 'CoreX Matches surfaces which of your buyer contacts fit a new listing — so '
            . 'the moment a property comes on, you know who to call.',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateMatchesEnabled'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesShowOnProperties'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesVisibilityScope'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesWaMessage'],
        ],
        'controls' => [
            ['key' => 'matches_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Enable Matches', 'explain' => 'Turn the buyer-matching engine on.',
             'affects' => 'Whether matches are computed at all.'],
            ['key' => 'matches_show_on_properties', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Show matches on the property page', 'explain' => 'Display matching buyers directly on each property.',
             'affects' => 'The property detail page.'],
            ['key' => 'matches_visibility_scope', 'source' => 'perf', 'type' => 'select', 'default' => 'agency',
             'options' => ['agent' => 'Only the listing agent', 'branch' => 'Everyone in the branch', 'agency' => 'Everyone in the agency'],
             'label' => 'Who sees a match', 'explain' => 'How widely a buyer match is visible.',
             'affects' => 'Who can act on matches.'],
            ['key' => 'matches_wa_message', 'source' => 'perf', 'type' => 'textarea', 'default' => '',
             'label' => 'WhatsApp message template', 'explain' => 'The default WhatsApp message used when contacting a matched buyer.',
             'affects' => 'The one-tap WhatsApp button on a match.'],
        ],
    ],

    'contacts' => [
        'title' => 'Contacts',
        'intro' => 'Your contacts are the people behind every deal — buyers, sellers, landlords, '
            . 'tenants, attorneys. Set how the list behaves, then tailor the categories you '
            . 'file them under.',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateContactsPerPage'],
        ],
        'controls' => [
            ['key' => 'contacts_per_page', 'source' => 'perf', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 200,
             'label' => 'Contacts per page', 'explain' => 'How many contacts show per page in the Contacts list.',
             'affects' => 'The Contacts list view.'],
        ],
        'links' => [
            ['label' => 'Manage contact types, sources & tags', 'route' => 'corex.settings', 'params' => ['s' => 'feature-contacts'],
             'explain' => 'Configure the categories, lead sources and tags you file contacts under.'],
        ],
    ],

    'compliance' => [
        'title' => 'Compliance',
        'intro' => 'South African property practice runs on FICA, POPIA and the PPRA. CoreX tracks '
            . 'it all — but it needs to know who your compliance officers are and where '
            . 'whistleblower reports should go.',
        'mode' => 'guide',
        'links' => [
            ['label' => 'Appoint FICA / MLRO & Information officers', 'route' => 'corex.settings', 'params' => ['s' => 'whistleblow-settings'],
             'explain' => 'Record who holds your FICA (Money Laundering Reporting Officer) and POPIA Information Officer appointments.'],
            ['label' => 'Set compliance reporting routing', 'route' => 'corex.settings', 'params' => ['s' => 'whistleblow-settings'],
             'explain' => 'Choose who receives whistleblower / compliance complaints and the escalation tiers.'],
        ],
    ],

    'notifications' => [
        'title' => 'Notifications & dashboard',
        'intro' => 'CoreX nudges your team about overdue tasks, expiring leases, FICA gaps and more. '
            . 'Decide whether reminder settings are controlled per-agent or agency-wide.',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateDashboardMode'],
        ],
        'controls' => [
            ['key' => 'dashboard_settings_mode', 'source' => 'agency', 'type' => 'select', 'default' => 'user',
             'options' => ['user' => 'Each agent controls their own reminders', 'agency' => 'The agency controls reminders for everyone'],
             'label' => 'Reminder control', 'explain' => 'Whether reminder preferences are set per-agent or centrally by the agency.',
             'affects' => 'Who can change reminder timing and channels.'],
        ],
        'links' => [
            ['label' => 'Fine-tune notification channels & reminders', 'route' => 'corex.settings', 'params' => ['s' => 'notifications'],
             'explain' => 'Turn individual reminder types on/off and choose in-app, email or push delivery.'],
        ],
    ],

    'access' => [
        'title' => 'Access & finish',
        'intro' => "Last step. Decide how much control CoreX platform owners have over your "
            . 'agency, then you are all set.',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateRemoteAccess'],
        ],
        'controls' => [
            ['key' => 'require_external_access_authorization', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Require my consent for platform-owner access', 'explain' => 'When on, a CoreX platform owner must request your consent before switching into your agency.',
             'affects' => 'Whether platform owners can enter your agency without asking.'],
        ],
    ],
];
