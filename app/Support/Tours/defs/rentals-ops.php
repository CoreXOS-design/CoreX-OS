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

];
