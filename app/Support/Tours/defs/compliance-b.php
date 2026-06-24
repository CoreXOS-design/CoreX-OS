<?php

/**
 * AT-41 guided-tour pack — Compliance (batch B).
 *
 * Record-keeping, governance and regulatory-return screens used by an agency's
 * compliance officer / manager. Each tour binds to its route by name; the
 * route's own permission middleware already gates access, and where a screen
 * carries an explicit permission key we set it here too so the Guided Tours
 * directory only offers the tour to users who can actually reach the page.
 *
 * Anchors are dedicated data-tour="…" attributes added to the real DOM of each
 * Blade view (non-invasive — attribute only). The engine silently skips any
 * step whose anchor isn't on the page, so every step below points at an element
 * that renders for this screen's audience.
 *
 * @return array<string,array<string,mixed>>
 */

return [

    // ── Communication Archive ────────────────────────────────────────────────
    // routes/web.php → permission:access_communication_archive
    'comp-comm-archive' => [
        'key'         => 'comp-comm-archive',
        'title'       => 'Reading the communication archive',
        'description' => 'The tamper-proof record of business email and WhatsApp — what FICA and POPIA require you to keep.',
        'route'       => 'compliance.comm-archive.index',
        'permission'  => 'access_communication_archive',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-comm-archive-intro"]',
                'title'   => 'Your compliance record',
                'body'    => 'Every business email and WhatsApp message CoreX captures lands here as a read-only record. You cannot edit or delete it — that\'s the point. This is the evidence trail FICA and POPIA expect an agency to keep.',
            ],
            [
                'element' => '[data-tour="comp-comm-archive-filters"]',
                'title'   => 'Find a conversation fast',
                'body'    => 'Search by subject, sender or a snippet of the message. Narrow by channel (Email or WhatsApp) and direction (Inbound or Outbound) when you need to pull the record for one deal or one client.',
            ],
            [
                'element' => '[data-tour="comp-comm-archive-list"]',
                'title'   => 'The message register',
                'body'    => 'Each row is one archived message: when it happened, its channel, who it was from and a preview. The green WhatsApp and blue Email chips tell you the channel at a glance.',
            ],
            [
                'element' => '[data-tour="comp-comm-archive-mailboxes"]',
                'title'   => 'Where the records come from',
                'body'    => 'Mailboxes shows which inboxes feed this archive. If a message is missing, the mailbox connection is usually the place to check. Close this and open any row to read the full message with its audit detail.',
            ],
        ],
    ],

    // ── Policy manager ───────────────────────────────────────────────────────
    // routes/web.php → permission:access_policy
    'comp-policy' => [
        'key'         => 'comp-policy',
        'title'       => 'Managing agency policies',
        'description' => 'Version, approve and track staff sign-off on your POPIA, CPA and other agency policies.',
        'route'       => 'compliance.policy.index',
        'permission'  => 'access_policy',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-policy-intro"]',
                'title'   => 'Your policy library',
                'body'    => 'This is where every agency policy lives — POPIA, the Consumer Protection Act and any house rules. Each policy is versioned so you always know which wording was in force on any given date.',
            ],
            [
                'element' => '[data-tour="comp-policy-card"]',
                'title'   => 'One card per policy',
                'body'    => 'Each policy gets its own card. The table beneath it lists every version of that policy, from the current active one back through its history.',
            ],
            [
                'element' => '[data-tour="comp-policy-status"]',
                'title'   => 'Knowing what\'s live',
                'body'    => 'The Status column tells you each version\'s standing: a green "Active" version is the one in force right now, "Draft" is still being prepared, and "Superseded" is an older version kept for the record.',
            ],
            [
                'element' => '[data-tour="comp-policy-register"]',
                'title'   => 'Who has acknowledged',
                'body'    => 'The Register is the sign-off board — it shows which staff have read and acknowledged the active policy and who still owes you a sign-off. Close this and open the Register to chase outstanding acknowledgements.',
            ],
        ],
    ],

    // ── RMCP manager ─────────────────────────────────────────────────────────
    // routes/web.php → permission:access_rmcp
    'comp-rmcp' => [
        'key'         => 'comp-rmcp',
        'title'       => 'Managing your RMCP',
        'description' => 'Version and approve the agency Risk Management & Compliance Programme that FICA requires.',
        'route'       => 'compliance.rmcp.index',
        'permission'  => 'access_rmcp',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-rmcp-intro"]',
                'title'   => 'The RMCP register',
                'body'    => 'Your Risk Management & Compliance Programme is the document FICA requires every agency to keep — it sets out how you identify and manage money-laundering risk. CoreX keeps every version of it here.',
            ],
            [
                'element' => '[data-tour="comp-rmcp-search"]',
                'title'   => 'Find a version',
                'body'    => 'Search the version history when you need to confirm which RMCP wording applied at a particular time — useful when an inspector or auditor asks.',
            ],
            [
                'element' => '[data-tour="comp-rmcp-versions"]',
                'title'   => 'Version history and approvals',
                'body'    => 'Each row is one version, with its status, who approved it and when it became effective. The green "Active" version is the programme in force today; "Draft" is in preparation and "Superseded" is kept for the audit trail.',
            ],
            [
                'element' => '[data-tour="comp-rmcp-versions"]',
                'title'   => 'Open or print a version',
                'body'    => 'Use View on any row to read the full programme, or PDF to download a signed copy for your file. Close this and open the active version to review what\'s currently in force.',
            ],
        ],
    ],

    // ── Whistleblower complaints register ────────────────────────────────────
    // routes/web.php → permission:compliance.whistleblow.view
    'comp-whistleblow' => [
        'key'         => 'comp-whistleblow',
        'title'       => 'Filing whistleblower reports',
        'description' => 'Lodge and track mandatory reports to the PPRA, with the built-in approval workflow.',
        'route'       => 'compliance.whistleblow.index',
        'permission'  => 'compliance.whistleblow.view',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-whistleblow-intro"]',
                'title'   => 'The reporting register',
                'body'    => 'Some breaches must be reported to the PPRA. This register is where you draft, approve and track those reports so nothing slips through and every report has a clear paper trail.',
            ],
            [
                'element' => '[data-tour="comp-whistleblow-status"]',
                'title'   => 'Track where each report sits',
                'body'    => 'Filter by status to see a report\'s stage — Draft, Pending Approval, Approved, Sent, or Acknowledged by the PPRA. The tier filter sorts reports by severity (Tier 1 through Tier 3).',
            ],
            [
                'element' => '[data-tour="comp-whistleblow-table"]',
                'title'   => 'The report list',
                'body'    => 'Each row shows a report\'s reference, its tier, the agency it concerns and how many days it has been sitting. The Days count is your nudge to act before a report goes stale.',
            ],
            [
                'element' => '[data-tour="comp-whistleblow-intro"]',
                'title'   => 'File a new report',
                'body'    => 'When you need to lodge a fresh report, use "File New Report" in the header. It walks you through the detail the PPRA needs, then routes the draft for approval before it\'s sent. Close this to begin.',
            ],
        ],
    ],

    // ── RCR (Risk & Compliance Return) submissions ───────────────────────────
    // Route group carries only auth middleware; the sidebar gates RCR by role
    // (manager / principal / admin), not by a permission key — so no permission
    // is set here. The route's own gate keeps the auto-launch correct.
    'comp-rcr' => [
        'key'         => 'comp-rcr',
        'title'       => 'Preparing your RCR return',
        'description' => 'Build the FIC Risk & Compliance Return — CoreX drafts the answers, you transpose them into goAML.',
        'route'       => 'corex.compliance.rcr.index',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-rcr-intro"]',
                'title'   => 'Your 2026 FIC return',
                'body'    => 'The Risk & Compliance Return is the annual report the FIC requires (Directive 11 of 2026, due 31 July 2026). CoreX prepares your answers here; you then transpose them into the FIC\'s goAML platform.',
            ],
            [
                'element' => '[data-tour="comp-rcr-kpis"]',
                'title'   => 'Your return at a glance',
                'body'    => 'These tiles show how many returns you\'ve started and how many are still active. Keep "Active returns" at zero once the deadline passes — an open return means there\'s still work to finish.',
            ],
            [
                'element' => '[data-tour="comp-rcr-start"]',
                'title'   => 'Start this year\'s return',
                'body'    => 'Pick the questionnaire for the period and start a new submission. There\'s one return per reporting period — start it early so you have time to gather anything CoreX can\'t fill in for you.',
            ],
            [
                'element' => '[data-tour="comp-rcr-autopop"]',
                'title'   => 'CoreX fills in what it knows',
                'body'    => 'When you start a return, CoreX auto-populates the answers it already holds — your FICA officers, RMCP status and transaction counts — so you only fill in the gaps. Close this and start a submission to see it in action.',
            ],
        ],
    ],

];
