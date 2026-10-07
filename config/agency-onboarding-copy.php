<?php

use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\DealPropertySyncSettingsController;
use App\Http\Controllers\Admin\ProformaSettingsController;
use App\Http\Controllers\Commission\CommissionSettingsController;
use App\Http\Controllers\Compliance\FicaOfficerAppointmentsController;
use App\Http\Controllers\CoreX\FeatureSettingsController;
use App\Http\Controllers\CoreX\LeaseSettingsController;
use App\Http\Controllers\CoreX\RentalInspectionSettingsController;
use App\Http\Controllers\CoreX\RentalInventorySettingsController;
use App\Http\Controllers\CoreX\RentalWorkOrderSettingsController;
use App\Http\Controllers\CoreX\SettingsController;
use App\Http\Controllers\Settings\Prospecting\StaleRulesController;

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

    // Explainer only — no savers, no controls, nothing to save. Sets expectations
    // before asking for a single field: what this wizard is, how long it takes,
    // and that nothing here is a one-shot decision.
    'welcome' => [
        'title' => 'Welcome to CoreX',
        'intro' => "You're setting up your agency's copy of CoreX. This walks you through "
            . "everything it needs to work the way your agency actually works — a few "
            . "screens, save-as-you-go, and every choice can be changed later from Settings.",
        'what' => [
            'title' => 'What this onboarding does',
            'body'  => "CoreX runs differently for every agency — different commission splits, different "
                . "branches, different portals, different compliance officers. This wizard is how you tell it "
                . "about YOUR agency, once, instead of hunting through Settings for two dozen separate pages on "
                . "day one. Each step explains what it's asking for and why before it asks, ships with a sensible "
                . "default so you can accept-and-continue if you're not sure, and saves the moment you press "
                . '"Save & continue" — nothing waits until the very end. You can stop at any point and pick up '
                . "exactly where you left off, and \"Skip for now\" is always there if a step doesn't apply to you "
                . "yet. Every one of these settings lives on the same Settings pages afterwards, so nothing here "
                . "is a locked-in, one-time decision — this is just the fastest way through all of them the first time.",
        ],
    ],

    'identity' => [
        'title' => 'Your agency identity',
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
            ['key' => 'vat_registered', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'VAT registered',
             'explain' => 'Whether this agency is registered for VAT with SARS.',
             'affects' => 'Whether VAT appears at all on rental job cards and quotes sent to landlords — off, job cards show no VAT anywhere. The VAT rate itself is set under Company Settings → Performance.'],
            ['key' => 'vat_capture_mode', 'source' => 'agency', 'type' => 'select', 'default' => 'excl',
             'options' => ['excl' => 'Excluding VAT', 'incl' => 'Including VAT'],
             'label' => 'Prices I capture are',
             'explain' => 'Whether prices your agents type in (job card lines, the parts & labour catalogue) are excl. or incl. VAT.',
             'affects' => 'How CoreX works out the VAT amount on a job card line from the price typed in. Existing prices are never converted when you change this.'],
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

    // ── Feature switchboard (spec: agency-onboarding-feature-switchboard.md) ──
    // A consolidated front door onto switches that already exist. Every toggle
    // fans to its EXISTING canonical saver (no parallel flag system). Turning a
    // feature OFF here skips its dedicated detail step (adaptive step-gating).
    'capabilities' => [
        'title' => 'What CoreX can do — turn features on or off',
        'intro' => 'CoreX is modular. Switch on the parts your agency uses and leave the rest off — '
            . 'everything you turn on is set up in the steps that follow, and everything you turn off is '
            . 'skipped. Nothing here is permanent; you can change any of it later from Settings.',
        'what' => [
            'title' => 'Your CoreX toolkit',
            'body'  => 'Think of this as the menu of everything CoreX can do for your agency. Each switch below '
                . 'turns a whole capability on or off. Turn one ON and the next few steps walk you through '
                . 'setting it up; turn one OFF and CoreX skips its setup and keeps it out of your agents\' way — '
                . 'you can always come back and switch it on later. Set the shape of the product here first, and '
                . 'the rest of this wizard tailors itself to the tools you chose.',
        ],
        // The auto-derived MODULE toggles (spec: corex-feature-registry.md §7) render
        // above these six capability toggles, and post their feature key to
        // FeatureSettingsController@update (agency_features). The six below keep their
        // own store field names + canonical savers.
        'partial' => 'agency-setup.steps.capabilities-modules',
        'savers' => [
            ['controller' => SettingsController::class,        'method' => 'updateMarketingEnabled'],
            ['controller' => SettingsController::class,        'method' => 'updateSyndicationPortals'],
            ['controller' => SettingsController::class,        'method' => 'updateMatchesEnabled'],
            ['controller' => SettingsController::class,        'method' => 'updateSplitBranches'],
            // toggleWebsite deliberately removed (Johan, 2026-08-12): the
            // 'website_enabled' control below was pulled from onboarding — going
            // public shouldn't happen before an agency has set up branding and
            // listings, so it's a deliberate action from Settings, never an
            // onboarding default. toggleWebsite requires the field ('required|
            // boolean') and every saver in this array runs on every submit
            // (AgencySetupWizardController::save()), so leaving it registered
            // with no matching control would fail validation on every save.
            ['controller' => FeatureSettingsController::class,  'method' => 'update'],
        ],
        'controls' => [
            ['key' => 'marketing_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Marketing',
             'explain' => 'Whether CoreX runs its marketing tooling for your listings — social posts, brochures and campaign tracking attached to each property.',
             'affects' => 'Whether the Marketing area and its buttons appear for your agents when they open a listing. Off hides the tools; nothing already created is deleted.'],

            ['key' => 'matches_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Core Matches',
             'explain' => 'Whether CoreX matches every new listing against your buyers\' wishlists in the background, so the right buyer surfaces the moment a fitting property lands.',
             'affects' => 'Whether your agents are told who to call when a new listing lands. Off means no match alerts — and the Core Matches setup step is skipped.'],

            ['key' => 'split_branches_enabled', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Multi-branch offices',
             'explain' => 'Whether your agency runs as more than one branch, each with its own agents and its own book of properties, contacts and deals.',
             'affects' => 'Whether agents are grouped by branch and whether a branch is credited on commission. With it on, an agent in one branch will not see another branch\'s data — decide it with your principal.'],

            ['key' => 'syndication_p24_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'heading' => 'Property portals',
             'label' => 'Publish to Property24',
             'explain' => 'Whether CoreX pushes your active mandates to Property24 automatically when a listing is marked to syndicate.',
             'affects' => 'Whether a syndicating listing is sent to Property24. Nothing sends with this off, even with your P24 credentials saved.'],

            ['key' => 'syndication_pp_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'label' => 'Publish to Private Property',
             'explain' => 'The same as Property24 above, for the Private Property portal.',
             'affects' => 'Whether a syndicating listing is sent to Private Property. Needs your PP credentials saved against the agency first.'],

            ['key' => 'pp_exclusivity_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'label' => 'Private Property exclusivity',
             'explain' => 'Private Property lets a newly signed sole mandate sale go exclusive to PP for a chosen number of days, with no other portal carrying it in that window. This switch lets agents opt a listing into that at all.',
             'affects' => 'Whether the "Make this listing exclusive to Private Property" tick appears on a sole mandate sale listing\'s syndication panel. Off removes the option entirely; it does not touch a listing already exclusive.'],

            ['key' => 'pp_exclusive_days_max', 'source' => 'perf', 'type' => 'number', 'default' => 92, 'min' => 1, 'max' => 92,
             'label' => 'Maximum PP exclusive days',
             'explain' => 'Private Property lets a sole mandate go exclusive to PP for a chosen number of days, during which no other portal may carry the listing. This is the ceiling an agent can choose from — nothing is ever exclusive unless an agent explicitly opts in on that listing. Private Property\'s own hard limit is 92 days.',
             'affects' => 'The maximum number of days offered on the exclusivity opt-in when an agent ticks it on a sole mandate sale listing.'],

            // Syndication Approval — the third layer after compliance.
            // .ai/specs/syndication-approval-gate.md §9. The two controls are a
            // PAIR: the switch cannot be saved on with an empty roster, and the
            // saver throws a ValidationException (never a redirect) so this step
            // re-renders with the error instead of silently not saving.
            ['key' => 'syndication_approval_required', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'heading' => 'Approval before a listing goes out',
             'label' => 'Require approval before a listing is syndicated',
             'explain' => 'When this is on, a listing that has passed compliance still cannot be sent to Property24, Private Property or your website until a person you choose has approved it. Anything already out on a portal is approved automatically the day you switch this on, so nobody has to work through your back catalogue.',
             'affects' => 'When compliance is done your agents get a "Send for approval" button instead of the portal switches; the people you pick are emailed for every listing and get an "Awaiting approval" filter on the Properties list.'],

            ['key' => 'syndication_approver_user_ids', 'source' => 'perf_json', 'type' => 'user_multiselect', 'default' => [],
             'label' => 'Who approves',
             'explain' => 'Everyone you tick is emailed when a listing is sent for approval, and any one of them can approve it. Pick more than one so a listing never waits on somebody who is away.',
             'affects' => 'Who receives the approval emails and who can release a listing to the portals. If the switch is off, this does nothing.'],
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
        'controls' => [
            // AT-234 — lives only on CompanySettingsController@update's validated
            // field set (not @updateAgency), so its home is here, not identity.
            ['key' => 'ncc_registration_number', 'source' => 'agency', 'type' => 'text', 'label' => 'NCC registration number',
             'explain' => 'Your National Credit Regulator (NCC) registration number, if your agency is registered as a credit provider or credit bureau.',
             'affects' => 'Printed alongside your other registration numbers on documents and payslips that carry your compliance details. Leave blank if this doesn\'t apply to your agency.'],
            // .ai/specs/viewing-pack.md §14 — Viewing Pack cover style. All five post through the same
            // canonical CompanySettingsController@update saver; only keys this step rendered are sent,
            // so nothing the step does not show can be wiped.
            ['key' => 'viewing_pack_cover_style', 'source' => 'agency', 'type' => 'select', 'default' => 'standard',
             'options' => \App\Services\ViewingPack\ViewingPackCoverService::STYLES,
             'label' => 'Buyer viewing pack — cover style',
             'explain' => 'The front page of the pack you hand a buyer on viewing day. "Standard" is the CoreX cover; "Classic welcome" is a large "Welcome to your viewing day" page with your logo, your agent\'s portrait and a coloured band carrying your slogan, website and office number.',
             'affects' => 'What the first page of every buyer viewing pack PDF looks like. You can change it at any time under Company Settings → Branding, where a preview shows the cover before you print.'],
            ['key' => 'viewing_pack_cover_slogan', 'source' => 'agency', 'type' => 'text',
             'label' => 'Cover slogan',
             'explain' => 'The line of text printed up the side band of the "Classic welcome" cover, for example your agency slogan.',
             'affects' => 'The slogan on the Classic welcome cover. Left blank, your company tagline is used; if you have neither, the line is simply left out.'],
            ['key' => 'viewing_pack_cover_website', 'source' => 'agency', 'type' => 'text',
             'label' => 'Cover website',
             'explain' => 'The website address printed up the side band of the "Classic welcome" cover.',
             'affects' => 'The website on the Classic welcome cover. Left blank, your agency website address is used; if you have neither, the line is left out.'],
            ['key' => 'viewing_pack_cover_phone', 'source' => 'agency', 'type' => 'text',
             'label' => 'Cover office phone',
             'explain' => 'The office telephone number printed up the side band of the "Classic welcome" cover.',
             'affects' => 'The number on the Classic welcome cover. Left blank, your company phone is used; if you have none, the line is left out.'],
            ['key' => 'viewing_pack_cover_accent_color', 'source' => 'agency', 'type' => 'text',
             'label' => 'Cover accent colour',
             'explain' => 'A hex colour such as #C00000, used for the large "YOUR" on the "Classic welcome" cover.',
             'affects' => 'The colour of the biggest word on the Classic welcome cover. Left blank, it is red (#C00000).'],
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
        // Multi-branch isolation (split_branches_enabled) is switched on in the
        // Capabilities step (spec §3.2 — one home per switch); it is deliberately
        // NOT re-added here. This step manages the branch list itself, and — from
        // AT-267 — surfaces the Assistants settings so they reach the wizard
        // (non-negotiable #10a). updateAssistants()'s boolean writes are all
        // $request->has()-guarded (§6.1), so this step posting a subset cannot wipe
        // a setting it never rendered.
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateAssistants'],
        ],
        'controls' => [
            ['key' => 'assistants_enabled', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Allow agents to have assistants',
             'explain' => 'An assistant is a person who works for one of your agents — a PA, or the office administrator who does an agent\'s paperwork. They get their own login instead of borrowing the agent\'s, and they start with a copy of that agent\'s permissions, which the agent then switches off item by item. An assistant can never do more than the agent they work for, and can never create or import a listing.',
             'affects' => 'What this changes: an "Assistants" page appears under Company for your admins, and any agent who has an assistant gets a "My Assistants" entry in their sidebar to control what that assistant may do. Everything the assistant does is recorded as being on their agent\'s behalf, so your audit trail stops saying the agent did work they did not do.'],

            ['key' => 'assistant_fica_required_default', 'source' => 'agency', 'type' => 'toggle', 'default' => 1,
             'label' => 'New assistants must complete FICA verification',
             'explain' => 'Whether a newly-created assistant is asked for their identity documents — an ID copy and proof of residence — as part of their onboarding. This sets the default; you can still change it for an individual assistant when you create them.',
             'affects' => 'What this changes: when on, a new assistant sees a Compliance tab on their profile asking for an ID copy and proof of residence, and they appear on your compliance dashboards. When off, that tab is hidden and they are skipped by compliance reminders.'],
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
                . 'their commission. Two parts are optional and switch off cleanly if you don\'t run them: the '
                . 'mentor programme, which takes an extra slice from a new agent\'s first few deals and shares '
                . 'it with the agent mentoring them; and revenue share, which pays agents a slice of company '
                . 'revenue from the agents they recruit. Get these wrong and every payout after today is wrong, '
                . 'so it is worth ten minutes now.',
        ],
        'partial' => 'agency-setup.steps.commission',
        'savers' => [
            ['controller' => CommissionSettingsController::class, 'method' => 'update'],
        ],
    ],

    // Gated on the proforma-invoices feature module (auto-derived toggle in the
    // Capabilities step) — skipped entirely for an agency that switches it off.
    'proforma' => [
        'title' => 'Proforma invoices',
        'intro' => 'Set how your proforma invoice numbers are formatted and when they fall due. '
            . 'Ships with sensible defaults — change only what your agency actually needs different.',
        'what' => [
            'title' => 'What a proforma invoice is',
            'body'  => 'A proforma invoice is the commission/fee invoice CoreX generates for a deal before '
                . 'the real tax invoice is raised — it is what an agent hands a client to show what is owed and '
                . 'when. Every one CoreX generates gets a sequential number built from the prefix and padding '
                . 'below, and a due date worked out from the rule you choose here. Your logo, VAT number and '
                . 'bank details already come from the Identity and Branding steps; this step is only the '
                . 'numbering and due-date behaviour.',
        ],
        'savers' => [
            ['controller' => ProformaSettingsController::class, 'method' => 'update'],
        ],
        'controls' => [
            ['key' => 'number_prefix', 'source' => 'proforma', 'type' => 'text', 'default' => 'PRO-',
             'label' => 'Invoice number prefix',
             'explain' => 'The letters that start every proforma invoice number.',
             'affects' => 'What every proforma invoice number looks like — e.g. "PRO-" produces PRO-0001, PRO-0002…'],
            ['key' => 'number_padding', 'source' => 'proforma', 'type' => 'number', 'default' => 4, 'min' => 1, 'max' => 10,
             'label' => 'Number padding (digits)',
             'explain' => 'How many digits the sequential number is padded to with leading zeros.',
             'affects' => 'Whether your invoices read PRO-1 or PRO-0001. 4 digits suits most agencies.'],
            ['key' => 'start_number', 'source' => 'proforma', 'type' => 'number', 'default' => 1, 'min' => 1,
             'label' => 'Start numbering from',
             'explain' => 'The first sequence number to use. Only relevant if you are migrating from another system and want to continue an existing number range — numbering can only move forward, never back or reused.',
             'affects' => 'The number on your very next proforma invoice. Leave at 1 for a brand-new agency.'],
            ['key' => 'due_date_rule', 'source' => 'proforma', 'type' => 'select', 'default' => 'end_of_month',
             'options' => ['end_of_month' => 'End of the month it was issued', 'days_after' => 'A fixed number of days after issue', 'on_receipt' => 'Due immediately on receipt'],
             'label' => 'When a proforma invoice falls due',
             'explain' => 'The rule CoreX uses to calculate the due date printed on every proforma invoice it generates.',
             'affects' => 'The due date shown on the invoice, and when it is flagged overdue if unpaid.'],
            ['key' => 'due_days', 'source' => 'proforma', 'type' => 'number', 'default' => 30, 'min' => 0, 'max' => 365,
             'label' => 'Days until due',
             'explain' => 'Only used when the rule above is "a fixed number of days after issue".',
             'affects' => 'How many days a client has to pay before the invoice is flagged overdue.'],
            ['key' => 'bank_details', 'source' => 'proforma', 'type' => 'textarea', 'default' => '',
             'label' => 'Bank details',
             'explain' => 'The account details a client pays into — bank name, account name, account number, branch code.',
             'affects' => 'Printed on every proforma invoice CoreX generates, so a client knows exactly where to pay.'],
        ],
    ],

    // .ai/specs/agency-onboarding-setup.md — Johan's ruling 2026-09-19:
    // "we will have to set up a rental in the take on wizard with all things
    // rental related." One home for every rental setting, not settings
    // scattered across the wizard. This step's KEY stays 'leases' deliberately
    // (§3.2 of that spec) — AgencyOnboardingSetup::completed_steps persists
    // step keys as literal strings per agency, so renaming the key would
    // silently regress an existing agency's progress for a step they already
    // completed under the old name. Only the TITLE/content changed to match
    // the new scope. Each saver below is deliberately narrow — validates and
    // writes ONLY its own columns — so this step can carry multiple domains'
    // settings without risking the saver-precondition incident named in that
    // spec's §4 (agency-onboarding-setup.md §6.1: a shared multi-field saver
    // silently wiping fields a step didn't render). Regression coverage:
    // tests/Feature/Onboarding/RentalsStepSaverIndependenceTest.php.
    'leases' => [
        'title' => 'Rentals',
        'intro' => 'How CoreX handles lease expiry and inspection windows for your rental portfolio.',
        'what' => [
            'title' => 'What this covers',
            'body'  => 'Everything here is a timing rule CoreX uses across your rental properties: how '
                . 'far ahead agents get warned of a lease expiring, how long a tenant has to report a '
                . 'fault after moving in, and how long they have to sign an out-inspection.',
        ],
        // .ai/specs/rental-application-field-config.md — conductor's ruling,
        // 2026-09-20: the shipped-field tick grid (shown/required) belongs
        // here because it is Johan's own tick/untick model, not a scalar
        // key/type/default control this generic form can render on its own.
        // It renders via the partial, BEFORE the generic controls below,
        // through the SAME form/save cycle (wizard.blade.php).
        //
        // The second partial renders the agency-worded repeater lists (refusal
        // reasons, condition ratings, photo-note types, inventory ratings) —
        // owner's ruling 2026-09-30, moved IN from the "Pending" list in
        // agency-onboarding-setup.md §5.1. Each list posts its own *_submitted
        // marker and saves through RentalListsWizardSaver / the canonical savers.
        'partial' => [
            'agency-setup.steps.rentals-field-config',
            'agency-setup.steps.rentals-inspection-lists',
            // LEASE-AGREEMENT BEGIN (leases.md §15.14 — Build L0): an information row with a link — the agency's
            // own lease agreement. It has no saver and posts no field, so there is nothing for a partial-step
            // post to wipe (agency-onboarding-setup.md §6.1 is satisfied by absence).
            'agency-setup.steps.rentals-lease-agreement',
            // LEASE-AGREEMENT END
        ],
        'savers' => [
            // Johan, 2026-09-22 (property 4283) — update() now also carries
            // default_deposit_months (§6.1: nullable + has()-guarded, NOT
            // required, so a request that omits it — an older wizard
            // render, a pre-existing test fixture — still saves the rest
            // of this step; the dedicated settings page always sends it).
            ['controller' => LeaseSettingsController::class, 'method' => 'update'],
            ['controller' => RentalInspectionSettingsController::class, 'method' => 'update'],
            // §24.5/§24.7 (AT-433 Part B) — its own narrow saver, same
            // one-concern-per-endpoint discipline as every other toggle on
            // this step; has()-guarded, never folded into update() above.
            ['controller' => RentalInspectionSettingsController::class, 'method' => 'updateAutoPairPhotosEnabled'],
            // §41, 2026-09-28 — same _submitted-marker-guarded discipline
            // (a checkbox, never has()-guarded on its own field — see that
            // saver's own docblock).
            ['controller' => RentalInspectionSettingsController::class, 'method' => 'updateAutoSendReportEnabled'],
            // §45.6 (Build I-4) — the report's extra copy recipients; own narrow, has()-guarded saver.
            ['controller' => RentalInspectionSettingsController::class, 'method' => 'updateReportCopies'],
            // §43 (2026-10-05) — schedule/reschedule/cancel notifications:
            // which parties, which channel(s), minimum notice, reminder
            // offset. Own narrow saver, same _submitted-marker discipline
            // as updateAutoSendReportEnabled above.
            ['controller' => RentalInspectionSettingsController::class, 'method' => 'updateScheduleNotifications'],
            // §45.7 (Build I-5) — due-date settings; one narrow, has()/filled()-guarded saver (see its own docblock).
            ['controller' => RentalInspectionSettingsController::class, 'method' => 'updateDueDates'],
            // §41-follow-up (Job 3, 2026-09-28) — the same toggle, mirrored
            // onto Inventory's own signed-report distribution. Its own
            // narrow saver, same discipline as the Inspections one directly
            // above — never folded into RentalInventorySettingsController::
            // update() (see that saver's own docblock for why).
            ['controller' => RentalInventorySettingsController::class, 'method' => 'updateAutoSendReportEnabled'],
            // rental-work-orders.md §3.4b/§8, Stage 3 (2026-09-26) — the spend
            // threshold, plus completion_requires_photo/overdue_reminder_days
            // (has()-guarded in the saver). Never merged into either saver above.
            ['controller' => RentalWorkOrderSettingsController::class, 'method' => 'update'],
            // AT-442 — own narrow saver, same has()-guarded checkbox
            // discipline as RentalInspectionSettingsController's own
            // auto_pair_photos_enabled/auto_send_report_enabled toggles —
            // never folded into update() above (that saver's own
            // required-numeric validation would reject a request that
            // omits the threshold).
            ['controller' => RentalWorkOrderSettingsController::class, 'method' => 'updateCapturePricesOnJobCards'],
            // Conductor's ruling, AT-442 follow-up — own narrow saver, same
            // discipline as the one directly above; the worker's printed
            // copy and the owner's quote PDF are not the same audience.
            ['controller' => RentalWorkOrderSettingsController::class, 'method' => 'updateShowCostsOnPrintedJobCard'],
            // §17.21.1 — each maintenance-flow build adds ITS savers (own narrow saver per setting, has()-guarded) between its markers.
            // BUILD 1 BEGIN — pricing savers (default markups, estimate term)
            // §17.14 / §17.11 — own narrow savers, has()-guarded (a key absent from this step's POST is never touched).
            ['controller' => \App\Http\Controllers\CoreX\RentalWorkOrderPricingSettingsController::class, 'method' => 'updateDefaultMarkups'],
            ['controller' => \App\Http\Controllers\CoreX\RentalWorkOrderPricingSettingsController::class, 'method' => 'updateQuoteEstimateTerm'],
            // BUILD 1 END
            // BUILD 2 BEGIN — approvals savers (tolerance, auto-variation mail, external-quote fee)
            // §17.14 — ONE narrow saver for the four approvals controls below; every field is has()-guarded in it, so a wizard step that posts
            // only a subset can never reset a setting it did not render (agency-onboarding-setup.md §6.1).
            ['controller' => RentalWorkOrderSettingsController::class, 'method' => 'updateApprovals'],
            // BUILD 2 END
            // BUILD 3 BEGIN — completion-check savers (enabled, window, dispute mail, notify crew)
            // ONE narrow saver for the four tenant-completion-check settings: each field is written only when it is present
            // in the request (has()-guarded; the toggles post a hidden "0" companion), so a wizard step that renders a subset
            // can never wipe the others (onboarding spec §6.1). It refuses 403 itself without rental_work_orders.manage_settings.
            ['controller' => \App\Http\Controllers\CoreX\RentalCompletionSettingsController::class, 'method' => 'update'],
            // BUILD 3 END
            // Owner's ruling 2026-09-30 — the four rental settings + three lists that
            // were "Pending Johan's ruling" are now in this step. Scalars use
            // has()-guarded canonical savers (credit bureau / tenanted label /
            // show_lease_type_field — LeaseSettingsController::update above;
            // require_notes_blocks_progression / omr_mark_threshold —
            // RentalInspectionSettingsController::update above). The lists go
            // through RentalListsWizardSaver, a no-op unless that list's
            // marker was posted, then straight to the canonical saver.
            ['controller' => \App\Http\Controllers\CoreX\RentalListsWizardSaver::class, 'method' => 'inspectionConditionStates'],
            ['controller' => \App\Http\Controllers\CoreX\RentalListsWizardSaver::class, 'method' => 'inspectionPhotoNoteClassifications'],
            // §45.4 item 3 (Build I-2) — the agency's own room types; no-op unless this step's marker was posted.
            ['controller' => \App\Http\Controllers\CoreX\RentalListsWizardSaver::class, 'method' => 'inspectionCustomRoomTypes'],
            // §45.5 (Build I-3) — the agency's own words for how someone attended an inspection; no-op unless this step's marker was posted.
            ['controller' => \App\Http\Controllers\CoreX\RentalListsWizardSaver::class, 'method' => 'inspectionAttendedAsLabels'],
            // §45.14 — the agency's own words for the three move-out classifications; no-op unless this step's marker was posted.
            ['controller' => \App\Http\Controllers\CoreX\RentalListsWizardSaver::class, 'method' => 'inspectionMoveOutClassificationLabels'],
            ['controller' => \App\Http\Controllers\CoreX\RentalListsWizardSaver::class, 'method' => 'inventoryConditionStates'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateCreditBureau'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateTenantedLabel'],
            // Shipped-field tick grid (rentals-field-config.blade.php partial) —
            // narrow, has()/submitted-marker-guarded savers, same independence
            // pattern as every other saver on this step.
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateFieldDisplayConfig'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateRequiredFields'],
            // The 5 scalar rental-application controls below.
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updatePropertyLock'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateTenantTagging'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateRequireFicaBeforeAuthorisation'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateDocumentUploadsOpenAfterApproval'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateReturnGate'],
            // AT-430 — one-step approval + the checklist-before-approval gate.
            // approval_mode is a required radio, always rendered/posted as
            // part of THIS step's own controls (never a subset-post risk);
            // require_checklist_complete is has()-guarded like every other
            // toggle above.
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateApprovalMode'],
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateRequireChecklistComplete'],
            // AT-445 — .ai/specs/rental-portal-access.md §7. One narrow saver
            // per toggle, same has()-guarded discipline as every other
            // checkbox on this step; the numeric expiry gets its own saver
            // too, same shape as RentalWorkOrderSettingsController::update().
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'update'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateTenantPortalEnabled'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateLandlordPortalEnabled'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateContractorLinksEnabled'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateNotifyLandlordOnDecisionNeeded'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateNotifyTenantOnStatusChange'],
            // .ai/specs/rental-work-orders.md §14.27.3 / §14.28 — crew links (Build 1's five). Each saver is
            // has()-guarded (onboarding §6.1) — an absent field leaves the saved value alone.
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewLinksEnabled'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewJobLinkExpiryDays'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewLinkShowCosts'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewLinkShowTenantContact'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateNotifyLandlordOnCrewCompletion'],
            // rental-work-orders.md §14.27.3 — Build 2. Narrow, has()-guarded saver.
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewPhotosVisibleToClients'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewStandingLinkExpiryDays'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewPageRecentCompletedDays'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewPageUpcomingDays'],
        ],
        'controls' => [
            ['key' => 'expiry_notice_window_days', 'source' => 'leases', 'type' => 'number', 'default' => 60, 'min' => 1, 'max' => 365,
             'label' => 'Warn me this many days before a lease expires',
             'explain' => 'The number of days before a lease\'s end date that CoreX should treat it as approaching expiry.',
             'affects' => 'When a lease starts showing as due for attention. 60 days suits most agencies — change it to match your own notice practice.'],
            // .ai/specs/rental-renewals.md §2 — AT-444. Same saver (LeaseSettingsController::update,
            // already registered above) — one more has()-guarded field on the same step.
            ['key' => 'tenant_notice_period_days', 'source' => 'leases', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 365,
             'label' => 'Days\' notice a tenant is expected to give',
             'explain' => 'A sensible South African convention for how much notice a tenant gives before moving out — not a legal minimum CoreX enforces.',
             'affects' => 'The notice-window figure shown on the Lease Hub and used when recording a tenant\'s notice to vacate. 30 days suits most agencies — change it to match your own lease wording.'],
            // Johan, 7 Oct 2026 (leases.md §5.3) — same saver (LeaseSettingsController::update), has()-guarded there (§6.1).
            ['key' => 'month_to_month_after_end_days', 'source' => 'leases', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 365,
             'label' => 'Days after a lease\'s end date before it goes month-to-month',
             'explain' => 'When a lease reaches its end date and there is no notice to vacate and no renewal on record, CoreX switches it to month-to-month by itself, logs it on the lease and tells the agent.',
             'affects' => 'How long after the end date a lease is left alone before it switches. 1 means the day after the end date; a higher number gives agents more time to record a renewal or a notice first. A notice or a renewal, even one still out for signing, always stops the switch.'],
            ['key' => 'fault_report_window_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 90,
             'label' => 'Days a tenant has to report a fault after moving in',
             'explain' => 'After the move-in inspection, a tenant can report anything missed without it counting against them, for this many days.',
             'affects' => 'How long the "report a fault" window stays open on a new tenancy. 7 days suits most agencies — a report after this window still reaches the agent, it is just their call whether to accept it.'],
            ['key' => 'out_inspection_signing_window_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 60,
             'label' => 'Days a tenant has to sign the out-inspection',
             'explain' => 'Once an out-inspection is ready to sign, the tenant has this many days before an agent may sign on their behalf (with a note recording that they were unreachable or declined).',
             'affects' => 'How long CoreX waits for the tenant\'s own signature before allowing an agent to close it out on their behalf. 7 days suits most agencies.'],
            // 2026-09-23 — same saver as the two window fields above
            // (RentalInspectionSettingsController::update() — registered
            // once, above); nullable + has()-guarded there, so this control
            // is safe alongside a step render that omits it.
            ['key' => 'public_link_expiry_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 90, 'min' => 1, 'max' => 3650,
             'label' => 'Days the public inspection-report link stays live',
             'explain' => 'A completed inspection\'s PDF carries a link a tenant or landlord can open with no CoreX login. This many days after it is issued, the link stops working.',
             'affects' => 'How long a shared inspection-report link keeps working. 90 days suits most agencies — an agent can always issue a fresh link later from the inspection\'s own screen.'],
            // §24.5/§24.7 (AT-433 Part B), Johan's ruling 2026-09-26 —
            // defaults ON.
            ['key' => 'auto_pair_photos_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Automatically pair before/after inspection photos',
             'explain' => 'When a move-in photo and a later inspection\'s photo are tagged to the exact same room and item, CoreX links them as a pair automatically, only when the match is unambiguous.',
             'affects' => 'Whether obvious photo pairs are already linked when an agent opens the compare screen, or every pair — even the obvious ones — waits for the agent to make it by hand. On by default; an agent can always re-run pairing manually and can unpair anything the system got wrong.'],
            // §41, 2026-09-28, Johan's ruling — defaults ON.
            ['key' => 'auto_send_report_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the signed inspection report automatically on completion',
             'explain' => 'The moment an inspection completes (every required party has signed or been dispositioned), CoreX emails the signed report to the tenant(s) and landlord from the completing agent\'s own mailbox, with a Sent Items copy and the agent CC\'d, and files it to the property.',
             'affects' => 'Whether that email goes out on its own, or an agent has to open the completed inspection and click "Resend report" themselves. Filing to the property happens either way — this toggle only governs the automatic email. On by default.'],
            // §45.6 (Build I-4) — who else is copied on the completed report. All three on ONE narrow saver
            // (RentalInspectionSettingsController::updateReportCopies, registered in 'savers' above) — every field has()-guarded.
            ['key' => 'report_agency_copy_emails', 'source' => 'rental_inspections', 'type' => 'text', 'default' => '',
             'label' => 'Agency copy address(es) for completed inspection reports',
             'explain' => 'An address your agency wants a copy of every completed inspection report to land in, e.g. a shared rentals mailbox. Separate several with commas; leave empty for none. The tenant(s) and landlord(s) always receive the report regardless.',
             'affects' => 'Whether your agency keeps its own emailed copy of each signed report, separate from the property\'s filed copy. Empty by default — nothing is assumed.'],
            ['key' => 'report_copy_inspector', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Send a copy of the report to the inspector',
             'explain' => 'The agent who actually ran the inspection (who may not be whoever created it) is emailed the signed report along with the tenant(s) and landlord(s).',
             'affects' => 'Whether the inspector receives their own copy. The report is also sent from the inspector\'s mailbox. On by default.'],
            ['key' => 'report_copy_creator', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Send a copy of the report to the agent who created the inspection',
             'explain' => 'The agent who first set the inspection up is emailed the signed report too, when that is a different person from the inspector.',
             'affects' => 'Whether the creating agent receives their own copy. On by default.'],
            // §43 (2026-10-05) — schedule/reschedule/cancel notifications.
            // All seven on the SAME saver (updateScheduleNotifications) —
            // see that method's own docblock for why none of them is
            // force-defaulted when this step is the one being saved.
            ['key' => 'notify_tenant_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Notify the tenant(s) when an inspection is scheduled, rescheduled or cancelled',
             'explain' => 'Whether the tenant(s) on the lease are told when an inspection affecting their tenancy is booked, moved, or called off.',
             'affects' => 'Whether a tenant ever hears about an inspection before the agent arrives. On by default.'],
            ['key' => 'notify_landlord_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Notify the landlord when an inspection is scheduled, rescheduled or cancelled',
             'explain' => 'Whether the property\'s landlord (resolved the same way the rest of CoreX resolves a landlord — never a guess at "the only contact on file") is told when an inspection is booked, moved, or called off.',
             'affects' => 'Whether a landlord ever hears about an inspection before it happens. On by default.'],
            ['key' => 'notify_inspector_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Notify the inspector when an inspection is scheduled, rescheduled or cancelled',
             'explain' => 'Whether the agent actually booked to do the inspection (who may not be whoever booked it) is sent their own notification.',
             'affects' => 'Whether an inspector is told directly, or only ever finds out by opening the Scheduled Inspections list. On by default.'],
            ['key' => 'notify_via_mail_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Send schedule notifications by email',
             'explain' => 'Sent from the booking agent\'s own mailbox where one is configured, the shared CoreX mailer otherwise — the same sending mechanism as every other outbound CoreX email.',
             'affects' => 'Whether any of the parties above receive an actual email. On by default.'],
            ['key' => 'notify_via_whatsapp_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 0,
             'label' => 'Send schedule notifications by WhatsApp',
             'explain' => 'There is no automated WhatsApp sending in CoreX today. Turning this on logs a ready-to-send message on the inspection itself for an agent to send by hand — it does not send anything on its own.',
             'affects' => 'Whether a WhatsApp message is prepared and logged for an agent to action, or this channel is skipped entirely. Off by default, since it does not actually send without a person.'],
            ['key' => 'minimum_notice_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 90,
             'label' => 'Usual notice period for booking an inspection (days)',
             'explain' => 'How much advance notice an inspection would normally be booked with.',
             'affects' => 'An agent booking inside this window still succeeds — they just see a flag that it\'s shorter notice than usual. Never blocks. 1 day suits most agencies.'],
            ['key' => 'reminder_days_before', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 30,
             'label' => 'Send a reminder this many days before a scheduled inspection',
             'explain' => 'A reminder notification, sent through the same parties/channels configured above, this many days before the booked date.',
             'affects' => 'Whether anyone is reminded ahead of the inspection, and how far ahead. 0 turns the reminder off entirely. 1 day suits most agencies.'],
            // §45.7 (Build I-5) — due dates and the agency's own loaded interim dates. All three on ONE narrow saver
            // (RentalInspectionSettingsController::updateDueDates, registered above) — every field has()/filled()-guarded.
            ['key' => 'raise_due_inspections_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Remind the agent when a move-in or move-out inspection is due',
             'explain' => 'CoreX works out from each active lease when its move-in inspection (the lease start, if none has been completed) and its move-out inspection (the move-out date, or the end of a fixed term) fall due, and reminds the agent responsible for the property.',
             'affects' => 'Whether that agent gets an in-CoreX reminder and an email for a due or overdue move-in/move-out inspection. The Due tab and the Command Centre show them either way; tenants and landlords are never contacted by these reminders. On by default.'],
            ['key' => 'planned_date_lead_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 14, 'min' => 0, 'max' => 90,
             'label' => 'Remind the agent this many days before an interim inspection date',
             'explain' => 'CoreX never schedules interim inspections for you — your agency loads the dates it wants on the Due tab. This is how early the responsible agent is first reminded about a date you loaded (they are reminded again on the day and the day after).',
             'affects' => 'How far ahead an agent is warned about a loaded interim date. 0 reminds on the day only. 14 days suits most agencies. Agencies that do no interim inspections never see a reminder.'],
            ['key' => 'out_due_lead_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 7, 'min' => 0, 'max' => 90,
             'label' => 'Show a move-out inspection as due this many days before the tenant leaves',
             'explain' => 'How long before the move-out date (or the end of a fixed term) the move-out inspection starts showing as due and the agent is first reminded.',
             'affects' => 'When a move-out inspection moves from "upcoming" to "due" on the Due tab and the Command Centre, and when its first reminder goes out. 0 means only from the day itself. 7 days suits most agencies.'],
            // §41-follow-up (Job 3, 2026-09-28) — same ruling, mirrored onto
            // Inventory's own signed report. Key deliberately distinct from
            // 'auto_send_report_enabled' above — see
            // RentalInventorySettingsController::updateAutoSendReportEnabled()'s
            // own docblock for why sharing a key would collide on this step.
            ['key' => 'inventory_auto_send_report_enabled', 'source' => 'rental_inventories', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the signed inventory report automatically on completion',
             'explain' => 'The moment an inventory completes (every required party has signed or been dispositioned), CoreX emails the signed report to the seller/landlord (and tenant(s), when the inventory has a lease) from the completing agent\'s own mailbox, with a Sent Items copy and the agent CC\'d, and files it to the property.',
             'affects' => 'Whether that email goes out on its own, or an agent has to open the completed inventory and click "Resend report" themselves. Filing to the property happens either way — this toggle only governs the automatic email. On by default.'],
            ['key' => 'no_approval_spend_threshold', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 500, 'min' => 0, 'max' => 99999999.99,
             'label' => 'No-approval spend limit (R)',
             'explain' => 'Below this amount, an agent can proceed with a repair without getting the owner\'s written approval first.',
             'affects' => 'Whether the owner-approval step is required at all for a given repair. R500 is a conservative default — raise it to match how much discretion you give your agents. A specific property can be set higher or lower on the property itself.'],
            // AT-442 — whether prices are used at all on internal job cards.
            ['key' => 'capture_prices_on_job_cards', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 1,
             'label' => 'Capture prices on job cards',
             'explain' => 'When your own maintenance team works a job (not an outside supplier), their job card can record a unit price and total for each part/labour line, or just the quantities with no money attached.',
             'affects' => 'Whether price and total columns appear anywhere on an internal job card. On by default — turn it off if you\'d rather job cards stayed a pure work record with no pricing.'],
            // Conductor's ruling, AT-442 follow-up — the worker's printed
            // copy and the owner's quote PDF are not the same audience.
            ['key' => 'show_costs_on_printed_job_card', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 0,
             'label' => 'Show costs on the printed job card',
             'explain' => 'The job card your maintenance worker takes on site can print with or without the cost of each part and job showing next to the parts and labour lines. It never shows the price you charge the owner.',
             'affects' => 'Whether the printed copy a worker carries shows what things cost, or just tasks, parts and quantities. Off by default — the quote you send the owner always shows the selling price either way, this only affects the worker\'s own printed copy.'],
            // AT-445 — .ai/specs/rental-portal-access.md §7. Defaults ON —
            // the whole point of this stage is that the portal works out of
            // the box; an agency that genuinely doesn't want it switches it off.
            ['key' => 'tenant_portal_enabled', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Tenant portal access',
             'explain' => 'Lets a tenant log in (same passwordless email code the buyer/seller portal already uses) and see their own lease, documents, and faults.',
             'affects' => 'Whether a tenant can reach the rentals portal at all. On by default.'],
            ['key' => 'landlord_portal_enabled', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Landlord portal access',
             'explain' => 'Lets a landlord log in and see their properties, approve/decline repair decisions, and view inspection reports.',
             'affects' => 'Whether a landlord can reach the rentals portal at all. On by default.'],
            ['key' => 'contractor_links_enabled', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Contractor secure links',
             'explain' => 'Lets an agent send a contractor a per-job link (no login) to upload a quote, upload after photos, and mark a job done.',
             'affects' => 'Whether contractor links can be issued at all. On by default.'],
            ['key' => 'contractor_secure_link_expiry_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 14, 'min' => 1, 'max' => 90,
             'label' => 'Contractor link expiry (days)',
             'explain' => 'A contractor\'s secure link stops working after this many days, when revoked, or once the job is marked done — whichever comes first.',
             'affects' => 'How long an unused contractor link stays valid. 14 days suits most agencies — an agent can always regenerate a fresh link from the work order.'],
            // .ai/specs/rental-work-orders.md §14.27.3 — crew links. Inserted directly after the contractor rows (§14.30).
            ['key' => 'crew_links_enabled', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Crew links (master switch)',
             'explain' => 'Lets an agent share a job card with the maintenance crew as a private link they open on their phone — no login — to see the tasks and materials, tick tasks off, add photos and mark the work completed.',
             'affects' => 'Whether any crew link works at all. Turning it off makes every crew link stop opening immediately, including ones already sent. On by default.'],
            ['key' => 'crew_job_link_expiry_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 14, 'min' => 1, 'max' => 90,
             'label' => 'Crew job link expiry (days)',
             'explain' => 'A crew\'s link to one job card stops working after this many days. It also stops the moment the job card is completed, cancelled or archived — whichever comes first.',
             'affects' => 'How long a job link stays valid for a job that drags on. 14 days suits most agencies — an agent can always create a fresh link from the job card.'],
            ['key' => 'crew_link_show_costs', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 0,
             'label' => 'Show costs on the crew\'s job view',
             'explain' => 'Whether the crew also sees the cost figures your office entered against the parts and labour on the job they open from a link, or only the tasks, parts and quantities. The crew never sees the price you charge the owner.',
             'affects' => 'What the crew can read on their phone. Off by default — a worker usually needs what to do and what to load, not what it costs. (Whenever a crew adds a part from their link they can always type what it cost.)'],
            ['key' => 'crew_link_show_tenant_contact', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 0,
             'label' => 'Show the tenant\'s name and phone to the crew',
             'explain' => 'Whether the crew sees the tenant\'s name and phone number on the job, so they can call ahead before arriving.',
             'affects' => 'Whether tenant contact details reach the crew\'s phone. Off by default — the crew sees the access notes only, and the agency stays the go-between.'],
            ['key' => 'notify_landlord_on_crew_completion', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the landlord when the crew marks the work completed',
             'explain' => 'When the crew signs that the work is done (on their link, or from the signed paper copy you upload), CoreX emails the landlord from the responsible agent\'s own mailbox.',
             'affects' => 'Whether the landlord hears the work is done straight away, or only after your agent has checked and closed the job card. The job card is never closed by the crew either way. On by default.'],
            ['key' => 'notify_landlord_on_decision_needed', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the landlord when a decision is needed',
             'explain' => 'When a repair needs the owner\'s approval, or a quote comes in over the spend limit, CoreX emails the landlord that a decision is waiting in their portal.',
             'affects' => 'Whether the landlord gets an email prompt, or only finds out by checking the portal themselves. On by default.'],
            ['key' => 'notify_tenant_on_status_change', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the tenant when a fault\'s status changes',
             'explain' => 'When a fault report the tenant raised is approved, declined, or resolved, CoreX emails them the update.',
             'affects' => 'Whether the tenant gets an email on each status change, or only finds out by checking the portal themselves. On by default.'],
            // rental-work-orders.md §14.27.3 — Build 2 (crew page + client visibility). Appended after the last
            // rental_portal control so it never collides with the per-job crew-link controls (§14.30).
            ['key' => 'crew_photos_visible_to_clients', 'source' => 'rental_portal', 'type' => 'select', 'default' => 'in_progress_and_completed',
             'options' => ['in_progress_and_completed' => 'Work-in-progress and completed photos', 'completed_only' => 'Completed photos only'],
             'label' => 'Crew photos tenants and landlords can see',
             'explain' => 'When your maintenance crew photographs a job, those photos can show on the job in the tenant\'s and the landlord\'s portal so they can see the work is being done.',
             'affects' => 'Whether a tenant or landlord sees the photos taken while the crew is working as well as the finished-job photos, or only the finished-job photos. The photos taken when a problem was first reported are never shown to either of them.'],
            ['key' => 'crew_standing_link_expiry_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => null, 'min' => 1, 'max' => 365,
             'label' => 'Crew page link lasts (days — blank = until revoked)',
             'explain' => 'Each maintenance crew gets one standing link to a page listing the jobs booked for them. Leave this blank and the link keeps working until you revoke or regenerate it; fill it in to make a crew link stop after that many days.',
             'affects' => 'How long a crew\'s jobs-page link stays valid after you create it. Blank suits most agencies — a crew bookmarks the page and uses it for months; an agent can revoke or regenerate the link at any time from the crew\'s screen.'],
            ['key' => 'crew_page_recent_completed_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 7, 'min' => 0, 'max' => 30,
             'label' => 'Show completed jobs on the crew page for (days)',
             'explain' => 'After the office closes a job it drops off the crew\'s jobs page. This keeps it listed (read-only) for a few days so the crew can look back at what was just done.',
             'affects' => 'How many days a finished job stays visible on the crew page. 7 days suits most agencies; set 0 to hide the finished-jobs list entirely.'],
            ['key' => 'crew_page_upcoming_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 14, 'min' => 1, 'max' => 60,
             'label' => 'Crew page "upcoming" reaches (days ahead)',
             'explain' => 'The crew page shows today\'s jobs, then the jobs booked over the next few days, and adds up the parts they need to load for all of them.',
             'affects' => 'How far ahead the crew can see booked jobs, and which jobs count towards the "what to load" list. 14 days suits most agencies — a longer window means loading for more jobs at once.'],
            // Johan, 2026-09-22 (property 4283) — "when a property has no
            // deposit amount, default it to a configurable multiple of the
            // monthly rent." Saved by LeaseSettingsController::update()
            // (registered above) — nullable + has()-guarded there (§6.1),
            // not required, after making it required first broke
            // pre-existing wizard tests that POST this step without it.
            ['key' => 'default_deposit_months', 'source' => 'leases', 'type' => 'number', 'default' => 1, 'min' => 0.1, 'max' => 12, 'step' => 0.1,
             'label' => 'Default deposit, as a multiple of monthly rent',
             'explain' => 'When an agent ticks "Has deposit" on a property but leaves the deposit amount blank, CoreX fills in a starting figure — this many months of that property\'s own rent.',
             'affects' => 'The deposit amount a property starts with when one is required but not yet typed in. 1 month suits most South African tenancies — the figure is always shown as a starting point an agent can change, never locked in.'],
            // rental-work-orders.md — the two remaining work-order settings
            // (Stage 4 shipped). Saved by RentalWorkOrderSettingsController::
            // update(), has()-guarded there (§6.1).
            ['key' => 'completion_requires_photo', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 0,
             'label' => 'Require a photo before a work order is marked complete',
             'explain' => 'When on, whoever completes a repair job must attach photo evidence of the finished work before CoreX lets it be marked complete.',
             'affects' => 'Whether a work order can be closed without a photo. Off by default, because some repairs (a replaced circuit board inside a gate motor, say) cannot sensibly be photographed.'],
            ['key' => 'overdue_reminder_days', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 365, 'step' => 1,
             'label' => 'Overdue work order reminder (days)',
             'explain' => 'How many days a work order can sit with no progress before CoreX reminds the responsible agent that it is overdue.',
             'affects' => 'How quickly stalled repairs are flagged. 3 days is the default — lower it to chase contractors harder, raise it if your jobs routinely take longer.'],
            // §17.21.1 — each maintenance-flow build adds ITS controls (explain + affects) between its markers.
            // BUILD 1 BEGIN — pricing controls
            ['key' => 'default_parts_markup_percent', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 1000, 'step' => 0.5,
             'label' => 'Default markup on parts (%)',
             'explain' => 'When your crew or office records what a part actually cost you, CoreX adds this percentage on top to arrive at the price the owner is charged — unless the office sets a price or a different markup for that line or for the whole job.',
             'affects' => 'The price an owner sees for every part on a quote. 0 % (the default) charges the owner exactly what the part cost you; for example 20 % turns a R100 part into R120. Each job card can still override it.'],
            ['key' => 'default_labour_markup_percent', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 1000, 'step' => 0.5,
             'label' => 'Default markup on labour (%)',
             'explain' => 'The same idea for labour: the percentage added on top of what the work cost you to arrive at the price the owner is charged, unless the office sets something else for that line or job.',
             'affects' => 'The price an owner sees for every labour line on a quote. 0 % (the default) charges the owner exactly what the labour cost you; each job card can still override it.'],
            ['key' => 'quote_estimate_term', 'source' => 'rental_work_orders', 'type' => 'textarea', 'default' => '',
             'label' => 'Estimate wording on owner quotes',
             'explain' => 'The short paragraph printed on every quote sent to an owner, telling them the quote is an estimate and what happens if the real cost turns out different. Leave it as it is to use the standard wording, or rewrite it in your own words.',
             'affects' => 'The wording the owner reads at the bottom of the quote PDF and in the quote email. The wording in force on the day a quote is sent is kept with that quote, so changing it later never alters a quote already sent.'],
            // BUILD 1 END
            // BUILD 2 BEGIN — approvals controls (.ai/specs/rental-work-orders.md §17.14)
            ['key' => 'variation_tolerance_percent', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 100, 'step' => 0.5,
             'label' => 'Extra work the owner is not asked about (% above what they approved)',
             'explain' => 'When a repair turns out to need extra work after the owner has approved the quote, this is how far the new total may rise above the approved amount before the owner is asked again. Each property can have its own figure, agreed with its owner; this is the starting point for any property that has none.',
             'affects' => 'Whether small extras go ahead straight away (and the owner is told) or always wait for the owner. 0 means every increase is put to the owner first — the safe starting point for a new agency. Emergency work and the owner\'s no-approval limit are unaffected.'],
            ['key' => 'notify_landlord_on_auto_variation', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the owner when extra work is approved automatically',
             'explain' => 'When extra work falls within the owner\'s agreed terms it goes ahead without asking them. This sends the owner an information email saying what was added and the new total.',
             'affects' => 'Whether the owner hears about every automatic extra, or only about the ones that need their decision. On by default.'],
            ['key' => 'external_quote_markup_type', 'source' => 'rental_work_orders', 'type' => 'select', 'default' => 'percent',
             'options' => ['percent' => 'A percentage of the contractor\'s quote', 'amount' => 'A fixed amount (R)'],
             'label' => 'Your fee on an outside contractor\'s quote — how it is worked out',
             'explain' => 'If your agency adds its own fee on top of an outside contractor\'s quote, choose whether it is a percentage of the quote or a fixed amount.',
             'affects' => 'How the figure below is applied. It does nothing while the fee is 0.'],
            ['key' => 'external_quote_markup_value', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 0, 'min' => 0, 'step' => 0.01,
             'label' => 'Your fee on an outside contractor\'s quote (0 = no fee)',
             'explain' => 'The fee your agency adds to an outside contractor\'s quote before it goes to the owner. The owner sees one total; your office sees the contractor\'s quote, your fee and the total. A single work order can override it.',
             'affects' => 'The amount the owner is asked to approve for outside work. 0 (the default) means the owner is asked to approve exactly what the contractor quoted.'],
            // BUILD 2 END
            // BUILD 3 BEGIN — completion-check controls (.ai/specs/rental-work-orders.md §17.10, §17.14)
            ['key' => 'tenant_completion_check_enabled', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 1,
             'label' => 'Ask the tenant to check finished work',
             'explain' => 'When your crew (or a contractor) reports a repair job done, CoreX emails the tenant a link to say whether the work is done, or still wrong — with photos if it is wrong. The tenant can answer from the email or from the tenant portal.',
             'affects' => 'Whether tenants are asked at all. On: a tenant who says the work is not complete puts the job into a "Disputed" state that your office must resolve before it can be closed. Off: nobody is asked and no job ever waits on a tenant. On by default.'],
            ['key' => 'completion_response_window_days', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 30, 'step' => 1,
             'label' => 'Days the tenant has to answer',
             'explain' => 'How many days a tenant has to confirm finished work, or say it is not complete, before CoreX treats their silence as "accepted". The window is fixed when the work is reported done — changing it later never moves a check that is already open.',
             'affects' => 'How long a finished job sits waiting for a tenant. 5 days suits most agencies — lower it to close jobs sooner, raise it for tenants who are slow to reply. After the window the tenant\'s link says the period has ended, and any later complaint is a new fault report.'],
            ['key' => 'notify_landlord_on_dispute', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the owner when a tenant says work is not complete',
             'explain' => 'When a tenant tells you finished work is still wrong, CoreX can email the property owner the tenant\'s words and photos, and tell them your office is arranging a fix.',
             'affects' => 'Whether the owner hears about a dispute straight away, or only when your agent tells them. On by default — owners usually prefer to hear it from you first.'],
            ['key' => 'dispute_notify_crew_immediately', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 0,
             'label' => 'Send a disputed job straight back to the crew',
             'explain' => 'When a tenant says work is not complete, the office normally looks at the complaint first and presses "Send back to crew". Switch this on and CoreX emails your crew a fresh job link, with the tenant\'s note and photos, the moment the tenant disputes the work.',
             'affects' => 'Whether a disputed job reaches the crew automatically or waits for an office decision. Off by default so no one is sent back on a complaint the office has not read. A contractor is always sent back by the office, never automatically.'],
            // BUILD 3 END
            // Owner's ruling 2026-09-30 — moved in from the §5.1 "Pending" list.
            ['key' => 'show_lease_type_field', 'source' => 'leases', 'type' => 'toggle', 'default' => 0,
             'label' => 'Show the lease type field on a lease',
             'explain' => 'Whether a lease record asks for a lease type (for example fixed-term or month-to-month). Agencies that only ever write one kind of lease can leave it off.',
             'affects' => 'Whether the "Lease type" field appears when an agent captures or edits a lease. Off hides the field; nothing already saved is deleted.'],
            ['key' => 'require_notes_blocks_progression', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Stop an inspection moving on while a required note is missing',
             'explain' => 'When an item is graded with a rating that needs a reason, an agent must type a note. This decides whether a missing note is a hard stop or only a warning.',
             'affects' => 'On: an inspection cannot be sent for signature or completed until every required note is written. Off: agents still see which rooms and items are missing notes, but can carry on.'],
            ['key' => 'all_items_required_to_complete', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Require every checklist item to be recorded before an inspection is signed or completed',
             'explain' => 'An incoming or outgoing inspection walks every room and every item on the property\'s checklist. This decides whether an agent may send the inspection for signature, or complete it, while some items have not been recorded yet. "Not applicable" counts as recorded.',
             'affects' => 'On: the agent is shown exactly which rooms and items are still unrecorded and cannot carry on until each has a condition (or "Not applicable"). Off: an inspection can be signed and completed with items left unrecorded. On by default.'],
            ['key' => 'omr_mark_threshold', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 0.35, 'min' => 0.05, 'max' => 0.95, 'step' => 0.05,
             'label' => 'Scanned inspection form: tick-box sensitivity',
             'explain' => 'If you print the paper inspection form and scan it back in, CoreX reads the tick boxes. This is the share of a box that must be dark before it counts as ticked. Most agencies never need to change it.',
             'affects' => 'How readily a scanned paper form is read as ticked. Lower it if faint or light scans are missing ticks; raise it if stray marks are being read as ticks.'],
            ['key' => 'credit_bureau_name', 'source' => 'rental_application', 'type' => 'text', 'default' => '',
             'heading' => 'Rental application wording',
             'label' => 'Credit bureau you use for tenant checks',
             'explain' => 'The name of the credit bureau your agency runs applicants through (for example TPN, XDS or Experian). Leave blank if you do not run a bureau check.',
             'affects' => 'The bureau name shown on the applicant\'s consent section and signature caption, and on the application PDF. Blank shows generic "Credit Bureau" wording.'],
            ['key' => 'tenanted_label', 'source' => 'rental_application', 'type' => 'text', 'default' => 'Rented Out',
             'label' => 'Wording for an approved application with an active lease',
             'explain' => 'Once an approved application is linked to an active lease, CoreX shows a further status so it is not confused with a recent approval that has no tenant yet. This is the word your agency uses for it.',
             'affects' => 'The status label on the applications list, the application detail screen and the contact record. Clearing it goes back to "Rented Out".'],
            // .ai/specs/rental-application-field-config.md — the 5 scalar rental-
            // application settings the conductor ruled IN the wizard, 2026-09-20.
            // identity_gate_enabled deliberately stays OUT — its own docblock
            // calls it a universal security decision, not a customisation.
            // Rate-limit knobs stay OUT — expert carve-out.
            ['key' => 'lock_property_after_submission', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'heading' => 'Rental applications',
             'label' => 'Lock the property link once an application is submitted',
             'explain' => 'Once an applicant submits, CoreX can stop the same application link from being used to apply for a different property.',
             'affects' => 'Whether an applicant\'s link stays tied to the one property they applied for, or can be reused for another listing.'],
            ['key' => 'tag_contact_as_tenant_on_approval', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'label' => 'Tag the contact as Tenant when an application is approved',
             'explain' => 'When an agent approves a rental application, CoreX can automatically add "Tenant" to that person\'s contact record.',
             'affects' => 'Whether an approved applicant\'s contact record picks up the Tenant tag automatically, or an agent has to add it by hand.'],
            ['key' => 'require_fica_before_authorisation', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 0,
             'label' => 'Require FICA verification before an application can be authorised',
             'explain' => 'CoreX can block an agent from authorising (final-approving) a rental application until the applicant\'s FICA/identity verification is complete.',
             'affects' => 'Whether the Authorise step on an application is blocked until FICA is done, or can happen before FICA is complete.'],
            ['key' => 'document_uploads_open_after_approval', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'label' => 'Keep document uploads open after an application is approved',
             'explain' => 'CoreX can keep letting an approved applicant upload outstanding documents (e.g. payslips, ID) after approval, instead of closing the upload window immediately.',
             'affects' => 'Whether an approved applicant can still add documents afterwards, or the upload window closes the moment they are approved.'],
            ['key' => 'return_gate_method', 'source' => 'rental_application', 'type' => 'select', 'default' => 'id_number',
             'options' => ['id_number' => 'ID number', 'email_otp' => 'Email OTP (one-time code by email)'],
             'label' => 'How a returning applicant proves who they are',
             'explain' => 'When someone reopens an application link they already started, CoreX asks for one of these before showing their saved answers.',
             'affects' => 'What a returning applicant is asked for before CoreX lets them back into their own in-progress application.'],
            // AT-430 Part A, 2026-09-24 — Johan, via Sherry (single-person
            // Cape Town agency): today's flow always hands an application to
            // a SECOND person for authorisation, which is a screen a
            // one-person agency sends to itself.
            ['key' => 'approval_mode', 'source' => 'rental_application', 'type' => 'select', 'default' => 'two_step',
             'options' => ['two_step' => 'Two step — agent submits, authoriser approves', 'one_step' => 'One step — the agent approves directly'],
             'label' => 'Application approval',
             'explain' => 'Two step keeps the existing hand-off to a second authoriser. One step lets an agent who is already configured as a Reviewer or Override user approve or decline an application directly, without a separate hand-off — for agencies where the same person handles and decides applications.',
             'affects' => 'Whether the review screen shows "Submit for approval" (two step) or "Approve application"/"Decline application" directly (one step) to an agent who is also a configured Reviewer or Override user. Someone who is neither still always sees "Submit for approval".'],
            // AT-430 §3.6 — Johan: "the checklist does not block approval by
            // default." Off (default): the checklist (link below) stays a
            // working aid.
            ['key' => 'require_checklist_complete', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 0,
             'label' => 'Require the application checklist to be complete before approving',
             'explain' => 'CoreX can block approving a rental application until every item on its checklist is ticked done or marked not applicable.',
             'affects' => 'Whether approving an application is blocked while checklist items are still outstanding, or the checklist stays an optional working aid.'],
        ],
        // Fine-tuning an agency does once they are live and know what they want —
        // custom labels, help text, field order, and the full custom-field editor
        // — deliberately stays out of the wizard (conductor's ruling, 2026-09-20)
        // and lives at /corex/settings/rental-applications instead. An agency
        // must never have to discover that screen by accident.
        'links' => [
            ['route' => 'corex.settings.rental-applications.edit',
             'label' => 'Rental application settings',
             'explain' => 'Custom field labels, help text, field ordering, and adding your own extra questions are set here, any time after setup.'],
            // Johan, 2026-09-20 — which property features count as inspection
            // items, and each room type's default items, are both array-
            // shaped (not the wizard's scalar key/type/default control shape)
            // — same carve-out as the link above, same reasoning: link out,
            // never silently absent.
            ['route' => 'corex.settings.rental-inspections.edit',
             'label' => 'Rental inspection settings',
             'explain' => 'Which property features count as inspection items, and each room type\'s default checklist items, are set here, any time after setup.'],
            // AT-430 §3.3 — the checklist TEMPLATE (sections + items) is
            // array-shaped CRUD, same carve-out as the two links above: link
            // out, never silently absent. Every agency gets the default
            // template (from Sherry's own paper checklist) automatically;
            // this is only where they customise it.
            ['route' => 'corex.settings.rental-applications.checklist.index',
             'label' => 'Application checklist',
             'explain' => 'The sections and items your team ticks off while vetting a rental application (documents, TPN, FICA, lease progress) start from a sensible default and can be renamed, reordered, or archived here, any time after setup.'],
        ],
    ],

    'properties' => [
        'title' => 'Properties & listings',
        // Marketing and portal syndication are switched on in the Capabilities
        // step (spec §3.2 — one home per switch). This step keeps only how the
        // property list itself behaves for your agents.
        'intro' => 'How your property list behaves day to day for your agents.',
        'what' => [
            'title' => 'Your listings, your way',
            'body'  => 'Whether CoreX markets your listings, and whether it publishes them to Property24 and '
                . 'Private Property, you already chose back in the Capabilities step. Here you set how the '
                . 'Properties list itself behaves — how much of your stock an agent sees at a time when they '
                . 'open the page in the field or at a desk. It also covers Other Agency Stock — a listing from '
                . 'another agency that your agent imports so they can take their own buyers to view it — '
                . 'who in your agency can see those listings and the consent sentence an agent agrees to when '
                . 'they import one.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updatePropertiesPerPage'],
            // AT-402 — Rental tab's Admin Fee / Marketing Fee sanity ceiling.
            ['controller' => SettingsController::class, 'method' => 'updateRentalFeeCeiling'],
            // DR2 Wave 2 — Deal → Property → Portal status sync. All three fields
            // render together on this step, so every save posts all three (the
            // controller's boolean fields default an ABSENT field to false —
            // safe only because they are never posted alone). Toggle controls
            // always post a hidden "0" companion (wizard.blade.php), so this
            // step can never partially-post and silently flip one off.
            ['controller' => DealPropertySyncSettingsController::class, 'method' => 'update'],
            // Other Agency Stock — .ai/specs/other-agency-stock.md §6/§3a. Same saver as
            // Company Settings → Other Agency Stock. Both fields are has()-guarded
            // inside it (the roles via their `_submitted` marker), so a post that
            // never rendered them leaves the saved values alone (§6.1).
            ['controller' => SettingsController::class, 'method' => 'updateOtherAgencyStock'],
        ],
        'controls' => [
            ['key' => 'properties_per_page', 'source' => 'perf', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 200,
             'label' => 'Properties per page',
             'explain' => 'How many listings load at a time on the Properties page. A smaller number loads faster on a phone in the field; a larger number means less clicking at a desk.',
             'affects' => 'How many properties an agent scrolls through before paging to the next set.'],

            ['key' => 'rental_fee_max_amount', 'source' => 'perf', 'type' => 'number', 'default' => 50000, 'min' => 1, 'max' => 10000000,
             'label' => 'Maximum rental Admin Fee / Marketing Fee (R)',
             'explain' => 'A rental listing\'s Admin Fee and Marketing Fee (on the property\'s Rental tab) can\'t be saved above this amount — a safety net against a typo like an extra zero turning a fee into a much larger number.',
             'affects' => 'The highest Rand amount an agent can enter for a rental\'s Admin Fee or Marketing Fee before the save is rejected.'],

            ['key' => 'flag_property_under_offer_on_deal', 'source' => 'deal_sync', 'type' => 'toggle', 'default' => 0,
             'heading' => 'Deal → property status sync',
             'label' => 'Flag the property Under Offer when a deal is created',
             'explain' => 'When an agent captures a deal on a linked property, CoreX can set that property to Under Offer immediately and push the change to your portals. The property\'s prior status is remembered so it can be restored automatically if the deal falls through.',
             'affects' => 'Whether a syndicated listing flips to Under Offer the moment a deal is captured against it, or stays as-is until you change it manually.'],

            ['key' => 'sold_milestone', 'source' => 'deal_sync', 'type' => 'select', 'default' => '',
             'options' => ['' => 'Off — never auto-mark sold', 'granted' => 'Commission Granted', 'registered' => 'Registered'],
             'label' => 'Which milestone marks the property Sold on portals',
             'explain' => 'When a deal on a linked property reaches this stage, CoreX sets the property to Sold and syncs it to your portals. Leave it off to keep marking properties sold manually.',
             'affects' => 'Whether — and at which deal milestone — a property is automatically switched to Sold and pushed to Property24/Private Property as sold.'],

            ['key' => 'revert_property_on_deal_declined', 'source' => 'deal_sync', 'type' => 'toggle', 'default' => 1,
             'label' => 'Revert the property when a deal is declined or lapses',
             'explain' => 'If a deal on an Under Offer property falls through, CoreX can automatically put the property back to the on-market status it held before — the safety companion to the toggle above.',
             'affects' => 'Whether a property that was auto-flagged Under Offer returns to its previous status on its own when the deal dies, or stays stuck as Under Offer until someone fixes it manually.'],

            // Other Agency Stock — .ai/specs/other-agency-stock.md §6/§3a/§10.
            // Roles: a live list of THIS agency's own roles (control type `role_multiselect`,
            // posts the `_submitted` marker the saver keys on). All ticked = visible to
            // everyone = the stored default (NULL).
            ['key' => 'other_agency_stock_visible_roles', 'source' => 'other_agency_stock', 'type' => 'role_multiselect',
             'heading' => 'Other Agency Stock',
             'label' => 'Who can see Other Agency Stock',
             'explain' => 'Other Agency Stock is a listing that belongs to another agency, which your agent has imported from Property24 or Private Property so they can show it to their own buyers. Every role is ticked as standard, so everyone in your agency sees it; untick a role to hide these listings from that role. Buyers always see them in viewing packs like any other property, whatever you choose here.',
             'affects' => 'Which of your own staff see Other Agency Stock on their Properties list and property pages. It never changes what a buyer sees, and it never publishes the listing anywhere.'],

            // Blank = the standard wording (shown greyed out as the placeholder, like the settings page).
            ['key' => 'other_agency_stock_consent_wording', 'source' => 'other_agency_stock', 'type' => 'textarea', 'rows' => 4,
             'placeholder' => \App\Models\OtherAgencyStockConsent::DEFAULT_WORDING,
             'label' => 'Import consent wording',
             'explain' => 'Before an agent imports another agency\'s listing, they must tick a box confirming they have that agency\'s permission. This is the sentence beside that box. Leave it blank to use the standard wording (shown greyed out below); write your own if your agency\'s policy needs different words.',
             'affects' => 'The exact words the agent agrees to on the import screen of the Chrome tool. The wording as it stood is saved with each import, so changing it later never rewrites an earlier consent.'],
        ],
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
        // The Core Matches master switch (matches_enabled) lives in the
        // Capabilities step (spec §3.2). This whole step is GATED on it — it only
        // appears when Core Matches is on — so it configures how Matches works,
        // never whether it is on.
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateMatchesShowOnProperties'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesVisibilityScope'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesWaMessage'],
            ['controller' => SettingsController::class, 'method' => 'updateMatchesEmailMessage'],
            // Won/Lost buyers — narrow saver, guarded by its own `_present` marker (spec §6.1).
            ['controller' => \App\Http\Controllers\CommandCenter\ContactGovernanceController::class, 'method' => 'updateCoreMatchesExcludedBuyerStates'],
        ],
        'controls' => [
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
            ['key' => 'matches_email_subject', 'source' => 'perf', 'type' => 'text', 'default' => '',
             'label' => 'Email subject line',
             'explain' => 'The subject pre-filled when an agent emails a match to a buyer who doesn\'t use WhatsApp. Leave blank to let agents write their own each time.',
             'affects' => 'The subject line sitting in the email composer when an agent emails a matched buyer.'],
            ['key' => 'matches_email_message', 'source' => 'perf', 'type' => 'textarea', 'default' => '',
             'label' => 'Email message template',
             'explain' => 'The message that pre-fills when an agent emails a match, for the buyers who don\'t have WhatsApp. Leave blank to let agents write their own each time.',
             'affects' => 'The text sitting in the email box when an agent contacts a matched buyer by email. They can always edit it before sending.'],
            ['key' => 'core_matches_excluded_buyer_states', 'source' => 'core_matches', 'type' => 'multiselect',
             'default' => ['won', 'lost'],
             'options' => ['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won'],
             'label' => 'Buyers who no longer get matches',
             'explain' => 'When a buyer reaches one of the ticked Buyer Pipeline statuses, CoreX stops matching listings to them. "Won" and "Lost" are ticked as standard — a buyer who has bought, or walked away, is finished.',
             'affects' => 'Whether a Won or Lost buyer still shows on the Core Matches screen, on a property\'s matching buyers, in new-listing alerts and in the daily match email. Move a buyer back to an active status in the pipeline and their matches return.'],
        ],
    ],

    // Gated on the prospecting feature module (auto-derived toggle in the
    // Capabilities step) — skipped entirely for an agency that switches it off.
    'market_intelligence' => [
        'title' => 'Market Intelligence',
        'intro' => 'Market Intelligence is the canvassing-pool workspace for properties that aren\'t on your books yet — '
            . 'but it has nothing to show until it knows your areas, property types, and price bands. Set that up now, '
            . 'here, so it is ready the moment portal listings start flowing in.',
        'what' => [
            'title' => 'What Market Intelligence is',
            'body'  => 'Every property CoreX sees through a portal alert — not just your own listings — lands in a '
                . 'canvassing pool. Market Intelligence groups that pool by the towns, suburbs, property types, bedroom '
                . 'counts and price bands you define below, then matches it against your buyers\' wishlists, so an agent '
                . 'opening the page immediately sees which not-yet-mandated properties fit a buyer they already have. '
                . 'None of this can be filled in for you — it depends on the towns you actually work and how your '
                . 'agency prices stock — so this is the one setup step that is genuinely yours to do.',
        ],
        'savers' => [
            ['controller' => StaleRulesController::class, 'method' => 'updateListingWindow'],
        ],
        'controls' => [
            ['key' => 'listing_off_market_days', 'source' => 'prospecting_thresholds', 'type' => 'number', 'default' => 90, 'min' => 1, 'max' => 365,
             'label' => 'Presume a portal listing off the market after (days unseen)',
             'explain' => 'CoreX only knows a portal listing is still for sale when the CoreX Chrome extension sees it again. A listing that has not been seen for this many days is presumed off the market. A mandate normally runs 90 days, so 90 is the standard.',
             'affects' => 'Which portal listings stay in Market Intelligence and in a presentation\'s Active Competition section. A shorter number drops listings an agent simply has not re-searched lately; a longer one keeps genuinely sold or withdrawn listings around for longer.'],
        ],
        'aux_partial' => 'agency-setup.steps.market-intelligence',
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
        // Lead response time (Johan, 2026-10-07; .ai/specs/lead-response-time.md) — the counting hours per
        // weekday render through this partial (a repeating 7-row control the generic loop cannot express); the
        // target is the generic number control below. Both post `lead_response_present` and save through the
        // one narrow, has()-guarded saver (spec §6.1) — the same saver Settings → Lead response uses.
        'partial' => 'agency-setup.steps.lead-response-hours',
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateContactsPerPage'],
            ['controller' => \App\Http\Controllers\CommandCenter\ContactGovernanceController::class, 'method' => 'updateLeadResponse'],
        ],
        'controls' => [
            ['key' => 'contacts_per_page', 'source' => 'perf', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 200,
             'label' => 'Contacts per page',
             'explain' => 'How many contacts load at a time on the Contacts page.',
             'affects' => 'How far an agent scrolls before paging to the next set of contacts.'],
            ['key' => 'lead_response_target_minutes', 'source' => 'lead_response', 'type' => 'number', 'default' => 60, 'min' => 1, 'max' => 10080,
             'label' => 'Respond to a new enquiry within (minutes)',
             'explain' => 'The time your agency aims to make first contact with someone who enquires through a portal, your website or a shared link. Only the hours chosen above count towards it.',
             'affects' => 'Whether an enquiry shows as answered "in target" or "late" on the Buyers Report and the Performance Report, and when a waiting enquiry is flagged as past target.'],
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
            // AT-236 — flagged 2026-07-14 as a deliberate-omission candidate pending
            // Johan's call; resolved 2026-08-04 to include it here rather than in
            // spec §5.1. Guarded internally by the 'fica_referral_settings_present'
            // hidden marker (§6.1) — the partial always renders it.
            ['controller' => FicaOfficerAppointmentsController::class, 'method' => 'saveReferralSettings'],
            // PPRA Inspection Pack Phase E — item (j), .ai/specs/ppra-inspection-pack.md
            // §6.7/§10a. A required <select> always posts a value, so no §6.1
            // has()-guard is needed here (that rule protects optional checkboxes only).
            ['controller' => SettingsController::class, 'method' => 'savePpraInspectionPackSettings'],
            // PPRA FFC Employment Letter — .ai/specs/ppra-ffc-employment-letter.md §11.
            // The saver is has()-guarded (§6.1), so a post without the field leaves it alone.
            ['controller' => SettingsController::class, 'method' => 'savePpraEmploymentLetterSettings'],
        ],
        'controls' => [
            ['key' => 'financial_year_start_month', 'source' => 'agency', 'type' => 'select', 'default' => 3,
             'options' => ['1' => 'January', '2' => 'February', '3' => 'March', '4' => 'April', '5' => 'May', '6' => 'June',
                           '7' => 'July', '8' => 'August', '9' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'],
             'label' => 'Financial year starts in',
             'explain' => 'The month your agency\'s financial year begins — used by the PPRA Inspection Pack to bound its "current financial year" sales and rentals list.',
             'affects' => 'Which sales and rentals count as "this financial year" on the PPRA Inspection Pack (Admin → PPRA Inspection Pack). Does not affect any other report.'],
            // PPRA Inspection Pack Phase F — .ai/specs/ppra-inspection-pack.md
            // §4.6a/§6.8a. Plain number inputs always post a value, so no §6.1
            // has()-guard is needed here either.
            ['key' => 'ppra_pack_sales_sample_size', 'source' => 'agency', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 50,
             'label' => 'Sales file sample size',
             'explain' => 'How many sale deals an admin can pick for the PPRA Inspection Pack\'s sales file sample (item k).',
             'affects' => 'The maximum number of sale deals selectable in the PPRA Inspection Pack\'s sample picker for item k.'],
            ['key' => 'ppra_pack_rental_sample_size', 'source' => 'agency', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 50,
             'label' => 'Rental file sample size',
             'explain' => 'How many rentals an admin can pick for the PPRA Inspection Pack\'s rental file sample (item l).',
             'affects' => 'The maximum number of rentals selectable in the PPRA Inspection Pack\'s sample picker for item l.'],
            ['key' => 'ppra_pack_mandate_sample_size', 'source' => 'agency', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 50,
             'label' => 'Mandate/MDF sample size',
             'explain' => 'How many active listings an admin can pick for the PPRA Inspection Pack\'s mandate/MDF sample (item m).',
             'affects' => 'The maximum number of active listings selectable in the PPRA Inspection Pack\'s sample picker for item m.'],
            // PPRA Inspection Pack Phase I — .ai/specs/ppra-inspection-pack.md
            // §6.8e/§11. Plain number inputs, no §6.1 has()-guard needed.
            ['key' => 'ppra_mandate_register_red_threshold_pct', 'source' => 'agency', 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 100,
             'label' => 'Mandate register red threshold (%)',
             'explain' => 'The percentage of active listings with a mandate/MDF/FICA gap that turns the PPRA Inspection Pack\'s item (m) red instead of amber.',
             'affects' => 'The red/amber threshold shown on the PPRA Inspection Pack checklist\'s item (m) row and the mandate/MDF/FICA register.'],
            ['key' => 'ppra_zip_max_files', 'source' => 'agency', 'type' => 'number', 'default' => 200, 'min' => 1, 'max' => 2000,
             'label' => 'PPRA ZIP max files',
             'explain' => 'The most files the PPRA Inspection Pack\'s mandate register "Download ZIP" (and other per-list ZIP exports) will ever bundle in one download.',
             'affects' => 'How many files the mandate register\'s bulk ZIP export includes before it stops and reports the rest as available-but-not-included.'],
            // PPRA FFC Employment Letter — .ai/specs/ppra-ffc-employment-letter.md §11.
            // Leave blank = the PPRA's own published address (shown greyed out as the
            // placeholder, exactly like the settings page), so it is correct for any agency.
            ['key' => 'ppra_employment_letter_address_block', 'source' => 'agency', 'type' => 'textarea', 'rows' => 4,
             'placeholder' => \App\Models\Compliance\PpraEmploymentLetter::DEFAULT_PPRA_ADDRESS_BLOCK,
             'label' => 'PPRA employment letter — who it is addressed to',
             'explain' => 'Each agent needs a signed "Confirmation of Employment" letter from you to renew their Fidelity Fund Certificate. This is the address block at the top of that letter (the "RE:" line). Leave it blank to use the Property Practitioners Regulatory Board\'s own published address — change it only if your agency writes to a different PPRA office.',
             'affects' => 'The addressee printed at the top of every PPRA employment letter your agents and admins generate (My Portal → Documents, and Admin → PPRA Employment Letters). Letters already printed keep what they were printed with.'],
        ],
    ],

    // AT-395 Phase A — mandatory wizard step (Johan's override, 2026-09-07):
    // "an agency sets email up correctly from the word go, so it is never
    // discovered broken later." Skippable — skipping leaves this step out of
    // completed_steps, the wizard's existing generic outstanding-setup signal;
    // no new dashboard widget needed.
    'outgoing_mail' => [
        'title' => 'Outgoing mail (SMTP)',
        'intro' => "Connect your own mail server so e-sign invitations and other CoreX emails "
            . 'go out under your own domain, not a shared one — this is what stops receiving '
            . 'mail servers from rejecting your emails.',
        'what' => [
            'title' => 'Why this matters',
            'body'  => 'When CoreX sends an e-sign invitation, receiving mail servers (like Gmail) check '
                . "whether the server that sent it is actually allowed to send on your domain's behalf. "
                . "Without your own mail server connected, that check can fail and the email never arrives "
                . '— sometimes without you or your client noticing until it is too late to sign in time. '
                . 'Connecting your own mailbox here fixes that, and also means a copy of every invitation '
                . "lands in your own Sent folder, exactly as if you had typed it yourself.",
        ],
        'savers' => [
            ['controller' => \App\Http\Controllers\Settings\EmailSetupController::class, 'method' => 'onboardingSaveOutgoing', 'pass_agency' => true],
        ],
        'controls' => [
            ['key' => 'outgoing_enabled', 'source' => 'mailbox', 'type' => 'toggle', 'default' => 0,
             'label' => 'Send outgoing mail through my own mailbox',
             'explain' => 'Turns on sending e-sign invitations through the mail server below instead of the shared CoreX address.',
             'affects' => 'Emails your agents send through CoreX (e-sign invitations first) will be sent from your own mail server and appear in your own Sent folder, instead of a shared CoreX address.'],
            ['key' => 'email_address', 'source' => 'mailbox', 'type' => 'text', 'label' => 'Your email address',
             'explain' => 'The mailbox e-sign invitations will be sent from and copied into.',
             'affects' => 'This is the From address your clients will see on e-sign invitations.'],
            ['key' => 'smtp_host', 'source' => 'mailbox', 'type' => 'text', 'label' => 'Outgoing mail server (SMTP host)',
             'explain' => 'Ask your email provider (e.g. Afrihost, cPanel) for this — it is usually something like mail.yourdomain.co.za.',
             'affects' => 'The server CoreX connects to when sending on your behalf.'],
            // AT-395 (2026-09-07) — this step never used to ask for an IMAP host at
            // all: onboardingSaveOutgoing() silently reused the SMTP host for it on
            // a brand-new mailbox, which is wrong whenever a provider's incoming and
            // outgoing servers differ (e.g. Gmail's imap.gmail.com vs
            // smtp.gmail.com). Optional here — left blank, it still defaults to the
            // SMTP host above, which is correct for the common single-host case
            // (cPanel/Afrihost) this wizard step was originally written for.
            ['key' => 'imap_host', 'source' => 'mailbox', 'type' => 'text', 'label' => 'Incoming mail server (IMAP host, optional)',
             'explain' => 'Only needed if your provider uses a different server for incoming mail. Leave blank to use the outgoing server above.',
             'affects' => 'The server CoreX connects to for reading captured email into the Communication Archive.'],
            ['key' => 'smtp_port', 'source' => 'mailbox', 'type' => 'number', 'default' => 587,
             'label' => 'Port', 'explain' => 'Usually 587. Your provider will confirm if different.',
             'affects' => 'The connection port used to send mail.'],
            ['key' => 'smtp_encryption', 'source' => 'mailbox', 'type' => 'select', 'default' => 'tls',
             'options' => ['tls' => 'TLS (most common, port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None'],
             'label' => 'Encryption',
             'explain' => 'How the connection to your mail server is secured.',
             'affects' => 'Must match what your mail provider expects, or sending will fail.'],
            ['key' => 'username', 'source' => 'mailbox', 'type' => 'text', 'label' => 'Mailbox username',
             'explain' => 'Usually your full email address.',
             'affects' => 'Used to log in and send mail through your mail server.'],
            ['key' => 'password', 'source' => 'mailbox', 'type' => 'text', 'label' => 'Mailbox password',
             'explain' => 'Your mailbox password. Stored encrypted, never shown again.',
             'affects' => 'Used to log in and send mail through your mail server. Leave blank later to keep it unchanged.'],
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

    'roles' => [
        'title' => 'Who can do what — roles & permissions',
        'intro' => 'Nothing to fill in here. This step explains how CoreX decides what each person '
            . 'in your agency is allowed to see and do, so that when you start adding your team you '
            . 'already know which role to give them.',
        'what' => [
            'title' => 'Roles, in one paragraph',
            'body'  => 'You never grant permissions to a person in CoreX. You grant them to a ROLE, and then '
                . 'you give the person that role. "Agent" is a role. "Branch Manager" is a role. Each one is a '
                . 'saved set of answers to about three hundred yes/no questions — can this person delete a '
                . 'deal, see another agent\'s commission, publish to Property24, approve a FICA pack. Hire a '
                . 'new agent and you don\'t configure anything: you pick "Agent" and they inherit the lot. '
                . 'Change your mind about what agents may do, and you change it once, on the role — and it '
                . 'applies to every agent you have, immediately. That last point is the one that catches '
                . 'people out, so it is worth reading twice.',
        ],
        // Explainer only — no savers, no controls. The Role Manager is a large,
        // permission-gated matrix (344 permissions × N roles) that cannot be
        // sensibly inlined, and nothing here needs saving. The step's job is to
        // make sure nobody meets roles for the first time on the day they are
        // trying to onboard an agent.
        'partial' => 'agency-setup.steps.roles',
    ],

    'access' => [
        'title' => 'Access & finish',
        'intro' => 'One last decision, then you are set up.',
        'what' => [
            'title' => 'Who can enter your agency',
            'body'  => 'CoreX\'s developers and testers — the people who build and support the system — can switch '
                . 'into an agency to diagnose a problem or help with a migration. Your data is never visible to '
                . 'another agency, only to them. If you would rather they ask first, switch the setting below on: '
                . 'they will have to request your consent, and you will see the request.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updateRemoteAccess'],
        ],
        'controls' => [
            ['key' => 'require_external_access_authorization', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Ask my permission before a developer or tester enters my agency',
             'explain' => 'With this on, a CoreX developer or tester must send you a consent request and wait for you to approve it before they can switch into your agency. With it off, they can enter directly to support you.',
             'affects' => 'Whether support can act on an urgent problem immediately, or has to wait for someone at your agency to approve the request first.'],
        ],
    ],
];
