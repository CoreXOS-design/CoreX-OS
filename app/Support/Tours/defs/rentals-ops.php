<?php

/**
 * Guided-tour definitions — RENTALS, OPERATIONS (AT-478 coverage pass, spec advanced-guiding.md §12).
 *
 * Pure DATA merged by App\Support\Tours\TourRegistry::all() from every file in
 * app/Support/Tours/defs/*.php. Keys are namespaced `rops-…`; every `element` /
 * `until` / `target` selector is a dedicated data-tour="rops-…" anchor added to the
 * rental views (a removed anchor is caught by tests/Feature/Tours/TourRegistryIntegrityTest).
 *
 * Features covered here: Rentals (the umbrella), Rental Command Centre, Leases,
 * Rental Applications, Rental Take-On Import, Rental Reports and Rental Notices.
 *
 * One definition drives all three modes: the click-through Guided Tour, the hands-on
 * Advanced Guide (the `do` steps) and Spot Help (the `section`s). Steps whose anchor is
 * not on screen (a button the viewer is not allowed to see, a panel that only shows in
 * some states) are skipped by the engine.
 *
 * Screens that need one record (a lease, an application) declare `pick_from`, so an
 * outside launch goes via the matching list first. Wording is neutral for any agency.
 */

return [

    // ══════════════════════════════════════════════════════════════════════════
    // LEASES
    // ══════════════════════════════════════════════════════════════════════════

    // ── The Leases list ──────────────────────────────────────────────────────
    'rops-lease-hub' => [
        'key'         => 'rops-lease-hub',
        'title'       => 'Finding and opening a lease',
        'description' => 'The Leases list - search, filter and sort every tenancy, then open one or start a new lease.',
        'route'       => 'corex.leases.index',
        'permission'  => 'leases.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-leases',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-lease-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Your Leases',
                'body'    => 'Every rental tenancy you are allowed to see is listed here, soonest to expire first. The tiles above the search box count the leases in each stage (Draft, Active, Expired and so on) - click a tile to see only those, click it again to clear.',
            ],
            [
                'element' => '[data-tour="rops-lease-search"]',
                'section' => 'Finding a lease',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a property address or a tenant name, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type part of the property address or the tenant name and the list narrows to match. Clear the box to see everything again.',
            ],
            [
                'element' => '[data-tour="rops-lease-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status to narrow the list.'],
                'title'   => 'Filter by status',
                'body'    => 'Draft means the lease is captured but not running yet. Signed - starts later is a signed lease waiting for its start date. Active is running now. Expired and Cancelled are finished.',
            ],
            [
                'element' => '[data-tour="rops-lease-agreement"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick where the signed agreement stands - or press Skip this step.'],
                'title'   => 'Filter by agreement',
                'body'    => 'Shows where the lease agreement itself has got to - for example not yet prepared, out for signing, or signed. Handy for chasing leases that are running without a signed agreement.',
            ],
            [
                'element' => '[data-tour="rops-lease-notice-terms"]',
                'title'   => 'Filter by notice terms',
                'body'    => 'Each lease carries its notice and early-cancellation terms. "To check - not confirmed" lists leases whose terms still come from your agency defaults and have not been confirmed against the signed lease.',
            ],
            [
                'element' => '[data-tour="rops-lease-property"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address and pick the property - or press Skip this step.', 'target' => 'input[type="text"]'],
                'title'   => 'Filter by property',
                'body'    => 'Start typing an address and pick the property from the drop-down. Only properties that have a lease you can see are offered.',
            ],
            [
                'element' => '[data-tour="rops-lease-date-from"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick an End date from - or press Skip this step.'],
                'title'   => 'Filter by end date',
                'body'    => 'Use End date from and End date to to see only leases that finish in a window - for example everything ending in the next three months, so you can plan renewals.',
            ],
            [
                'element' => '[data-tour="rops-lease-filter-btn"]',
                'title'   => 'Apply the filters',
                'body'    => 'Press Filter to apply everything you chose. Clear (which appears once a filter is on) removes them all. You can also click the Property, Status, Start or End headings in the table to sort.',
            ],
            [
                'element' => '[data-tour="rops-lease-bulk"]',
                'section' => 'Breach notices for several leases',
                'title'   => 'One breach notice for many leases',
                'body'    => 'Tick the running leases in the table, then choose this button to issue breach notices for all of them from one screen. You fill in the amount owing on the next screen - nothing is sent until you confirm.',
            ],
            [
                'element' => '[data-tour="rops-lease-list"]',
                'section' => 'Opening a lease',
                'do'      => ['action' => 'click', 'say' => 'Click View on a lease.', 'target' => '[data-tour="rops-lease-open"]'],
                'title'   => 'Open a lease',
                'body'    => 'Each row is one tenancy: the property, the tenant and landlord, the two agents looking after it, its status, the dates and the monthly rent. View opens the lease itself, where you manage everything about the tenancy.',
            ],
            [
                'element' => '[data-tour="rops-lease-new"]',
                'section' => 'Starting a new lease',
                'title'   => 'Start a new lease',
                'body'    => 'New Lease opens the capture form: pick the rental property, add the tenant or tenants, and set the rent, deposit and dates. You are ready - close this and capture your next lease.',
            ],
        ],
    ],

    // ── New lease (capture form) ─────────────────────────────────────────────
    'rops-lease-new' => [
        'key'         => 'rops-lease-new',
        'title'       => 'Capturing a new lease',
        'description' => 'The New Lease form - the property, the tenants, the rent, deposit and dates, the notice terms, and how to save.',
        'route'       => 'corex.leases.create',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-leases',
        'permission'  => 'leases.create',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-lc-intro"]',
                'section' => 'Property and tenants',
                'title'   => 'One lease, one form',
                'body'    => 'A lease joins a rental property to the tenant or tenants who rent it, with the rent, the deposit and the dates. Fill in what you know - the next boxes guide you through it.',
            ],
            [
                'element' => '[data-tour="rops-lc-property"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of the address, then pick the property from the list.'],
                'title'   => 'The property',
                'body'    => 'Required. Start typing an address, a property name or a reference and pick the rental property from the list. The landlord and the owner\'s agent come across from the property record.',
            ],
            [
                'element' => '[data-tour="rops-lc-tenant"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the tenant\'s name, then pick them from the list.'],
                'title'   => 'The tenant(s)',
                'body'    => 'Required. Search your contacts and pick the tenant. A lease can have joint tenants, so add everyone who signs - the first one you add is the primary tenant.',
            ],
            [
                'element' => '[data-tour="rops-lc-rent"]',
                'section' => 'Rent, deposit and dates',
                'do'      => ['action' => 'fill', 'say' => 'Type the monthly rent in rand.'],
                'title'   => 'Monthly rental',
                'body'    => 'Required. The rent the tenant pays each month, in rand. It starts from the property\'s rent when one is on record.',
            ],
            [
                'element' => '[data-tour="rops-lc-deposit"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the deposit in rand - or press Skip this step.'],
                'title'   => 'Deposit',
                'body'    => 'The deposit held for the tenancy. It fills in from your agency\'s usual deposit terms until you type your own figure.',
            ],
            [
                'element' => '[data-tour="rops-lc-start"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the start date.'],
                'title'   => 'Start date',
                'body'    => 'Required. The day the tenancy begins. A lease that starts in the future stays a draft until that day.',
            ],
            [
                'element' => '[data-tour="rops-lc-end"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the end date - or tick Month-to-month below.'],
                'title'   => 'End date',
                'body'    => 'The day the fixed term ends. Leave it empty only for a month-to-month lease.',
            ],
            [
                'element' => '[data-tour="rops-lc-m2m"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick this only if the lease has no fixed end date - or press Skip this step.'],
                'title'   => 'Month-to-month',
                'body'    => 'Tick this when the lease runs from month to month with no end date. The end-date box is switched off while it is ticked.',
            ],
            [
                'element' => '[data-tour="rops-lc-agents"]',
                'section' => 'Who looks after the lease',
                'title'   => 'The two agents',
                'body'    => 'The owner\'s agent looks after the landlord and the tenant\'s agent looks after the tenant. Both start with sensible defaults. They are the people the tenant and landlord portal links show as "who to call".',
            ],
            [
                'element' => '[data-tour="rops-lc-notice"]',
                'section' => 'Notice terms',
                'title'   => 'Notice and early cancellation',
                'body'    => 'How much notice either side must give, and what happens if the tenant cancels early. These start from your agency\'s defaults - change them here if this lease says something different. They are used to fill the lease agreement and to answer questions in the portals.',
            ],
            [
                'element' => '[data-tour="rops-lc-activate"]',
                'section' => 'Saving the lease',
                'do'      => ['action' => 'choose', 'say' => 'Tick this only if the lease should run straight away - or press Skip this step.'],
                'title'   => 'Activate immediately',
                'body'    => 'Normally a new lease is saved as a draft until it is signed and its start date arrives. Tick this to skip the draft stage and make it active straight away.',
            ],
            [
                'element' => '[data-tour="rops-lc-paper"]',
                'title'   => 'Already signed on paper?',
                'body'    => 'If the tenant and landlord have already signed a paper copy, open this and attach the signed document instead - the lease is created with the copy filed against it.',
            ],
            [
                'element'       => '[data-tour="rops-lc-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Create lease only to save it.'],
                'title'         => 'Save the lease',
                'body'          => 'Create lease only saves the lease and takes you to it. If your agency has a lease agreement set up, the button next to it also prepares the agreement for signing.',
            ],
        ],
    ],

    // ── One lease (the Lease Hub) ────────────────────────────────────────────
    'rops-lease-record' => [
        'key'         => 'rops-lease-record',
        'title'       => 'Working on a lease',
        'description' => 'One lease - its status and next step, the actions menu, the tenancy log, terms, agents and portal access.',
        'route'       => 'corex.leases.show',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-leases',
        'permission'  => 'leases.view',
        'pick_from'   => 'corex.leases.index',
        'pick_note'   => 'Open the lease you want to work on - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-lr-head"]',
                'section' => 'Reading the lease',
                'title'   => 'The lease at a glance',
                'body'    => 'The property, the tenant or tenants, the rent and the term are at the top, with the lease\'s status beside them. The buttons on the right are everything you can do to this lease.',
            ],
            [
                'element' => '[data-tour="rops-lr-lifecycle"]',
                'title'   => 'Where the lease is up to',
                'body'    => 'The strip shows the stages of this tenancy from capture to move-out. Each stage is worked out live from what has actually happened, so it cannot get out of date.',
            ],
            [
                'element' => '[data-tour="rops-lr-next"]',
                'title'   => 'The next step',
                'body'    => 'CoreX works out the next thing that needs doing on this lease and puts a button here - for example preparing the agreement for signing, or activating the lease. When there is nothing to click it simply states what is being waited for.',
            ],
            [
                'element' => '[data-tour="rops-lr-report"]',
                'section' => 'The buttons at the top',
                'title'   => 'Print the tenancy report',
                'body'    => 'A printable summary of this tenancy - the parties, the terms and what has happened so far.',
            ],
            [
                'element' => '[data-tour="rops-lr-notice"]',
                'title'   => 'Send a notice',
                'body'    => 'Choose one of your agency\'s notice templates and send it to the tenant, the landlord or both. Every notice sent is kept in the tenancy log.',
            ],
            [
                'element' => '[data-tour="rops-lr-edit"]',
                'do'      => ['action' => 'click', 'say' => 'Click Edit to open the edit panel.'],
                'title'   => 'Edit the lease',
                'body'    => 'Opens the edit panel in the right-hand column, where you can correct the rent, the deposit and the dates. Changes are logged.',
            ],
            [
                'element' => '[data-tour="rops-lr-actions"]',
                'title'   => 'Lease actions',
                'body'    => 'The menu for the big moments in a tenancy: renewing the lease, moving it to month-to-month, recording that the tenant or the landlord gave notice, or cancelling it. Each one opens a short form first and explains what it will do.',
            ],
            [
                'element' => '[data-tour="rops-lr-log"]',
                'section' => 'The tenancy log',
                'title'   => 'Everything that has happened',
                'body'    => 'Every event on this tenancy - notices, payments chased, inspections, changes of agent - is recorded here with who did it and when. Nothing in the log can be quietly edited.',
            ],
            [
                'element' => '[data-tour="rops-lr-log-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a word to look for in the log - or press Skip this step.'],
                'title'   => 'Search the log',
                'body'    => 'Type part of a description or a person\'s name, then press Enter, to find a particular entry. The Show chips below the box narrow the log to one kind of event.',
            ],
            [
                'element' => '[data-tour="rops-lr-terms"]',
                'section' => 'Terms and people',
                'title'   => 'Lease terms',
                'body'    => 'The deposit, the property, the landlord and the links to an earlier or renewed lease. If something needs attention - for example a missing landlord or an incomplete FICA - it is flagged here.',
            ],
            [
                'element' => '[data-tour="rops-lr-agents"]',
                'title'   => 'The two agents',
                'body'    => 'Who looks after the landlord and who looks after the tenant on this lease. Change moves the lease to another agent - the change is logged and the portal links update.',
            ],
            [
                'element' => '[data-tour="rops-lr-noticeterms"]',
                'title'   => 'Notice terms',
                'body'    => 'How much notice each side must give and what an early cancellation costs. Confirm them once you have checked them against the signed lease - they feed the lease agreement and the answers shown in the portals.',
            ],
            [
                'element' => '[data-tour="rops-lr-open"]',
                'section' => 'Open items and portals',
                'title'   => 'Open items',
                'body'    => 'A quick count of what is still open on this tenancy - faults, work orders and inspections. Click a line to go straight to those records.',
            ],
            [
                'element' => '[data-tour="rops-lr-portal"]',
                'title'   => 'Tenant and landlord portal access',
                'body'    => 'Invite the tenant (and below it the landlord) to their own portal, where they can report faults, see notices and find their documents. Nothing is sent until you press the invite button.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // RENTAL APPLICATIONS
    // ══════════════════════════════════════════════════════════════════════════

    // ── Application control centre (list) ────────────────────────────────────
    'rops-app-list' => [
        'key'         => 'rops-app-list',
        'title'       => 'Tracking rental applications',
        'description' => 'Every rental application and where it stands - tiles by stage, search, filters, and opening an application.',
        'route'       => 'corex.rental-applications.index',
        'permission'  => 'rental_applications.view',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-applications',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-app-intro"]',
                'section' => 'Reading the screen',
                'title'   => 'Rental applications',
                'body'    => 'Send an application link to a prospective tenant, then follow it here until it ends in a lease, a decline or a withdrawal. This one screen shows where every application actually is.',
            ],
            [
                'element' => '[data-tour="rops-app-scope"]',
                'title'   => 'Whose applications',
                'body'    => 'Switch between your own applications, your branch\'s and the whole agency\'s - as far as your access allows. The numbers on the tiles below always follow this choice.',
            ],
            [
                'element' => '[data-tour="rops-app-tiles"]',
                'do'      => ['action' => 'click', 'say' => 'Click a tile to see only the applications at that stage.'],
                'title'   => 'The stage tiles',
                'body'    => 'Each tile is a stage - not yet submitted, returned by the tenant, under assessment, sent for authorisation, approved, tenanted, declined. The number is how many applications are in it. The two FICA tiles tell you who to chase: the applicant, or your own team.',
            ],
            [
                'element' => '[data-tour="rops-app-search"]',
                'section' => 'Finding an application',
                'do'      => ['action' => 'fill', 'say' => 'Type an applicant name, phone, email, ID number or property, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Search by applicant name, phone number, email, ID number, property or the application number. Clear the box to see everything in the chosen stage again.',
            ],
            [
                'element' => '[data-tour="rops-app-date-from"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a Created from date - or press Skip this step.'],
                'title'   => 'Filter by date',
                'body'    => 'Created from and Created to show only applications started in that window.',
            ],
            [
                'element' => '[data-tour="rops-app-filter-btn"]',
                'title'   => 'Apply, clear, archived',
                'body'    => 'Press Filter to apply the search and dates. Clear removes them, and Show archived switches to applications that were archived. Click any heading in the table to sort by it.',
            ],
            [
                'element' => '[data-tour="rops-app-list"]',
                'section' => 'Opening an application',
                'do'      => ['action' => 'click', 'say' => 'Click View or Edit on an application.', 'target' => '[data-tour="rops-app-open"]'],
                'title'   => 'The applications',
                'body'    => 'Each row shows the applicant, the property, the status, the agent and the dates. Depending on the stage the row also offers Review & Assess, Send approval or Create lease. View or Edit opens the application itself.',
            ],
            [
                'element' => '[data-tour="rops-app-new"]',
                'section' => 'Starting an application',
                'title'   => 'New rental application',
                'body'    => 'Pick a contact and, if you know it, the property. CoreX creates the application and you send the applicant their link. Close this and start your next application.',
            ],
        ],
    ],

    // ── New application ──────────────────────────────────────────────────────
    'rops-app-new' => [
        'key'         => 'rops-app-new',
        'title'       => 'Starting a rental application',
        'description' => 'The New Rental Application form - pick the contact, optionally the property, and create it.',
        'route'       => 'corex.rental-applications.create',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-applications',
        'permission'  => 'rental_applications.create',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-ac-intro"]',
                'section' => 'Who is applying',
                'title'   => 'Start with the person',
                'body'    => 'Only the contact is needed to begin. Everything else on the application can be filled in later - by you, or by the applicant on their own link.',
            ],
            [
                'element' => '[data-tour="rops-ac-contact"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the applicant\'s name, phone or email, then pick them from the list.'],
                'title'   => 'The applicant',
                'body'    => 'Required. Search your contacts and click the right person. The Create button stays off until a contact is chosen.',
            ],
            [
                'element' => '[data-tour="rops-ac-newcontact"]',
                'title'   => 'Not a contact yet?',
                'body'    => 'Create a new contact on the spot with a name and a phone number or email. CoreX checks for an existing person first so you do not end up with the same person twice.',
            ],
            [
                'element' => '[data-tour="rops-ac-property"]',
                'section' => 'Which property',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address and pick the property - or press Skip this step.'],
                'title'   => 'The property (optional)',
                'body'    => 'If the applicant is applying for one of your properties, pick it so the application is linked to it. You can also set or change this later.',
            ],
            [
                'element'       => '[data-tour="rops-ac-create"]',
                'section'       => 'Creating it',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Create.'],
                'title'         => 'Create the application',
                'body'          => 'Creates the application as a draft and opens it. Nothing is sent to the applicant until you press Send on the next screen.',
            ],
        ],
    ],

    // ── One application ──────────────────────────────────────────────────────
    'rops-app-record' => [
        'key'         => 'rops-app-record',
        'title'       => 'Working on a rental application',
        'description' => 'One application - saving it, sending it to the applicant, the documents and signatures, and the applicant\'s details.',
        'route'       => 'corex.rental-applications.show',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'rental-applications',
        'permission'  => 'rental_applications.view',
        'pick_from'   => 'corex.rental-applications.index',
        'pick_note'   => 'Open the application you want to work on - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-ar-head"]',
                'section' => 'The buttons at the top',
                'title'   => 'The application',
                'body'    => 'The applicant\'s name and the stage the application is at. The bar stays at the top as you scroll, so Save and Send are always within reach.',
            ],
            [
                'element' => '[data-tour="rops-ar-pdf"]',
                'title'   => 'Download PDF',
                'body'    => 'A PDF copy of the application as it stands now, for your records or to show someone.',
            ],
            [
                'element' => '[data-tour="rops-ar-save"]',
                'do'      => ['action' => 'click', 'say' => 'Make a change below first, then click Save.'],
                'title'   => 'Save',
                'body'    => 'Saves anything you have typed on this page. The button turns solid blue when there are unsaved changes.',
            ],
            [
                'element' => '[data-tour="rops-ar-send"]',
                'title'   => 'Send or Resend',
                'body'    => 'Emails the applicant their personal link to fill in the application and upload their documents. It is switched off until the application has a saved email address and no unsaved changes.',
            ],
            [
                'element' => '[data-tour="rops-ar-signatures"]',
                'section' => 'Documents and signatures',
                'title'   => 'Signatures',
                'body'    => 'Shows whether the applicant has signed the declaration and given their credit-check consent. Both come from the applicant\'s own link.',
            ],
            [
                'element' => '[data-tour="rops-ar-docs"]',
                'title'   => 'Supporting documents',
                'body'    => 'Everything the applicant or an agent has uploaded - ID, proof of income, bank statements and so on. Click a name to open it.',
            ],
            [
                'element' => '[data-tour="rops-ar-property"]',
                'section' => 'The applicant\'s details',
                'title'   => 'The property',
                'body'    => 'The property being applied for. Link it to one of your properties, or type the address if it is not one of yours.',
            ],
            [
                'element' => '[data-tour="rops-ar-personal"]',
                'do'      => ['action' => 'fill', 'say' => 'Click into a box and correct or add a detail - or press Skip this step.', 'target' => 'input'],
                'title'   => 'Personal details',
                'body'    => 'Name, ID number, contact details and so on. The applicant fills these in themselves, and you can correct or complete them here.',
            ],
            [
                'element' => '[data-tour="rops-ar-employment"]',
                'title'   => 'Employment',
                'body'    => 'Where the applicant works and what they earn. This drives the affordability check, and decides which documents are required.',
            ],
            [
                'element' => '[data-tour="rops-ar-lease"]',
                'title'   => 'What the applicant needs',
                'body'    => 'When they want to move in, how many people will live there, and any other requirements for the lease.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // RENTAL COMMAND CENTRE
    // ══════════════════════════════════════════════════════════════════════════
    'rops-cc' => [
        'key'         => 'rops-cc',
        'title'       => 'Using the Rental Command Centre',
        'description' => 'Every rental property in one place - the stage tiles, the needs-action queue, and the property list.',
        'route'       => 'corex.rentals.command-centre.index',
        'permission'  => 'rental_command_centre.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-cc-intro"]',
                'section' => 'Reading the screen',
                'title'   => 'Your rentals, in one place',
                'body'    => 'Every rental property with its current state, and a queue of what needs your action. Start here each morning.',
            ],
            [
                'element' => '[data-tour="rops-cc-scope"]',
                'title'   => 'Whose properties',
                'body'    => 'Switch between your own properties, your branch\'s and the whole agency\'s, as far as your access allows. Everything on the screen follows this choice.',
            ],
            [
                'element' => '[data-tour="rops-cc-tiles"]',
                'do'      => ['action' => 'click', 'say' => 'Click a tile to filter the property list to it.'],
                'title'   => 'The tiles',
                'body'    => 'Each tile counts properties (or open records) in one situation - for example leases ending soon, open faults, or properties with no inspection. Click a tile to filter the list to it, click it again to clear.',
            ],
            [
                'element' => '[data-tour="rops-cc-queue"]',
                'section' => 'The needs-action queue',
                'title'   => 'Needs action',
                'body'    => 'The jobs waiting on you, most urgent first. Each line is named after its own record - a fault, a work order or a lease - with one button that opens exactly that record. Collapse hides the panel, and it remembers your choice.',
            ],
            [
                'element' => '[data-tour="rops-cc-queue-sort"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how to sort the queue - or press Skip this step.'],
                'title'   => 'Sort and group the queue',
                'body'    => 'Sort by urgency or by date, and group the queue by property or by date. Your choice is remembered for next time.',
            ],
            [
                'element' => '[data-tour="rops-cc-search"]',
                'section' => 'Finding a property',
                'do'      => ['action' => 'fill', 'say' => 'Type an address, tenant, landlord or erf number, then press Enter.'],
                'title'   => 'Search the property list',
                'body'    => 'Search by address, tenant name, landlord name or erf number.',
            ],
            [
                'element' => '[data-tour="rops-cc-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status to narrow the list.'],
                'title'   => 'Filter by status',
                'body'    => 'Narrow the list to properties in one rental status. Next to it are filters for the agent and the branch.',
            ],
            [
                'element' => '[data-tour="rops-cc-date-from"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a lease-end date - or press Skip this step.'],
                'title'   => 'Filter by lease end',
                'body'    => 'Lease end from and to show only properties whose lease finishes in that window - useful for planning renewals. Press Filter to apply.',
            ],
            [
                'element' => '[data-tour="rops-cc-list"]',
                'section' => 'Working a property',
                'title'   => 'The property list',
                'body'    => 'Each row is a rental property with its status, tenant, agent, lease end, open faults (F) and work orders (WO), and when it was last inspected. Click a heading to sort.',
            ],
            [
                'element' => '[data-tour="rops-cc-row-actions"]',
                'do'      => ['action' => 'click', 'say' => 'Open the actions menu on a property.'],
                'title'   => 'Row actions',
                'body'    => 'The actions menu on each row jumps straight to the lease, starts a renewal, records a notice, or logs a fault, work order or inspection for that property.',
            ],
            [
                'element' => '[data-tour="rops-cc-print"]',
                'title'   => 'Print',
                'body'    => 'Prints the property list exactly as filtered on screen. The Reports button next to it opens the full set of rental reports.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // RENTAL TAKE-ON IMPORT
    // ══════════════════════════════════════════════════════════════════════════
    'rops-import' => [
        'key'         => 'rops-import',
        'title'       => 'Importing an existing rental book',
        'description' => 'Bring your current rentals into CoreX from a spreadsheet - download the template, upload, review and confirm.',
        'route'       => 'corex.rentals.take-on-import.index',
        'permission'  => 'rentals_take_on_import.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-imp-intro"]',
                'section' => 'How the import works',
                'title'   => 'Take-on import',
                'body'    => 'Use this once, when you move onto CoreX: it brings in the properties, tenants and leases you already manage. You upload a spreadsheet, check every row, and only then confirm. Nothing is created - and no email or message is sent to anyone - until you confirm.',
            ],
            [
                'element' => '[data-tour="rops-imp-upload"]',
                'title'   => 'Upload a take-on book',
                'body'    => 'This box is where an import starts. Download the template, fill in one row per tenancy, then upload it.',
            ],
            [
                'element' => '[data-tour="rops-imp-template"]',
                'do'      => ['action' => 'click', 'say' => 'Click Download template.'],
                'title'   => 'Download the template',
                'body'    => 'A spreadsheet with exactly the columns CoreX expects. Fill in one row per tenancy - property, landlord, tenant, rent, deposit and dates.',
            ],
            [
                'element' => '[data-tour="rops-imp-mappings"]',
                'title'   => 'Saved column mappings',
                'body'    => 'Using a file from another system? If its columns do not match the template you map them on the next screen, and can save that mapping. Saved mappings are listed here for next time.',
            ],
            [
                'element' => '[data-tour="rops-imp-file"]',
                'title'   => 'Choose your file',
                'body'    => 'Pick the filled-in spreadsheet (.xlsx or .csv) from your computer.',
            ],
            [
                'element'       => '[data-tour="rops-imp-submit"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Choose your file, then click Upload & preview.'],
                'title'         => 'Upload and preview',
                'body'          => 'Reads the file and shows every row for checking. Nothing has been created yet.',
            ],
            [
                'element' => '[data-tour="rops-imp-search"]',
                'section' => 'Your past imports',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a file name or uploader, then press Enter.'],
                'title'   => 'Search past imports',
                'body'    => 'Every import you have run is kept as a batch. Search by file name or by who uploaded it.',
            ],
            [
                'element' => '[data-tour="rops-imp-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status - or press Skip this step.'],
                'title'   => 'Filter by status',
                'body'    => 'See only batches that are waiting for your review, still need columns mapped, finished, failed or were cancelled.',
            ],
            [
                'element' => '[data-tour="rops-imp-list"]',
                'do'      => ['action' => 'click', 'say' => 'Open a batch if you have one - or press Skip this step.', 'target' => 'a'],
                'title'   => 'Your batches',
                'body'    => 'Map columns, Review or View takes you to the next thing to do for that batch. A finished batch can be archived, which also archives anything it created that nobody has edited since.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // RENTAL REPORTS
    // ══════════════════════════════════════════════════════════════════════════
    'rops-reports' => [
        'key'         => 'rops-reports',
        'title'       => 'Running a rental report',
        'description' => 'Pick a report, narrow it by period, scope and search, then print it, save it as PDF or export it.',
        'route'       => 'corex.rentals.reports.index',
        'permission'  => 'rental_reports.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-rep-intro"]',
                'section' => 'Choosing a report',
                'title'   => 'Rental reports',
                'body'    => 'Every rental report works the same way: pick the report, narrow it down, then print or export it.',
            ],
            [
                'element' => '[data-tour="rops-rep-picker"]',
                'do'      => ['action' => 'click', 'say' => 'Click a report in the list.', 'target' => 'a'],
                'title'   => 'Pick a report',
                'body'    => 'The reports are listed down the left - for example lease status, lease expiries, faults, work orders, job cards and inspections. The one in bold is on screen. Property history opens a separate view of one property\'s whole story.',
            ],
            [
                'element' => '[data-tour="rops-rep-period"]',
                'section' => 'Narrowing the report',
                'do'      => ['action' => 'choose', 'say' => 'Pick a period.'],
                'title'   => 'Period',
                'body'    => 'The time window the report covers. Forward-looking reports such as lease expiries look ahead; the rest look back. Choose Custom range to set exact From and To dates.',
            ],
            [
                'element' => '[data-tour="rops-rep-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a word to look for - or press Skip this step.'],
                'title'   => 'Search',
                'body'    => 'Narrows the report to rows that mention what you type - a property, a person, a supplier. Press Enter or Filter to apply.',
            ],
            [
                'element' => '[data-tour="rops-rep-group"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how to group the rows - or press Skip this step.'],
                'title'   => 'Group by',
                'body'    => 'Gather the rows under headings such as property, agent or supplier, with a count for each. Which groupings are offered depends on the report.',
            ],
            [
                'element' => '[data-tour="rops-rep-filter-btn"]',
                'title'   => 'Apply or clear',
                'body'    => 'Filter applies everything you chose. Clear puts the report back to its starting view. The badge on the right counts the rows you have.',
            ],
            [
                'element' => '[data-tour="rops-rep-grid"]',
                'section' => 'Reading the results',
                'title'   => 'The results',
                'body'    => 'The rows for the report you chose. Click a column heading to sort by it. Money columns show in rand, and a totals line appears at the bottom where adding up makes sense.',
            ],
            [
                'element' => '[data-tour="rops-rep-print"]',
                'section' => 'Sharing the report',
                'title'   => 'Print',
                'body'    => 'Opens a print-ready page of the report exactly as it is filtered now.',
            ],
            [
                'element' => '[data-tour="rops-rep-pdf"]',
                'title'   => 'PDF',
                'body'    => 'Downloads the same report as a PDF you can email to a landlord or keep on file.',
            ],
            [
                'element' => '[data-tour="rops-rep-export"]',
                'do'      => ['action' => 'click', 'say' => 'Click Export XLSX to download the rows.'],
                'title'   => 'Export',
                'body'    => 'Downloads every row as a spreadsheet (Excel, or CSV from the button next to it) so you can sort and add it up yourself.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // RENTAL NOTICES
    // ══════════════════════════════════════════════════════════════════════════

    // ── Notices sent ─────────────────────────────────────────────────────────
    'rops-notices-sent' => [
        'key'         => 'rops-notices-sent',
        'title'       => 'Finding a notice you sent',
        'description' => 'Every notice sent to a tenant or landlord - what it was, who got it and when.',
        'route'       => 'corex.rental-notices.index',
        'permission'  => 'rental_notices.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-ns-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Notices sent',
                'body'    => 'A record of every notice your agency has sent to a tenant or a landlord. Notices are sent from the lease; this is where you look them up afterwards.',
            ],
            [
                'element' => '[data-tour="rops-ns-search"]',
                'section' => 'Finding a notice',
                'do'      => ['action' => 'fill', 'say' => 'Type a tenant name or part of an address, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Search by the tenant\'s name or by the property address.',
            ],
            [
                'element' => '[data-tour="rops-ns-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a notice type.'],
                'title'   => 'Filter by type',
                'body'    => 'Show only one kind of notice - for example a breach notice or a notice to vacate. The types come from your agency\'s notice templates.',
            ],
            [
                'element' => '[data-tour="rops-ns-filter-btn"]',
                'title'   => 'Apply the filters',
                'body'    => 'Press Filter to apply the search, type and the From and To dates. The badge on the right counts the notices you have.',
            ],
            [
                'element' => '[data-tour="rops-ns-list"]',
                'section' => 'Opening a notice',
                'do'      => ['action' => 'click', 'say' => 'Click View on a notice.', 'target' => '[data-tour="rops-ns-open"]'],
                'title'   => 'The notices',
                'body'    => 'Each row shows when it was sent, the property, the type and whether it went to the tenant, the landlord or both. View opens the notice itself and the document that was sent.',
            ],
        ],
    ],

    // ── Notices received ─────────────────────────────────────────────────────
    'rops-notices-received' => [
        'key'         => 'rops-notices-received',
        'title'       => 'Reviewing notices received',
        'description' => 'Notices given by a tenant or a landlord - review each one against the lease before anything goes to the other party.',
        'route'       => 'corex.lease-notices.index',
        'permission'  => 'rental_notices.review',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-nr-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Notices received',
                'body'    => 'When a tenant or a landlord gives notice - from their portal, or recorded on the lease by an agent - it lands here. Check each one against the lease and the law before anything is sent on to the other party.',
            ],
            [
                'element' => '[data-tour="rops-nr-search"]',
                'section' => 'Finding a notice',
                'do'      => ['action' => 'fill', 'say' => 'Type a name, address or reason, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Search by the tenant or owner\'s name, the property address or the reason given.',
            ],
            [
                'element' => '[data-tour="rops-nr-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status.'],
                'title'   => 'Filter by status',
                'body'    => 'It starts on the notices still waiting for review. Choose another status to see notices that have been accepted or dealt with.',
            ],
            [
                'element' => '[data-tour="rops-nr-given-by"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick who gave the notice - or press Skip this step.'],
                'title'   => 'Given by',
                'body'    => 'Show only notices given by a tenant, or only those given by a landlord.',
            ],
            [
                'element' => '[data-tour="rops-nr-filter-btn"]',
                'title'   => 'Apply or reset',
                'body'    => 'Filter applies your choices, including the date range and the Archived box. Reset goes back to the notices waiting for review.',
            ],
            [
                'element' => '[data-tour="rops-nr-list"]',
                'section' => 'Reviewing a notice',
                'do'      => ['action' => 'click', 'say' => 'Click Review on a notice.', 'target' => '[data-tour="rops-nr-open"]'],
                'title'   => 'The notices',
                'body'    => 'Each row shows the date it was given, the property, who gave it, the date the tenancy would end and its status. Review opens the notice, where you check the dates and accept it.',
            ],
        ],
    ],

    // ── Breach register ──────────────────────────────────────────────────────
    'rops-breaches' => [
        'key'         => 'rops-breaches',
        'title'       => 'Tracking breach notices',
        'description' => 'The breach register - every breach notice and notice to vacate, and whether each has been put right.',
        'route'       => 'corex.breaches.index',
        'permission'  => 'rental_breaches.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-br-intro"]',
                'section' => 'Reading the register',
                'title'   => 'The breach register',
                'body'    => 'Every breach notice and notice to vacate issued on a lease, and where each one stands. A red row is a breach whose remedy-by date has passed with nothing put right - ready for the next step.',
            ],
            [
                'element' => '[data-tour="rops-br-choose"]',
                'title'   => 'Issue breach notices',
                'body'    => 'Opens the Leases list so you can tick the running leases that are in breach and issue notices for all of them together. You can also use the Breach notice button on a single lease.',
            ],
            [
                'element' => '[data-tour="rops-br-tiles"]',
                'do'      => ['action' => 'click', 'say' => 'Click a tile to see only breaches in that stage.', 'target' => 'a'],
                'title'   => 'The stage tiles',
                'body'    => 'Each tile counts the breaches in one stage. Click one to filter the list to it, and click it again to clear. Show archived reveals breaches that were archived.',
            ],
            [
                'element' => '[data-tour="rops-br-search"]',
                'section' => 'Finding a breach',
                'do'      => ['action' => 'fill', 'say' => 'Type a name, address, clause or reason, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Search by the tenant or landlord\'s name, the property address, the lease clause or the reason given.',
            ],
            [
                'element' => '[data-tour="rops-br-kind"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a kind - or press Skip this step.'],
                'title'   => 'Kind of breach',
                'body'    => 'Payment breaches are about money owing. Other breaches cover everything else the lease requires.',
            ],
            [
                'element' => '[data-tour="rops-br-party"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick who is in breach - or press Skip this step.'],
                'title'   => 'Who is in breach',
                'body'    => 'Show only breaches by a tenant, or only those by a landlord.',
            ],
            [
                'element' => '[data-tour="rops-br-filter-btn"]',
                'title'   => 'Apply or reset',
                'body'    => 'Filter applies your choices and the issued-date range. Reset clears them.',
            ],
            [
                'element' => '[data-tour="rops-br-list"]',
                'section' => 'Opening a breach',
                'do'      => ['action' => 'click', 'say' => 'Click Open on a breach.', 'target' => '[data-tour="rops-br-open"]'],
                'title'   => 'The breaches',
                'body'    => 'Each row shows when it was issued, the property, who is in breach, what for, the amount, the remedy-by date and the status. Open shows the notice and lets you record that it was put right or send a notice to vacate.',
            ],
        ],
    ],

    // ── Send a notice (from a lease) ─────────────────────────────────────────
    'rops-notice-send' => [
        'key'         => 'rops-notice-send',
        'title'       => 'Sending a notice on a lease',
        'description' => 'Choose a notice template, fill in the figures, preview it and send it to the tenant, the landlord or both.',
        'route'       => 'corex.leases.notices.create',
        'permission'  => 'rental_notices.create',
        'pick_from'   => 'corex.leases.index',
        'pick_note'   => 'Open the lease you want to send a notice on, then press Send notice - the guide starts once the form opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-nc-intro"]',
                'section' => 'Choosing the notice',
                'title'   => 'Send a notice',
                'body'    => 'Notices are your agency\'s own documents. You pick a template, fill in the figures that apply, check the preview and send. The property this lease is for is shown in the heading. If the page says your agency has no notice templates yet, an administrator adds them first.',
            ],
            [
                'element' => '[data-tour="rops-nc-template"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the notice template.'],
                'title'   => 'The template',
                'body'    => 'Required. Each template is one of your agency\'s notices - for example a breach notice or a notice to vacate. The type is shown in brackets.',
            ],
            [
                'element' => '[data-tour="rops-nc-figures"]',
                'section' => 'Filling it in',
                'do'      => ['action' => 'fill', 'say' => 'Type any figures the template needs, one per line.'],
                'title'   => 'The figures',
                'body'    => 'Only fill in what your template asks for - for example an amount owing or a date to vacate by. Write each one on its own line as name=value, using the name exactly as it appears in the template. Everything else comes from the lease automatically.',
            ],
            [
                'element' => '[data-tour="rops-nc-recipients"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose who receives the notice.'],
                'title'   => 'Who receives it',
                'body'    => 'Tick the tenant, the landlord or both. The notice goes by email and is kept in the lease\'s tenancy log.',
            ],
            [
                'element' => '[data-tour="rops-nc-preview"]',
                'section' => 'Checking and sending',
                'title'   => 'Preview first',
                'body'    => 'Preview shows the finished notice on this page without sending anything. If a figure is still missing it tells you which one.',
            ],
            [
                'element'       => '[data-tour="rops-nc-send"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Send notice when you are happy with the preview.'],
                'title'         => 'Send the notice',
                'body'          => 'CoreX asks you to confirm, then sends the notice and records it against the lease.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // RENTALS (umbrella) — who may capture rentals
    // ══════════════════════════════════════════════════════════════════════════
    'rops-permissions' => [
        'key'         => 'rops-permissions',
        'title'       => 'Choosing who can capture rentals',
        'description' => 'The Rental Permissions screen - tick the users who may capture rentals, then save.',
        'route'       => 'rentals.permissions',
        'permission'  => 'manage_rentals',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-perm-intro"]',
                'section' => 'What this screen is for',
                'title'   => 'Rental permissions',
                'body'    => 'Not everyone in the office needs to capture rentals. Here you choose, user by user, who is allowed to.',
            ],
            [
                'element' => '[data-tour="rops-perm-table"]',
                'title'   => 'Your users',
                'body'    => 'Every user is listed with a tick box in the Can Capture Rentals column. A ticked box means that person may capture rentals.',
            ],
            [
                'element' => '[data-tour="rops-perm-check"]',
                'section' => 'Changing who can capture',
                'do'      => ['action' => 'choose', 'say' => 'Tick or untick a user.'],
                'title'   => 'Tick a user',
                'body'    => 'Tick the people who should be able to capture rentals and untick the rest. Nothing changes until you save.',
            ],
            [
                'element'       => '[data-tour="rops-perm-save"]',
                'do'            => ['action' => 'click', 'say' => 'Click Save Permissions.'],
                'title'         => 'Save Permissions',
                'body'          => 'Saves the ticks for everyone on the list at once. Anyone you left unticked can no longer capture rentals.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // ROUND 2 - SIDE PAGES (AT-478)
    // Rentals hub (the shared Contacts / Properties / Pipeline / Core Matches screens seen
    // through the rental lens), the lease side pages, notices and breaches, the application
    // authorisation + review screens, take-on import and the property history report.
    // ══════════════════════════════════════════════════════════════════════════

    // ── Rentals hub: Contacts ────────────────────────────────────────────────
    'rops-hub-contacts' => [
        'key'         => 'rops-hub-contacts',
        'title'       => 'Finding and adding rental contacts',
        'description' => 'Rentals - Contacts: tenants, people looking to rent and landlords - search the list and add a new one.',
        'route'       => 'corex.rentals.contacts.index',
        'permission'  => 'access_contacts',
        'feature'     => 'rentals',
        // The capture panel is collapsed by default - open it so each box has something to point at.
        'setup'       => [
            ['action' => 'alpineSet', 'selector' => '[data-tour-root="contacts"]', 'prop' => 'showAdd', 'value' => true],
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="contact-search"]',
                'section' => 'Finding a rental contact',
                'do'      => ['action' => 'fill', 'say' => 'Type a name, phone number or email, then press Enter.'],
                'title'   => 'Search rental contacts',
                'body'    => 'This is your Contacts list seen through a rental lens: tenants, people who want to rent and landlords - even when the same person is also a buyer or a seller. Type a name, phone number or email to find someone fast.',
            ],
            [
                'element' => '[data-tour="contact-street-search"]',
                'title'   => 'Search by street or complex',
                'body'    => 'Know where someone lives or rents but not their name? Open this to search by street or complex name and see who is linked to it.',
            ],
            [
                'element' => '[data-tour="contact-add-btn"]',
                'section' => 'Adding a person',
                'do'      => ['action' => 'click', 'say' => 'Click Add Contact to open the capture panel.', 'until' => '[data-tour="contact-form"]'],
                'skip_if' => '[data-tour="contact-first-name"]',
                'title'   => 'Add a contact',
                'body'    => 'Capture a new tenant, applicant or landlord here. The button opens the capture panel - it is already open for you so you can see each box.',
            ],
            [
                'element' => '[data-tour="contact-first-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the first name.'],
                'title'   => 'First name',
                'body'    => 'Required. Use the name as it appears on their ID - it flows into the lease and the rental application.',
            ],
            [
                'element' => '[data-tour="contact-last-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the surname.'],
                'title'   => 'Surname',
                'body'    => 'Required. Together with the first name it becomes the person\'s name on every lease, notice and application.',
            ],
            [
                'element' => '[data-tour="contact-phone"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the phone number, then click or tab out of the box.'],
                'title'   => 'Phone number',
                'body'    => 'Required. When you leave the box CoreX checks whether you already have a contact with this number, so you never create a duplicate.',
            ],
            [
                'element' => '[data-tour="contact-type"]',
                'section' => 'Making them a tenant or landlord',
                'do'      => ['action' => 'choose', 'say' => 'Tick Tenant or Landlord (or both).'],
                'title'   => 'Contact type',
                'body'    => 'Tick Tenant or Landlord and the person shows up in this rental list. Someone who both owns a rental and rents another simply gets both ticks.',
            ],
            [
                'element'       => '[data-tour="contact-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save to create the contact.'],
                'title'         => 'Save the contact',
                'body'          => 'Saves the person and opens their record, where you can add documents, FICA details and rental applications.',
            ],
        ],
    ],

    // ── Rentals hub: Pipeline ────────────────────────────────────────────────
    'rops-hub-pipeline' => [
        'key'         => 'rops-hub-pipeline',
        'title'       => 'Working your rental pipeline',
        'description' => 'Rentals - Pipeline: tenant enquiries from New to Warm, Cold or Lost, for your own, your branch or the whole agency.',
        'route'       => 'corex.rentals.pipeline.index',
        'permission'  => 'buyer_pipeline.view',
        'feature'     => 'rentals',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="buyers-intro"]',
                'section' => 'Rental enquiries',
                'title'   => 'Your rental pipeline',
                'body'    => 'Every tenant lead you are working, grouped by how warm it is: New, Warm, Cold or Lost. Leads land here when you capture what a tenant is looking for on their contact, so the board fills itself as you work. This entry point shows rental leads only - sales buyers are worked from the main Buyer Pipeline.',
            ],
            [
                'element' => '[data-tour="buyers-scope"]',
                'do'      => ['action' => 'click', 'say' => 'Click Mine, Branch or All to choose whose leads you see.'],
                'title'   => 'Whose leads?',
                'body'    => 'Switch between your own leads, your branch, or everyone (where you are allowed to see them). Start on Mine - that is your own list to action.',
            ],
            [
                'element' => '[data-tour="buyers-view"]',
                'section' => 'Board or list',
                'do'      => ['action' => 'click', 'say' => 'Click Kanban or List to choose how the leads are shown.'],
                'title'   => 'Board or list',
                'body'    => 'Kanban shows leads as cards you can drag between stages; List is a compact table. Use whichever you prefer.',
            ],
            [
                'element'       => '[data-tour="buyers-search"]',
                'section'       => 'Finding a lead',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type a name, phone number or email.'],
                'title'         => 'Search your leads',
                'body'          => 'Looking for one person? Search by name, phone or email. The boxes next to it narrow the list by state, agent or date.',
            ],
            [
                'element'       => '[data-tour="buyers-search-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Search.'],
                'title'         => 'Run the search',
                'body'          => 'The board reloads showing only the leads that match.',
            ],
            [
                'element'       => '[data-tour="buyers-board"]',
                'section'       => 'Working a lead',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click a card to open that lead.'],
                'title'         => 'Open a lead',
                'body'          => 'Each card is one tenant lead. Drag it to another column to move it along (Lost asks for a reason first), or click it to open everything about them.',
            ],
            [
                'element'       => '[data-tour="buyers-list"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click a name to open that lead.'],
                'title'         => 'Open a lead',
                'body'          => 'Each row is one tenant lead. Click the name to open everything about them - or View Matches to see the rentals that fit.',
            ],
        ],
    ],

    // ── Rentals hub: Properties ──────────────────────────────────────────────
    'rops-hub-properties' => [
        'key'         => 'rops-hub-properties',
        'title'       => 'Finding your rental properties',
        'description' => 'Rentals - Properties: search and filter the properties you let, and read what is Available and what is Rented Out.',
        'route'       => 'corex.rentals.properties.index',
        'permission'  => 'access_properties',
        'feature'     => 'rentals',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="re-properties-intro"]',
                'section' => 'Reading your rental stock',
                'title'   => 'The properties you let',
                'body'    => 'The Properties list locked to rentals - only properties let (or to let) show here. Use the New Property button at the top to add one; this tour is about finding the ones already here.',
            ],
            [
                'element' => '[data-tour="re-properties-kpis"]',
                'do'      => ['action' => 'click', 'say' => 'Click a tile to show only those properties.'],
                'title'   => 'The numbers at a glance',
                'body'    => 'A quick count of your rental stock, including how many are Available and how many are Rented Out. Click a tile to filter the list down to just those.',
            ],
            [
                'element' => '[data-tour="re-properties-search"]',
                'section' => 'Filtering the list',
                'do'      => ['action' => 'fill', 'say' => 'Type a suburb, property name or reference, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type a title, a suburb or a reference number to jump straight to a property.',
            ],
            [
                'element' => '[data-tour="re-properties-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status to narrow the list.'],
                'title'   => 'Filter by status',
                'body'    => 'Narrow the list to one status - for example only the properties that are Available to let.',
            ],
            [
                'element'       => '[data-tour="re-properties-sort"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick how you want the list sorted.'],
                'title'         => 'Sort the list',
                'body'          => 'Put the newest, oldest, dearest or cheapest at the top - whichever gets you to the property you want fastest.',
            ],
            [
                'element' => '[data-tour="re-properties-viewtoggle"]',
                'section' => 'Opening a property',
                'do'      => ['action' => 'choose', 'say' => 'Click grid or list to switch the view.'],
                'title'   => 'Grid or list',
                'body'    => 'Switch between picture cards (grid) and a compact table (list). CoreX remembers which you prefer.',
            ],
            [
                'element' => '[data-tour="re-properties-list"]',
                'do'      => ['action' => 'click', 'say' => 'Click a property to open it - it opens in a new tab.'],
                'skip_if' => '[data-tour="re-properties-table"]',
                'title'   => 'Open a property',
                'body'    => 'Each card is one property. Click it to open the full record - photos, rent, the owner, documents and its rental details.',
            ],
            [
                'element'       => '[data-tour="re-properties-table"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click a property to open it - it opens in a new tab.'],
                'skip_if' => '[data-tour="re-properties-list"]',
                'title'   => 'Open a property',
                'body'    => 'In list view each row is one property. Click its photo or name to open the full record.',
            ],
        ],
    ],

    // ── Rentals hub: Core Matches ────────────────────────────────────────────
    'rops-hub-matches' => [
        'key'         => 'rops-hub-matches',
        'title'       => 'Matching tenants to rentals',
        'description' => 'Rentals - Core Matches: see every saved tenant search and how many rentals fit it right now.',
        'route'       => 'corex.rentals.core-matches.index',
        // The agency-wide variant is the same screen with a wider scope.
        'also_on'     => ['corex.rentals.core-matches.all'],
        'permission'  => 'core_matches.view',
        'feature'     => 'rentals',
        'setup'       => [
            ['action' => 'scrollTop'],
            ['action' => 'click', 'selector' => '[data-tour="re-core-matches-card"] summary'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="re-core-matches-intro"]',
                'section' => 'Finding a tenant',
                'title'   => 'Tenants, matched to rentals',
                'body'    => 'When you save what a tenant is looking for on their contact, it appears here as a saved search. CoreX then watches your rentals and tells you which ones fit, so you stop hunting by hand. This entry point only shows rental searches.',
            ],
            [
                'element' => '[data-tour="re-core-matches-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a name, phone number or email.'],
                'title'   => 'Find a tenant',
                'body'    => 'Search by name, phone or email to jump straight to one person\'s saved searches.',
            ],
            [
                'element' => '[data-tour="re-core-matches-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status, like Active.'],
                'title'   => 'Filter by status',
                'body'    => 'Active searches are still being matched to new rentals. Paused, Fulfilled and Expired ones stay on record for later.',
            ],
            [
                'element' => '[data-tour="re-core-matches-apply"]',
                'do'      => ['action' => 'click', 'say' => 'Click Apply.'],
                'title'   => 'Apply the search',
                'body'    => 'The list narrows to the tenants that match. Clear takes you back to everyone.',
            ],
            [
                'element' => '[data-tour="re-core-matches-card"]',
                'section' => 'Reading the matches',
                'title'   => 'One tenant, their searches',
                'body'    => 'Each block is a tenant or prospective tenant, with every search saved for them underneath - rent range, suburb, bedrooms and so on.',
            ],
            [
                'element' => '[data-tour="re-core-matches-counts"]',
                'title'   => 'What "visible" and "hidden" mean',
                'body'    => 'The chips count the rentals that fit. "Visible" ones can be shown to the tenant; "hidden" ones are matches you have chosen to keep back from them.',
            ],
            [
                'element' => '[data-tour="re-core-matches-view"]',
                'section' => 'Sending matches',
                'do'      => ['action' => 'click', 'say' => 'Click View Matches to see the rentals that fit.'],
                'title'   => 'Open the matches',
                'body'    => 'Shows the actual rentals that fit this search, ready to send to the tenant.',
            ],
        ],
    ],

    // ── Rental settings: Document types ──────────────────────────────────────
    'rops-doc-types' => [
        'key'         => 'rops-doc-types',
        'title'       => 'Setting up rental document types',
        'description' => 'The kinds of document your agency files against a lease or a rental - rename, switch off or add your own.',
        'route'       => 'rental.settings.document-types.index',
        'permission'  => 'access_settings',
        'feature'     => 'rentals',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-dt-intro"]',
                'section' => 'What document types are',
                'title'   => 'Rental document types',
                'body'    => 'The kinds of document your agency files against a lease or a rental - for example a signed lease or a deposit receipt. Picking the right type when a document is uploaded keeps every lease file tidy and easy to search.',
            ],
            [
                'element' => '[data-tour="rops-dt-list"]',
                'title'   => 'Your types',
                'body'    => 'Each row is one type. The coloured dot is how it shows up, "(system)" types come with CoreX, and a "Lease tracking" tag marks a type that counts as the lease document itself.',
            ],
            [
                'element' => '[data-tour="rops-dt-edit"]',
                'section' => 'Changing a type',
                'do'      => ['action' => 'click', 'say' => 'Click Edit on a type.'],
                'title'   => 'Edit',
                'body'    => 'Opens a small form under the row where you can change the name, the colour and whether it is a lease-tracking type, then Save.',
            ],
            [
                'element' => '[data-tour="rops-dt-toggle"]',
                'title'   => 'Deactivate or activate',
                'body'    => 'Switch off a type you no longer use so it stops being offered when documents are uploaded. Nothing is deleted - Activate brings it back. System types cannot be switched off.',
            ],
            [
                'element' => '[data-tour="rops-dt-add"]',
                'section' => 'Adding a type',
                'do'      => ['action' => 'click', 'say' => 'Click + Add Document Type.', 'until' => '[data-tour="rops-dt-add-form"]'],
                'skip_if' => '[data-tour="rops-dt-add-form"]',
                'title'   => 'Add a type',
                'body'    => 'Opens the form for a new document type of your own.',
            ],
            [
                'element' => '[data-tour="rops-dt-add-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a name for the new type.'],
                'title'   => 'Name',
                'body'    => 'Required. Use the words your team uses, for example Deposit Receipt.',
            ],
            [
                'element' => '[data-tour="rops-dt-add-lease"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick this if it is a lease document - or press Skip this step.'],
                'title'   => 'Lease tracking',
                'body'    => 'Tick this when documents of this type are the lease itself, so CoreX tracks them as part of the lease.',
            ],
            [
                'element'       => '[data-tour="rops-dt-add-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Add to save the new type.'],
                'title'         => 'Add it',
                'body'          => 'Saves the type. It is offered from the next upload on.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // LEASE SIDE PAGES
    // ══════════════════════════════════════════════════════════════════════════

    // ── Confirm lease details (agreement vs lease) ───────────────────────────
    'rops-lease-agreement' => [
        'key'         => 'rops-lease-agreement',
        'title'       => 'Confirming the lease against its agreement',
        'description' => 'Check the lease agreement against the lease record before it is approved or the lease goes active.',
        'route'       => 'corex.leases.agreement.confirm',
        'permission'  => 'leases.view',
        'feature'     => 'rental-leases',
        'pick_from'   => 'corex.leases.index',
        'pick_note'   => 'Open the lease whose agreement you are checking, then choose Confirm lease details on it - the guide starts once that screen opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-ag-intro"]',
                'section' => 'Why you are here',
                'title'   => 'Confirm lease details',
                'body'    => 'The signed lease agreement and the lease record in CoreX should say the same thing. This screen sets them side by side before the agreement is approved or the lease goes active. Leaving the screen changes nothing on the lease.',
            ],
            [
                'element' => '[data-tour="rops-ag-verdict"]',
                'title'   => 'What CoreX found',
                'body'    => 'Either "the agreement and the lease agree", or a warning that the agreement was changed while it was being signed and some values need your eye.',
            ],
            [
                'element' => '[data-tour="rops-ag-rows"]',
                'section' => 'Checking the details',
                'title'   => 'Every compared value',
                'body'    => 'Each detail is shown as the agreement prints it - the rent, the dates, who signs and so on, in groups. Plain boxes agree with the lease. Highlighted ones differ, and show what the lease says beside what the agreement says.',
            ],
            [
                'element' => '[data-tour="rops-ag-differs"]',
                'title'   => 'A value that needs checking',
                'body'    => 'Read both versions. Confirming at the bottom accepts the agreement\'s value for the lease. If the lease is the right one, go back to the agreement and fix it before you approve.',
            ],
            [
                'element' => '[data-tour="rops-ag-typed"]',
                'do'      => ['action' => 'fill', 'say' => 'Type what the agreement says in this box.'],
                'title'   => 'A value CoreX could not read',
                'body'    => 'When the agreement shows something CoreX cannot read as a figure or a date, type exactly what the agreement says. CoreX then compares your entry with the lease.',
            ],
            [
                'element' => '[data-tour="rops-ag-blocked"]',
                'title'   => 'A different person',
                'body'    => 'If the agreement names a different tenant or landlord, it cannot be confirmed - a change of person is a new lease. Correct the contact if their details are wrong, or go back to the agreement.',
            ],
            [
                'element' => '[data-tour="rops-ag-wording"]',
                'title'   => 'Changes to the wording',
                'body'    => 'Wording changed inside the agreement is shown here for you to read - struck through is the old text, then the new, with who changed it and when. These are not compared with the lease, so read them and change the lease yourself if it matters.',
            ],
            [
                'element' => '[data-tour="rops-ag-actions"]',
                'section' => 'Confirming',
                'title'   => 'Back, or confirm',
                'body'    => 'Back to the agreement leaves everything as it is. The main button depends on where the lease is: Approve and finalise when nothing differs, Confirm lease details and approve once you have checked the differences, or Confirm and activate for a signed agreement waiting to go live. It stays off while a different person is named, and while the agreement is still out for signing there is nothing to confirm yet.',
            ],
            [
                'element'       => '[data-tour="rops-ag-confirm"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the button once every value is right.'],
                'title'         => 'Confirm',
                'body'          => 'Records your confirmation and moves the agreement on.',
            ],
        ],
    ],

    // ── Breach notice for one lease ──────────────────────────────────────────
    'rops-lease-breach-new' => [
        'key'         => 'rops-lease-breach-new',
        'title'       => 'Sending a breach notice',
        'description' => 'Choose a notice template, fill in what the breach is, preview it and send it from the lease.',
        'route'       => 'corex.leases.breaches.create',
        'permission'  => 'rental_breaches.manage',
        'feature'     => 'rental-notices',
        'pick_from'   => 'corex.leases.index',
        'pick_note'   => 'Open the lease the breach is on, then choose its Breach notice button - the guide starts once the notice screen opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-bc-intro"]',
                'section' => 'Choosing the notice',
                'title'   => 'A breach notice',
                'body'    => 'Use this to send a formal breach notice on the lease - unpaid rent, or any other breach. The notice is made from your agency\'s own template and sent from your mailbox, and the other party is told. Nothing is sent until you press Send.',
            ],
            [
                'element' => '[data-tour="rops-bc-template"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the notice template.'],
                'title'   => 'Template',
                'body'    => 'A payment template asks for the amount outstanding. An ad hoc template asks who is in breach, the clause and what happened. The boxes below change to match the template you pick.',
            ],
            [
                'element' => '[data-tour="rops-bc-amount"]',
                'section' => 'The details',
                'do'      => ['action' => 'fill', 'say' => 'Type the amount outstanding in rand.'],
                'title'   => 'Amount outstanding',
                'body'    => 'For a payment breach: the rent or other amount owing, in rand. A payment breach is always against the tenant.',
            ],
            [
                'element' => '[data-tour="rops-bc-party"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick who is in breach.'],
                'title'   => 'Who is in breach',
                'body'    => 'For an ad hoc breach, choose the tenant or the landlord. The notice is addressed accordingly.',
            ],
            [
                'element' => '[data-tour="rops-bc-clause"]',
                'do'      => ['action' => 'fill', 'say' => 'Type or correct the clause that was breached.'],
                'title'   => 'Lease clause breached',
                'body'    => 'Quote the clause of the lease that was broken. It starts from the template\'s own wording - change it to the clause that was actually breached.',
            ],
            [
                'element' => '[data-tour="rops-bc-reason"]',
                'do'      => ['action' => 'fill', 'say' => 'Describe what the breach is.'],
                'title'   => 'What the breach is',
                'body'    => 'A plain description of what happened, for example that the pool has not been maintained.',
            ],
            [
                'element' => '[data-tour="rops-bc-figures"]',
                'title'   => 'Extra figures',
                'body'    => 'Only needed if your template uses extra figures of its own. Open it to see which placeholders CoreX already fills in for you.',
            ],
            [
                'element' => '[data-tour="rops-bc-preview"]',
                'section' => 'Preview and send',
                'do'      => ['action' => 'click', 'say' => 'Click Preview to see the notice before it goes.'],
                'title'   => 'Preview',
                'body'    => 'Shows the finished notice on this page and lists anything still missing. Nothing is sent.',
            ],
            [
                'element' => '[data-tour="rops-bc-send"]',
                'do'      => ['action' => 'click', 'say' => 'Click Send breach notice when you are ready.'],
                'title'   => 'Send breach notice',
                'body'    => 'Asks you to confirm, then sends the notice and records it in the breach register, where you can follow whether it is put right.',
            ],
        ],
    ],

    // ── Move-out charges / deposit deductions ────────────────────────────────
    'rops-lease-deposit' => [
        'key'         => 'rops-lease-deposit',
        'title'       => 'Reading the move-out charges',
        'description' => 'What the move-out inspection recorded as chargeable to the tenant, and how to mark it reviewed.',
        'route'       => 'corex.leases.deposit-deductions',
        'permission'  => 'rental_inspections.view',
        'feature'     => 'rental-inspections',
        'pick_from'   => 'corex.leases.index',
        'pick_note'   => 'Open the lease that has moved out, then choose its Move-out charges (or Deposit deductions) button - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-dd-intro"]',
                'section' => 'What this shows',
                'title'   => 'Move-out charges',
                'body'    => 'What the move-out inspection recorded as chargeable to the tenant. The heading follows your agency setting, so it may read Move-out charges or Deposit deductions.',
            ],
            [
                'element' => '[data-tour="rops-dd-note"]',
                'title'   => 'Read this first',
                'body'    => 'CoreX only records these items. The deposit itself is held, deducted and paid out in your accounting system, so carry the figures across there. Depending on your agency setting the list shows the items only, or the items with amounts.',
            ],
            [
                'element' => '[data-tour="rops-dd-lines"]',
                'section' => 'The charges',
                'title'   => 'The charge lines',
                'body'    => 'Each line is one item charged to the tenant: what it was, the room, who recorded it and when. A line can link to the work order raised for the repair. When amounts are on, the figure is on the right, or it says "no amount recorded".',
            ],
            [
                'element' => '[data-tour="rops-dd-total"]',
                'title'   => 'Total recorded',
                'body'    => 'The sum of every recorded amount. A warning below the list tells you if some charges still have no amount.',
            ],
            [
                'element' => '[data-tour="rops-dd-links"]',
                'section' => 'Evidence and review',
                'title'   => 'Comparisons',
                'body'    => 'Open the move-out comparison or the inventory comparison to see the evidence behind each charge, side by side with the move-in.',
            ],
            [
                'element' => '[data-tour="rops-dd-reviewed"]',
                'do'      => ['action' => 'click', 'say' => 'Click Mark as reviewed once you have checked the charges.'],
                'title'   => 'Mark as reviewed',
                'body'    => 'Records that you have checked these charges, with your name and the date. It only appears once the move-out inspection is completed.',
            ],
        ],
    ],

    // ── Renew a lease ────────────────────────────────────────────────────────
    'rops-lease-renew' => [
        'key'         => 'rops-lease-renew',
        'title'       => 'Renewing a lease',
        'description' => 'Renew an active lease for a new term - same tenants, new dates and terms.',
        'route'       => 'corex.leases.renewal.create',
        'permission'  => 'leases.renew',
        'feature'     => 'rental-leases',
        'pick_from'   => 'corex.leases.index',
        'pick_note'   => 'Open the active lease you want to renew and choose Renew - the guide starts once the renewal screen opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-rn-property"]',
                'section' => 'Who is renewing',
                'title'   => 'The property',
                'body'    => 'A renewal is done on the lease you opened, so the property is fixed. It is shown here, not chosen.',
            ],
            [
                'element' => '[data-tour="rops-rn-tenants"]',
                'title'   => 'The tenants',
                'body'    => 'A renewal keeps the same tenants. If a tenant is leaving or a new one is joining, that is a new lease, not a renewal - the button in this box starts one for the property. The current lease must be ended before the new one can be made active.',
            ],
            [
                'element' => '[data-tour="rops-lc-rent"]',
                'section' => 'The new term',
                'do'      => ['action' => 'fill', 'say' => 'Check the monthly rent in rand - change it if the rent is changing.'],
                'title'   => 'Monthly rental',
                'body'    => 'Starts from the current term. Change it if the rent goes up or down for the new term.',
            ],
            [
                'element' => '[data-tour="rops-lc-deposit"]',
                'do'      => ['action' => 'fill', 'say' => 'Check the deposit in rand - or press Skip this step.'],
                'title'   => 'Deposit',
                'body'    => 'Carried over from the current lease. Change it only if the new term needs a different figure.',
            ],
            [
                'element' => '[data-tour="rops-lc-start"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the start date of the new term.'],
                'title'   => 'Start date',
                'body'    => 'Required. The day the new term begins - normally the day after the current one ends.',
            ],
            [
                'element' => '[data-tour="rops-lc-end"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the end date - or tick Month-to-month below.'],
                'title'   => 'End date',
                'body'    => 'The day the new fixed term ends. Leave it empty only for a month-to-month lease.',
            ],
            [
                'element' => '[data-tour="rops-lc-m2m"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick this only if the lease has no fixed end date - or press Skip this step.'],
                'title'   => 'Month-to-month',
                'body'    => 'Tick this when the renewal runs from month to month. The end-date box is switched off while it is ticked.',
            ],
            [
                'element' => '[data-tour="rops-lc-agents"]',
                'section' => 'Agents and notice terms',
                'title'   => 'The two agents',
                'body'    => 'The owner\'s agent looks after the landlord and the tenant\'s agent looks after the tenant. Both are carried over from the current lease.',
            ],
            [
                'element' => '[data-tour="rops-lc-notice"]',
                'title'   => 'Notice and early cancellation',
                'body'    => 'How much notice either side must give and what happens on early cancellation. They start from the term being renewed - change them if the new term says something different.',
            ],
            [
                'element' => '[data-tour="rops-lc-activate"]',
                'section' => 'Saving the renewal',
                'do'      => ['action' => 'choose', 'say' => 'Tick this only if the renewal should run straight away - or press Skip this step.'],
                'title'   => 'Activate immediately',
                'body'    => 'Normally the renewal waits until the current lease ends and the new term starts. Tick this to make it active straight away.',
            ],
            [
                'element'       => '[data-tour="rops-lc-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Renew lease only to save the renewal.'],
                'title'         => 'Save the renewal',
                'body'          => 'Renew lease only saves the new term. If your agency has a lease agreement set up, the button next to it also prepares the agreement for signing.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // NOTICES AND BREACHES - RECORD PAGES
    // ══════════════════════════════════════════════════════════════════════════

    // ── One breach ───────────────────────────────────────────────────────────
    'rops-breach-detail' => [
        'key'         => 'rops-breach-detail',
        'title'       => 'Following up one breach',
        'description' => 'One breach notice - its deadline, its documents, and marking it remedied or escalating to a notice to vacate.',
        'route'       => 'corex.breaches.show',
        'permission'  => 'rental_breaches.view',
        'feature'     => 'rental-notices',
        'pick_from'   => 'corex.breaches.index',
        'pick_note'   => 'Open the breach you want to follow up - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-bd-head"]',
                'section' => 'Reading the breach',
                'title'   => 'The breach',
                'body'    => 'One breach notice on a lease. The badge shows where it stands - for example Issued or Remedied - and who is in breach. Open lease and Breach register take you back.',
            ],
            [
                'element' => '[data-tour="rops-bd-details"]',
                'title'   => 'The details',
                'body'    => 'When it was issued and by whom, the amount outstanding on a payment breach, the lease clause and what the breach is.',
            ],
            [
                'element' => '[data-tour="rops-bd-deadline"]',
                'title'   => 'Remedy by',
                'body'    => 'The date by which the breach must be put right, from your agency\'s remedy window. The days left count down and turn red when overdue. Once the date is reached a tenant breach can be escalated.',
            ],
            [
                'element' => '[data-tour="rops-bd-docs"]',
                'title'   => 'The documents',
                'body'    => 'The breach notice, and the notice to vacate once it has been sent, as PDFs. Both the tenant and the landlord can read them in their portal.',
            ],
            [
                'element' => '[data-tour="rops-bd-actions"]',
                'section' => 'Dealing with it',
                'title'   => 'Two ways forward',
                'body'    => 'Either the breach gets put right, or - for a tenant who has not fixed it by the deadline - you send a notice to vacate. These boxes only appear while the breach is still open.',
            ],
            [
                'element' => '[data-tour="rops-bd-remedy"]',
                'title'   => 'Breach remedied',
                'body'    => 'Use this once the breach has been put right. Set the date it was fixed and add a note if you like, for example that the arrears were paid in full. A remedied breach can no longer be escalated.',
            ],
            [
                'element'       => '[data-tour="rops-bd-remedy-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Breach remedied once it has been put right.'],
                'title'         => 'Mark it remedied',
                'body'          => 'Closes the breach and records who marked it and when.',
            ],
            [
                'element' => '[data-tour="rops-bd-vacate"]',
                'title'   => 'Notice to vacate',
                'body'    => 'Only for a tenant, and only once the remedy-by date has passed without the breach being put right. Then you pick your template, set the date and time the tenant must be out, and send it. The landlord is told.',
            ],
            [
                'element' => '[data-tour="rops-bd-archive"]',
                'section' => 'Tidying up',
                'title'   => 'Archive and restore',
                'body'    => 'Archive takes the breach off the active register but keeps it on record. Restore brings it back. Nothing is ever deleted for good.',
            ],
        ],
    ],

    // ── Bulk payment breach ──────────────────────────────────────────────────
    'rops-breach-bulk' => [
        'key'         => 'rops-breach-bulk',
        'title'       => 'Sending payment breach notices in bulk',
        'description' => 'Put an amount against each lease that is behind and send all the payment breach notices in one go.',
        'route'       => 'corex.breaches.bulk',
        'permission'  => 'rental_breaches.manage',
        'feature'     => 'rental-notices',
        'pick_from'   => 'corex.leases.index',
        'pick_note'   => 'Tick the running leases that are behind with rent, then press the breach notice button above the list - the guide starts on the next screen.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-bk-intro"]',
                'section' => 'Several leases at once',
                'title'   => 'Payment breach notices in bulk',
                'body'    => 'The leases you ticked are listed below so you can issue a payment breach notice for each one that is behind. Each notice is sent from the agent\'s own mailbox and the landlord is told.',
            ],
            [
                'element' => '[data-tour="rops-bk-howto"]',
                'title'   => 'How it works',
                'body'    => 'Fill in the amount owing on each lease. Only leases with an amount get a notice - leave the rest blank. The tenant has your agency\'s remedy window to put it right.',
            ],
            [
                'element' => '[data-tour="rops-bk-template"]',
                'section' => 'Preparing the notices',
                'do'      => ['action' => 'choose', 'say' => 'Pick the notice template.'],
                'title'   => 'Template',
                'body'    => 'Only your payment breach templates are listed here. For any other kind of breach, open the lease and use its own breach notice button.',
            ],
            [
                'element' => '[data-tour="rops-bk-sheet"]',
                'title'   => 'The leases',
                'body'    => 'One row per lease: the tenant, the property and the monthly rent. CoreX does not hold rent payments yet, so the last-paid column stays empty.',
            ],
            [
                'element' => '[data-tour="rops-bk-amount"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the amount owing in rand on at least one lease.'],
                'title'   => 'Outstanding amount',
                'body'    => 'The rent or other amount the tenant owes, in rand. A lease with no amount here gets no notice.',
            ],
            [
                'element' => '[data-tour="rops-bk-figures"]',
                'title'   => 'Extra figures',
                'body'    => 'Only needed if your template uses figures of its own. Open it to see the placeholders CoreX fills in for you.',
            ],
            [
                'element' => '[data-tour="rops-bk-send"]',
                'section' => 'Sending',
                'do'      => ['action' => 'click', 'say' => 'Click Send breach notices when every amount is right.'],
                'title'   => 'Send breach notices',
                'body'    => 'Asks you to confirm, then sends a notice for every lease that has an amount and shows you the result.',
            ],
        ],
    ],

    // ── A notice received: review ────────────────────────────────────────────
    'rops-notice-review' => [
        'key'         => 'rops-notice-review',
        'title'       => 'Reviewing a notice you received',
        'description' => 'Check a tenant\'s or landlord\'s notice against the lease and the law, then accept or reject it.',
        'route'       => 'corex.lease-notices.show',
        'permission'  => 'rental_notices.review',
        'feature'     => 'rental-notices',
        'pick_from'   => 'corex.lease-notices.index',
        'pick_note'   => 'Open the notice you want to review - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-lnd-head"]',
                'section' => 'Reading the notice',
                'title'   => 'A notice to review',
                'body'    => 'A tenant or landlord has given notice on this lease. Nothing reaches the other party until you accept it, and accepting it tells them.',
            ],
            [
                'element' => '[data-tour="rops-lnd-notice"]',
                'title'   => 'The notice',
                'body'    => 'Who gave it, whether it came from their portal or was recorded by an agent, the date given, the move-out date they asked for, their reason and any attachment.',
            ],
            [
                'element' => '[data-tour="rops-lnd-correct"]',
                'title'   => 'Correct these details',
                'body'    => 'If the notice was captured wrongly, open this to fix the date given, the end date or the reason, then save the correction.',
            ],
            [
                'element' => '[data-tour="rops-lnd-terms"]',
                'section' => 'Checking it against the lease',
                'title'   => 'What the lease says',
                'body'    => 'The lease term, its notice period, the earliest date notice may be given, whether early cancellation is allowed and any penalty. The last line says whether these terms were checked against the signed lease.',
            ],
            [
                'element' => '[data-tour="rops-lnd-flags"]',
                'title'   => 'Does it fit the lease and the law?',
                'body'    => 'CoreX compares the dates with the lease\'s notice terms and the Consumer Protection Act and lists anything to look at. A "Check" needs your attention, a "Note" is for information. Some flags show the earliest end date that fits.',
            ],
            [
                'element' => '[data-tour="rops-lnd-decision"]',
                'section' => 'Deciding',
                'title'   => 'Accept or reject',
                'body'    => 'Two choices, side by side. Accept records the move-out date on the lease and tells the other party. Reject refuses the notice and tells the person who gave it why.',
            ],
            [
                'element' => '[data-tour="rops-lnd-end-date"]',
                'title'   => 'Move-out date to record',
                'body'    => 'Starts as the date given. Change it to accept the notice with a corrected date, or use the button below the box to take the earliest date that fits.',
            ],
            [
                'element' => '[data-tour="rops-lnd-note"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a note - or press Skip this step.'],
                'title'   => 'Note',
                'body'    => 'Optional when the dates fit. It is required if you changed the date, or while there are items to check above - say why it is fine as given.',
            ],
            [
                'element'       => '[data-tour="rops-lnd-accept-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Accept notice when you are happy with it.'],
                'title'         => 'Accept the notice',
                'body'          => 'Sets the move-out date on the lease and tells the other party by email.',
            ],
            [
                'element' => '[data-tour="rops-lnd-reject"]',
                'title'   => 'Rejecting a notice',
                'body'    => 'Give a reason - it is required. The person who gave the notice is told why, by email and in their portal; the other party is told nothing.',
            ],
            [
                'element' => '[data-tour="rops-lnd-archive"]',
                'section' => 'Tidying up',
                'title'   => 'Archive and restore',
                'body'    => 'Archive takes the notice off the active list but keeps it on record. Restore brings it back.',
            ],
        ],
    ],

    // ── A notice sent: detail ────────────────────────────────────────────────
    'rops-notice-sent-detail' => [
        'key'         => 'rops-notice-sent-detail',
        'title'       => 'Looking at a notice you sent',
        'description' => 'One notice sent to a tenant or landlord - the facts and the document that went out.',
        'route'       => 'corex.rental-notices.show',
        'permission'  => 'rental_notices.view',
        'feature'     => 'rental-notices',
        'pick_from'   => 'corex.rental-notices.index',
        'pick_note'   => 'Open the notice you want to look at - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-rnd-intro"]',
                'section' => 'The notice',
                'title'   => 'A notice you sent',
                'body'    => 'The record of one notice sent from a lease. The heading is the kind of notice - for example a breach notice or a notice to vacate.',
            ],
            [
                'element' => '[data-tour="rops-rnd-details"]',
                'title'   => 'The facts',
                'body'    => 'The property it was about, when it was sent, who sent it and who received it.',
            ],
            [
                'element' => '[data-tour="rops-rnd-recipients"]',
                'title'   => 'Who got it',
                'body'    => 'Tenant, landlord or both. If someone is missing, the notice was not sent to them.',
            ],
            [
                'element' => '[data-tour="rops-rnd-download"]',
                'do'      => ['action' => 'click', 'say' => 'Click Download document to open the notice that went out.'],
                'title'   => 'The document',
                'body'    => 'The exact document that was sent, for your file or to show someone.',
            ],
            [
                'element' => '[data-tour="rops-rnd-back"]',
                'section' => 'Back to the lease',
                'title'   => 'Back to the lease',
                'body'    => 'Returns to the lease the notice was sent from. It is not shown when the lease has since been archived.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // APPLICATIONS - AUTHORISATION AND REVIEW
    // ══════════════════════════════════════════════════════════════════════════

    // ── Authorisation queue ──────────────────────────────────────────────────
    // The controller admits only a configured Reviewer or Override user (a per-agency list of named users, not a
    // permission key or a role), so the tour is offered through the same User check (`user_check`).
    'rops-auth-queue' => [
        'key'         => 'rops-auth-queue',
        'title'       => 'Working the authorisation queue',
        'description' => 'Rental applications waiting for your decision - search the queue and open one to review and decide.',
        'route'       => 'corex.rental-applications.authorisation.index',
        'user_check'  => 'seesRentalAuthorisationQueue',
        'feature'     => 'rental-applications',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-au-intro"]',
                'section' => 'Your queue',
                'title'   => 'Awaiting your decision',
                'body'    => 'Rental applications an agent has submitted for your decision, oldest first so the longest wait is at the top. This page is only for the people your agency has set as Reviewers or Override approvers.',
            ],
            [
                'element' => '[data-tour="rops-au-search"]',
                'section' => 'Finding an application',
                'do'      => ['action' => 'fill', 'say' => 'Type an applicant, property or agent, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Search by the applicant\'s name or email, the property address or the agent who submitted it.',
            ],
            [
                'element' => '[data-tour="rops-au-date-from"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a Submitted from date - or press Skip this step.'],
                'title'   => 'Submitted dates',
                'body'    => 'Use Submitted from and Submitted to to see only applications handed over in a window.',
            ],
            [
                'element' => '[data-tour="rops-au-per-page"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how many rows to show - or press Skip this step.'],
                'title'   => 'Per page',
                'body'    => 'How many applications to show on one page.',
            ],
            [
                'element' => '[data-tour="rops-au-filter-btn"]',
                'title'   => 'Apply the filters',
                'body'    => 'Filter applies the search and dates. Clear, which appears once a filter is on, removes them. The badge on the right counts the applications waiting.',
            ],
            [
                'element' => '[data-tour="rops-au-sort"]',
                'title'   => 'Sorting',
                'body'    => 'Click Contact, Property, Agent or Submitted for approval to sort. The arrow shows the direction; the default is oldest first.',
            ],
            [
                'element' => '[data-tour="rops-au-list"]',
                'section' => 'Reviewing an application',
                'do'      => ['action' => 'click', 'say' => 'Click Review & Decide on an application.', 'target' => '[data-tour="rops-au-open"]'],
                'title'   => 'The queue',
                'body'    => 'Each row is one application: the applicant, the property, the agent who submitted it and when. Review & Decide opens the file with the documents and the agent\'s figures, where you make your decision.',
            ],
        ],
    ],

    // ── Authoriser: review and decide ────────────────────────────────────────
    'rops-auth-review' => [
        'key'         => 'rops-auth-review',
        'title'       => 'Reviewing and deciding an application',
        'description' => 'Read the file - documents, the agent\'s figures and the checklist - then send back, approve or decline.',
        'route'       => 'corex.rental-applications.authorisation.show',
        // Same gate as the queue it opens from: only a configured Reviewer / Override user gets in.
        'user_check'  => 'seesRentalAuthorisationQueue',
        'feature'     => 'rental-applications',
        'pick_from'   => 'corex.rental-applications.authorisation.index',
        'pick_note'   => 'Open the application you want to decide with Review & Decide - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-rv-head"]',
                'section' => 'Reading the file',
                'title'   => 'The application',
                'body'    => 'The applicant, the property and badges such as the FICA status. The bar stays at the top as you scroll, so the way back to the list is always within reach.',
            ],
            [
                'element' => '[data-tour="rops-rv-summary"]',
                'title'   => 'Submitted application',
                'body'    => 'Opens a summary of what the applicant filled in - employer, income, who will live there and so on. Hidden by default to leave room for the documents.',
            ],
            [
                'element' => '[data-tour="rops-rv-docs"]',
                'section' => 'The paperwork',
                'title'   => 'Supporting documents',
                'body'    => 'ID, proof of income, bank statements and so on. For you this list starts open. Click a document to read it.',
            ],
            [
                'element' => '[data-tour="rops-rv-markup"]',
                'title'   => 'View & Mark Up all',
                'body'    => 'Read every document one after another and mark up what matters - highlight an amount or write a note. Marks are saved with the file for the next person.',
            ],
            [
                'element' => '[data-tour="rops-rv-finances"]',
                'section' => 'The numbers',
                'title'   => 'The agent\'s assessment',
                'body'    => 'The income and expenses the agent captured from the documents and what rent the applicant qualifies for. If you disagree with a figure, strike it out and add the right one - the struck line stays visible as a record.',
            ],
            [
                'element' => '[data-tour="rops-rv-checklist"]',
                'title'   => 'The checklist',
                'body'    => 'The agency checklist the agent worked through, item by item, with their notes. It shows how complete the file is.',
            ],
            [
                'element' => '[data-tour="rops-rv-audit"]',
                'title'   => 'Audit trail',
                'body'    => 'Everything that has happened on the application - strikes, additions, links and decisions - with who did it and when.',
            ],
            [
                'element' => '[data-tour="rops-rv-decide"]',
                'section' => 'Making the decision',
                'title'   => 'Send back, approve or decline',
                'body'    => 'Send back returns the file to the agent with your questions. Approve & continue opens a box to set the approved rent and deposit. Decline opens a box for your reasons. If you created the application yourself, another approver must decide it. Changing a decision already made is an override, and needs a reason.',
            ],
            [
                'element'       => '[data-tour="rops-rv-decide"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'When you have decided, click Send back, Approve & continue or Decline.'],
                'title'         => 'Your decision',
                'body'          => 'Each button opens a box where you confirm the decision.',
            ],
        ],
    ],

    // ── Agent: review screen ─────────────────────────────────────────────────
    'rops-app-review' => [
        'key'         => 'rops-app-review',
        'title'       => 'Reviewing an application before it goes for approval',
        'description' => 'Go through the documents, capture the figures, send back to the applicant or submit to the authoriser.',
        'route'       => 'corex.rental-applications.review',
        'permission'  => 'rental_applications.view',
        'feature'     => 'rental-applications',
        'pick_from'   => 'corex.rental-applications.index',
        'pick_note'   => 'Open the application you want to review, then choose Review - the guide starts once the review screen opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-rv-head"]',
                'section' => 'The file at a glance',
                'title'   => 'The application',
                'body'    => 'The applicant, the property and badges such as the FICA status. Use the buttons at the top to link or change the property, go back to the application or submit the file; the bar stays put as you scroll.',
            ],
            [
                'element' => '[data-tour="rops-rv-summary"]',
                'title'   => 'Submitted application',
                'body'    => 'Opens a summary of what the applicant filled in - employer, income, who will live there and so on. Hidden by default to leave room for the documents.',
            ],
            [
                'element' => '[data-tour="rops-rv-docs"]',
                'section' => 'The paperwork',
                'do'      => ['action' => 'click', 'say' => 'Click Supporting Documents to open the list.'],
                'title'   => 'Supporting documents',
                'body'    => 'ID, proof of income, bank statements and so on. A badge warns when a document still has to be split into typed, filed pieces - that must be done before the file can go for approval.',
            ],
            [
                'element' => '[data-tour="rops-rv-markup"]',
                'title'   => 'View & Mark Up all',
                'body'    => 'Read every document one after another. Highlight an amount to capture it as an income or expense line, or write a note on the document.',
            ],
            [
                'element' => '[data-tour="rops-rv-finances"]',
                'section' => 'The numbers',
                'title'   => 'Affordability assessment',
                'body'    => 'The income and expenses you capture from the documents, and the rent the applicant qualifies for. You type these - nothing is pre-filled from the application, and nothing here goes to the applicant.',
            ],
            [
                'element' => '[data-tour="rops-rv-addline"]',
                'do'      => ['action' => 'click', 'say' => 'Click Add line manually - or press Skip this step.'],
                'title'   => 'Add line manually',
                'body'    => 'For a figure with nothing on a document to highlight. Choose Income or Expense, then fill in the line.',
            ],
            [
                'element' => '[data-tour="rops-rv-checklist"]',
                'title'   => 'The checklist',
                'body'    => 'Your agency\'s checklist for this kind of application. Work through it item by item and add a note where you need to; the authoriser sees how complete the file is.',
            ],
            [
                'element' => '[data-tour="rops-rv-audit"]',
                'title'   => 'Audit trail',
                'body'    => 'Everything that has happened on the application, with who did it and when.',
            ],
            [
                'element' => '[data-tour="rops-rv-sendback"]',
                'section' => 'Moving it on',
                'title'   => 'Send back to applicant',
                'body'    => 'Use this when something is missing or wrong: ask the applicant for more information or corrections instead of going on.',
            ],
            [
                'element' => '[data-tour="rops-rv-submit"]',
                'do'      => ['action' => 'click', 'say' => 'Click Submit to authoriser when the file is complete.'],
                'title'   => 'Submit to authoriser',
                'body'    => 'Hands the file to your agency\'s authoriser for the decision. It cannot go until every supporting document has been split and filed. Once it is with the authoriser this screen becomes read-only for you; if they send it back you can work on it again.',
            ],
        ],
    ],

    // ── One sealed submission ────────────────────────────────────────────────
    'rops-app-submission' => [
        'key'         => 'rops-app-submission',
        'title'       => 'Looking at a past submission',
        'description' => 'The read-only record of exactly what an applicant submitted and signed at one point in time.',
        'route'       => 'corex.rental-applications.generations.show',
        'permission'  => 'rental_applications.view',
        'feature'     => 'rental-applications',
        'pick_from'   => 'corex.rental-applications.index',
        'pick_note'   => 'Open an application that was reopened and resubmitted, choose Review, then open Submission history and pick a submission - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-gen-head"]',
                'section' => 'Reading the record',
                'title'   => 'One submission',
                'body'    => 'When an applicant is sent back and submits again, CoreX seals each version. This is one sealed version: which submission it is, when it was signed and from which address.',
            ],
            [
                'element' => '[data-tour="rops-gen-previous"]',
                'title'   => 'An older version',
                'body'    => 'Shown only when a newer submission exists. This one is read-only history and cannot be changed.',
            ],
            [
                'element' => '[data-tour="rops-gen-fields"]',
                'title'   => 'What was submitted',
                'body'    => 'Every answer and uploaded file exactly as the applicant gave it at that moment. Later changes to the application never alter this record.',
            ],
            [
                'element' => '[data-tour="rops-gen-signatures"]',
                'title'   => 'Signatures',
                'body'    => 'The declaration and consents the applicant signed with this submission, and when they signed.',
            ],
            [
                'element' => '[data-tour="rops-gen-back"]',
                'section' => 'Going back',
                'do'      => ['action' => 'click', 'say' => 'Click Back to review.'],
                'title'   => 'Back to review',
                'body'    => 'Returns to the review screen for the current version of the application.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // TAKE-ON IMPORT - SIDE PAGES
    // ══════════════════════════════════════════════════════════════════════════

    // ── One batch ────────────────────────────────────────────────────────────
    'rops-import-batch' => [
        'key'         => 'rops-import-batch',
        'title'       => 'Reading an import batch',
        'description' => 'What one take-on import did, row by row - and how to download the problem report or archive the batch.',
        'route'       => 'corex.rentals.take-on-import.show',
        'permission'  => 'rentals_take_on_import.view',
        'feature'     => 'rental-take-on-import',
        'pick_from'   => 'corex.rentals.take-on-import.index',
        'pick_note'   => 'Open the batch you want to look at - the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-tos-intro"]',
                'section' => 'This batch',
                'title'   => 'One import batch',
                'body'    => 'The record of one spreadsheet you imported, named after the file. The buttons at the top download the issue report or go back to all batches.',
            ],
            [
                'element' => '[data-tour="rops-tos-summary"]',
                'title'   => 'Status and who ran it',
                'body'    => 'Where the batch stands, who uploaded it and when it was uploaded and completed.',
            ],
            [
                'element' => '[data-tour="rops-tos-rows"]',
                'section' => 'Row by row',
                'title'   => 'What happened to each row',
                'body'    => 'Each spreadsheet row, the property it matched or created, the lease it made and its status. Red text is an error that stopped the row, amber is a warning to look at.',
            ],
            [
                'element' => '[data-tour="rops-tos-issues"]',
                'do'      => ['action' => 'click', 'say' => 'Click Download issue report - or press Skip this step.'],
                'title'   => 'Download issue report',
                'body'    => 'A file listing every row that had a problem, so you can fix the spreadsheet and upload it again as a new batch.',
            ],
            [
                'element' => '[data-tour="rops-tos-archive"]',
                'section' => 'Undoing a batch',
                'title'   => 'Archive this batch',
                'body'    => 'Only for a completed batch. Archiving puts away everything the batch created that nobody has edited since. Nothing is deleted for good.',
            ],
        ],
    ],

    // ── Map your columns ─────────────────────────────────────────────────────
    'rops-import-map' => [
        'key'         => 'rops-import-map',
        'title'       => 'Mapping your spreadsheet columns',
        'description' => 'Tell CoreX which column of your own file holds what, and save the mapping for next time.',
        'route'       => 'corex.rentals.take-on-import.map-columns',
        'permission'  => 'rentals_take_on_import.manage',
        'feature'     => 'rental-take-on-import',
        'pick_from'   => 'corex.rentals.take-on-import.index',
        'pick_note'   => 'Upload a spreadsheet that does not use the CoreX column headings - the guide starts on the mapping screen it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-tom-intro"]',
                'section' => 'Matching your columns',
                'title'   => 'Map your columns',
                'body'    => 'The file you uploaded does not use the CoreX column headings exactly, so CoreX needs you to say which of your columns holds what. Nothing is read into the system until you continue.',
            ],
            [
                'element' => '[data-tour="rops-tom-howto"]',
                'title'   => 'Required fields',
                'body'    => 'Fields marked with * are required. Any field you leave unmapped is simply not captured for this import.',
            ],
            [
                'element' => '[data-tour="rops-tom-saved"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a saved mapping - or press Skip this step.'],
                'title'   => 'Use a saved mapping',
                'body'    => 'If you have imported from the same system before, load the mapping you saved and the columns line up by themselves. It only shows once you have saved one.',
            ],
            [
                'element' => '[data-tour="rops-tom-table"]',
                'section' => 'Matching the fields',
                'title'   => 'The mapping table',
                'body'    => 'The left column is what CoreX wants to know; the right is a list of your file\'s own columns. CoreX has already suggested the obvious matches.',
            ],
            [
                'element' => '[data-tour="rops-tom-column"]',
                'do'      => ['action' => 'choose', 'say' => 'Check or change the column picked for the first field.'],
                'title'   => 'Pick the column',
                'body'    => 'Choose the column of your file that holds this information, or "not mapped" to skip it. Do the same down the whole list.',
            ],
            [
                'element' => '[data-tour="rops-tom-save-toggle"]',
                'section' => 'Saving for next time',
                'do'      => ['action' => 'choose', 'say' => 'Tick this to save the mapping - or press Skip this step.'],
                'title'   => 'Save this mapping',
                'body'    => 'Saves the choices you made so the next file from the same system maps itself.',
            ],
            [
                'element' => '[data-tour="rops-tom-save-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a name for the mapping.'],
                'title'   => 'Mapping name',
                'body'    => 'A name you will recognise next time, for example the name of the system the file came from.',
            ],
            [
                'element' => '[data-tour="rops-tom-continue"]',
                'do'      => ['action' => 'click', 'say' => 'Click Continue to preview.'],
                'title'   => 'Continue to preview',
                'body'    => 'Reads your file using this mapping and takes you to the preview, where nothing is imported until you say so.',
            ],
        ],
    ],

    // ── Preview before importing ─────────────────────────────────────────────
    'rops-import-preview' => [
        'key'         => 'rops-import-preview',
        'title'       => 'Checking an import before it runs',
        'description' => 'Review the rows from your spreadsheet, then import them all, some of them or one at a time.',
        'route'       => 'corex.rentals.take-on-import.preview',
        'permission'  => 'rentals_take_on_import.view',
        'feature'     => 'rental-take-on-import',
        'pick_from'   => 'corex.rentals.take-on-import.index',
        'pick_note'   => 'Upload a spreadsheet, or open a batch that is waiting for review - the guide starts on the preview screen.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-top-intro"]',
                'section' => 'Reading the preview',
                'title'   => 'Review the import',
                'body'    => 'This is a dry run of your spreadsheet. Nothing has been created yet - not a property, a contact or a lease.',
            ],
            [
                'element' => '[data-tour="rops-top-counts"]',
                'title'   => 'The totals',
                'body'    => 'How many rows there are, how many properties are new and how many matched one you already have, how many leases are complete and how many are drafts that need completing, and how many rows have errors.',
            ],
            [
                'element' => '[data-tour="rops-top-note"]',
                'title'   => 'What happens next',
                'body'    => 'Tick the rows you want and press Import selected, or import one row at a time. A row with errors cannot be imported until you fix the spreadsheet and upload it again as a new batch. Importing never emails or messages a landlord or tenant.',
            ],
            [
                'element' => '[data-tour="rops-top-select-all"]',
                'section' => 'Choosing what to import',
                'do'      => ['action' => 'choose', 'say' => 'Tick Select all without errors - or tick rows one by one.'],
                'title'   => 'Select all without errors',
                'body'    => 'Ticks every row that can be imported. You can still untick any row you want to leave out.',
            ],
            [
                'element' => '[data-tour="rops-top-table"]',
                'title'   => 'The rows',
                'body'    => 'One line per spreadsheet row: the property, the landlord, the tenants and the rent, a status, and any errors (red) or warnings (amber). A Draft lease has details still to complete after it is imported.',
            ],
            [
                'element' => '[data-tour="rops-top-bulk"]',
                'do'      => ['action' => 'click', 'say' => 'Click Import selected.'],
                'title'   => 'Import selected',
                'body'    => 'Imports every ticked row in one go.',
            ],
            [
                'element' => '[data-tour="rops-top-import"]',
                'section' => 'One row at a time',
                'title'   => 'Import a single row',
                'body'    => 'Brings in just this row. The button is switched off while the row has errors.',
            ],
            [
                'element' => '[data-tour="rops-top-exclude"]',
                'title'   => 'Exclude a row',
                'body'    => 'Leaves this row out of the import. It stays in the list marked Excluded so you can see it was left out on purpose.',
            ],
        ],
    ],

    // ── Saved column mappings ────────────────────────────────────────────────
    'rops-import-mappings' => [
        'key'         => 'rops-import-mappings',
        'title'       => 'Managing saved column mappings',
        'description' => 'The column layouts you saved from earlier imports - find them, archive the ones you no longer use.',
        'route'       => 'corex.rentals.take-on-import.mappings.index',
        'permission'  => 'rentals_take_on_import.manage',
        'feature'     => 'rental-take-on-import',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-tml-intro"]',
                'section' => 'Your saved mappings',
                'title'   => 'Saved column mappings',
                'body'    => 'The column layouts you saved when importing files from another system, so the next upload from that system maps itself. You save one from the Map your columns screen.',
            ],
            [
                'element' => '[data-tour="rops-tml-search"]',
                'section' => 'Finding a mapping',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a mapping name, then press Enter.'],
                'title'   => 'Search by name',
                'body'    => 'Type part of the name you gave the mapping.',
            ],
            [
                'element' => '[data-tour="rops-tml-filter-btn"]',
                'title'   => 'Apply the search',
                'body'    => 'Filter narrows the list. The badge on the right counts the mappings you have.',
            ],
            [
                'element' => '[data-tour="rops-tml-archived"]',
                'do'      => ['action' => 'click', 'say' => 'Click View archived mappings - or press Skip this step.'],
                'title'   => 'Archived mappings',
                'body'    => 'Switches between your active mappings and the ones you have archived, which you can restore.',
            ],
            [
                'element' => '[data-tour="rops-tml-list"]',
                'section' => 'Tidying up',
                'title'   => 'The mappings',
                'body'    => 'Each row shows the name, how many fields it maps and when it was created.',
            ],
            [
                'element' => '[data-tour="rops-tml-archive"]',
                'title'   => 'Archive a mapping',
                'body'    => 'Puts a mapping you no longer use away. It is not deleted - view archived mappings and Restore it any time.',
            ],
        ],
    ],

    // ══════════════════════════════════════════════════════════════════════════
    // REPORTS - PROPERTY HISTORY
    // ══════════════════════════════════════════════════════════════════════════
    'rops-report-history' => [
        'key'         => 'rops-report-history',
        'title'       => 'Reading a property\'s rental history',
        'description' => 'Pick a property to see every lease on it, with the faults, work orders and inspections inside each stay.',
        'route'       => 'corex.rentals.reports.property-history',
        'permission'  => 'rental_reports.view',
        'feature'     => 'rental-reports',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rops-ph-intro"]',
                'section' => 'Picking a property',
                'title'   => 'Property History',
                'body'    => 'One property\'s whole rental story, in order: every lease on it, with the faults, work orders and inspections inside each tenancy, and anything that happened between tenants.',
            ],
            [
                'element' => '[data-tour="rops-ph-property"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a property from the list.'],
                'title'   => 'Choose the property',
                'body'    => 'The page loads the history as soon as you pick. Only properties you are allowed to see are listed.',
            ],
            [
                'element' => '[data-tour="rops-ph-timeline"]',
                'section' => 'Reading the history',
                'title'   => 'The timeline',
                'body'    => 'One block per tenancy, plus a block for anything that happened to the property between tenancies.',
            ],
            [
                'element' => '[data-tour="rops-ph-block"]',
                'title'   => 'One tenancy',
                'body'    => 'The heading shows the tenancy, the tenant and the rent. Underneath, each event has a date, a type and a description.',
            ],
            [
                'element' => '[data-tour="rops-ph-event"]',
                'title'   => 'The events',
                'body'    => 'Faults reported, work orders raised and inspections done during that stay - handy when a landlord asks what happened at the property.',
            ],
            [
                'element' => '[data-tour="rops-ph-print"]',
                'section' => 'Printing and sharing',
                'title'   => 'Print or save as PDF',
                'body'    => 'Print opens a clean printable page; PDF downloads the same history as a file.',
            ],
            [
                'element' => '[data-tour="rops-ph-landlord"]',
                'title'   => 'Landlord activity report',
                'body'    => 'A PDF for the landlord covering the activity at their property. Send it to the landlord when they ask what has been going on.',
            ],
        ],
    ],

];
