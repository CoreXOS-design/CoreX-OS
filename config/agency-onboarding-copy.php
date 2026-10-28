<?php

use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\DealPropertySyncSettingsController;
use App\Http\Controllers\Admin\ProformaSettingsController;
use App\Http\Controllers\Commission\CommissionSettingsController;
use App\Http\Controllers\Compliance\FicaOfficerAppointmentsController;
use App\Http\Controllers\CoreX\Auctions\AuctionSettingsController;
use App\Http\Controllers\CoreX\AgencyTypeController;
use App\Http\Controllers\CoreX\ComplianceSwitchesController;
use App\Http\Controllers\CoreX\FeatureRolloutController;
use App\Http\Controllers\CoreX\FeatureSettingsController;
use App\Http\Controllers\CoreX\LeaseSettingsController;
use App\Http\Controllers\CoreX\RentalInspectionSettingsController;
use App\Http\Controllers\CoreX\RentalInventorySettingsController;
use App\Http\Controllers\CoreX\RentalWorkOrderSettingsController;
use App\Http\Controllers\CoreX\SettingsController;
use App\Http\Controllers\Settings\Prospecting\StaleRulesController;
use App\Http\Controllers\TitleDeeds\TitleDeedSettingsController;

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
            // Runs after updateAgency; writes only when the step posted `agency_type` (§6.1).
            ['controller' => AgencyTypeController::class, 'method' => 'update'],
        ],
        'controls' => [
            ['key' => 'agency_type', 'source' => 'agency', 'type' => 'select', 'default' => 'both',
             // Same three values/labels as config/agency-types.php (a test keeps the two identical).
             'options' => ['both' => 'Sales and rentals', 'sales' => 'Sales only', 'rentals' => 'Rentals only'],
             'label' => 'What does your agency do?',
             'explain' => 'Whether you sell property, rent it out for landlords, or do both. CoreX uses this to switch on the tools that fit — a rentals agency is not given the sales toolkit, and a sales agency is not given the Rentals area.',
             'affects' => 'Which features start switched on in the next step. Rentals only turns the sales tools off (buyers, prospecting, presentations, deal tracking); sales only turns the Rentals area off. You can still switch any feature on or off yourself on the Features page.'],
            ['key' => 'trading_name', 'source' => 'agency', 'type' => 'text', 'label' => 'Trading name',
             'explain' => 'The name your agency trades under. If you trade as something different to your registered company name, put the trading name here.',
             'affects' => 'The name printed at the top of every document, in agent email signatures, and on your public property pages.'],
            ['key' => 'legal_name', 'source' => 'agency', 'type' => 'text', 'label' => 'Registered company name (document footer)',
             'explain' => 'Your registered company name as it should read at the foot of every document, for example "Sunrise Rentals cc t/a Coastal Rentals". Leave it blank to use your trading name.',
             'affects' => 'The first part of the footer line on every document CoreX produces for you (inventory and inspection reports, FICA forms, applications): "<this name> | Reg No ... | PPRA Reg ...".'],
            ['key' => 'document_website', 'source' => 'agency', 'type' => 'text', 'label' => 'Website printed on documents',
             'explain' => 'The website address you want on your letterhead, for example www.coastalrentals.co.za. This is only a printed line - it is separate from the website address your public listings use.',
             'affects' => 'A website line in the letterhead at the top of your documents. Left blank, no website line is printed.'],
            ['key' => 'short_code', 'source' => 'agency', 'type' => 'text', 'label' => 'Short code',
             'explain' => 'Two to ten letters or numbers that stand for your agency, for example CTR for Cape Town Rentals. We worked one out from your name — change it if you prefer other letters.',
             'affects' => 'The letters your document numbers start with — payslips read CTR-202610-001. Changing it later only affects numbers issued from then on; numbers already issued stay as they are.'],
            ['key' => 'timezone', 'source' => 'agency', 'type' => 'select', 'default' => '',
             'options' => ['' => 'Same as the system (' . config('app.timezone') . ')'] + array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
             'label' => 'Timezone',
             'explain' => 'The timezone your agency works in. Pick it if you are not in the system\'s own timezone; leave it on the default if you are.',
             'affects' => 'The times your staff, crews, owners and tenants see - appointment and scheduled times on job cards, crew links, emails and the crew page, and the hours outreach messages may be sent. Times already stored are not changed, only shown in this timezone.'],
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
        // AT-478: the gradual-introduction choice renders FIRST (how CoreX switches on), then the toolkit.
        'partial' => ['agency-setup.steps.feature-rollout-choice', 'agency-setup.steps.capabilities-modules'],
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
            // AT-478 — MUST stay last: the gradual introduction holds back the features that are ON after
            // every switch above has been saved. No-op unless its block was rendered (feature_rollout_present).
            ['controller' => FeatureRolloutController::class,   'method' => 'saveChoice'],
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

            ['key' => 'sold_portal_show_days', 'source' => 'perf', 'type' => 'number', 'default' => 14, 'min' => 0, 'max' => 365,
             'label' => 'Keep showing on portals as Sold for (days)',
             'explain' => 'When a property sells it can stay on Property24 marked Sold for a while - it shows buyers you get results. This is how many days after the sale date it stays up before CoreX takes it down. Your own website\'s Sold showcase keeps every sale regardless.',
             'affects' => 'How long a sold listing stays on the portals. 0 takes it down the day it sells; nothing about your Sold stock list or website Sold feed changes.'],

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
        'controls' => [
            ['key' => 'portal_show_agency_commission_totals', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Show agents their branch and agency commission totals',
             'explain' => 'Every agent sees their own commission on My Portal — what they earned, what they take home after splits and deductions, and what is still in their pipeline. This decides whether they also see the combined commission totals for their branch and for the whole agency. It never shows another agent\'s figures, only totals, and only to people whose role already lets them see that branch or agency.',
             'affects' => 'What this changes: when on, the "My commission" card on My Portal gains two extra lines — the branch total and the agency total for the month. When off (the default), agents see only their own commission.'],
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
            // Rentals front-half decision D6: the decline reasons (a link row, no saver - the decline email wording is below).
            'agency-setup.steps.rentals-decline-templates',
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
            ['controller' => \App\Http\Controllers\CoreX\RentalWorkOrderPricingSettingsController::class, 'method' => 'updateCatalogueMarkup'],
            // BUILD 1 END
            // BUILD 2 BEGIN — approvals savers (external-quote fee)
            // §17.14 — ONE narrow saver for the approvals controls below; every field is has()-guarded in it, so a wizard step that posts
            // only a subset can never reset a setting it did not render (agency-onboarding-setup.md §6.1).
            ['controller' => RentalWorkOrderSettingsController::class, 'method' => 'updateApprovals'],
            // BUILD 2 END
            // BUILD 3 BEGIN — completion-check savers (enabled, window, dispute mail, notify crew)
            // ONE narrow saver for the four tenant-completion-check settings: each field is written only when it is present
            // in the request (has()-guarded; the toggles post a hidden "0" companion), so a wizard step that renders a subset
            // can never wipe the others (onboarding spec §6.1). It refuses 403 itself without rental_work_orders.manage_settings.
            ['controller' => \App\Http\Controllers\CoreX\RentalCompletionSettingsController::class, 'method' => 'update'],
            // BUILD 3 END
            // §17.31 — the two supplier-invoice limits; each written only when present in the request (has()-guarded).
            ['controller' => \App\Http\Controllers\CoreX\RentalWorkOrderInvoiceSettingsController::class, 'method' => 'update'],
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
            // §55 - the standard room list per kind of property (house / flat / townhouse / other); no-op unless this step's marker was posted.
            ['controller' => \App\Http\Controllers\CoreX\RentalListsWizardSaver::class, 'method' => 'inspectionRoomTemplates'],
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
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateFrontHalfDefaults'],
            // Rentals front-half decision D6: the decline email's subject and body (has()-guarded; the settings page keeps its own saver).
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateDeclineEmailWording'],
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
            // Audit fix A4/A27 (2026-10-19): who approves applications. One narrow saver, each list written only when posted (has()-guarded).
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateAuthorisersFromWizard'],
            // Johan, 2026-10-07 — lease rent above the approved amount. has()-guarded on its own field.
            ['controller' => \App\Http\Controllers\CoreX\RentalApplicationSettingsController::class, 'method' => 'updateRentAboveApprovedMode'],
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
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updatePushInformationalDefault'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateAutoPortalAccessOnSigning'],
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
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewPageUpcomingDays'],
            // .ai/specs/rental-portal-access.md §22 — fault photo limits and the portal Home FAQ wording. Each saver has()-guards its own field(s).
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateFaultPhotoMaxCount'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateFaultPhotoMaxMb'],
            // rentals-faults-work-orders.md §18 (AT-452) — has()-guarded toggle.
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateTenantSeesAgentMarks'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateCrewLinkAutoSendOnFaultJob'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateFaultRecurrenceWindowDays'],
            // Rental portal security timings (audit 2026-10-19). Each saver skips a blank/absent field (onboarding 6.1).
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updatePortalRememberDays'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updatePortalAppTokenDays'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updatePortalApiPerMinute'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updatePortalPhotoLinkMinutes'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updatePortalLinkDays'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateOwnerReminderHours'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateOwnerSecondReminderHours'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateOwnerQueueAfterHours'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateLinkRenewOnUse'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateQuoteMaxAmount'],
            ['controller' => \App\Http\Controllers\CoreX\RentalPortalSettingsController::class, 'method' => 'updateFaqTexts'],
        ],
        'controls' => [
            ['key' => 'expiry_notice_window_days', 'source' => 'leases', 'type' => 'number', 'default' => 60, 'min' => 1, 'max' => 365,
             'label' => 'Warn me this many days before a lease expires',
             'explain' => 'The number of days before a lease\'s end date that CoreX should treat it as approaching expiry.',
             'affects' => 'When a lease starts showing as due for attention. 60 days suits most agencies — change it to match your own notice practice.'],
            // .ai/specs/rental-renewals.md §2 — AT-444. Same saver (LeaseSettingsController::update,
            // already registered above) — one more has()-guarded field on the same step.
            ['key' => 'tenant_notice_period_days', 'source' => 'leases', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 365,
             'label' => 'Notice a tenant gives (length)',
             'explain' => 'How much notice a tenant gives before moving out — the length; the unit is the next control. Every new lease starts with this and you can change it on each lease. A sensible South African convention, not a legal minimum CoreX enforces.',
             'affects' => 'The notice period written onto each new lease (and onto old leases when you run the back-fill), shown to tenants and owners in the portal and used on the lease agreement when your agreement carries it. 30 days suits most agencies — change it to match your own lease wording.'],
            // .ai/specs/leases.md §18.2 — same saver (LeaseSettingsController::update), each field has()-guarded there (§6.1).
            ['key' => 'tenant_notice_period_unit', 'source' => 'leases', 'type' => 'select', 'default' => 'days',
             'options' => ['days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months'],
             'label' => 'Notice a tenant gives (unit)',
             'explain' => 'Whether the notice length above counts days, weeks or months.',
             'affects' => 'How the notice period reads on a new lease and in the portal (for example "30 days" or "1 month") and how its dates are worked out. Days suits most agencies.'],
            // lease-notices.md §7 - same saver (LeaseSettingsController::update), has()-guarded there (§6.1).
            ['key' => 'cpa_early_cancel_business_days', 'source' => 'leases', 'type' => 'number', 'default' => 20, 'min' => 1, 'max' => 365,
             'label' => 'Business days\' notice a tenant may cancel a fixed term on',
             'explain' => 'The law (the Consumer Protection Act) lets a tenant cancel a fixed-term lease early on this many business days\' written notice. 20 is the figure in the Act; change it only if your legal advice says otherwise.',
             'affects' => 'When a tenant gives notice to end a fixed-term lease early, the notice review checks the end date against this many business days (weekends and public holidays do not count) and flags it when it is shorter.'],
            // rental-notices.md §5 - same saver, has()-guarded there (§6.1).
            ['key' => 'breach_remedy_business_days', 'source' => 'leases', 'type' => 'number', 'default' => 20, 'min' => 1, 'max' => 365,
             'label' => 'Days a tenant has to put a breach right (when a notice template does not set its own)',
             'explain' => 'When you issue a breach notice, this is how many days the tenant has to put the breach right (counted as business or calendar days, next setting) unless the notice template names its own number. 20 business days is the period the Consumer Protection Act uses; change it only if your legal advice says otherwise.',
             'affects' => 'The deadline printed on the lease screen and in the breach notice, and when "Notice to vacate" becomes available for a breach that has not been remedied.'],
            // rental-notices.md §5 (AT-462) - same saver, has()-guarded there (§6.1).
            ['key' => 'breach_remedy_day_basis', 'source' => 'leases', 'type' => 'select', 'default' => 'business',
             'options' => ['business' => 'Business days', 'calendar' => 'Calendar days'],
             'label' => 'The breach period counts',
             'explain' => 'Whether the days a tenant has to put a breach right skip weekends and public holidays (business days) or count every day (calendar days). Pick calendar days if your lease says, for example, "within 7 days of written notice".',
             'affects' => 'The deadline printed in each breach notice and on the lease screen, and the date from which "Notice to vacate" can be issued.'],
            ['key' => 'vacate_time', 'source' => 'leases', 'type' => 'text', 'default' => '12:00',
             'label' => 'Time of day a notice to vacate asks the tenant to be out by (24-hour, e.g. 12:00)',
             'explain' => 'When you make a notice to vacate you pick the date the tenant must be out; this is the time of day on that date.',
             'affects' => 'The time a new notice to vacate starts with (the agent can change it for one notice). Blank means 12:00.'],
            // rental-notices.md §7b (AT-462) - same saver, has()-guarded there (§6.1).
            ['key' => 'vacate_notice_days', 'source' => 'leases', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 365,
             'label' => 'Days for notice to vacate',
             'explain' => 'When a tenant has not put a breach right and you send a notice to vacate, this is how many days after the day it is sent the tenant must be out of the property.',
             'affects' => 'The date a new notice to vacate starts with (today plus this many days). The agent can change the date on each notice. Blank means 30.'],
            ['key' => 'payment_proof_email', 'source' => 'leases', 'type' => 'text', 'default' => '',
             'label' => 'Rental payment proof email',
             'explain' => 'The email address a tenant is asked to send proof of payment to in a payment breach notice. Leave blank to use your agency email.',
             'affects' => 'The address printed in your payment breach notices wherever the template says the proof-of-payment address. Blank means your agency email.'],
            ['key' => 'expiry_notice_send_business_days', 'source' => 'leases', 'type' => 'number', 'default' => 60, 'min' => 40, 'max' => 80,
             'label' => 'Send the lease expiry notice ___ business days before the lease ends',
             'explain' => 'The Consumer Protection Act says you must tell a tenant their fixed term is ending between 80 and 40 business days before it ends (weekends and public holidays do not count). Choose the day, from 40 to 80, that CoreX sends the notice. It is sent in your own "Lease expiry notice" template (Settings, Rental Notice Templates).',
             'affects' => 'Each lease shows "Expiry notice due between ... and ..." and "CoreX sends it on ...". On the day you choose CoreX emails the tenants your expiry notice from the lease agent; with no template set up it alerts the agents instead.'],
            ['key' => 'expiry_notice_alert_business_days', 'source' => 'leases', 'type' => 'number', 'default' => 45, 'min' => 40, 'max' => 80,
             'label' => 'If it has not gone out, alert the lease agents when ___ business days remain',
             'explain' => 'If the expiry notice has still not gone out when this many business days remain before the lease ends, the lease\'s agents are alerted. It must be between 40 and the day above. Fewer than 40 business days left and the lease is flagged overdue.',
             'affects' => 'The alert to the lease agents, and the point from which the lease shows as needing the notice urgently (the Rentals Command Centre and the lease screen).'],
            ['key' => 'default_early_cancellation_allowed', 'source' => 'leases', 'type' => 'select', 'default' => 'yes',
             'options' => ['yes' => 'Allowed', 'no' => 'Not allowed'],
             'label' => 'Early cancellation of a fixed term is',
             'explain' => 'Whether your leases let a tenant cancel a fixed term before it ends (with notice). Each lease can differ.',
             'affects' => 'What a new lease starts with, and what the tenant and owner portal FAQ says about cancelling early. Change it if your leases do not allow it.'],
            ['key' => 'default_early_cancellation_notice', 'source' => 'leases', 'type' => 'number', 'default' => null, 'min' => 1, 'max' => 999,
             'label' => 'Notice needed to cancel early (length)',
             'explain' => 'The notice a tenant must give to cancel a fixed term early. Leave blank to use the ordinary notice period.',
             'affects' => 'The early-cancellation notice written onto each new lease and shown in the portal.'],
            ['key' => 'default_early_cancellation_notice_unit', 'source' => 'leases', 'type' => 'select', 'default' => 'days',
             'options' => ['days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months'],
             'label' => 'Notice needed to cancel early (unit)',
             'explain' => 'Whether the early-cancellation notice counts days, weeks or months.',
             'affects' => 'How the early-cancellation notice reads on a lease and in the portal.'],
            ['key' => 'default_early_cancellation_penalty', 'source' => 'leases', 'type' => 'textarea', 'default' => '',
             'label' => 'Early-cancellation penalty (wording)',
             'explain' => 'The penalty wording for cancelling early, exactly as your lease states it (for example a number of months\' rent). Leave blank for none.',
             'affects' => 'Copied onto each new lease and shown to the tenant and owner in the portal FAQ. Each lease can carry its own wording.'],
            // Johan, 7 Oct 2026 (leases.md §5.3) — same saver (LeaseSettingsController::update), has()-guarded there (§6.1).
            ['key' => 'month_to_month_after_end_days', 'source' => 'leases', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 365,
             'label' => 'Days after a lease\'s end date before it goes month-to-month',
             'explain' => 'When a lease reaches its end date and there is no notice to vacate and no renewal on record, CoreX switches it to month-to-month by itself, logs it on the lease and tells the agent.',
             'affects' => 'How long after the end date a lease is left alone before it switches. 1 means the day after the end date; a higher number gives agents more time to record a renewal or a notice first. A notice or a renewal, even one still out for signing, always stops the switch.'],
            // Rentals front-half decisions (8 Oct 2026) - same saver (LeaseSettingsController::update), has()-guarded (§6.1).
            ['key' => 'require_end_or_month_to_month_for_signing', 'source' => 'leases', 'type' => 'toggle', 'default' => 1,
             'label' => 'Ask for an end date (or month-to-month) before "prepare for signing"',
             'explain' => 'A lease sent for signing should say how long it runs. When this is on, "Create lease & prepare for signing" needs either an end date or the month-to-month box ticked.',
             'affects' => 'Whether an agent can send a lease for signing with no end date at all. Off lets it through (the agreement then prints no term); "Create lease only" is never blocked either way.'],
            ['key' => 'restore_end_date_on_leaving_month_to_month', 'source' => 'leases', 'type' => 'toggle', 'default' => 1,
             'label' => 'Put the original end date back when month-to-month is reversed',
             'explain' => 'Switching a lease to month-to-month clears its end date. Reversing the switch can bring the original end date back, where it is on record.',
             'affects' => 'What a lease shows after "Reverse month-to-month": its original end date (on) or no end date until the agent types one (off).'],
            // Property status follows the lease (rental-renewals.md) - same saver, has()-guarded (§6.1). The fourth setting,
            // default_pre_let_status, is a pick from the agency's OWN statuses (not a static list), so it lives on the
            // Settings page only - recorded in agency-onboarding-setup.md section 5.1.
            ['key' => 'auto_readvertise_on_notice', 'source' => 'leases', 'type' => 'toggle', 'default' => 1,
             'label' => 'Put the property back on the market when notice is recorded',
             'explain' => 'When a tenant (or the landlord) gives notice, the agent is asked whether to put the property back on the market for the date after move-out. This sets whether that box arrives ticked.',
             'affects' => 'Whether a recorded notice re-advertises the property by default (on) or leaves it as let out until the agent ticks the box (off). The agent can always change it for a single notice.'],
            ['key' => 'auto_restore_status_on_lease_ended', 'source' => 'leases', 'type' => 'toggle', 'default' => 1,
             'label' => 'Put the property back on the market when the move-out inspection confirms it is empty',
             'explain' => 'When a move-out inspection confirms the property is vacant, CoreX can return it to the status it had before it was let, and the agent who owns the listing is told it is free again.',
             'affects' => 'On: a vacant property goes back on the market in CoreX by itself and the agent gets an in-app alert; nothing is pushed to Property24 or Private Property - the agent checks the rent and re-lists it on the portals. Off: it stays "let out" until an agent changes it.'],
            ['key' => 'end_tenancy_on_move_out_date', 'source' => 'leases', 'type' => 'toggle', 'default' => 1,
             'label' => 'End the tenancy on the move-out date from an accepted notice',
             'explain' => 'When a notice has been accepted, the lease carries a move-out date. This decides whether the lease ends by itself on that date, or only after a move-out inspection has been completed.',
             'affects' => 'On: on the move-out date the lease is marked ended (the property follows the setting above) even if nobody has done the move-out inspection yet. Off: the lease stays active until a completed move-out inspection confirms the property is empty.'],
            ['key' => 'auto_restore_status_on_lease_cancelled', 'source' => 'leases', 'type' => 'toggle', 'default' => 1,
             'label' => 'Put the property back on the market when a lease is cancelled',
             'explain' => 'When an active lease is cancelled, CoreX can return the property to the status it had before it was let.',
             'affects' => 'On: a cancelled lease puts the property back on the market from the cancellation date. Off: the property stays "let out" until an agent changes it.'],
            ['key' => 'signed_copy_not_live_note', 'source' => 'leases', 'type' => 'textarea', 'default' => 'Your signed lease is attached. It is not active on our system yet - your agent will confirm when it is.',
             'label' => 'Sentence in the signed-copy email when the lease cannot go live yet',
             'explain' => 'Everyone still gets their signed copy. When the lease cannot become active straight away (for example another lease is still running on the property), this one sentence tells the tenant so.',
             'affects' => 'The extra line in the tenant\'s signed-copy email in that situation only. Leave empty to add nothing.'],
            ['key' => 'fault_report_window_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 90,
             'label' => 'Days a tenant has to report a fault after moving in',
             'explain' => 'After the move-in inspection, a tenant can report anything missed without it counting against them, for this many days.',
             'affects' => 'How long the "report a fault" window stays open on a new tenancy. 7 days suits most agencies — a report after this window still reaches the agent, it is just their call whether to accept it.'],
            ['key' => 'out_inspection_signing_window_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 60,
             'label' => 'Days a tenant has to sign an inspection',
             'explain' => 'Once any inspection (In, Routine, Interim or Out) is ready to sign, the tenant has this many days to sign. The inspection shows how many days are left, and the agent is reminded when the window is about to close with someone still outstanding.',
             'affects' => 'The "days left" the agent sees on a report waiting for signatures, and when the reminder goes out. Nothing happens automatically when it passes. 7 days suits most agencies.'],
            // 2026-09-23 — same saver as the two window fields above
            // (RentalInspectionSettingsController::update() — registered
            // once, above); nullable + has()-guarded there, so this control
            // is safe alongside a step render that omits it.
            ['key' => 'public_link_expiry_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 90, 'min' => 1, 'max' => 3650,
             'label' => 'Days the public inspection-report link stays live',
             'explain' => 'A completed inspection\'s PDF carries a link a tenant or landlord can open with no CoreX login. This many days after it is issued, the link stops working.',
             'affects' => 'How long a shared inspection-report link keeps working. 90 days suits most agencies — an agent can always issue a fresh link later from the inspection\'s own screen.'],
            // Audit 2026-10-19 B21 - same saver (RentalInspectionSettingsController::update()); filled()-guarded there.
            ['key' => 'photo_link_minutes', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 480, 'min' => 5, 'max' => 10080,
             'label' => 'Minutes an inspection or inventory photo link stays live',
             'explain' => 'Inspection and inventory photos are kept private. Every picture on the recording screen, the inventory and the public report is shown through a link that stops working after this many minutes, so a copied picture address cannot be opened later.',
             'affects' => 'How long a photo stays visible on a page that has been open for a while - after this the page needs a reload to show the pictures again. 480 minutes (8 hours) covers a working day.'],
            // Johan's ruling 20 Oct 2026 - same saver (RentalInspectionSettingsController::update()), has()-guarded there.
            ['key' => 'record_deduction_amounts', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 0,
             'label' => 'Record deduction amounts',
             'explain' => 'When something is charged to the tenant at the move-out inspection, CoreX can also record the rand amount. Most agencies settle the deposit in their accounting system and leave this off, so CoreX only lists what was charged and why.',
             'affects' => 'Off (the default): charging an item to the tenant needs no amount, and the lease\'s "Move-out charges" card shows the charged items only - no totals and no deposit figure. On: each charge carries an amount and the lease shows the total recorded and the deposit held.'],
            // §46 — signing an inspection by a personal link. Same saver as the controls above
            // (RentalInspectionSettingsController::update(), registered once) — both has()-guarded there.
            ['key' => 'signing_link_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Let tenants and landlords sign an inspection from a personal link',
             'explain' => 'The agent can send each tenant and the landlord a personal link by email or WhatsApp, or show a QR code on their own phone. The person opens the full inspection report on their own phone, reads it, and signs from there — CoreX records the signature, the time and the device it was signed on.',
             'affects' => 'Whether the agent sees the "Sign by link" controls on an inspection. Off means signing stays on the agent\'s own screen, on paper, or on the agent\'s device. On by default.'],
            ['key' => 'signing_link_expiry_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 365,
             'label' => 'Days a personal signing link stays live',
             'explain' => 'Each person\'s signing link stops working this many days after the agent issues it, and also as soon as the agent revokes it.',
             'affects' => 'How long a tenant or landlord has to open their link and sign. 30 days suits most agencies — the agent can issue a fresh link from the inspection at any time.'],
            // §49 — Johan, 8 Oct 2026: are the three signatures (every tenant, the landlord, the agent) needed before an
            // inspection of each type can be completed? Same saver as the controls above
            // (RentalInspectionSettingsController::update(), registered once) — all four has()-guarded there.
            // §52 — the 8 Oct 2026 walk's rules, generated from ONE list (RentalInspectionSetting::INSPECTION_RULES) so the
            // settings page, the saver and this step cannot drift. Same saver as above, every field has()-guarded there.
            ...array_values(array_map(
                fn (string $column, array $rule) => ['key' => $column, 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => $rule[0] ? 1 : 0,
                    'label' => $rule[1], 'explain' => $rule[2], 'affects' => $rule[3]],
                array_keys(\App\Models\RentalInspectionSetting::INSPECTION_RULES),
                \App\Models\RentalInspectionSetting::INSPECTION_RULES,
            )),
            ['key' => 'signing_reminder_lead_days', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 2, 'min' => 0, 'max' => 30,
             'label' => 'Days before the signing window closes that the first reminder goes out',
             'explain' => 'If someone still has not signed, the agent is reminded this many days before the signing window closes (and once more after it has closed).',
             'affects' => 'How early the agent is nudged. 0 means only once the window has closed. 2 days suits most agencies.'],
            ['key' => 'signatures_required_in', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Require every signature before a move-in (In) inspection can be completed',
             'explain' => 'The tenant(s), the landlord and the agent each sign the In-inspection report. With this on, CoreX will not let the agent complete the inspection until all of them have signed (or a refusal is on record).',
             'affects' => 'Whether an In-inspection can be completed without the three signatures. On by default — the move-in condition is the document a deposit dispute turns on.'],
            ['key' => 'signatures_required_out', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Require every signature before a move-out (Out) inspection can be completed',
             'explain' => 'The tenant(s), the landlord and the agent each sign the Out-inspection report. With this on, CoreX will not let the agent complete the inspection until all of them have signed (or a refusal is on record).',
             'affects' => 'Whether an Out-inspection can be completed without the three signatures. On by default.'],
            ['key' => 'signatures_required_interim', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Require every signature before an Interim (planned, mid-tenancy) inspection can be completed',
             'explain' => 'An Interim inspection is a planned check during the tenancy, booked from a date your agency loaded. With this on, it cannot be completed until the tenant(s), the landlord and the agent have signed (or a refusal is on record).',
             'affects' => 'Whether an Interim inspection can be completed without the three signatures. On by default.'],
            ['key' => 'signatures_required_routine', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 0,
             'label' => 'Require every signature before a Routine (unplanned, mid-tenancy) inspection can be completed',
             'explain' => 'A Routine inspection is an unplanned check during the tenancy. With this off, the agent can complete it without signatures; with it on, it needs the tenant(s), the landlord and the agent to sign like the other types.',
             'affects' => 'Whether a Routine inspection can be completed without signatures. Off by default — signatures can still be collected on a Routine inspection whenever the agent wants them.'],
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
            ['key' => 'remind_tenant_by_email', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 0,
             'label' => 'Also email the tenant(s) the day-before reminder',
             'explain' => 'The reminder (sent the number of days before the inspection set below) always goes to the inspector. This also sends it to the tenant(s) on the lease. The booking, change and cancellation notices above are separate and are not affected.',
             'affects' => 'Whether a tenant gets a reminder email shortly before the inspector arrives. Off until you turn it on - tenants are never reminded by surprise.'],
            ['key' => 'remind_landlord_by_email', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 0,
             'label' => 'Also email the landlord the day-before reminder',
             'explain' => 'Same as the tenant reminder, for the property\'s landlord. The booking, change and cancellation notices above are separate and are not affected.',
             'affects' => 'Whether a landlord gets a reminder email shortly before the inspection. Off until you turn it on - landlords are never reminded by surprise.'],
            ['key' => 'reminder_days_before', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 1, 'min' => 0, 'max' => 30,
             'label' => 'Send a reminder this many days before a scheduled inspection',
             'explain' => 'A reminder notification this many days before the booked date. It always goes to the inspector; tenants and landlords get it only if you tick their reminder boxes above.',
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
             'label' => 'Suggested repair limit (R)',
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
            ['key' => 'auto_portal_access_on_signing', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Give tenant and landlord portal access automatically when a lease is signed',
             'explain' => 'When a lease is signed, CoreX sets up the portal login for its tenant(s) and landlord(s) using the email on their contact, and the signed-lease email they receive carries their personal portal link. The first time they open it they confirm their email with a code and choose a password.',
             'affects' => 'Whether your agents have to set up portal access by hand for every lease, or it is simply there when the lease is signed. Has no effect if tenant or landlord portal access above is switched off. On by default.'],
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
            ['key' => 'crew_link_show_tenant_contact', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Show the tenant\'s name and phone to the crew and contractors',
             'explain' => 'Whether the crew and the contractor see the tenant\'s name and phone number on the job (their link and the job email), so they can call ahead before arriving.',
             'affects' => 'Whether tenant contact details reach the worker\'s phone. On by default — switch it off and they see the access contact and notes only, and the agency stays the go-between.'],
            ['key' => 'notify_landlord_on_crew_completion', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 0,
             'label' => 'Email the owner as soon as the work is reported finished (crew or contractor)',
             'explain' => 'When the crew signs that the work is done (on their link, or from the signed paper copy you upload), or a contractor presses "Work finished" on their link, CoreX can email the owner straight away. The same rule applies to both.',
             'affects' => 'Whether the owner hears the work is done the moment it is reported, or only after your agent has checked and closed the job (the owner then gets the final statement). Off by default - the owner hears when you have checked it. The job is never closed by the crew or contractor either way.'],
            ['key' => 'notify_landlord_on_decision_needed', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the landlord when a decision is needed',
             'explain' => 'When a repair needs the owner\'s approval, or a quote comes in over the spend limit, CoreX emails the landlord that a decision is waiting in their portal.',
             'affects' => 'Whether the landlord gets an email prompt, or only finds out by checking the portal themselves. On by default.'],
            ['key' => 'notify_tenant_on_status_change', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the tenant when a fault\'s status changes',
             'explain' => 'When a fault report the tenant raised is approved, declined, or resolved, CoreX emails them the update.',
             'affects' => 'Whether the tenant gets an email on each status change, or only finds out by checking the portal themselves. On by default.'],
            ['key' => 'push_informational_default', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Send tenants and owners the general (non-urgent) updates on their phone',
             'explain' => 'When a tenant or owner uses the mobile app, CoreX can send general progress updates to their phone - for example that a fault was sent to the owner or approved. Anything that needs a decision, is urgent, is an appointment, is finished work or is a notice outcome is always sent and cannot be switched off.',
             'affects' => 'Whether tenants and owners start with the general updates on or off. Each person can still change this for themselves in the app. On by default.'],
            // rental-work-orders.md §14.27.3 — Build 2 (crew page + client visibility). Appended after the last
            // rental_portal control so it never collides with the per-job crew-link controls (§14.30).
            ['key' => 'crew_photos_visible_to_clients', 'source' => 'rental_portal', 'type' => 'select', 'default' => 'in_progress_and_completed',
             'options' => ['in_progress_and_completed' => 'Work-in-progress and completed photos', 'completed_only' => 'Completed photos only'],
             'label' => 'Crew photos tenants and landlords can see',
             'explain' => 'When your maintenance crew photographs a job, those photos can show on the job in the tenant\'s and the landlord\'s portal so they can see the work is being done.',
             'affects' => 'Whether a tenant or landlord sees the photos taken while the crew is working as well as the finished-job photos, or only the finished-job photos. The photos taken when a problem was first reported are never shown to either of them.'],
            ['key' => 'crew_standing_link_expiry_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 180, 'min' => 0, 'max' => 365,
             'label' => 'Crew home link lasts (days — 0 = until revoked)',
             'explain' => 'Each maintenance crew gets one home link to a page listing all the jobs booked for them — today\'s, what is coming up, what is waiting on them and what they finished this month — plus the parts to load. A new link stops working after this many days; enter 0 and it keeps working until you revoke or regenerate it.',
             'affects' => 'How long a crew\'s home link stays valid after you create it. 180 days suits most agencies — a crew keeps the link on their phone for months, and an agent can resend, revoke or regenerate it at any time from the crew\'s screen. Links already out keep the date they were made with.'],
            ['key' => 'crew_page_upcoming_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 14, 'min' => 1, 'max' => 60,
             'label' => 'Crew home page "Scheduled" reaches (days ahead)',
             'explain' => 'The crew home page shows today\'s jobs, then the jobs booked over the next few days, and adds up the parts they need to load for all of them.',
             'affects' => 'How far ahead the crew can see booked jobs, and which jobs count towards the "what to load" list. 14 days suits most agencies — a longer window means loading for more jobs at once.'],
            // rental-portal-access.md §22 — fault photos from the tenant / owner portal.
            ['key' => 'fault_photo_max_count', 'source' => 'rental_portal', 'type' => 'number', 'default' => 6, 'min' => 1, 'max' => 10,
             'label' => 'Photos per fault report',
             'explain' => 'When a tenant or owner reports a fault from their portal link they can add several photos, from the camera or the phone gallery. This is the most they can add to one report.',
             'affects' => 'How many photos your agents see on a portal-reported fault. 6 suits most agencies — enough to show the problem from a few angles without a long upload on a phone.'],
            ['key' => 'fault_photo_max_mb', 'source' => 'rental_portal', 'type' => 'number', 'default' => 8, 'min' => 1, 'max' => 15,
             'label' => 'Largest fault photo (MB)',
             'explain' => 'The portal shrinks each phone photo before it is sent (a typical photo arrives well under 1 MB). A photo that is still larger than this when it arrives is refused with a clear message.',
             'affects' => 'Only matters for unusually large images. 8 MB suits most agencies.'],
            // rentals-faults-work-orders.md §19 (AT-452 round 2) - the crew's job link goes out by itself.
            ['key' => 'crew_link_auto_send_on_fault_job', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Send the crew their job link automatically when a fault becomes a job',
             'explain' => 'When the owner has approved a repair and your agent chooses "our own maintenance crew", CoreX can email the crew their private job link straight away (and prepare a WhatsApp for you to open and send), instead of waiting for someone to remember. If the crew has no email or mobile on file, or no crew is chosen yet, the job card says so plainly.',
             'affects' => 'Whether the crew hears about a new job the moment the job card is created. On by default; the "Email to crew" button on the job card still works as Resend either way.'],
            // rentals-faults-work-orders.md §19.7 (AT-452 round 2) - "reported before" on a repeat fault.
            ['key' => 'fault_recurrence_window_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 90, 'min' => 1, 'max' => 730,
             'label' => '"Reported before" window (days)',
             'explain' => 'When a tenant fixes a fault themselves with the suggested step and the same kind of fault is reported again on that property, your agent and the owner are shown a line saying it was reported before and the tenant resolved it then. This is how many days that link lasts.',
             'affects' => 'Whether a repeat fault is flagged as a repeat. 90 days suits most agencies - shorter hides older repeats, longer flags more of them.'],
            // Rental portal security timings (audit 2026-10-19).
            ['key' => 'portal_remember_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 30,
             'label' => "Remember a tenant or owner's sign-in on a browser (days)",
             'explain' => 'After someone signs in to the portal on a browser, that browser opens their portal again without asking for the password, for this many days. The count starts at the real sign-in; using the portal does not extend it.',
             'affects' => 'How often tenants and owners have to sign in again on the same browser. 7 days suits most agencies - shorter is safer on shared computers, longer is more convenient.'],
            ['key' => 'portal_app_token_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 90,
             'label' => 'Phone app stays signed in (days)',
             'explain' => 'The phone app signs a tenant or owner out once it has gone unused for this many days. Every use renews it, and changing the password signs every phone out straight away.',
             'affects' => 'How long a lost or forgotten phone stays signed in. 30 days suits most agencies.'],
            ['key' => 'portal_api_per_minute', 'source' => 'rental_portal', 'type' => 'number', 'default' => 120, 'min' => 30, 'max' => 1000,
             'label' => 'Portal requests per minute (per person)',
             'explain' => 'The most requests one signed-in tenant or owner can make in a minute across the portal and the phone app. Normal use is far below this; it only stops a runaway page or a script from overloading the system.',
             'affects' => 'Whether a tenant or owner is asked to slow down. 120 suits most agencies - raise it only if people with very large photo uploads are being slowed.'],
            ['key' => 'portal_photo_link_minutes', 'source' => 'rental_portal', 'type' => 'number', 'default' => 120, 'min' => 5, 'max' => 1440,
             'label' => 'Portal picture links last (minutes)',
             'explain' => 'Pictures in the tenant and owner portal are shown through private links that stop working after this long, and immediately once the report they belong to is archived. The portal fetches fresh links every time a page loads.',
             'affects' => 'How long a copied picture link keeps working for someone who is not signed in. 120 minutes suits most agencies.'],
            ['key' => 'portal_link_days', 'source' => 'rental_portal', 'type' => 'number', 'default' => 60, 'min' => 1, 'max' => 365,
             'label' => 'Portal email links stay recognised (days)',
             'explain' => "The personal link in a portal email carries a sealed reference that pre-fills the sign-in and shows your agency's header. After this many days the link still opens the portal but no longer pre-fills anything - the person signs in as normal.",
             'affects' => 'How long an old email link keeps recognising its recipient. 60 days suits most agencies.'],
            // rentals-faults-work-orders.md section 29 (audit fixes, 19 Oct 2026) - chasing an owner who has not answered, link renewal, the largest quote.
            ['key' => 'owner_reminder_hours', 'source' => 'rental_portal', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 720,
             'label' => 'Remind the owner after (hours with no answer)',
             'explain' => 'When your agent sends a fault to the owner for a decision and the owner does not answer, CoreX emails them a reminder after this many hours.',
             'affects' => 'How soon a silent owner is chased. 24 hours suits most agencies; the reminder goes once, then a second one, then the fault appears on your Command Centre queue.'],
            ['key' => 'owner_second_reminder_hours', 'source' => 'rental_portal', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 720,
             'label' => 'Second reminder (hours after the first)',
             'explain' => 'If the owner still has not answered this many hours after the first reminder, they get one more reminder.',
             'affects' => 'The gap between the two reminders. 24 hours suits most agencies.'],
            ['key' => 'owner_queue_after_hours', 'source' => 'rental_portal', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 720,
             'label' => 'Put it on the agent queue (hours after the second reminder)',
             'explain' => 'After the second reminder and this many more hours of silence, the fault shows on the Command Centre as "owner has not answered", so an agent can phone them.',
             'affects' => 'When a silent owner becomes a task for a person rather than another email. 24 hours suits most agencies.'],
            ['key' => 'link_renew_on_use', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 1,
             'label' => 'Contractor and crew links renew each time they are used',
             'explain' => 'A contractor or crew link normally expires after a set number of days. With this on, every time they open and use the link its expiry moves forward again, so a long job (parts on order) does not leave them with a dead link.',
             'affects' => 'Whether a job that runs longer than the link\'s life keeps working. On by default; links the agent revokes, and links for a finished or cancelled job, still stop working.'],
            ['key' => 'quote_max_amount', 'source' => 'rental_portal', 'type' => 'number', 'default' => 1000000, 'min' => 1, 'max' => 99999999,
             'label' => 'Largest quote a contractor may enter (R)',
             'explain' => 'A contractor who quotes on their private link cannot enter R0 or an amount above this. It stops a typing slip becoming a quote the owner is asked to approve.',
             'affects' => 'Which quotes are refused with a plain message on the contractor\'s link. R1,000,000 suits most agencies.'],
            // rentals-faults-work-orders.md §18 (AT-452) — agent marks on fault photos.
            ['key' => 'tenant_sees_agent_marks', 'source' => 'rental_portal', 'type' => 'toggle', 'default' => 0,
             'label' => 'Show the tenant my agents\' marks and notes on their photos',
             'explain' => 'Your agents can draw a circle or an arrow on a fault photo and write a short note about it. The owner sees these on the photos your agent chooses to share. This decides whether the tenant sees them too, on the photos the tenant sent.',
             'affects' => 'Whether a tenant opening their own fault photo also sees what your agent marked on it. Off by default — the tenant sees the photo exactly as they sent it.'],
            ['key' => 'faq_tenant_notice_question', 'source' => 'rental_portal', 'type' => 'text', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_tenant_notice_question'],
             'label' => 'Tenant FAQ: question 1',
             'explain' => 'The first question on a tenant’s portal Home page, about giving notice.',
             'affects' => 'The heading of the first FAQ the tenant reads. It only appears when the tenant’s lease has a notice or cancellation term on record.'],
            ['key' => 'faq_tenant_notice_answer', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_tenant_notice_answer'],
             'label' => 'Tenant FAQ: answer 1',
             'explain' => 'Your wording for the answer, with the lease’s own values merged in: {notice_days}, {earliest_termination_date}, {lease_end_date}, {early_cancellation_terms}. Put a piece in [[double square brackets]] and it is left out whenever the lease does not have that value.',
             'affects' => 'What a tenant is told about giving notice. A lease with no notice or cancellation terms shows no FAQ at all — CoreX never guesses a figure.'],
            ['key' => 'faq_tenant_early_question', 'source' => 'rental_portal', 'type' => 'text', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_tenant_early_question'],
             'label' => 'Tenant FAQ: question 2',
             'explain' => 'The second question on a tenant’s portal Home page, about giving notice before the lease expires.',
             'affects' => 'The heading of the second FAQ on the tenant’s Home page.'],
            ['key' => 'faq_tenant_early_answer', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_tenant_early_answer'],
             'label' => 'Tenant FAQ: answer 2',
             'explain' => 'Your wording for what happens when notice is given before the lease expires. The same {values} and [[pieces]] as above.',
             'affects' => 'What a tenant is told about leaving early. Shown only when the lease has an earliest-termination date or early-cancellation terms on record.'],
            ['key' => 'faq_landlord_notice_question', 'source' => 'rental_portal', 'type' => 'text', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_landlord_notice_question'],
             'label' => 'Owner FAQ: question 1',
             'explain' => 'The first question on an owner’s portal Home page, about the tenant giving notice.',
             'affects' => 'The heading of the first FAQ on the owner’s Home page.'],
            ['key' => 'faq_landlord_notice_answer', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_landlord_notice_answer'],
             'label' => 'Owner FAQ: answer 1',
             'explain' => 'Your wording for the answer an owner reads. The same {values} and [[pieces]] as the tenant’s.',
             'affects' => 'What an owner is told about the tenant giving notice.'],
            ['key' => 'faq_landlord_early_question', 'source' => 'rental_portal', 'type' => 'text', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_landlord_early_question'],
             'label' => 'Owner FAQ: question 2',
             'explain' => 'The second question on an owner’s portal Home page.',
             'affects' => 'The heading of the second FAQ on the owner’s Home page.'],
            ['key' => 'faq_landlord_early_answer', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_landlord_early_answer'],
             'label' => 'Owner FAQ: answer 2',
             'explain' => 'Your wording for what happens when the tenant gives notice before the lease expires. The same {values} and [[pieces]].',
             'affects' => 'What an owner is told about the tenant leaving early.'],
            ['key' => 'faq_tenant_cancel_yes', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_tenant_cancel_yes'],
             'label' => 'Tenant FAQ: when early cancellation is allowed',
             'explain' => 'The sentence a tenant reads when their lease says early cancellation is allowed. Merge {early_cancellation_notice} and {early_cancellation_penalty}; a [[piece]] is left out when the lease does not have that value.',
             'affects' => 'What a tenant is told about cancelling early.'],
            ['key' => 'faq_tenant_cancel_no', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_tenant_cancel_no'],
             'label' => 'Tenant FAQ: when it is not allowed',
             'explain' => 'The sentence a tenant reads when their lease says early cancellation is not allowed.',
             'affects' => 'What a tenant is told when the lease forbids cancelling early.'],
            ['key' => 'faq_landlord_cancel_yes', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_landlord_cancel_yes'],
             'label' => 'Owner FAQ: when early cancellation is allowed',
             'explain' => 'The sentence an owner reads when the lease allows the tenant to cancel early.',
             'affects' => 'What an owner is told about the tenant cancelling early.'],
            ['key' => 'faq_landlord_cancel_no', 'source' => 'rental_portal', 'type' => 'textarea', 'default' => \App\Models\RentalPortalSetting::FAQ_DEFAULTS['faq_landlord_cancel_no'],
             'label' => 'Owner FAQ: when it is not allowed',
             'explain' => 'The sentence an owner reads when the lease does not allow the tenant to cancel early.',
             'affects' => 'What an owner is told when the lease forbids cancelling early.'],
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
            // leases.md "Lease fee" (26 Oct 2026, Johan). Saved by LeaseSettingsController::update() - nullable + has()-guarded there (§6.1).
            ['key' => 'default_lease_fee', 'source' => 'leases', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 99999999.99, 'step' => 0.01,
             'label' => 'Default lease fee (R)',
             'explain' => 'The lease (letting) fee a new lease starts with. The agent can change it on any lease, and it prints on your lease agreement when your agreement has a lease fee line linked to it.',
             'affects' => 'The lease fee a new lease or renewal starts with, and what prints on the lease fee line of your lease agreement. 0 (the default) means no fee is charged.'],
            // leases.md "Lease fee" (26 Oct 2026, Johan) - the agency's own name for that fee. Same saver, same has()-guard.
            ['key' => 'lease_fee_label', 'source' => 'leases', 'type' => 'text', 'default' => 'Lease fee',
             'label' => 'What you call the lease fee',
             'explain' => 'The name your agency uses for the fee charged when a lease is set up - for example "Admin fee", "Lease fee" or "Letting fee". Leave it as "Lease fee" if you have no other name for it.',
             'affects' => 'The name shown for that fee on the lease screen, the new-lease and renewal screens, the agreement check and the lease agreement set-up. It does not change the wording printed inside your own lease document - that stays as your document has it.'],
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
            ['key' => 'catalogue_default_markup_type', 'source' => 'rental_work_orders', 'type' => 'select', 'default' => 'percent',
             'options' => ['percent' => 'A percentage of the cost', 'rand' => 'A fixed amount (R)'],
             'label' => 'Default markup on a new catalogue item — how it is worked out',
             'explain' => 'When you add a part or a piece of labour to your catalogue, CoreX works out its selling price as the cost plus a markup. This chooses whether that starting markup is a percentage of the cost or a fixed rand amount. You can still change the markup on each item.',
             'affects' => 'The unit the markup box starts in on the Parts & Labour catalogue form. It does nothing while the amount below is 0.'],
            ['key' => 'catalogue_default_markup_value', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 0, 'min' => 0, 'step' => 0.01,
             'label' => 'Default markup on a new catalogue item (0 = none)',
             'explain' => 'The markup a new catalogue item starts with. Type the cost on the form and the selling price fills in from it.',
             'affects' => 'The selling price a new catalogue item gets when you type its cost. 0 (the default) means the selling price starts equal to the cost; for example 20 % turns a cost of R100 into R120.'],
            // BUILD 1 END
            // BUILD 2 BEGIN — approvals controls (.ai/specs/rental-work-orders.md §17.14)
            // AT-494 (Johan's ruling) - one switch for who may approve quotes. Saved by RentalWorkOrderSettingsController::updateApprovals (has()-guarded, audited).
            ['key' => 'agents_approve_quotes', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 1,
             'label' => 'Agents can approve quotes and send them to the owner',
             'explain' => 'When a repair needs a quote, someone has to choose the quote, send it to the owner and authorise the repair. This decides whether your ordinary agents can do that themselves, or whether it is left to branch managers and admins.',
             'affects' => 'What an agent sees on a work order. On: the Select, Send to owner and Authorise buttons work for agents. Off: those buttons are replaced by "Ask your branch manager to approve". Branch managers and admins can always approve, and the owner\'s own repair limit still applies. On by default.'],
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
             'affects' => 'Whether tenants are asked at all. On: a tenant who says the work is not complete raises a "Tenant says not fixed" item for your office; it never stops you closing the job and never reopens it by itself. Off: nobody is asked. On by default.'],
            ['key' => 'completion_response_window_days', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 30, 'step' => 1,
             'label' => 'Days the tenant\'s email link stays open',
             'explain' => 'How long the link in the tenant\'s email works (plus a week of grace). A tenant\'s answer is optional and never holds a job up; they can always answer from the tenant portal.',
             'affects' => 'Only how long the emailed link keeps working; nothing is ever accepted or closed because a tenant stayed silent. 5 days suits most agencies.'],
            ['key' => 'internal_team_label', 'source' => 'rental_work_orders', 'type' => 'text', 'default' => 'Our maintenance team', 'maxlength' => 60,
             'label' => 'What tenants and owners read for your own maintenance team',
             'explain' => 'When your own crew does a repair, tenants and owners are told the job is with this team - using the wording you choose here. A crew member\'s own name is never shown to them.',
             'affects' => 'The wording on the tenant\'s and owner\'s portal and in the "please check the work" email for jobs done by your own crew, for example "Our maintenance team" or "Ramsgate Maintenance". Blank keeps the neutral default.'],
            ['key' => 'notify_landlord_on_dispute', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 1,
             'label' => 'Email the owner when a tenant says work is not complete',
             'explain' => 'When a tenant tells you finished work is still wrong, CoreX can email the property owner the tenant\'s words and photos, and tell them your office is arranging a fix.',
             'affects' => 'Whether the owner hears about a dispute straight away, or only when your agent tells them. On by default — owners usually prefer to hear it from you first.'],
            ['key' => 'dispute_notify_crew_immediately', 'source' => 'rental_work_orders', 'type' => 'toggle', 'default' => 0,
             'label' => 'Send a disputed job straight back to the crew',
             'explain' => 'When a tenant says work is not complete, the office normally looks at the complaint first and presses "Send back to crew". Switch this on and CoreX emails your crew a fresh job link, with the tenant\'s note and photos, the moment the tenant disputes the work.',
             'affects' => 'Whether a disputed job reaches the crew automatically or waits for an office decision. Off by default so no one is sent back on a complaint the office has not read. A contractor is always sent back by the office, never automatically.'],
            // BUILD 3 END
            // §17.31 — supplier invoice upload limits (own narrow saver, has()-guarded, registered in the step's savers above).
            ['key' => 'invoice_max_file_mb', 'source' => 'rental_work_orders', 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 50, 'step' => 1,
             'label' => 'Largest supplier invoice file (MB)',
             'explain' => 'When your office files a supplier\'s invoice against a work order, the file can be at most this many megabytes.',
             'affects' => 'Whether a large scan or photo is accepted when an invoice is uploaded; a bigger file is refused with a plain message. 10 MB suits most invoices. The most you can set is 50 MB.'],
            ['key' => 'invoice_allowed_file_types', 'source' => 'rental_work_orders', 'type' => 'select', 'default' => 'pdf_images',
             'options' => ['pdf' => 'PDF only', 'pdf_images' => 'PDF and photos (JPG, PNG, WebP)', 'pdf_images_heic' => 'PDF and photos, including iPhone HEIC'],
             'label' => 'File types allowed for a supplier invoice',
             'explain' => 'Which kinds of file your office can upload as a supplier invoice. Most suppliers send a PDF; some send a phone photo of a paper invoice.',
             'affects' => 'What the invoice upload on a work order accepts. Choosing "PDF only" refuses photos; "including iPhone HEIC" also accepts photos taken straight from an iPhone.'],
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
            ['key' => 'form_paper_size', 'source' => 'rental_inspections', 'type' => 'select', 'default' => 'a4',
             'options' => \App\Models\RentalInspectionSetting::formPaperSizeOptions(),
             'label' => 'Paper for the printed inspection site form',
             'explain' => 'An agent can print a paper form of an inspection\'s rooms and items, tick it at the property and scan it back in. This is the paper your office prints it on.',
             'affects' => 'The page size of the "Print site form" PDF on an inspection. A4 is the default. A form printed on one size is not accepted when scanned back in after you change to the other, so print a fresh one.'],
            ['key' => 'form_safe_margin_mm', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 15, 'min' => 5, 'max' => 25, 'step' => 1,
             'label' => 'Printed inspection site form: safe margin (mm)',
             'explain' => 'Copiers and scanners clip the outer few millimetres of a sheet. This is how far in from every edge of the paper the printed site form keeps everything - the corner marks CoreX scans against, the QR code, the rows and the tick boxes.',
             'affects' => 'The layout of the "Print site form" PDF: a bigger margin makes the printed form a little smaller and lets a scan that lost its edges still be read. 15 is a good start; raise it if your scanner clips more. A form already printed with another margin still scans back in.'],
            ['key' => 'omr_mark_threshold', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 0.04, 'min' => 0.01, 'max' => 0.5, 'step' => 0.01,
             'label' => 'Scanned inspection form: tick-box sensitivity',
             'explain' => 'If you print the paper inspection form and scan it back in, CoreX reads the tick boxes. This is the share of the inside of a box that must carry ink before it counts as ticked (a hand tick covers about 5 to 20 percent). Most agencies never need to change it.',
             'affects' => 'How readily a scanned paper form is read as ticked. Lower it if faint or light scans are missing ticks; raise it if stray marks are being read as ticks.'],
            ['key' => 'omr_unsure_below', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 0.5, 'min' => 0.1, 'max' => 0.95, 'step' => 0.05,
             'label' => 'Scanned inspection form: ticks to check below this confidence',
             'explain' => 'When a scanned paper inspection form is read, every tick comes with a confidence from 0 to 1. A tick read with less confidence than this is shown to the agent as "to check" instead of as read, and the agent must confirm it. Two ticked boxes on one row are always "to check".',
             'affects' => 'How many ticks an agent is asked to confirm after a scan. Raise it to be asked about more rows; lower it if too many clear ticks are being queried.'],
            // Audit M7 (Johan, 20 Oct 2026) - same saver (RentalInspectionSettingsController::update()), has()-guarded there.
            ['key' => 'note_read_enabled', 'source' => 'rental_inspections', 'type' => 'toggle', 'default' => 1,
             'label' => 'Scanned inspection form: read handwritten notes with AI',
             'explain' => 'When a scanned paper inspection form is uploaded, CoreX can send a small picture of each row\'s handwritten note area to an AI service to read the handwriting and pre-fill the note box. Only note areas that actually contain writing are sent, never the whole page, and the agent always confirms or edits the note before it is saved.',
             'affects' => 'On (the default): handwritten notes on a scanned form arrive pre-filled for the agent to check. Off: nothing from your scanned forms is sent to an AI service - the agent types the notes in. Ticks are always read inside CoreX either way.'],
            ['key' => 'note_read_min_confidence', 'source' => 'rental_inspections', 'type' => 'number', 'default' => 0.5, 'min' => 0.1, 'max' => 0.95, 'step' => 0.05,
             'label' => 'Scanned inspection form: handwritten notes to type in below this confidence',
             'explain' => 'CoreX reads the handwritten note on each row of a scanned paper inspection form and fills it into the note box. A note read with less confidence than this (0 to 1) is not filled in; the agent sees "Couldn\'t read the handwritten note" and types it in.',
             'affects' => 'Whether a doubtful reading of someone\'s handwriting is offered as the note or left for the agent to type. Raise it to trust fewer readings; lower it to accept more of them.'],
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
            // Rentals front-half decisions (8 Oct 2026) - one has()-guarded saver (RentalApplicationSettingsController::updateFrontHalfDefaults).
            ['key' => 'allow_manual_link_share', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'label' => 'Let agents hand an applicant their link themselves',
             'explain' => 'An applicant normally gets their application link by email. For a tenant with no email, the agent can instead press "Share the link myself" and give them the link on WhatsApp or in person.',
             'affects' => 'Whether the "Share the link myself" button appears on an application. Off means an application can only be sent by email.'],
            ['key' => 'notify_agents_on_application_returned', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'label' => 'Tell the property\'s and lease\'s agents when a tenant submits an application',
             'explain' => 'The agent who created the application is always emailed. This adds an in-app note for the property\'s agent and, where the property is let, the lease\'s owner-side and tenant-side agents.',
             'affects' => 'Who sees a note in the app when an application comes back. Each person can still switch their own notifications off.'],
            ['key' => 'notify_authoriser_on_hand_over', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'label' => 'Tell the authoriser when an application is handed to them',
             'explain' => 'When an agent hands an application to the authorisers, the people chosen as reviewers are told, in the app and by email, instead of having to find it in the queue.',
             'affects' => 'Whether reviewers get a notification at hand-over. Each person can still switch their own notifications off.'],
            ['key' => 'invite_policy_sentence', 'source' => 'rental_application', 'type' => 'textarea', 'default' => '', 'rows' => 2,
             'label' => 'Your sentence in the applicant\'s email and PDF',
             'explain' => 'One sentence of your own at the top of the application email and PDF, for example how your agency screens tenants. Type {agency} where your agency name should appear. Leave empty to say nothing.',
             'affects' => 'The wording an applicant reads before they start. Nothing is added if you leave it empty.'],
            ['key' => 'prefill_lease_from_application', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'label' => 'Suggest the lease start date and term from the application',
             'explain' => 'When an agent creates a lease from an approved application, the date and term the applicant asked for are filled in as a suggestion.',
             'affects' => 'What the lease screen starts with. The agent can change both before saving.'],
            ['key' => 'allow_withdraw_after_approval', 'source' => 'rental_application', 'type' => 'toggle', 'default' => 1,
             'label' => 'Allow an approved application with no lease yet to be withdrawn',
             'explain' => 'If the tenant walks away after being approved and before a lease exists, the agent can withdraw the application with a note.',
             'affects' => 'Whether the Withdraw action is offered on an approved application. It is logged either way; a decided application with a lease can never be withdrawn this way.'],
            ['key' => 'decline_email_subject', 'source' => 'rental_application', 'type' => 'text', 'default' => 'Your rental application - {{agency_name}}',
             'label' => 'Decline email - subject',
             'explain' => 'The subject line of the email an applicant gets when their application is declined. {{agency_name}} becomes your agency name. Leave it as it is to use the suggested wording.',
             'affects' => 'What an applicant sees in their inbox when declined. Nothing changes for applications already declined.'],
            ['key' => 'decline_email_body', 'source' => 'rental_application', 'type' => 'textarea', 'default' => '', 'rows' => 8,
             'label' => 'Decline email - wording',
             'explain' => 'The email an applicant gets when their application is declined, in your own words. Placeholders such as {{applicant_name}}, {{agency_name}} and {{decline_guidance}} are filled in for each applicant. It starts with a suggested, respectful default; edit it freely.',
             'affects' => 'The tone and content of every decline email your agency sends from now on. Clear it back to the suggested default on the Rental applications settings page.'],
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
            // Audit fix A4/A27 (2026-10-19): the approvers. Without anyone ticked here an application handed over for approval
            // reached nobody; CoreX now falls back to one-step approval (and warns) until at least one person is chosen.
            ['key' => 'rental_application_ro_user_ids', 'source' => 'rental_application', 'type' => 'user_multiselect', 'default' => [],
             'label' => 'Who reviews and approves applications (Reviewers)',
             'explain' => 'Tick the people who may approve or decline a rental application. A Reviewer cannot approve an application they created (unless your agency has just the one approver) and cannot change a decision once made. If you tick nobody, whoever reviews an application approves it directly and CoreX shows a warning on the screen.',
             'affects' => 'Who is told when an application is handed over for approval and who can approve or decline it. With nobody ticked there is no second check on a decision.'],
            ['key' => 'rental_application_co_user_ids', 'source' => 'rental_application', 'type' => 'user_multiselect', 'default' => [],
             'label' => 'Who can override a decision (Override approvers)',
             'explain' => 'Tick the people who can do everything a Reviewer can, plus decide an application they created themselves and change a decision that has already been made (a reason is then required). Usually the head of rentals or the principal.',
             'affects' => 'Who can reverse an approval or decline, and who can approve their own applications. Administrators can always override.'],
            // Johan, 2026-10-07 — QA1 rentals test: a tenant approved for R10,000 was leased at R11,400 unnoticed.
            ['key' => 'rent_above_approved_mode', 'source' => 'rental_application', 'type' => 'select', 'default' => 'warn',
             'options' => ['warn' => 'Warn and confirm — the agent confirms with a reason, which is logged', 'block' => 'Block — the lease cannot be above the approved amount'],
             'label' => 'Lease rent above the approved amount',
             'explain' => 'When a lease is created from an approved application, CoreX compares the lease rent to the amount the tenant was approved for. This decides what happens when the lease rent is higher.',
             'affects' => 'Whether the agent can still create the lease after confirming a reason (logged on the lease history and the application audit trail), or CoreX refuses to create a lease above the approved amount.'],
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

    // AT-432 — Auctions. Gated on the `auctions` feature (AgencyOnboardingSetup::
    // stepGates), so an agency that does not auction never sees it. Posts a
    // SUBSET of the settings page, so its saver (updateWizard) guards every write
    // with $request->has() — spec §6.1. The deeper auction settings (fees,
    // bidding rules, deposits) stay on Settings → Auctions: see the spec's
    // "Deliberately NOT in the wizard" list.
    'auctions' => [
        'title' => 'Auctions',
        'intro' => 'Tell CoreX how your agency uses Auctions — most agencies only need to advertise their auction properties.',
        'what' => [
            'title' => 'What Auctions is',
            'body'  => 'Auctions is where you advertise properties that are being sold at auction. Each auction gets its own '
                . 'public page showing the date, venue, who is running the sale, the guide price, viewing times and the '
                . 'Rules of Auction, plus an "I\'m interested" form that sends the enquiry straight to the listing agent. '
                . 'After the sale you record the result and the advert shows Sold or Passed in. If you also want CoreX to run '
                . 'the sale itself — register bidders, take bids, bang the hammer — you can switch that on below.',
        ],
        'savers' => [
            ['controller' => AuctionSettingsController::class, 'method' => 'updateWizard'],
        ],
        'controls' => [
            ['key' => 'auction_advertising_only', 'source' => 'auction', 'type' => 'toggle', 'default' => 1,
             'label' => 'Advertising only',
             'explain' => 'On means CoreX is used to advertise auction properties, and the auction itself is run somewhere else — by an outside auction house or on the day without CoreX. Off means CoreX also runs the sale: bidder registration, the Sale Room and recording bids.',
             'affects' => 'What this changes: when on, the Sale Room and Bidder Register disappear and "Register to bid" sends buyers to the auctioneer\'s own link; when off, they appear and buyers can register on CoreX.'],
            ['key' => 'auction_auctioneer_mode', 'source' => 'auction', 'type' => 'select', 'default' => 'both',
             'options' => ['internal' => 'Our own auctioneer', 'external' => 'An outside auction house', 'both' => 'Either — ask each time'],
             'label' => 'Who conducts your auctions',
             'explain' => 'Whether the auctioneer is someone at your agency or an outside auction house. An outside auction house\'s name and licence number are printed on the public advert.',
             'affects' => 'What the New Auction form asks for, and what buyers see under "Conducted by" on the public page. An agency auctioneer must hold a valid Fidelity Fund Certificate before the auction can be published.'],
            ['key' => 'auction_reserve_visibility', 'source' => 'auction', 'type' => 'select', 'default' => 'private',
             'options' => ['private' => 'Never show the reserve amount', 'disclosed_on_the_day' => 'Reveal on the day', 'published' => 'Show it on the advert'],
             'label' => 'Reserve price on the advert',
             'explain' => 'Every advert must say whether a lot is subject to a reserve — that is the law. This only decides whether the reserve AMOUNT is shown too.',
             'affects' => 'Whether buyers see the actual reserve figure on the public lot page, or only "Subject to a reserve price".'],
            ['key' => 'auction_guide_price_enabled', 'source' => 'auction', 'type' => 'toggle', 'default' => 1,
             'label' => 'Show a guide price',
             'explain' => 'A guide price is the range you expect the lot to sell for. It is shown to buyers as an indication, not a promise.',
             'affects' => 'Whether the public advert and the portal listings show a guide price on each lot.'],
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
            // AT-448 — mandate expiry warn-days + expiry lock. Both writes are
            // $request->has()-guarded, so a step posting a subset never wipes the other.
            ['controller' => SettingsController::class, 'method' => 'updateMandateExpiry'],
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
            // AT-450 — Title Deed Register. Same saver as Settings -> Title Deeds; every field is has()-guarded (§6.1).
            ['controller' => TitleDeedSettingsController::class, 'method' => 'save'],
        ],
        // AT-450 round 2 - the agency's own list of title deed storage locations (a list editor, so it sits outside the form).
        'aux_partial' => 'agency-setup.steps.title-deed-locations',
        'controls' => [
            ['key' => 'properties_per_page', 'source' => 'perf', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 200,
             'label' => 'Properties per page',
             'explain' => 'How many listings load at a time on the Properties page. A smaller number loads faster on a phone in the field; a larger number means less clicking at a desk.',
             'affects' => 'How many properties an agent scrolls through before paging to the next set.'],

            ['key' => 'rental_fee_max_amount', 'source' => 'perf', 'type' => 'number', 'default' => 50000, 'min' => 1, 'max' => 10000000,
             'label' => 'Maximum rental Admin Fee / Marketing Fee (R)',
             'explain' => 'A rental listing\'s Admin Fee and Marketing Fee (on the property\'s Rental tab) can\'t be saved above this amount — a safety net against a typo like an extra zero turning a fee into a much larger number.',
             'affects' => 'The highest Rand amount an agent can enter for a rental\'s Admin Fee or Marketing Fee before the save is rejected.'],

            // AT-448 — .ai/specs/at448-property-expiry.md §5.3
            ['key' => 'mandate_expiry_warn_days', 'source' => 'perf', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 90,
             'heading' => 'Mandate expiry',
             'label' => 'Warn agents before a mandate expires (days)',
             'explain' => 'Every listing carries a mandate expiry date. This many days before that date, the agent sees a pop-up on the Properties page listing the mandates about to run out — once per listing, so nobody is nagged. When the date passes, CoreX marks the listing Expired at midnight and takes it off any portal or website it was being advertised on automatically.',
             'affects' => 'How far ahead of the expiry date your agents are shown the "Mandates expiring soon" pop-up, and which listings the "Expiring soon" filter on the Properties page shows.'],

            ['key' => 'mandate_expiry_lock_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 0,
             'label' => 'Expiry lock',
             'explain' => 'With this on, once a listing has gone live its expiry date can no longer be changed by hand. To extend a mandate the agent first uploads the signed extension to the property\'s extension folder in Drive, which unlocks the date for one change; saving the new date locks it again. Drafts that never went live are not affected.',
             'affects' => 'Whether an agent can simply retype the expiry date on a live listing, or must have the signed extension on file in Drive before the date will accept a change.'],

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
            // ── Title Deed Register (AT-450) - .ai/specs/title-deed-register.md §9 and §15. Same wording as Settings -> Title Deeds. ──
            ['key' => 'pin_mode', 'source' => 'title_deeds', 'type' => 'select', 'default' => 'required',
             'options' => ['required' => 'Required', 'optional' => 'Optional', 'not_used' => 'Not used'],
             'heading' => 'To collect a title deed',
             'label' => 'Collection PIN from the owner',
             'explain' => "When a title deed arrives, the owner is told it is at the office. Required: they are sent a PIN and the deed is only handed over against it. Optional: the PIN is sent and may be used, or staff can check the owner's ID instead. Not used: no PIN is sent.",
             'affects' => "Whether the owner's notice carries a PIN, and whether a deed can be handed over without one."],
            ['key' => 'signature_mode', 'source' => 'title_deeds', 'type' => 'select', 'default' => 'required',
             'options' => ['required' => 'Required', 'optional' => 'Optional', 'not_used' => 'Not used'],
             'label' => 'Signature of the person collecting',
             'explain' => 'Required: no hand-over without a signature. Optional: staff may skip it, and the record then says "not captured". Not used: no signature box and no signing link.',
             'affects' => 'Whether the signature box and the signing link appear, and whether a hand-over can finish without one.'],
            ['key' => 'collector_id_photo_mode', 'source' => 'title_deeds', 'type' => 'select', 'default' => 'not_used',
             'options' => ['required' => 'Required', 'optional' => 'Optional', 'not_used' => 'Not used'],
             'label' => "Photo of the collector's ID",
             'explain' => "Required: staff must photograph the collector's ID. Optional: staff may skip it. Not used: staff are never asked.",
             'affects' => 'Whether the photo field appears at the hand-over, and whether it can be skipped.'],
            ['key' => 'representatives_allowed', 'source' => 'title_deeds', 'type' => 'select', 'default' => '1',
             'options' => ['1' => 'Yes', '0' => 'No'],
             'heading' => 'Someone collecting for the owner',
             'label' => 'May a representative collect?',
             'explain' => 'Yes: staff can hand the deed to someone the owner sent, who gives their own name, ID number and relationship to the owner. No: only the owners themselves can collect.',
             'affects' => 'Whether staff can hand a title deed to anyone other than the owners themselves.'],
            ['key' => 'rep_without_pin_mode', 'source' => 'title_deeds', 'type' => 'select', 'default' => 'owner_consent',
             'options' => ['refuse' => 'Refuse - they cannot collect', 'owner_consent' => 'Ask the owner to confirm by link', 'consent_or_letter' => 'Owner link or a signed authority letter'],
             'label' => 'A representative who arrives without the PIN',
             'explain' => 'What staff may do when the person collecting for the owner cannot give the PIN. (Ignored while the PIN is Required: then a representative must have it.)',
             'affects' => 'Whether such a person is turned away, or every owner is emailed a link to approve or decline them, or a signed authority letter is accepted instead.'],
            ['key' => 'phone_consent_allowed', 'source' => 'title_deeds', 'type' => 'select', 'default' => '0',
             'options' => ['1' => 'Yes', '0' => 'No'],
             'label' => "Accept the owner's consent by phone call",
             'explain' => 'Yes: staff can phone an owner on a number stored on their contact and record that they agreed to the named representative collecting. No: only the confirmation link counts.',
             'affects' => "Whether a recorded phone call counts as the owner's consent, as well as the approval link."],
            ['key' => 'authority_letter_mode', 'source' => 'title_deeds', 'type' => 'select', 'default' => 'optional',
             'options' => ['required' => 'Required', 'optional' => 'Optional', 'not_used' => 'Not used'],
             'label' => 'Authority letter',
             'explain' => "Required: every representative must hand in the owner's signed letter. Optional: staff can upload one. Not used: never asked for.",
             'affects' => "Whether a handover to a representative can be completed without the owner's signed letter."],
            ['key' => 'sign_on_screen_enabled', 'source' => 'title_deeds', 'type' => 'select', 'default' => '1',
             'options' => ['1' => 'Yes', '0' => 'No'],
             'heading' => 'Ways to sign',
             'label' => 'Sign on our screen',
             'explain' => "Yes: the collector signs in a box on the staff member's screen. No: no signature box on our screen. (At least one way to sign must stay Yes while the signature is Required.)",
             'affects' => 'Whether staff see a signature box at the hand-over.'],
            ['key' => 'sign_by_link_enabled', 'source' => 'title_deeds', 'type' => 'select', 'default' => '1',
             'options' => ['1' => 'Yes', '0' => 'No'],
             'label' => 'Sign on their own phone from a link or QR',
             'explain' => 'Yes: staff can send the collector a one-time link (or show a QR code) and they sign on their own phone. No: no signing links.',
             'affects' => 'Whether staff see "Create the signing link" on the hand-over screen.'],
            ['key' => 'notify_listing_agent', 'source' => 'title_deeds', 'type' => 'select', 'default' => '1',
             'options' => ['1' => 'Yes', '0' => 'No'],
             'heading' => 'Who is told',
             'label' => "Tell the deal's listing agent when a deed is collected",
             'explain' => 'Yes: the listing agent of the deal the deed came from gets a notification when it is collected. No: only the responsible agent and the other agents on the deed are told.',
             'affects' => 'Whether the listing agent gets a notification when a title deed linked to their deal is collected.'],
            ['key' => 'reference_prefix', 'source' => 'title_deeds', 'type' => 'text', 'default' => 'TD', 'placeholder' => 'TD',
             'heading' => 'Reference numbers',
             'label' => 'Reference prefix',
             'explain' => 'Printed on every title deed record, e.g. TD-00042. Changing it only affects new deeds.',
             'affects' => 'The first part of the reference number written on the envelope and quoted to owners. Numbers already issued never change.'],
            ['key' => 'pin_lock_attempts', 'source' => 'title_deeds', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 20,
             'heading' => 'Limits and reminders',
             'label' => 'Wrong PIN attempts before the PIN locks (attempts)',
             'explain' => 'After this many wrong PINs the deed locks until someone issues a new PIN.',
             'affects' => 'How many mistakes are allowed before the PIN stops working.'],
            ['key' => 'consent_validity_minutes', 'source' => 'title_deeds', 'type' => 'number', 'default' => 60, 'min' => 5, 'max' => 1440,
             'label' => "How long an owner's confirmation lasts (minutes)",
             'explain' => 'After this long the owner has to confirm again before the representative can collect.',
             'affects' => 'How long an approval lasts once an owner gives it.'],
            ['key' => 'signing_link_minutes', 'source' => 'title_deeds', 'type' => 'number', 'default' => 30, 'min' => 5, 'max' => 1440,
             'label' => 'How long a signing link lasts (minutes)',
             'explain' => 'A signing link works once, and stops working after this long.',
             'affects' => 'How long the collector has to open the link and sign.'],
            ['key' => 'reminder_days', 'source' => 'title_deeds', 'type' => 'number', 'default' => 14, 'min' => 1, 'max' => 365,
             'label' => 'Remind the owners every (days)',
             'explain' => 'An owner who has been told the deed is here gets a reminder email this many days after the last notice.',
             'affects' => 'How soon a deed that is still waiting gets a reminder.'],
            ['key' => 'max_reminders', 'source' => 'title_deeds', 'type' => 'number', 'default' => 3, 'min' => 0, 'max' => 20,
             'label' => 'Most reminders for one deed (0 = none)',
             'explain' => 'After this many reminders no more are sent for that deed.',
             'affects' => 'When reminders stop for a deed.'],
            ['key' => 'contact_changed_block_days', 'source' => 'title_deeds', 'type' => 'number', 'default' => 7, 'min' => 0, 'max' => 365,
             'label' => "Extra check after an owner's email or phone changed (days, 0 = no extra check)",
             'explain' => "If an owner's email or phone changed within this many days, only someone with the override permission can hand the deed over, and must give a reason.",
             'affects' => 'Whether a deed can be handed over straight away when the details used to reach its owner changed very recently.'],

        ],
    ],

    'presentations' => [
        'title' => 'Presentations & CMA',
        'intro' => 'These settings decide how CoreX builds the evaluation you put in front of a seller.',
        'what' => [
            'title' => 'What a CMA is',
            'body'  => 'A CMA — Comparative Market Analysis — is how you justify a price to a seller. CoreX finds '
                . 'recent sales of similar properties near theirs ("comparables", or comps), and uses them to '
                . 'produce a defensible price range. The settings below tell it how far to look, how far back to '
                . 'go, and how many comparables it needs before it will call the evidence strong. More comps means '
                . 'a more confident evaluation — but search too wide and you start comparing a beachfront house to '
                . 'one three suburbs inland.',
        ],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'updatePresentations'],
        ],
        'controls' => [
            ['key' => 'presentations_coverage_rich_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 999,
             'label' => 'Comparables needed for "strong evidence"',
             'explain' => 'Find at least this many comparable sales and CoreX marks the evaluation as strongly evidenced. Must be the highest of the three thresholds.',
             'affects' => 'The confidence badge your seller sees on the presentation. A "strong" badge is the one that wins mandates.'],
            ['key' => 'presentations_coverage_moderate_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 6, 'min' => 1, 'max' => 999,
             'label' => 'Comparables needed for "moderate evidence"',
             'explain' => 'The middle tier. Must be less than or equal to the strong threshold above.',
             'affects' => 'The confidence badge on the presentation, and the wording CoreX uses to caveat the price range.'],
            ['key' => 'presentations_coverage_thin_threshold', 'source' => 'agency', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 999,
             'label' => 'Comparables needed for "thin evidence"',
             'explain' => 'The floor. Below this, CoreX warns you there is not enough recent evidence to price confidently. Must be less than or equal to the moderate threshold.',
             'affects' => 'When CoreX warns an agent that an evaluation is under-evidenced before they present it to a seller.'],
            ['key' => 'presentations_default_period_months', 'source' => 'agency', 'type' => 'number', 'default' => 12, 'min' => 1, 'max' => 60,
             'label' => 'How far back to look (months)',
             'explain' => 'Only sales concluded within this many months count as comparables. Twelve months suits most markets; stretch it in a quiet suburb where little sells, shorten it in a fast-moving one.',
             'affects' => 'Which past sales are allowed into the evaluation. Too long and you are pricing off a different market.'],
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
            // Report recogniser (mic-complete-spec §8.9) — same stored value as Settings -> Prospecting -> Stale-claim rules.
            ['controller' => StaleRulesController::class, 'method' => 'updateReportAiRead'],
        ],
        'controls' => [
            ['key' => 'listing_off_market_days', 'source' => 'prospecting_thresholds', 'type' => 'number', 'default' => 90, 'min' => 1, 'max' => 365,
             'label' => 'Presume a portal listing off the market after (days unseen)',
             'explain' => 'CoreX only knows a portal listing is still for sale when the CoreX Chrome extension sees it again. A listing that has not been seen for this many days is presumed off the market. A mandate normally runs 90 days, so 90 is the standard.',
             'affects' => 'Which portal listings stay in Market Intelligence and in a presentation\'s Active Competition section. A shorter number drops listings an agent simply has not re-searched lately; a longer one keeps genuinely sold or withdrawn listings around for longer.'],
            ['key' => 'report_ai_read_enabled', 'source' => 'perf', 'type' => 'toggle', 'default' => 1,
             'label' => 'Read unrecognised market reports with AI',
             'explain' => 'When an agent uploads a market or CMA report in a layout CoreX does not know, CoreX can read it with AI instead of just filing it, and then remembers that layout so the next one is read instantly. Reports read this way are clearly marked "Read by AI – check figures".',
             'affects' => 'Whether the figures in an unfamiliar report reach your comparables and presentations. Off means such a report is saved but nothing is read from it, and CoreX is told so it can add the format; nothing already imported changes.'],
        ],
        'aux_partial' => 'agency-setup.steps.market-intelligence',
    ],

    // AT-454 — Road to Sold. Gated on the road-to-sold feature (which itself depends on prospecting).
    // Spec: .ai/specs/road-to-sold.md section 4. One saver (SettingsController@saveFromWizard) writes
    // through the same path as the settings page and only touches the fields this step posts (spec 6.1).
    'road_to_sold' => [
        'title' => 'Road to Sold',
        'intro' => 'Road to Sold gives every agent a daily list of properties to phone to win stock, measured against a target for each day of the week. Set the ground rules now - you can change them later.',
        'what' => [
            'title' => 'What Road to Sold is',
            'body'  => 'Every morning each agent opens one page: the properties to phone today, how many they should get through, '
                . 'and a bar showing whether they are on track for the month. CoreX builds the list for them from the prospecting '
                . 'data it already holds - buyers who want stock, price drops, new and long-standing listings, owners it already knows, '
                . 'and the agent\'s own expired or slow-moving stock - starting with the suburbs you give that agent. '
                . 'The agent logs what happened on each call, and managers see the results. The numbers below set the standard week; '
                . 'you can give individual agents their own numbers, and their own suburbs, afterwards under Settings -> Road to Sold. '
                . 'Your agency\'s suburbs are not chosen here: they come from the stock you hold and the Market Intelligence you capture, and update by themselves.',
        ],
        'savers' => [
            ['controller' => \App\Http\Controllers\RoadToSold\SettingsController::class, 'method' => 'saveFromWizard'],
        ],
        'controls' => [
            ['key' => 'default_target_1', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 200,
             'label' => 'Monday target',
             'explain' => 'How many properties an agent should work on a Monday. Use 0 for a day nobody works.',
             'affects' => 'The number shown on every agent\'s Road to Sold page on Mondays, and how many properties are added to their list. An agent can be given their own number later.'],
            ['key' => 'default_target_2', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 200,
             'label' => 'Tuesday target',
             'explain' => 'How many properties an agent should work on a Tuesday.',
             'affects' => 'The Tuesday number on every agent\'s page and list, unless that agent has their own.'],
            ['key' => 'default_target_3', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 200,
             'label' => 'Wednesday target',
             'explain' => 'How many properties an agent should work on a Wednesday.',
             'affects' => 'The Wednesday number on every agent\'s page and list, unless that agent has their own.'],
            ['key' => 'default_target_4', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 200,
             'label' => 'Thursday target',
             'explain' => 'How many properties an agent should work on a Thursday.',
             'affects' => 'The Thursday number on every agent\'s page and list, unless that agent has their own.'],
            ['key' => 'default_target_5', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 5, 'min' => 0, 'max' => 200,
             'label' => 'Friday target',
             'explain' => 'How many properties an agent should work on a Friday.',
             'affects' => 'The Friday number on every agent\'s page and list, unless that agent has their own.'],
            ['key' => 'default_target_6', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 200,
             'label' => 'Saturday target',
             'explain' => 'How many properties an agent should work on a Saturday. The standard is 0 - no Saturday list.',
             'affects' => 'Whether agents get a Saturday list and a Saturday target. At 0 they do not, and Saturdays add nothing to the month\'s target.'],
            ['key' => 'default_target_7', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 200,
             'label' => 'Sunday target',
             'explain' => 'How many properties an agent should work on a Sunday. The standard is 0 - no Sunday list.',
             'affects' => 'Whether agents get a Sunday list and a Sunday target. At 0 they do not, and Sundays add nothing to the month\'s target.'],
            ['key' => 'default_monthly_points', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 1000000,
             'label' => 'Monthly points target for an agent',
             'explain' => 'The points an agent works towards each month. 0 means you have not set a standard. It is only a starting point: a manager presses one button on Road to Sold settings -> Targets to give it to every agent who has no target yet, and can change any agent\'s own number.',
             'affects' => 'The number given to an agent without a points target when a manager gives out the agency default. Nothing changes for an agent until that button is pressed.'],
            ['key' => 'default_monthly_deals', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 1000000,
             'label' => 'Monthly deals target for an agent',
             'explain' => 'How many deals an agent should close in a month. 0 means no standard.',
             'affects' => 'The deals target given to an agent without one when a manager gives out the agency default, and what the agent\'s progress bar on My Portal is measured against.'],
            ['key' => 'default_monthly_listings', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 1000000,
             'label' => 'Monthly new listings target for an agent',
             'explain' => 'How many new listings an agent should take on in a month. 0 means no standard.',
             'affects' => 'The listings target given to an agent without one, and the target CoreX compares the agent\'s new listings against each month.'],
            ['key' => 'default_monthly_value', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 999999999999,
             'label' => 'Monthly sales value target for an agent (rand)',
             'explain' => 'The total value of property sold an agent should reach in a month. 0 means no standard.',
             'affects' => 'The sales value target given to an agent without one, shown against their sales on My Portal and the performance pages.'],
            ['key' => 'half_day_leave_halves_target', 'source' => 'road_to_sold', 'type' => 'toggle', 'default' => 0,
             'label' => 'Half-day leave halves the target',
             'explain' => 'Public holidays and whole days of approved leave never carry a target. This decides what happens on an approved half day of leave: off means the agent still owes the full day\'s number, on means it is halved.',
             'affects' => 'An agent\'s target and their month-to-date figure on days they are on half-day leave.'],
            ['key' => 'lock_agents_to_areas', 'source' => 'road_to_sold', 'type' => 'toggle', 'default' => 0,
             'label' => 'Lock agents to their areas',
             'explain' => 'Each agent can be given their own suburbs. Off (recommended): their suburbs come first, then the list carries on into the rest of your agency\'s suburbs if the day is not full. On: they only ever get properties from their own suburbs.',
             'affects' => 'Whether an agent can be sent to a suburb they have not been assigned. With this on, an agent who has no suburbs gets no call list at all.'],
            ['key' => 'min_area_properties', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 1, 'min' => 1, 'max' => 1000,
             'label' => 'A suburb counts as a working area from (properties)',
             'explain' => 'Your working suburbs are worked out from your own stock and the Market Intelligence adverts captured for your agency. A suburb with only a stray advert or two can be left out by raising this number.',
             'affects' => 'Which suburbs appear on the Areas screen, can be given to an agent, and are used to build call lists. At 1, every suburb you hold stock in or have captured anything for appears.'],
            ['key' => 'refresh_portal_p24', 'source' => 'road_to_sold', 'type' => 'toggle', 'default' => 1,
             'label' => 'We capture from Property24',
             'explain' => 'Road to Sold only asks for a refresh of a portal your agency actually captures from. Leave this on if you capture Property24 listings.',
             'affects' => 'Whether a suburb is flagged "Needs a refresh" when nothing has been captured from Property24 for it recently, and whether the Areas screen shows Property24 dates. At least one portal must stay on.'],
            ['key' => 'refresh_portal_pp', 'source' => 'road_to_sold', 'type' => 'toggle', 'default' => 1,
             'label' => 'We capture from Private Property',
             'explain' => 'Switch this off if your agency does not capture Private Property listings, so suburbs are never flagged for a portal you do not use.',
             'affects' => 'Whether a suburb is flagged "Needs a refresh" when nothing has been captured from Private Property for it recently, and whether the Areas screen shows Private Property dates.'],
            ['key' => 'area_refresh_warn_days', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 7, 'min' => 1, 'max' => 365,
             'label' => 'Ask for a refresh after (days)',
             'explain' => 'Road to Sold can only suggest what CoreX has captured. When nothing has been captured for a suburb in this many days, it shows "Needs a refresh" so someone re-captures it.',
             'affects' => 'When a suburb is flagged as stale on the Areas screen. Refreshing stays a manual job.'],
            ['key' => 'missed_items_mode', 'source' => 'road_to_sold', 'type' => 'select', 'default' => 'show_missed',
             'options' => ['show_missed' => 'Show them as missed', 'roll_over' => 'Roll them over to the next working day'],
             'label' => 'Properties an agent did not get to',
             'explain' => 'At the end of the day, any property still not worked is either shown as missed on that day, or moved onto the agent\'s next working day.',
             'affects' => 'Whether missed properties count against the day they were meant for, or are carried forward and worked later.'],
            ['key' => 'no_answer_drop_after', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 3, 'min' => 1, 'max' => 20,
             'label' => 'No-answers in a row before a property drops off',
             'explain' => 'When an agent phones an owner and gets no answer this many times in a row, the property drops off their list and shows on the manager\'s report instead.',
             'affects' => 'How long an agent keeps chasing a number that never answers before the property is handed to the manager\'s attention.'],
            ['key' => 'hold_days', 'source' => 'road_to_sold', 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 365,
             'label' => 'Days an agent keeps a property after a call',
             'explain' => 'Once an agent logs an outcome on a property, it is theirs for this many days and is not suggested to other agents. Each new outcome restarts the clock.',
             'affects' => 'How long a property stays reserved for the agent who last worked it, so two agents do not phone the same owner.'],
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
             'affects' => 'Whether an enquiry shows as answered "in target" or "late" on the Lead Response report (Reports menu), and when a waiting enquiry is flagged as past target.'],
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
        // AT-509 — Compliance Switches (.ai/specs/property-compliance-gate-settings.md §11) renders after the
        // officers/whistleblow partial, inside the same form.
        'partial' => ['agency-setup.steps.compliance', 'agency-setup.steps.compliance-switches'],
        'savers' => [
            ['controller' => SettingsController::class, 'method' => 'saveWhistleblowSettings'],
            // AT-509 — same saver as Settings → Compliance Switches. Does nothing unless the partial's
            // `compliance_switches_submitted` marker was posted, and only reads the switches it rendered (§6.1).
            // Switching a check off needs the same disclaimer, enforced server-side.
            ['controller' => ComplianceSwitchesController::class, 'method' => 'update'],
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
            // Rentals cross-cut — FICA time windows; has()-guarded per field (§6.1).
            ['controller' => FicaOfficerAppointmentsController::class, 'method' => 'saveWindowSettings'],
            // E-sign signing modes (esign-signing-modes.md, ruling 5) — has()-guarded; 403 before any write without esign.settings.
            ['controller' => \App\Http\Controllers\Docuperfect\EsignSigningModeSettingsController::class, 'method' => 'saveFromWizard'],
            // Who may authorise documents (esign-template-transfer.md §13.3) — has()-guarded per list; 403 before any write without esign.settings.
            ['controller' => \App\Http\Controllers\Docuperfect\DocumentAuthoriserSettingsController::class, 'method' => 'saveFromWizard'],
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
            // Rentals cross-cut (8 Oct 2026) — the FICA time windows that were literals in code. Plain number inputs; the saver is has()-guarded (§6.1).
            ['key' => 'fica_link_expiry_days', 'source' => 'agency', 'type' => 'number', 'default' => 14, 'min' => 1, 'max' => 90,
             'label' => 'FICA link stays valid for (days)',
             'explain' => 'How long the FICA link emailed to a client works before it expires and has to be re-sent.',
             'affects' => 'The expiry date printed in the FICA request email and the day the client\'s link stops working.'],
            ['key' => 'fica_current_months', 'source' => 'agency', 'type' => 'number', 'default' => 11, 'min' => 1, 'max' => 60,
             'label' => 'An approved FICA counts as current for (months)',
             'explain' => 'How long an approved FICA is treated as up to date before CoreX flags it as expiring.',
             'affects' => 'When a contact\'s FICA badge turns to "Expiring", and when a repeat rental applicant is asked for FICA again.'],
            ['key' => 'fica_validity_months', 'source' => 'agency', 'type' => 'number', 'default' => 24, 'min' => 1, 'max' => 120,
             'label' => 'An approval is valid for (months)',
             'explain' => 'The expiry date CoreX stamps on a FICA when your compliance officer approves it.',
             'affects' => 'The expiry date shown on approved FICA records and on the compliance calendar.'],
            ['key' => 'fica_expiring_soon_days', 'source' => 'agency', 'type' => 'number', 'default' => 60, 'min' => 1, 'max' => 365,
             'label' => '"Expiring soon" looks ahead (days)',
             'explain' => 'How far ahead the "expiring soon" list looks for FICA approvals about to lapse.',
             'affects' => 'Which FICA approvals appear under "Expiring soon" in document filing.'],
            // Trust account name on the FICA form's payment guidance (was one agency's name in the wording). Text, has()-guarded by saveWindowSettings (§6.1).
            ['key' => 'rental_trust_account_name', 'source' => 'agency', 'type' => 'text', 'placeholder' => 'e.g. Smith Property Trust Account',
             'label' => 'Trust account name (rent and deposits)',
             'explain' => 'How your clients should refer to the trust account their rent and deposit are paid into. Tenants and landlords see it in the payment guidance on the FICA form. Leave it blank and the form says "the agency\'s trust account".',
             'affects' => 'The wording of the payment hints on the FICA form your tenants and landlords fill in, e.g. "paid via EFT to the Smith Property Trust Account".'],
            // E-sign signing modes (esign-signing-modes.md, Johan's ruling 5, 23 Oct 2026) - the same choice as E-Sign -> How my signers sign.
            ['key' => 'signing_mode', 'source' => 'esign', 'type' => 'select', 'default' => 'sign_each', 'heading' => 'E-signing',
             'options' => [
                 'sign_each' => 'Sign and initial each place - the signer signs or initials each place, one by one',
                 'adopt_once' => 'Adopt once - the signer adopts a signature and initial once, then clicks each place to apply it and/or presses "Apply to all my places"',
             ],
             'label' => 'How my signers sign',
             'explain' => 'How people who sign by link (tenants, landlords, sellers, buyers, directors, witnesses) put their signature and initials on a document you send them. Your own agents keep their saved signature either way.',
             'affects' => 'What a signer does on the signing page: sign every place themselves, or adopt once and apply it place by place or to all their places at once. Documents already out for signing finish the way they were sent; only documents sent from now on follow this choice. Condition and amendment initials and disclosure answers are always done one by one, whichever you pick.'],
            // Who may authorise documents (esign-template-transfer.md §13.3, Johan's ruling R2) - the same two lists as E-Sign -> Who may authorise documents.
            ['key' => 'document_authoriser_user_ids', 'source' => 'document_authorisers', 'type' => 'user_multiselect', 'default' => [], 'heading' => 'Documents CoreX prepares for you',
             'label' => 'People who may approve documents',
             'explain' => 'When CoreX prepares a document for your agency - a lease, a mandate, a disclosure form - it is not live until one of the people you choose approves it. Tick the people who may approve. Until you choose anyone, your administrators can.',
             'affects' => 'Who can open the Approvals screen and make a prepared document live for your agents. Nobody else can, however senior - and CoreX itself cannot approve on your behalf.'],
            ['key' => 'document_authoriser_roles', 'source' => 'document_authorisers', 'type' => 'role_multiselect', 'default' => [],
             'label' => 'Roles that may approve documents',
             'explain' => 'Tick a whole role instead of (or as well as) naming people: everyone who holds a ticked role may approve a document CoreX has prepared for your agency.',
             'affects' => 'The same as the people above, for everyone who holds the role - including people who join later. A role also needs the "Approve documents released to your agency" permission in Role Manager.'],
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
        // AT-451 follow-up (14 Oct 2026) - written through updateAgencyDashboardSettings, which only touches the field when this step posts it.
        'controls' => [
            ['key' => 'alert_catchup_minutes', 'source' => 'alert_settings', 'type' => 'number', 'default' => 10, 'min' => 1, 'max' => 240,
             'label' => 'Show alerts still unread from the last (minutes)',
             'explain' => 'When someone opens CoreX in a new tab or signs in, any alert that arrived in this many minutes and has not been read pops up on screen, so a fault reported a moment before is not missed.',
             'affects' => 'How far back a freshly opened CoreX screen looks for unread alerts to pop up. A short time suits a busy office; a longer one suits people who open CoreX only now and then.'],
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
            ['controller' => SettingsController::class, 'method' => 'updateDesk'],
        ],
        'controls' => [
            // CoreX Desk (.ai/specs/corex-desk.md section 6): on by default, so an agency that does nothing can be helped.
            ['key' => 'desk_enabled', 'source' => 'agency', 'type' => 'toggle', 'default' => 1,
             'label' => 'Let my agents press Get help to share their CoreX window with CoreX support',
             'explain' => 'Every page has a Get help button. The agent reads a 6-digit code to CoreX support (usually on a phone call), and support can then see that one CoreX window live, point at things and highlight them. Support sees the agent\'s CoreX window (and the page text on it, apart from private fields such as passwords, ID, tax and bank inputs, which are hidden before anything is sent). Support can ask to take control and click and type for the agent: the agent must press Allow every time, can take control back at any moment, and support needs the separate take-over permission. The agent can also choose to share other tabs with support through the CoreX Chrome extension, only on supported websites and only while they keep sharing. Support never sees the desktop, files or tabs the agent has not shared. Every session and every action is logged under Admin > Desk > Desk sessions. Nothing from the screen is stored.',
             'affects' => 'Whether the Get help button starts a live session with CoreX support, or just shows the CoreX support phone number.'],
            ['key' => 'desk_control_idle_minutes', 'source' => 'agency', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 60,
             'label' => 'End a support take-over after this many idle minutes',
             'explain' => 'CoreX support can ask an agent to let them take control of the agent\'s window (to click and type for them). The agent has to press Allow every time, can take control back at any moment, and every action is logged. If support does nothing for this many minutes, control ends by itself.',
             'affects' => 'How long a take-over stays open when support stops using it.'],
            ['key' => 'require_external_access_authorization', 'source' => 'agency', 'type' => 'toggle', 'default' => 0,
             'label' => 'Ask my permission before a developer or tester enters my agency',
             'explain' => 'With this on, a CoreX developer or tester must send you a consent request and wait for you to approve it before they can switch into your agency. With it off, they can enter directly to support you.',
             'affects' => 'Whether support can act on an urgent problem immediately, or has to wait for someone at your agency to approve the request first.'],
        ],
    ],
];
