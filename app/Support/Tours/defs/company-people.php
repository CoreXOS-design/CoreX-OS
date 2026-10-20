<?php

/**
 * Guided-tour definitions — COMPANY & PEOPLE (AT-478 coverage pass, spec §12).
 *
 * Pure DATA merged by App\Support\Tours\TourRegistry::all() from every file in
 * app/Support/Tours/defs/*.php. Keys are namespaced `cop-…`; every `element` /
 * `target` / `until` / `skip_if` selector is a dedicated data-tour="cop-…" anchor
 * on the matching Blade view (a removed anchor is caught by
 * tests/Feature/Tours/TourRegistryIntegrityTest).
 *
 * Features covered: Billing, Finance Engine, Proforma Invoices, Multi-branch
 * offices, Staff Take-On, Agent Onboarding, PPRA Employment Letters and the PPRA
 * Inspection Pack. One definition drives all three modes — the click-through
 * Guided Tour, the hands-on Advanced Guide (the `do` steps) and Spot Help (the
 * `section`s). Steps whose anchor is not on screen (an empty list, a panel that
 * only shows for some records) are skipped by the engine. The guide never types,
 * picks or saves for the agent.
 *
 * Screens that need a record (one invoice, one take-on, one application, one
 * letter) declare `pick_from` so an outside launch goes via the list first.
 */

