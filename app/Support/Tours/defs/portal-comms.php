<?php

/**
 * Guided-tour pack: My Portal + Communications + Earnings (AT-41).
 *
 * The agent's own corner of CoreX — the screens a brand-new estate agent meets
 * in their first week: their personal compliance home, the mailbox/WhatsApp
 * capture they switch on for the legal archive, the message triage queue, leave
 * and payslips, the agency document library, and their earnings dashboard.
 *
 * Every step anchors on a real `data-tour="..."` element added to the live view —
 * the engine silently skips any step whose selector is absent, so anchors are the
 * contract. Keys are namespaced (portal-* / comms-* / earn-*) so this pack never
 * clobbers another module's keys when TourRegistry merges the defs folder.
 */

return [

    // ── My Portal home ───────────────────────────────────────────────────────
    'portal-home' => [
        'key'         => 'portal-home',
        'title'       => 'Your My Portal home',
        'description' => 'Your personal home in CoreX — compliance, FFC, training and earnings in one place.',
        'route'       => 'agent.portal',
        'permission'  => 'access_my_portal',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="portal-home-intro"]',
                'section' => 'Your portal at a glance',
                'title'   => 'This is your portal',
                'body'    => 'Everything that is about you lives here — your profile, your documents, your compliance and your pay. Start your week on this screen and you will always know where you stand.',
            ],
            [
                'element' => '[data-tour="portal-home-compliance"]',
                'prep'    => [['action' => 'click', 'selector' => '[data-tour="portal-home-tab-overview"]']],
                'title'   => 'Your compliance at a glance',
                'body'    => 'A green dot means that item is in order; amber or red means it needs attention. Your FFC (Fidelity Fund Certificate) lives here — you may not legally trade without a valid one, so keep these green.',
            ],
            [
                'element' => '[data-tour="portal-home-earnings"]',
                'prep'    => [['action' => 'click', 'selector' => '[data-tour="portal-home-tab-overview"]']],
                'title'   => 'Your earnings snapshot',
                'body'    => 'A quick view of what you have earned this month and year, plus how far you are towards your annual cap. Tap "View Full Earnings" any time for the full breakdown.',
            ],
            [
                'element' => '[data-tour="portal-home-tabs"]',
                'section' => 'Checking your compliance',
                'do'      => ['action' => 'click', 'target' => '[data-tour="portal-home-tab-compliance"]', 'say' => 'Click the Compliance tab.', 'until' => '[data-tour="portal-home-compliance-tab"]'],
                'skip_if' => '[data-tour="portal-home-compliance-tab"]',
                'title'   => 'The rest of your portal',
                'body'    => 'These tabs hold your Profile, Documents, Compliance detail, Training, Payslips and Leave. Close this and start by checking your Compliance tab is all green.',
            ],
            [
                'element'       => '[data-tour="portal-home-compliance-breakdown"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click the button next to anything that is not green to fix it — or press Skip this step if everything is green.'],
                'title'         => 'Fix what is not green',
                'body'          => 'Every item that needs attention has a button beside it that takes you straight to where you fix it — your Profile, your Documents or the RMCP acknowledgement.',
            ],
        ],
    ],

    // ── Communication Capture (email self-service) ───────────────────────────
    'portal-comm-capture' => [
        'key'         => 'portal-comm-capture',
        'title'       => 'Link your email for capture',
        'description' => 'Connect your mailbox so client emails are archived for the legal 5-year record.',
        'route'       => 'my-portal.comm-capture.index',
        'permission'  => 'access_communication',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="portal-comm-capture-intro"]',
                'section' => 'Linking your mailbox',
                'title'   => 'Why link your mailbox',
                'body'    => 'The law requires your agency to keep client communications for five years. Linking your mailbox here lets CoreX archive those emails automatically — no copying, no forwarding.',
            ],
            [
                'element' => '[data-tour="portal-comm-capture-mailbox"]',
                'do'      => ['action' => 'click', 'target' => '[data-tour="user-mailbox-add"]', 'say' => 'Click + Link a mailbox.', 'until' => '[data-tour="user-mailbox-add-form"]'],
                'skip_if' => '[data-tour="user-mailbox-add-form"]',
                'title'   => 'Add your email account',
                'body'    => 'Enter your email address and its connection details. Your password is encrypted, write-only, and never shown back to anyone — not even your principal.',
            ],
            [
                'element'       => '[data-tour="user-mailbox-add-form"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'input[name="email_address"]', 'say' => 'Type your work email address.'],
                'title'         => 'Your email address',
                'body'          => 'The mailbox you use with clients — the one whose emails must be kept for the legal record.',
            ],
            [
                'element'       => '[data-tour="user-mailbox-add-form"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'input[name="username"]', 'say' => 'Type the username you log in to your mailbox with (usually your email address).'],
                'title'         => 'Mailbox username',
                'body'          => 'The name your mail server knows you by. For most mailboxes this is simply your full email address.',
            ],
            [
                'element'       => '[data-tour="user-mailbox-add-form"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'input[name="imap_host"]', 'say' => 'Type your mail server\'s IMAP address, e.g. imap.yourdomain.co.za.'],
                'title'         => 'Mail server address',
                'body'          => 'Where CoreX collects your mail from. Not sure? Your email provider or your agency\'s IT person can give you the IMAP address — the port is usually 993.',
            ],
            [
                'element'       => '[data-tour="user-mailbox-add-form"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'input[name="password"]', 'say' => 'Type your mailbox password.'],
                'title'         => 'Mailbox password',
                'body'          => 'Stored encrypted and never shown back to anyone. To change it later, you simply type a new one.',
            ],
            [
                'element'       => '[data-tour="user-mailbox-add-submit"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Link mailbox.'],
                'title'         => 'Link it',
                'body'          => 'CoreX saves the mailbox and starts archiving your client emails on the schedule shown. Nothing is sent from your mailbox.',
            ],
            [
                'element' => '[data-tour="portal-comm-capture-back"]',
                'section' => 'Back to your portal',
                'do'      => ['action' => 'click', 'say' => 'Click My Portal to go back.'],
                'title'   => 'Back to your portal',
                'body'    => 'Done here, or your agency set it up for you? This takes you back to My Portal. Close this and add your work mailbox so your client emails start archiving.',
            ],
        ],
    ],

    // ── My Leave ─────────────────────────────────────────────────────────────
    'portal-leave' => [
        'key'         => 'portal-leave',
        'title'       => 'Apply for and track leave',
        'description' => 'See your leave balances, apply for time off, and follow each request.',
        'route'       => 'my-portal.leave.index',
        'permission'  => 'apply_for_leave',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="portal-leave-balances"]',
                'section' => 'Checking your balance',
                'title'   => 'Your leave balances',
                'body'    => 'Each card shows a leave type and how many days you have available out of your yearly entitlement, plus when that cycle resets. Check here before you plan time off.',
            ],
            [
                'element' => '[data-tour="portal-leave-apply"]',
                'section' => 'Applying for leave',
                'do'      => ['action' => 'click', 'say' => 'Click Apply for Leave to open the application form.'],
                'title'   => 'Apply for leave',
                'body'    => 'This opens a short form to request time off. Pick the type and dates, and CoreX works out the working days for you and sends it for approval.',
            ],
            [
                'element' => '[data-tour="portal-leave-status-tabs"]',
                'section' => 'Following your requests',
                'do'      => ['action' => 'click', 'say' => 'Click Pending to see the requests still waiting for approval.'],
                'title'   => 'Filter by status',
                'body'    => 'Switch between All, Pending, Approved, Rejected and Cancelled. Pending means it is still with your manager; Approved means you are good to go.',
            ],
            [
                'element' => '[data-tour="portal-leave-applications"]',
                'title'   => 'Your applications',
                'body'    => 'Every request you have made is listed below, with its dates, days and current status. Close this and tap "Apply for Leave" when you are ready to book time off.',
            ],
        ],
    ],

    // ── My Payslips ──────────────────────────────────────────────────────────
    'portal-payslips' => [
        'key'         => 'portal-payslips',
        'title'       => 'Find your payslips',
        'description' => 'View and download every finalised payslip your agency issues you.',
        'route'       => 'my-portal.payslips',
        'permission'  => 'view_own_payslips',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="portal-payslips-intro"]',
                'section' => 'Finding a payslip',
                'title'   => 'Your payslips',
                'body'    => 'Once your employer finalises a payroll run, that month\'s payslip appears here. If the list is empty, there is simply nothing finalised yet.',
            ],
            [
                'element' => '[data-tour="portal-payslips-table"]',
                'title'   => 'Each pay period',
                'body'    => 'One row per month, showing the pay date, your gross earnings and your net pay — the amount that actually lands in your account.',
            ],
            [
                'element' => '[data-tour="portal-payslips-actions"]',
                'section' => 'Viewing or downloading',
                'title'   => 'View or download',
                'body'    => 'Use View to see the full breakdown on screen, or Download to save the PDF for your records or the bank. Close this — your payslips are always here when you need them.',
            ],
            [
                'element'       => '[data-tour="portal-payslips-download"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Download to save this payslip as a PDF.'],
                'title'         => 'Download the PDF',
                'body'          => 'Saves the payslip to your device — handy for the bank or your own records.',
            ],
            [
                'element'       => '[data-tour="portal-payslips-view"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click View to see the full breakdown.'],
                'title'         => 'See the full breakdown',
                'body'          => 'Opens the payslip on screen with every earning and deduction listed.',
            ],
        ],
    ],

    // ── Agency Documents (staff read-only viewer) ────────────────────────────
    'portal-agency-docs' => [
        'key'         => 'portal-agency-docs',
        'title'       => 'Your agency documents',
        'description' => 'Read and download the compliance documents your agency makes available to staff.',
        'route'       => 'my-portal.agency-documents',
        'permission'  => 'view_agency_documents',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="portal-agency-docs-intro"]',
                'section' => 'Finding your documents',
                'title'   => 'The agency library',
                'body'    => 'These are the compliance documents your agency keeps for everyone — things like your FICA risk policy and POPIA notice. Where your branch has its own version, you see that one.',
            ],
            [
                'element' => '[data-tour="portal-agency-docs-grid"]',
                'title'   => 'One card per document',
                'body'    => 'Each card is a single document. A "Required" tag means it is one you are expected to read. The grey "Company" or blue branch tag tells you which version applies to you.',
            ],
            [
                'element' => '[data-tour="portal-agency-docs-status"]',
                'title'   => 'The status dot',
                'body'    => 'The coloured dot and its label show whether the document is current. If something looks out of date or missing, your compliance officer can help.',
            ],
            [
                'element' => '[data-tour="portal-agency-docs-download"]',
                'section' => 'Reading a document',
                'do'      => ['action' => 'click', 'say' => 'Click Download to open and read this document.'],
                'title'   => 'Download to read',
                'body'    => 'Tap Download to open the document. Close this and make sure you have read anything marked Required.',
            ],
        ],
    ],

    // ── Message Triage ───────────────────────────────────────────────────────
    'comms-triage' => [
        'key'         => 'comms-triage',
        'title'       => 'Triage unknown messages',
        'description' => 'Decide what to do with messages from people not yet in your contacts.',
        'route'       => 'communications.triage.index',
        'permission'  => 'triage_communications',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comms-triage-intro"]',
                'section' => 'Your triage queue',
                'title'   => 'What triage is',
                'body'    => 'When an email or WhatsApp arrives from someone CoreX does not recognise, it waits here for your decision. You tell CoreX whether that person matters to your work.',
            ],
            [
                'element'       => '[data-tour="comms-triage-channel"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Pick Email or WhatsApp to show only that channel — or press Skip this step to see them all.'],
                'title'         => 'Filter by channel',
                'body'          => 'Working through email first, or just WhatsApp? Pick a channel and the list reloads with only those messages.',
            ],
            [
                'element' => '[data-tour="comms-triage-table"]',
                'section' => 'Adding the sender as a contact',
                'title'   => 'Messages awaiting you',
                'body'    => 'Each row shows when the message came in, which channel it used, who it was from, and a preview — enough to recognise the person without opening anything.',
            ],
            [
                'element' => '[data-tour="comms-triage-add"]',
                'do'      => ['action' => 'click', 'say' => 'Click Add contact if this is a real client or lead.', 'until' => '[data-tour="comms-triage-add-form"]'],
                'title'   => 'Add contact',
                'body'    => 'If this is a real client or lead, add them as a Contact. CoreX then archives this conversation and any others from them — building your compliance record automatically.',
            ],
            [
                'element'       => '[data-tour="comms-triage-add-form"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'input[name="first_name"]', 'say' => 'Type the person\'s first name.'],
                'skip_if'       => '[data-tour="comms-triage-table"]:not(:has([data-tour="comms-triage-add"]))',
                'title'         => 'Their name',
                'body'          => 'CoreX has already filled in their number or email from the message. Add their name so you recognise them later.',
            ],
            [
                'element'       => '[data-tour="comms-triage-add-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Add & Archive — this saves the contact and files the conversation in your records.'],
                'skip_if'       => '[data-tour="comms-triage-table"]:not(:has([data-tour="comms-triage-add"]))',
                'title'         => 'Save and file it',
                'body'          => 'The person becomes a Contact, and this conversation and any others from them are kept in your compliance record.',
            ],
            [
                // "Not real estate" removes the message from the agent's list, so it
                // stays an explanation-only step — the guide never walks anyone into
                // removing things.
                'element' => '[data-tour="comms-triage-dismiss"]',
                'section' => 'Clearing what is not work',
                'title'   => 'Not real estate',
                'body'    => 'Spam, a supplier, a wrong number? Tap "Not real estate" to clear it from your list — CoreX will stop bringing it up. Close this and clear your queue, one decision at a time.',
            ],
        ],
    ],

    // ── WhatsApp Capture device linking ──────────────────────────────────────
    'comms-wa-devices' => [
        'key'         => 'comms-wa-devices',
        'title'       => 'Link WhatsApp Capture',
        'description' => 'Register the device that runs the read-only WhatsApp capture extension.',
        'route'       => 'communications.wa-devices.index',
        'permission'  => 'access_communication',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comms-wa-devices-intro"]',
                'section' => 'Registering your device',
                'title'   => 'Why register a device',
                'body'    => 'Your business WhatsApp chats with loaded contacts must be archived too. A small read-only browser extension does this — and it only runs on a device you register here.',
            ],
            [
                'element' => '[data-tour="comms-wa-devices-register"]',
                'do'      => ['action' => 'fill', 'say' => 'Type your WhatsApp number — or press Skip this step to leave it out.'],
                'title'   => 'Register and get a token',
                'body'    => 'Add your WhatsApp number (optional) and tap Register. CoreX issues a one-time security token — paste it into the extension. The token is shown only once, so keep it safe.',
            ],
            [
                'element'       => '[data-tour="comms-wa-devices-register-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Register Device & Issue Token.'],
                'title'         => 'Register the device',
                'body'          => 'CoreX registers this device and shows you a one-time security token straight after.',
            ],
            [
                'element'       => '[data-tour="comms-wa-devices-token"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'target' => 'code', 'say' => 'Select the token and copy it — you paste it into the extension next.'],
                'title'         => 'Copy your token',
                'body'          => 'This token links the extension to your CoreX login. It is shown only once, so copy it now.',
            ],
            [
                'element' => '[data-tour="comms-wa-devices-extension"]',
                'section' => 'Installing the extension',
                'do'      => ['action' => 'click', 'target' => 'a', 'say' => 'Click wa-capture-extension.zip to download the extension.'],
                'title'   => 'Get the extension',
                'body'    => 'Download the capture extension here, load it into Chrome, then enter your CoreX address and that token. It only reads — it never sends messages on your behalf.',
            ],
            [
                // Revoke is the only action in this table — explanation only, never guided.
                'element' => '[data-tour="comms-wa-devices-table"]',
                'section' => 'Your linked devices',
                'title'   => 'Your registered devices',
                'body'    => 'Every device you link shows here with when it was last seen. Lost a phone or laptop? Tap Revoke to stop it capturing. Close this and register your work device to get started.',
            ],
        ],
    ],

    // ── My Earnings (commission dashboard) ───────────────────────────────────
    'earn-dashboard' => [
        'key'         => 'earn-dashboard',
        'title'       => 'Read your earnings dashboard',
        'description' => 'Track your commission, annual cap progress and revenue share.',
        'route'       => 'commission.dashboard',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                // Read-only screen (no buttons or links), so sections only — no `do`.
                'element' => '[data-tour="earn-dashboard-intro"]',
                'section' => 'Your headline numbers',
                'title'   => 'Your earnings, live',
                'body'    => 'This is the full picture of what you are earning. It updates as deals are processed, so it is always current — no waiting for a month-end statement.',
            ],
            [
                'element' => '[data-tour="earn-dashboard-cards"]',
                'title'   => 'The headline numbers',
                'body'    => 'At a glance: what you have earned this month and this year, how far you are toward your cap, and your revenue share. All amounts are in Rand.',
            ],
            [
                'element' => '[data-tour="earn-dashboard-cap"]',
                'title'   => 'Your annual cap',
                'body'    => 'Once your paid commission reaches your cap for the year, you keep 100% of what you earn after that. The bar shows how close you are, and when the cap resets.',
            ],
            [
                'element' => '[data-tour="earn-dashboard-chart"]',
                'section' => 'Your trend and deals',
                'title'   => 'Your last 12 months',
                'body'    => 'The chart plots your earnings month by month, so you can see your trend and your busiest seasons at a glance.',
            ],
            [
                'element' => '[data-tour="earn-dashboard-transactions"]',
                'title'   => 'Recent transactions',
                'body'    => 'Every deal that fed your earnings is listed here. Close this and check your latest transaction has come through correctly.',
            ],
        ],
    ],

];