return [

    // ═══════════════════════════════════════════════════════════════════════
    // BILLING — what the agency pays CoreX (read-only, agency admin view)
    // ═══════════════════════════════════════════════════════════════════════
    'cop-billing' => [
        'key'         => 'cop-billing',
        'title'       => 'Understanding your CoreX bill',
        'description' => 'See what your agency pays for CoreX each month and check every line of the maths.',
        'route'       => 'billing.index',
        'permission'  => 'billing.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-bill-intro"]',
                'section' => 'Your monthly total',
                'title'   => 'Your CoreX bill',
                'body'    => 'This page shows what your agency pays for CoreX this month, and exactly how that amount is worked out. It is for reading and checking only — nothing here can be changed.',
            ],
            [
                'element' => '[data-tour="cop-bill-total"]',
                'title'   => 'Monthly total',
                'body'    => 'The amount your agency pays for the current month. It moves up or down by itself as people are added or deactivated.',
            ],
            [
                'element' => '[data-tour="cop-bill-tiles"]',
                'title'   => 'The summary at a glance',
                'body'    => 'Your price band or plan, how many users are being billed, how many branches you have and the monthly total. If your office is large enough, the total may read "On quotation", which means the fee is agreed with CoreX directly.',
            ],
            [
                'element' => '[data-tour="cop-bill-receipt"]',
                'section' => 'Checking the maths',
                'title'   => 'How this is worked out',
                'body'    => 'Think of this as a receipt. Every line adds up to your total, so you can check it by hand. Lines are grouped into the base fee, users and branches.',
            ],
            [
                'element' => '[data-tour="cop-bill-users"]',
                'title'   => 'Users',
                'body'    => 'Your users are priced by headcount. The fee only changes when the number of users moves you into a different band — it is never based on who the person is.',
            ],
            [
                'element' => '[data-tour="cop-bill-details-btn"]',
                'section' => 'Who is on the bill',
                'do'      => ['action' => 'click', 'say' => 'Click Show details.', 'until' => '[data-tour="cop-bill-details"]'],
                'title'   => 'Show details',
                'body'    => 'Open this to see exactly which people and which branches are being billed.',
            ],
            [
                'element'       => '[data-tour="cop-bill-details"]',
                'advanced_only' => true,
                'title'         => 'Who and what you are paying for',
                'body'          => 'The first list is every billable user, with their role. Below it are your branches — the first is included free. If someone leaves, deactivate them and they drop off the next bill; the "Not billed" line proves it.',
            ],
            [
                'element' => '[data-tour="cop-bill-totals"]',
                'section' => 'The final amount',
                'title'   => 'Totals',
                'body'    => 'The standard price comes first. If your agency has a discount or an agreed rate, it is shown here and "You pay" is the final figure.',
            ],
            [
                'element' => '[data-tour="cop-bill-seats"]',
                'title'   => 'What counts as a billable user',
                'body'    => 'Everyone who can log in counts as one user, except assistants (who work for an agent) and people who are deactivated or archived. This is the answer to the most common billing question.',
            ],
            [
                'element' => '[data-tour="cop-bill-readonly"]',
                'title'   => 'Why you cannot edit this page',
                'body'    => 'Your plan and pricing are set by CoreX and update automatically as your team grows or shrinks. You are ready — close this and review your bill.',
            ],
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // FINANCE ENGINE — formula definitions and the audit centre
    // ═══════════════════════════════════════════════════════════════════════
    'cop-fin-defs' => [
        'key'         => 'cop-fin-defs',
        'title'       => 'Reading the finance definitions',
        'description' => 'See every commission and fee formula the Finance Engine uses, and recalculate a period.',
        'route'       => 'admin.finance.definitions',
        'permission'  => 'access_finance_engine',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-fin-def-intro"]',
                'section' => 'What the Finance Engine is',
                'title'   => 'Finance Definitions',
                'body'    => 'The Finance Engine is the one place that calculates the commission and fee figures on your deals. This page lists every formula it knows about, so you can see where each number comes from.',
            ],
            [
                'element' => '[data-tour="cop-fin-def-tiles"]',
                'title'   => 'The counts',
                'body'    => 'How many formulas are registered, how many are active, and how many calculated values CoreX has stored from them.',
            ],
            [
                'element' => '[data-tour="cop-fin-def-table"]',
                'section' => 'Reading a formula',
                'title'   => 'The definitions list',
                'body'    => 'Each row is one formula: its key (the name used inside CoreX), what it applies to (a deal, a payment and so on), the kind of value it produces, its version and whether it is active. Notes explain anything unusual.',
            ],
            [
                'element' => '[data-tour="cop-fin-def-period"]',
                'section' => 'Recalculating a period',
                'do'      => ['action' => 'choose', 'say' => 'Pick the month you want to recalculate.'],
                'title'   => 'Choose a period',
                'body'    => 'Pick the month whose figures you want the engine to work out again. Use this after a rule or a deal has been corrected.',
            ],
            [
                'element'       => '[data-tour="cop-fin-def-recalc"]',
                'advanced_only' => true,
                'title'         => 'Recalculate',
                'body'          => 'Recalculate Period reworks one month. Recalculate ALL reworks every month that has deals and can take a while, so CoreX asks you to confirm first. Nothing is deleted — the engine stores fresh figures and the audit shows what changed.',
            ],
            [
                'element' => '[data-tour="cop-fin-def-history"]',
                'section' => 'Checking the results',
                'do'      => ['action' => 'click', 'say' => 'Click Audit History to see past checks.'],
                'title'   => 'Audit History',
                'body'    => 'Opens the Audit Centre, where every past recalculation and check is kept. That is where you confirm the figures came out right.',
            ],
        ],
    ],

    'cop-fin-audit' => [
        'key'         => 'cop-fin-audit',
        'title'       => 'Running a finance audit',
        'description' => 'Review past finance audit runs, or run a new audit for any month.',
        'route'       => 'admin.finance.audit.index',
        'permission'  => 'access_finance_engine',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-fin-aud-intro"]',
                'section' => 'The Audit Centre',
                'title'   => 'Finance Audit Centre',
                'body'    => 'An audit recalculates a month of deals and checks every commission and fee figure against its formula. This page keeps each run so you have a record of what was checked and what was found.',
            ],
            [
                'element' => '[data-tour="cop-fin-aud-period"]',
                'section' => 'Finding a past run',
                'do'      => ['action' => 'choose', 'say' => 'Pick a month to narrow the list of runs.'],
                'title'   => 'Filter by month',
                'body'    => 'Choose a month to see only the audits run for it. "All periods" shows everything.',
            ],
            [
                'element'       => '[data-tour="cop-fin-aud-filter"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter.', 'target' => 'button'],
                'title'         => 'Apply the filter',
                'body'          => 'Filter applies your month choice. If the list looks shorter than expected, a filter is still on — use Clear to remove it.',
            ],
            [
                'element' => '[data-tour="cop-fin-aud-run"]',
                'section' => 'Running a new audit',
                'title'   => 'Run New Audit',
                'body'    => 'Pick a month here and press Audit This Period. CoreX asks you to confirm, then recalculates and checks that month. Do this after changing a deal or a commission rule.',
            ],
            [
                'element' => '[data-tour="cop-fin-aud-run-period"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the month you want audited.'],
                'title'   => 'Which month',
                'body'    => 'The current month is already chosen. Pick an earlier one to re-check past figures.',
            ],
            [
                'element' => '[data-tour="cop-fin-aud-runs"]',
                'section' => 'Reading the results',
                'title'   => 'Audit Runs',
                'body'    => 'One row per run. Items is how many figures were checked; Errors need fixing, Warnings need a look, and Pass are correct. A run that shows "failed" did not finish and should be run again.',
            ],
            [
                'element' => '[data-tour="cop-fin-aud-runs"]',
                'do'      => ['action' => 'click', 'say' => 'Click a run number to open its detail.', 'target' => '[data-tour="cop-fin-aud-open"]'],
                'title'   => 'Open a run',
                'body'    => 'Click the run number to see every deal that was checked and exactly which figure did not match.',
            ],
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // PROFORMA INVOICES
    // ═══════════════════════════════════════════════════════════════════════
    'cop-pf-list' => [
        'key'         => 'cop-pf-list',
        'title'       => 'Finding a proforma invoice',
        'description' => 'Search every proforma invoice generated from a deal, then view or download it.',
        'route'       => 'proforma.index',
        'permission'  => 'proforma.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pf-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Proforma Invoices',
                'body'    => 'A proforma invoice is the bill for your commission and fees that you send before the deal pays out. Every one generated from a deal is listed here, so you can find it without opening the deal first.',
            ],
            [
                'element' => '[data-tour="cop-pf-search"]',
                'section' => 'Finding an invoice',
                'do'      => ['action' => 'fill', 'say' => 'Type an invoice number or part of a property address, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type an invoice number or the property it relates to. Clear the box and search again to see everything.',
            ],
            [
                'element' => '[data-tour="cop-pf-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick Issued or Voided to narrow the list.'],
                'title'   => 'Filter by status',
                'body'    => 'Issued invoices are live. Voided ones were cancelled but are kept for the record — their numbers are never reused.',
            ],
            [
                'element'       => '[data-tour="cop-pf-search-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Search.'],
                'title'         => 'Apply your search',
                'body'          => 'Search applies the words you typed along with the status you picked.',
            ],
            [
                'element' => '[data-tour="cop-pf-table"]',
                'section' => 'Opening an invoice',
                'title'   => 'The invoice list',
                'body'    => 'Each row shows the invoice number, status, date, the deal or property, who it is billed to, the agent who generated it and the total including VAT.',
            ],
            [
                'element' => '[data-tour="cop-pf-table"]',
                'do'      => ['action' => 'click', 'say' => 'Click View on an invoice.', 'target' => '[data-tour="cop-pf-view"]'],
                'title'   => 'View an invoice',
                'body'    => 'View opens the invoice with its lines and totals. Download gives you the PDF to send to the person being billed.',
            ],
        ],
    ],

    'cop-pf-invoice' => [
        'key'         => 'cop-pf-invoice',
        'title'       => 'Working with one proforma invoice',
        'description' => 'Check an invoice\'s lines and total, download the PDF, and (for admins) adjust or void it.',
        'route'       => 'proforma.show',
        'permission'  => 'proforma.generate',
        'pick_from'   => 'proforma.index',
        'pick_note'   => 'Open the invoice you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pfi-intro"]',
                'section' => 'Checking the invoice',
                'title'   => 'This proforma invoice',
                'body'    => 'The invoice number and the deal reference are at the top. A red VOID tag means the invoice was cancelled — it is kept for the record but should not be sent.',
            ],
            [
                'element' => '[data-tour="cop-pfi-details"]',
                'title'   => 'Who and when',
                'body'    => 'Who is being billed, any attorney it is addressed care of, the date, the due date and whether VAT applies. Check these before you send it.',
            ],
            [
                'element' => '[data-tour="cop-pfi-lines"]',
                'title'   => 'The lines',
                'body'    => 'Each line is one charge. Lines marked "from deal" come straight from the deal and are locked, so the invoice always agrees with the deal.',
            ],
            [
                'element' => '[data-tour="cop-pfi-total"]',
                'title'   => 'The total',
                'body'    => 'The amount to be paid. If the agency is VAT-registered, the VAT is already added to the total.',
            ],
            [
                'element' => '[data-tour="cop-pfi-download"]',
                'section' => 'Sending it',
                'do'      => ['action' => 'click', 'say' => 'Click Download PDF to get the invoice file.'],
                'title'   => 'Download PDF',
                'body'    => 'Gives you the finished invoice on your agency letterhead, ready to email.',
            ],
            [
                'element' => '[data-tour="cop-pfi-admin"]',
                'section' => 'Adjusting it (admins)',
                'title'   => 'Admin — adjust',
                'body'    => 'Only people who manage proforma invoices see this box. Use it to add an extra line (such as a discount), rebuild the PDF, or void the invoice.',
            ],
            [
                'element' => '[data-tour="cop-pfi-addline-desc"]',
                'do'      => ['action' => 'fill', 'say' => 'Type what the extra line is for.'],
                'title'   => 'Describe the line',
                'body'    => 'A short description, for example "Discount on commission".',
            ],
            [
                'element'       => '[data-tour="cop-pfi-addline-amount"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type the amount before VAT. Use a minus sign for a discount.'],
                'title'         => 'Amount',
                'body'          => 'Enter the amount excluding VAT; CoreX adds the VAT. A negative amount takes money off the invoice.',
            ],
            [
                'element'       => '[data-tour="cop-pfi-addline"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Add line.', 'target' => 'button'],
                'title'         => 'Add the line',
                'body'          => 'The line is added and the totals update.',
            ],
            [
                'element' => '[data-tour="cop-pfi-void"]',
                'title'   => 'Void',
                'body'    => 'Voiding cancels the invoice but keeps the record, and its number is never reused. You must give a reason. Regenerate PDF rebuilds the file after you have changed lines.',
            ],
        ],
    ],

    'cop-pf-settings' => [
        'key'         => 'cop-pf-settings',
        'title'       => 'Setting up proforma invoices',
        'description' => 'Choose how proforma invoices are numbered, when they fall due and which bank details they show.',
        'route'       => 'admin.proforma-settings',
        'permission'  => 'proforma.manage',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pfs-intro"]',
                'section' => 'Numbering',
                'title'   => 'Proforma invoice settings',
                'body'    => 'These settings apply to every proforma invoice your agency creates from now on. Your letterhead, logo and VAT number come from Company Branding, not from here.',
            ],
            [
                'element' => '[data-tour="cop-pfs-prefix"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the letters that start every invoice number.'],
                'title'   => 'Prefix',
                'body'    => 'The letters at the front of each invoice number, for example "PF-". Keep it short.',
            ],
            [
                'element' => '[data-tour="cop-pfs-padding"]',
                'do'      => ['action' => 'fill', 'say' => 'Type how many digits the number should have.'],
                'title'   => 'Zero-padding',
                'body'    => 'How many digits the number part is padded to. With 5, the first invoice reads 00001.',
            ],
            [
                'element' => '[data-tour="cop-pfs-start"]',
                'title'   => 'Start number',
                'body'    => 'Only fill this in if you need the next number to jump forward. The sequence can never go back or reuse a number, which keeps your invoice trail clean.',
            ],
            [
                'element' => '[data-tour="cop-pfs-due-rule"]',
                'section' => 'Due date',
                'do'      => ['action' => 'choose', 'say' => 'Pick when invoices fall due.'],
                'title'   => 'Due-date rule',
                'body'    => 'End of the current month, a number of days after the invoice is issued, or on receipt. This sets the Due date printed on each invoice.',
            ],
            [
                'element' => '[data-tour="cop-pfs-due-days"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the number of days (only used for "N days after").'],
                'title'   => 'Days',
                'body'    => 'Only used when the rule is "N days after issue". Otherwise it is ignored.',
            ],
            [
                'element' => '[data-tour="cop-pfs-bank-text"]',
                'section' => 'Banking details',
                'do'      => ['action' => 'fill', 'say' => 'Type your bank, account name, account number and branch code.'],
                'title'   => 'Bank details',
                'body'    => 'Printed as notes on every proforma invoice so the person being billed knows where to pay. Include the payment reference they should use.',
            ],
            [
                'element' => '[data-tour="cop-pfs-branding"]',
                'section' => 'Company details',
                'title'   => 'From company branding',
                'body'    => 'Your company name and VAT status are shown for checking only. To change them, use the Edit company branding link.',
            ],
            [
                'element'       => '[data-tour="cop-pfs-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save proforma settings.'],
                'title'         => 'Save',
                'body'          => 'Saves the numbering, due date and bank details. New invoices use them straight away; invoices already issued are not changed.',
            ],
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // MULTI-BRANCH OFFICES — branches, their details and archiving
    // ═══════════════════════════════════════════════════════════════════════
    'cop-branches' => [
        'key'         => 'cop-branches',
        'title'       => 'Setting up your branches',
        'description' => 'Add a branch, give it its own contact details and logo, or archive one that has closed.',
        'route'       => 'admin.branch-assignments',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'multi-branch',
        'permission'  => 'access_branch_assignments',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-br-intro"]',
                'section' => 'About branches',
                'title'   => 'Branch Assignments',
                'body'    => 'If your agency runs more than one office, each office is a branch. Agents are grouped by branch, and with multi-branch on, an agent in one branch does not see another branch\'s properties, contacts or deals.',
            ],
            [
                'element' => '[data-tour="cop-br-name"]',
                'section' => 'Adding a branch',
                'do'      => ['action' => 'fill', 'say' => 'Type the branch name.'],
                'title'   => 'Branch name',
                'body'    => 'The name people will recognise, for example the town or office name.',
            ],
            [
                'element' => '[data-tour="cop-br-code"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a short code for the branch.'],
                'title'   => 'Branch code',
                'body'    => 'A short code that identifies the branch in reports and on documents.',
            ],
            [
                'element'       => '[data-tour="cop-br-add-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Add Branch.'],
                'title'         => 'Add the branch',
                'body'          => 'Creates the branch. It then appears in the list below and can be given its own details.',
            ],
            [
                'element' => '[data-tour="cop-br-list"]',
                'section' => 'Existing branches',
                'title'   => 'Your branches',
                'body'    => 'Every branch you have. Archive closes a branch: CoreX walks you through moving its people to another branch and keeps all the history. Nothing is deleted.',
            ],
            [
                'element' => '[data-tour="cop-br-details"]',
                'section' => 'Branch details',
                'title'   => 'Branch contact details',
                'body'    => 'Each branch can have its own trading name, address, phone, email and registration numbers. Anything you leave blank is taken from your agency settings, so you only fill in what is different.',
            ],
            [
                'element' => '[data-tour="cop-br-trading"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a trading name for this branch — or press Skip this step.'],
                'title'   => 'Trading name',
                'body'    => 'Shown on this branch\'s documents instead of the agency name. Leave it blank to use the agency name.',
            ],
            [
                'element' => '[data-tour="cop-br-address"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the branch address — or press Skip this step.'],
                'title'   => 'Address',
                'body'    => 'The branch\'s physical address, used on its letterhead and documents.',
            ],
            [
                'element' => '[data-tour="cop-br-regs"]',
                'title'   => 'Registration and licence numbers',
                'body'    => 'Registration, VAT, Fidelity Fund Certificate (FFC) and FIC numbers, if this branch has its own. Leave blank to use the agency\'s.',
            ],
            [
                'element' => '[data-tour="cop-br-logo"]',
                'title'   => 'Branch logo',
                'body'    => 'Upload a logo (JPG, PNG or WebP, up to 2 MB) if the branch has its own. Without one, the agency logo is used.',
            ],
            [
                'element'       => '[data-tour="cop-br-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save on the branch you changed.'],
                'title'         => 'Save',
                'body'          => 'Each branch saves on its own, so save the branch you edited before moving on.',
            ],
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // STAFF TAKE-ON — onboarding an employee into payroll
    // ═══════════════════════════════════════════════════════════════════════
    'cop-stk-list' => [
        'key'         => 'cop-stk-list',
        'title'       => 'Tracking staff take-ons',
        'description' => 'See every staff take-on in progress or completed, resume one, or start a new one.',
        'route'       => 'staff-take-on.index',
        'permission'  => 'manage_staff_take_on',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-stk-intro"]',
                'section' => 'Reading the list',
                'title'   => 'Staff Take-On',
                'body'    => 'A take-on is the checklist you complete when a new staff member joins: personal details, tax and banking, employment terms, pay, leave balances and compliance documents. Each person has one record here.',
            ],
            [
                'element' => '[data-tour="cop-stk-tabs"]',
                'do'      => ['action' => 'click', 'say' => 'Click a tab to filter the list.'],
                'title'   => 'Filter by progress',
                'body'    => 'All, In Progress, Completed, or only the ones started this month. The small number beside each tab is how many records are in it.',
            ],
            [
                'element' => '[data-tour="cop-stk-progress"]',
                'title'   => 'Progress',
                'body'    => 'How far through the eight steps each person is. A completed take-on shows a green Completed badge instead of a step number.',
            ],
            [
                'element' => '[data-tour="cop-stk-open"]',
                'section' => 'Carrying on with a take-on',
                'do'      => ['action' => 'click', 'say' => 'Click Resume (or View) on a record.'],
                'title'   => 'Resume or view',
                'body'    => 'Resume takes you to the next step that still needs doing. View opens a completed take-on for review.',
            ],
            [
                'element' => '[data-tour="cop-stk-new"]',
                'section' => 'Starting a new take-on',
                'title'   => 'Start New Take-On',
                'body'    => 'Use this when a new person joins. They need a CoreX user account first — if they do not have one, create it in User Management and then come back. You are ready — close this and start your first take-on.',
            ],
        ],
    ],

    'cop-stk-new' => [
        'key'         => 'cop-stk-new',
        'title'       => 'Starting a staff take-on',
        'description' => 'Choose the person and the type of take-on, then open the step-by-step wizard.',
        'route'       => 'staff-take-on.create',
        'permission'  => 'manage_staff_take_on',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-stkc-intro"]',
                'section' => 'Choosing the person',
                'title'   => 'Start a take-on',
                'body'    => 'Two choices here — who is being taken on and what kind of take-on it is. The wizard that follows captures everything else.',
            ],
            [
                'element' => '[data-tour="cop-stkc-user-select"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the person from the list.'],
                'title'   => 'Select user',
                'body'    => 'Only people who have a CoreX user account but are not on payroll yet are listed. If the person you want is missing, they either already have a take-on or still need a user account.',
            ],
            [
                'element' => '[data-tour="cop-stkc-type"]',
                'section' => 'The kind of take-on',
                'do'      => ['action' => 'choose', 'say' => 'Pick New Hire, Migration or Transfer.'],
                'title'   => 'Take-on type',
                'body'    => 'New hire for someone joining now. Migration for someone you already employ whose records are moving from an old system. Transfer for someone moving from another branch.',
            ],
            [
                'element' => '[data-tour="cop-stkc-date"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the take-on date.'],
                'title'   => 'Take-on date',
                'body'    => 'The date the person is taken on to payroll. It starts as today — change it if they started earlier or start later.',
            ],
            [
                'element'       => '[data-tour="cop-stkc-start"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Start Wizard.', 'until' => '[data-tour="cop-stkw-strip"]'],
                'title'         => 'Start Wizard',
                'body'          => 'Creates the take-on record and opens the first step. You can stop at any time and resume later.',
            ],
        ],
    ],

    'cop-stk-wizard' => [
        'key'         => 'cop-stk-wizard',
        'title'       => 'Completing a staff take-on',
        'description' => 'Work through the take-on steps for one employee, from personal details to sign-off.',
        'route'       => 'staff-take-on.wizard',
        'permission'  => 'manage_staff_take_on',
        'pick_from'   => 'staff-take-on.index',
        'pick_note'   => 'Open the take-on you want to work on (Resume or View) — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-stkw-intro"]',
                'section' => 'How the wizard works',
                'title'   => 'One employee, eight steps',
                'body'    => 'You are capturing everything payroll and compliance need for this person. Each step saves when you press Save & Continue, so you can stop whenever you like and pick up from the list later.',
            ],
            [
                'element' => '[data-tour="cop-stkw-strip"]',
                'title'   => 'The progress strip',
                'body'    => 'The steps in order. The blue one is where you are; a tick means that step is done. Click any step to jump to it.',
            ],
            [
                'element' => '[data-tour="cop-stkw-step"]',
                'section' => 'Filling in a step',
                'title'   => 'This step',
                'body'    => 'The details for the current step. Personal details come first, then tax and banking, employment terms, compensation, opening leave balances, compliance documents, and finally review and sign-off.',
            ],
            [
                'element' => '[data-tour="cop-stkw-dob"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the date of birth — or press Skip this step.'],
                'title'   => 'Date of birth',
                'body'    => 'Used for payroll and tax calculations.',
            ],
            [
                'element' => '[data-tour="cop-stkw-phone"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a contact number — or press Skip this step.'],
                'title'   => 'Phone',
                'body'    => 'The best number to reach this person on.',
            ],
            [
                'element' => '[data-tour="cop-stkw-emergency"]',
                'title'   => 'Emergency contact and next of kin',
                'body'    => 'Who to call in an emergency, and the person\'s next of kin. Capture both now — it is the sort of detail you only miss when it is needed.',
            ],
            [
                'element'       => '[data-tour="cop-stkw-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the button at the bottom of the step to save and move on.'],
                'title'         => 'Save and continue',
                'body'          => 'Saves this step and opens the next one. On the last step the button submits the take-on instead.',
            ],
            [
                'element' => '[data-tour="cop-stkw-signoff"]',
                'section' => 'Signing it off',
                'do'      => ['action' => 'choose', 'say' => 'Tick the confirmation box once you have checked everything.', 'target' => 'input[type="checkbox"]'],
                'title'   => 'Review and sign-off',
                'body'    => 'On the final step, check the verification list. Anything shown in amber still needs attention. When everything is right, tick the confirmation and press Submit Take-On — that completes the take-on and records who signed it off.',
            ],
            [
                'element' => '[data-tour="cop-stkw-exit"]',
                'section' => 'Stopping part-way',
                'title'   => 'Save & Exit',
                'body'    => 'Leaves the wizard and takes you back to the list. What you have saved is kept, and Resume brings you back to the next step that is not done.',
            ],
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // AGENT ONBOARDING — applications pipeline (owner / super-admin only)
    // ═══════════════════════════════════════════════════════════════════════
    'cop-ao-pipeline' => [
        'key'         => 'cop-ao-pipeline',
        'title'       => 'Following the agent onboarding pipeline',
        'description' => 'See every agent application by stage, search them, open one or start a new one.',
        'route'       => 'onboarding.index',
        // Gated in-controller by owner-role / super_admin (no permission key exists) —
        // declared here, in lockstep with OnboardingController, so the guide is only
        // offered to the people who can open the page.
        'roles'       => ['super_admin'],
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-ao-intro"]',
                'section' => 'Reading the pipeline',
                'title'   => 'Agent Onboarding Pipeline',
                'body'    => 'Every person applying to join your agency is a card on this board. The card moves right as they progress: applied, documents pending, compliance review, mentor assignment, training and finally activated.',
            ],
            [
                'element' => '[data-tour="cop-ao-board"]',
                'title'   => 'The stages',
                'body'    => 'One column per stage with a count at the top. Look for columns where cards pile up — that is where applications are getting stuck.',
            ],
            [
                'element' => '[data-tour="cop-ao-progress"]',
                'title'   => 'Reading a card',
                'body'    => 'Name, whether they are a practitioner, candidate or intern, how many days they have been in this stage, and a bar showing how much of the onboarding checklist is done.',
            ],
            [
                'element' => '[data-tour="cop-ao-search"]',
                'section' => 'Finding an applicant',
                'do'      => ['action' => 'fill', 'say' => 'Type a name or email, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Finds applicants by name or email across every stage.',
            ],
            [
                'element' => '[data-tour="cop-ao-designation"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a designation to narrow the board.'],
                'title'   => 'Filter by designation',
                'body'    => 'Show only property practitioners, candidate practitioners or interns.',
            ],
            [
                'element'       => '[data-tour="cop-ao-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter.'],
                'title'         => 'Apply the filter',
                'body'          => 'Applies your search and designation. Use Clear to bring everyone back.',
            ],
            [
                'element' => '[data-tour="cop-ao-card"]',
                'section' => 'Opening an application',
                'do'      => ['action' => 'click', 'say' => 'Click an applicant\'s card to open it.'],
                'title'   => 'Open an application',
                'body'    => 'Opens the full application: their details, documents, checklist and the buttons that move them on to the next stage.',
            ],
            [
                'element' => '[data-tour="cop-ao-new"]',
                'section' => 'Adding an applicant',
                'title'   => 'New Application',
                'body'    => 'Use this to add someone yourself, for example after an interview. The onboarding checklist is set up for them automatically.',
            ],
        ],
    ],

    'cop-ao-new' => [
        'key'         => 'cop-ao-new',
        'title'       => 'Adding an agent application',
        'description' => 'Capture a new agent applicant\'s details to start their onboarding.',
        'route'       => 'onboarding.create',
        'roles'       => ['super_admin'],
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-aon-intro"]',
                'section' => 'Personal details',
                'title'   => 'New agent application',
                'body'    => 'Capture who the applicant is and where they are in their career. Saving creates the application and the onboarding checklist, and puts them in the first stage of the pipeline.',
            ],
            [
                'element' => '[data-tour="cop-aon-first"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the first name.'],
                'title'   => 'First name',
                'body'    => 'Required.',
            ],
            [
                'element' => '[data-tour="cop-aon-last"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the last name.'],
                'title'   => 'Last name',
                'body'    => 'Required.',
            ],
            [
                'element' => '[data-tour="cop-aon-email"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the applicant\'s email address.'],
                'title'   => 'Email',
                'body'    => 'Required. This is how the applicant is contacted, and it becomes their login once they are activated.',
            ],
            [
                'element' => '[data-tour="cop-aon-id"]',
                'title'   => 'ID number',
                'body'    => 'The 13-digit South African ID number. Leave it blank if you do not have it yet — it can be added later.',
            ],
            [
                'element' => '[data-tour="cop-aon-designation"]',
                'section' => 'Professional details',
                'do'      => ['action' => 'choose', 'say' => 'Pick the designation.'],
                'title'   => 'Designation',
                'body'    => 'Property practitioner (fully registered), candidate practitioner (working towards registration) or intern. It decides which documents and checks are needed.',
            ],
            [
                'element' => '[data-tour="cop-aon-ffc"]',
                'title'   => 'PPRA and FFC',
                'body'    => 'The applicant\'s Fidelity Fund Certificate number and expiry, and their PPRA registration status. Practitioners need a valid FFC before they can be activated. Leave blank for candidates and interns who do not have one yet.',
            ],
            [
                'element' => '[data-tour="cop-aon-motivation"]',
                'section' => 'Background',
                'title'   => 'Motivation',
                'body'    => 'Why they want to join. Short notes from the interview are enough.',
            ],
            [
                'element' => '[data-tour="cop-aon-referral"]',
                'title'   => 'Referral',
                'body'    => 'How they heard about you and, if one of your agents referred them, who. Useful for any referral rewards.',
            ],
            [
                'element'       => '[data-tour="cop-aon-create"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Create Application.'],
                'title'         => 'Create the application',
                'body'          => 'Saves the application and opens it so you can start on the documents and checklist.',
            ],
        ],
    ],

    'cop-ao-application' => [
        'key'         => 'cop-ao-application',
        'title'       => 'Moving an applicant through onboarding',
        'description' => 'Check an applicant\'s documents and checklist, then advance, activate or reject them.',
        'route'       => 'onboarding.show',
        'roles'       => ['super_admin'],
        'pick_from'   => 'onboarding.index',
        'pick_note'   => 'Open the applicant you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-aos-intro"]',
                'section' => 'The applicant',
                'title'   => 'This application',
                'body'    => 'The applicant\'s name, designation, current stage and how many days they have been in it. A long time in one stage usually means something is waiting on you or on them.',
            ],
            [
                'element' => '[data-tour="cop-aos-details"]',
                'title'   => 'Their details',
                'body'    => 'Personal and professional details, their motivation and referral, and a timeline of when the application changed stage and who reviewed it.',
            ],
            [
                'element' => '[data-tour="cop-aos-docs"]',
                'section' => 'Documents',
                'title'   => 'Documents',
                'body'    => 'The documents the applicant has supplied — ID, qualifications, FFC and so on. Each must be checked by you before the application can move on.',
            ],
            [
                'element' => '[data-tour="cop-aos-doctype"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the type of document you are uploading.'],
                'title'   => 'Document type',
                'body'    => 'Choose what the document is first, so it is filed in the right place.',
            ],
            [
                'element' => '[data-tour="cop-aos-file"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose the file to upload.'],
                'title'   => 'Choose the file',
                'body'    => 'Pick the file from your computer, then press Upload.',
            ],
            [
                'element' => '[data-tour="cop-aos-doc"]',
                'title'   => 'Checking a document',
                'body'    => 'View opens the file. If it is correct, press Verify; if it is unreadable or wrong, press Reject and give a reason so the applicant knows what to fix.',
            ],
            [
                'element' => '[data-tour="cop-aos-checklist"]',
                'section' => 'Checklist',
                'title'   => 'Onboarding checklist',
                'body'    => 'Everything that has to happen before this person is activated. The bar shows how much is done. An asterisk marks items that are required.',
            ],
            [
                'element' => '[data-tour="cop-aos-checklist-items"]',
                'do'      => ['action' => 'click', 'say' => 'Click the box beside an item you have completed.', 'target' => 'button'],
                'title'   => 'Tick items off',
                'body'    => 'Click the box when an item is done. CoreX records who ticked it and when.',
            ],
            [
                'element' => '[data-tour="cop-aos-actions"]',
                'section' => 'Moving them on',
                'title'   => 'Actions',
                'body'    => 'The buttons here move the applicant to the next stage. A button stays greyed out until the required documents and checklist items for that stage are done.',
            ],
            [
                'element' => '[data-tour="cop-aos-advance"]',
                'title'   => 'Advance',
                'body'    => 'Moves the applicant to the next stage after you confirm. At the last stage the button becomes Activate Agent, which creates their user account and lets them log in.',
            ],
            [
                'element' => '[data-tour="cop-aos-reject"]',
                'title'   => 'Reject',
                'body'    => 'Closes the application with a reason you type. The record is kept.',
            ],
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // PPRA EMPLOYMENT LETTERS — the FFC confirmation-of-employment register
    // ═══════════════════════════════════════════════════════════════════════
    'cop-pel-list' => [
        'key'         => 'cop-pel-list',
        'title'       => 'Managing PPRA employment letters',
        'description' => 'Search the register of PPRA employment letters, see which are signed and filed, and start a new one.',
        'route'       => 'admin.ppra-employment-letters.index',
        'permission'  => 'ppra_employment_letters.manage',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pel-intro"]',
                'section' => 'Reading the register',
                'title'   => 'PPRA FFC Employment Letters',
                'body'    => 'The PPRA asks for a letter confirming that a practitioner is employed by your agency and which principal they work under. This is the register of every such letter, with its signing status.',
            ],
            [
                'element' => '[data-tour="cop-pel-search"]',
                'section' => 'Finding a letter',
                'do'      => ['action' => 'fill', 'say' => 'Type an agent or principal name, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Finds letters by the agent\'s or the principal\'s name.',
            ],
            [
                'element' => '[data-tour="cop-pel-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status to narrow the list.'],
                'title'   => 'Filter by status',
                'body'    => 'A letter moves from draft to signed to a signed copy being filed. Pick a status to see only those letters.',
            ],
            [
                'element' => '[data-tour="cop-pel-branch"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a branch — or press Skip this step.'],
                'title'   => 'Filter by branch',
                'body'    => 'Show only the letters for agents in one branch.',
            ],
            [
                'element' => '[data-tour="cop-pel-dates"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a From date — or press Skip this step.'],
                'title'   => 'Filter by date',
                'body'    => 'Choose a From and To date to see only letters created in that window. Year narrows it by calendar year.',
            ],
            [
                'element' => '[data-tour="cop-pel-sort"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how to sort the list.'],
                'title'   => 'Sort',
                'body'    => 'Newest first (the default), oldest first, by agent name or by status.',
            ],
            [
                'element' => '[data-tour="cop-pel-archived"]',
                'title'   => 'Show archived',
                'body'    => 'Archived letters are hidden by default. Tick this to see them; they can be restored.',
            ],
            [
                'element'       => '[data-tour="cop-pel-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter.'],
                'title'         => 'Apply the filters',
                'body'          => 'Applies everything you chose above.',
            ],
            [
                'element' => '[data-tour="cop-pel-table"]',
                'section' => 'Working a letter',
                'title'   => 'The register',
                'body'    => 'Each row shows the agent, branch, principal, the letter\'s status and when it was made. The Signed copy column is where the signed, scanned letter is filed — upload it here once the letter has been signed on paper.',
            ],
            [
                'element' => '[data-tour="cop-pel-table"]',
                'do'      => ['action' => 'click', 'say' => 'Click View on a letter.', 'target' => '[data-tour="cop-pel-open"]'],
                'title'   => 'Open a letter',
                'body'    => 'Opens the letter itself so you can read it, download it and manage its signed copy.',
            ],
            [
                'element' => '[data-tour="cop-pel-new"]',
                'section' => 'Starting a letter',
                'title'   => 'New letter',
                'body'    => 'Starts a letter for one of your agents. You are ready — close this and start the first one.',
            ],
        ],
    ],

    'cop-pel-new' => [
        'key'         => 'cop-pel-new',
        'title'       => 'Starting a PPRA employment letter',
        'description' => 'Choose the agent (and the principal if there is more than one) and start their letter.',
        'route'       => 'admin.ppra-employment-letters.create',
        'permission'  => 'ppra_employment_letters.manage',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pelc-intro"]',
                'section' => 'Choosing the agent',
                'title'   => 'Start a letter',
                'body'    => 'You are starting the letter on the agent\'s behalf. Pick the agent, and CoreX prepares the letter using their details and the agency\'s principal.',
            ],
            [
                'element' => '[data-tour="cop-pelc-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of the agent\'s name to filter the list.'],
                'title'   => 'Search for the agent',
                'body'    => 'Type a few letters to cut the list down. Clear the box to see everyone again.',
            ],
            [
                'element' => '[data-tour="cop-pelc-agents"]',
                'do'      => ['action' => 'choose', 'say' => 'Select the agent the letter is for.', 'target' => 'input[type="radio"]'],
                'title'   => 'Pick the agent',
                'body'    => 'Each agent shows their role, their FFC number and a coloured tag for the state of their Fidelity Fund Certificate (green is current, amber is close to expiring, red needs attention). Only agents you are allowed to manage appear.',
            ],
            [
                'element' => '[data-tour="cop-pelc-principal"]',
                'section' => 'The principal',
                'do'      => ['action' => 'choose', 'say' => 'Pick the principal who will sign.'],
                'title'   => 'Principal for this letter',
                'body'    => 'Shown only when your agency has more than one principal. Choose the one who employs this agent. If your agency has no principal at all, a letter cannot be started until a user is marked as the principal practitioner.',
            ],
            [
                'element'       => '[data-tour="cop-pelc-start"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Start letter.'],
                'title'         => 'Start letter',
                'body'          => 'Creates the letter and opens it. You then download it, have it signed on paper, and upload the signed copy.',
            ],
        ],
    ],

    'cop-pel-letter' => [
        'key'         => 'cop-pel-letter',
        'title'       => 'Filing a signed employment letter',
        'description' => 'Read an employment letter, download it, and upload the signed copy.',
        'route'       => 'admin.ppra-employment-letters.show',
        'permission'  => 'ppra_employment_letters.manage',
        'pick_from'   => 'admin.ppra-employment-letters.index',
        'pick_note'   => 'Open the letter you want to work on (View) — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pels-intro"]',
                'section' => 'The letter',
                'title'   => 'Confirmation of employment',
                'body'    => 'The agent\'s name, the letter\'s status and when it was created.',
            ],
            [
                'element' => '[data-tour="cop-pels-details"]',
                'title'   => 'Who it covers',
                'body'    => 'The agent, their branch, the principal who employs them and who created the letter. Check these are right before you send it to be signed.',
            ],
            [
                'element' => '[data-tour="cop-pels-letter"]',
                'title'   => 'The letter itself',
                'body'    => 'This is the letter exactly as it will be printed.',
            ],
            [
                'element' => '[data-tour="cop-pels-download"]',
                'do'      => ['action' => 'click', 'say' => 'Click Download to get the letter to print and sign.'],
                'title'   => 'Download',
                'body'    => 'Download the letter, print it, and have the agent and the principal sign it by hand.',
            ],
            [
                'element' => '[data-tour="cop-pels-signed"]',
                'section' => 'Filing the signed copy',
                'do'      => ['action' => 'choose', 'say' => 'Choose the scanned signed letter (PDF, JPG or PNG).', 'target' => 'input[type="file"]'],
                'title'   => 'Signed copy',
                'body'    => 'Once the letter is signed, scan it and upload it here. A newer upload replaces the current one, and earlier uploads are kept as history so nothing is lost.',
            ],
            [
                'element' => '[data-tour="cop-pels-archive"]',
                'section' => 'Archiving',
                'title'   => 'Archive letter',
                'body'    => 'If a letter was made by mistake, archive it. It is hidden from the register but never deleted, and can be restored from the list with "Show archived".',
            ],
        ],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // PPRA INSPECTION PACK — readiness against a PPRA section 25 inspection
    // ═══════════════════════════════════════════════════════════════════════
    'cop-pip-checklist' => [
        'key'         => 'cop-pip-checklist',
        'title'       => 'Getting ready for a PPRA inspection',
        'description' => 'See live readiness against each inspection item, fix the gaps, and download the inspection report or pack.',
        'route'       => 'admin.ppra-inspection-pack.index',
        'permission'  => 'ppra_inspection_pack.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pip-intro"]',
                'section' => 'Where you stand',
                'title'   => 'PPRA Inspection Pack',
                'body'    => 'When the PPRA serves an inspection notice, you must hand over a list of documents and registers. This page checks your agency against that list in real time, so you know what is missing before the inspector arrives.',
            ],
            [
                'element' => '[data-tour="cop-pip-lastpack"]',
                'title'   => 'The last pack',
                'body'    => 'The state of the most recent full inspection pack — queued, generating, ready to download, or failed with the reason.',
            ],
            [
                'element' => '[data-tour="cop-pip-table"]',
                'title'   => 'The checklist',
                'body'    => 'One row per inspection item. Status is green when you are covered, amber when something is close to expiring or incomplete, red when it is missing or expired, and "Not yet available" when CoreX cannot check it yet. Why tells you the reason in plain words.',
            ],
            [
                'element' => '[data-tour="cop-pip-remediate"]',
                'section' => 'Fixing a gap',
                'title'   => 'Set a remediation date',
                'body'    => 'For an amber or red item, record by when you will fix it and a short note. This builds a record showing the PPRA that you identified the gap and have a plan.',
            ],
            [
                'element' => '[data-tour="cop-pip-due"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the date you will fix this by.'],
                'title'   => 'Due date',
                'body'    => 'Be realistic — items past their date show as overdue in the Remediation Log.',
            ],
            [
                'element' => '[data-tour="cop-pip-upload"]',
                'title'   => 'Upload a missing document',
                'body'    => 'Where a document is the fix (for example an expired certificate), upload the replacement here and the item turns green. Add the expiry date so CoreX warns you before it lapses.',
            ],
            [
                'element' => '[data-tour="cop-pip-roster"]',
                'section' => 'Registers and samples',
                'title'   => 'The registers',
                'body'    => 'The last column takes you to the register behind an item: the practitioner roster, the letterhead, the transformation statement, the sales and rentals list and the mandate register. Most can be exported as PDF or CSV.',
            ],
            [
                'element' => '[data-tour="cop-pip-sample"]',
                'title'   => 'Choose a sample',
                'body'    => 'The inspector asks for a sample of your sales, rentals and mandates. Choose sample lets you pick which ones go into the pack, instead of leaving it to chance.',
            ],
            [
                'element' => '[data-tour="cop-pip-report"]',
                'section' => 'Handing it over',
                'title'   => 'Download Inspection Report',
                'body'    => 'A single report of your readiness against every item, ready to give the inspector.',
            ],
            [
                'element' => '[data-tour="cop-pip-pack"]',
                'title'   => 'Download full inspection pack',
                'body'    => 'Builds one ZIP containing the report and every source document sampled. It is made in the background, so you will be told when it is ready — the "Last pack" line above shows its progress.',
            ],
            [
                'element' => '[data-tour="cop-pip-remlog"]',
                'section' => 'Tracking your fixes',
                'do'      => ['action' => 'click', 'say' => 'Click Remediation Log to see every gap you are tracking.'],
                'title'   => 'Remediation Log',
                'body'    => 'Every remediation note you have set, with its due date and whether it is open, resolved or overdue.',
            ],
        ],
    ],

    'cop-pip-mandates' => [
        'key'         => 'cop-pip-mandates',
        'title'       => 'Checking mandates, MDFs and FICA',
        'description' => 'See which advertised listings are missing a mandate, a mandate disclosure form or FICA documents.',
        'route'       => 'admin.ppra-inspection-pack.mandate-register',
        'permission'  => 'ppra_inspection_pack.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pim-intro"]',
                'section' => 'What this register is',
                'title'   => 'Mandate / MDF / FICA register',
                'body'    => 'The PPRA expects every advertised listing to have a signed mandate, a mandate disclosure form (MDF) and the owner\'s FICA documents on file. This register shows you, listing by listing, what is missing.',
            ],
            [
                'element' => '[data-tour="cop-pim-status"]',
                'section' => 'Finding the gaps',
                'do'      => ['action' => 'choose', 'say' => 'Pick Missing mandate, Missing MDF or Missing FICA.'],
                'title'   => 'Filter by what is missing',
                'body'    => 'Choose the kind of gap to work through. "All clear" shows the listings that are fully covered.',
            ],
            [
                'element' => '[data-tour="cop-pim-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an address, suburb or agent — or press Skip this step.'],
                'title'   => 'Search',
                'body'    => 'Find a particular listing by address, suburb or the agent responsible.',
            ],
            [
                'element' => '[data-tour="cop-pim-branch"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a branch — or press Skip this step.'],
                'title'   => 'Filter by branch',
                'body'    => 'Look at one branch\'s listings at a time.',
            ],
            [
                'element' => '[data-tour="cop-pim-dates"]',
                'title'   => 'Listed between',
                'body'    => 'Limit the register to listings first listed within a date window.',
            ],
            [
                'element' => '[data-tour="cop-pim-sort"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a sort order — "Status (gaps first)" is the useful one.'],
                'title'   => 'Sort',
                'body'    => 'Sort by address, by status with the gaps first, or by listed date.',
            ],
            [
                'element' => '[data-tour="cop-pim-advertised"]',
                'title'   => 'Advertised only',
                'body'    => 'On by default, because the inspection is about listings that are actually being advertised. Untick it to include listings that are not.',
            ],
            [
                'element'       => '[data-tour="cop-pim-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter.'],
                'title'         => 'Apply the filters',
                'body'          => 'Applies everything you chose above.',
            ],
            [
                'element' => '[data-tour="cop-pim-table"]',
                'section' => 'Reading the register',
                'title'   => 'The register',
                'body'    => 'A green tick means the document is on file; a red cross means it is missing. Click an address to open the listing and upload what is missing.',
            ],
            [
                'element' => '[data-tour="cop-pim-zip"]',
                'section' => 'Downloading the documents',
                'title'   => 'Download ZIP',
                'body'    => 'Packs every mandate and MDF for the listings currently shown into a single ZIP file — handy for an inspector who asks for copies.',
            ],
        ],
    ],

    'cop-pip-remediation' => [
        'key'         => 'cop-pip-remediation',
        'title'       => 'Tracking inspection fixes',
        'description' => 'Review every remediation note set against an inspection gap and mark each one resolved.',
        'route'       => 'admin.ppra-inspection-pack.remediation-log',
        'permission'  => 'ppra_inspection_pack.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="cop-pir-intro"]',
                'section' => 'What the log is',
                'title'   => 'Remediation Log',
                'body'    => 'Every time you set a "fix by" date against an inspection gap, it is recorded here. If the PPRA asks what you did about a gap, this is your proof of the plan and when it was done.',
            ],
            [
                'element' => '[data-tour="cop-pir-status"]',
                'section' => 'Finding a note',
                'do'      => ['action' => 'choose', 'say' => 'Pick Open, Resolved or Overdue.'],
                'title'   => 'Filter by status',
                'body'    => 'Overdue shows the notes whose date has passed without being resolved — start there.',
            ],
            [
                'element' => '[data-tour="cop-pir-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an item letter or some note text — or press Skip this step.'],
                'title'   => 'Search',
                'body'    => 'Finds notes by the inspection item or the words in the note.',
            ],
            [
                'element' => '[data-tour="cop-pir-sort"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how to sort the notes.'],
                'title'   => 'Sort',
                'body'    => 'By due date (the default, soonest first) or by when the note was created.',
            ],
            [
                'element'       => '[data-tour="cop-pir-filter-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Filter.'],
                'title'         => 'Apply the filters',
                'body'          => 'Applies your status, search and sort.',
            ],
            [
                'element' => '[data-tour="cop-pir-table"]',
                'section' => 'Closing a note',
                'title'   => 'The notes',
                'body'    => 'Each row is one note: the inspection item, its due date (red when overdue), the note, who it is assigned to, and whether it is open or resolved.',
            ],
            [
                'element' => '[data-tour="cop-pir-resolve"]',
                'do'      => ['action' => 'click', 'say' => 'Click Mark resolved on a note you have dealt with.'],
                'title'   => 'Mark resolved',
                'body'    => 'Once the gap has been fixed, mark the note resolved. It stays in the log with the date it was closed. Archive removes a note that was made by mistake.',
            ],
        ],
    ],

];
