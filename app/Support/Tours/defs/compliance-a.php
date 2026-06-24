<?php

/**
 * AT-41 Guided-Tour pack — Compliance (batch A).
 *
 * Returns array<key, definition> merged by App\Support\Tours\TourRegistry::all().
 * Every step anchors on a real [data-tour="..."] element added to the matching
 * Blade view. Keys are namespaced "comp-" so they never clobber the core set.
 *
 * NOTE: the existing core tour `fica-capture` already covers
 * compliance.fica.index — it is NOT touched here.
 *
 * These are manager / compliance-officer screens. Each tour sets the exact
 * permission key guarding its route (from routes/web.php) so the help directory
 * only lists it to the role that can actually reach it.
 */

return [

    // ── FICA — capture / send a verification request ─────────────────────────
    // Route gate: middleware('permission:access_compliance') on the fica group.
    // Single-step form (pick a contact → send). The verification itself happens
    // on the secure link the contact receives — this screen just starts it.
    'comp-fica-create' => [
        'key'         => 'comp-fica-create',
        'title'       => 'Sending a FICA verification request',
        'description' => 'Choose a contact and send them a FICA verification request to complete.',
        'route'       => 'compliance.fica.create',
        'permission'  => 'access_compliance',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-fica-create-intro"]',
                'title'   => 'Start a FICA verification',
                'body'    => 'FICA is the law that requires you to confirm who your client really is before you act for them. This screen sends the request — you pick the person, CoreX does the rest.',
            ],
            [
                'element' => '[data-tour="comp-fica-create-contact"]',
                'title'   => 'Pick the contact',
                'body'    => 'Search and select the buyer, seller, landlord or tenant you need to verify. They must already exist as a Contact, and they must have an email address on file — that\'s where the secure link goes.',
            ],
            [
                'element' => '[data-tour="comp-fica-create-send"]',
                'title'   => 'Send the request',
                'body'    => 'This emails the contact a secure link to upload their ID and proof of address themselves. You\'ll watch it move to verified back on the FICA list — no paper, no chasing. Close this and send your first request.',
            ],
        ],
    ],

    // ── Compliance Officer — DELIBERATELY SKIPPED (AT-41) ────────────────────
    // The route compliance.officer.index is RETIRED: it 301-redirects to
    // /corex/settings?tab=user (routes/web.php ~1597) and renders no page of its
    // own, so a tour bound here would never launch (the browser leaves before the
    // engine loads). The real RmcpComplianceOfficerController@index view is not
    // wired to any route today. No tour is registered — re-add one here once the
    // screen is live again. The data-tour anchors left in compliance/officer/
    // index.blade.php are harmless and ready for that day.

    // ── Employee Screening register ──────────────────────────────────────────
    // Route gate: middleware('permission:manage_employee_screenings').
    'comp-screenings' => [
        'key'         => 'comp-screenings',
        'title'       => 'The employee screening register',
        'description' => 'Track staff background screenings — who\'s been screened, their risk tier, and what\'s due.',
        'route'       => 'compliance.screenings.index',
        'permission'  => 'manage_employee_screenings',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-screenings-new"]',
                'title'   => 'Start a new screening',
                'body'    => 'FICA expects you to screen the people who work for the agency. This register holds every screening; start a fresh one for a staff member here.',
            ],
            [
                'element' => '[data-tour="comp-screenings-overdue"]',
                'title'   => 'See what\'s overdue',
                'body'    => 'Screenings expire and must be redone. Overdue jumps straight to anyone whose screening has lapsed — clear this list to stay compliant.',
            ],
            [
                'element' => '[data-tour="comp-screenings-filters"]',
                'title'   => 'Filter the register',
                'body'    => 'Narrow by name, by Status — In Progress, Completed or Flagged — or by Risk Tier (High, Medium, Low). Risk Tier is how much scrutiny a role needs; a flagged screening is one that found something to review.',
            ],
            [
                'element' => '[data-tour="comp-screenings-table"]',
                'title'   => 'The register itself',
                'body'    => 'Each row is one staff member\'s screening — their risk tier, status, when it was completed and when it\'s next due. A red Next Due date means it has lapsed. Click View to open one. Close this and check yours are up to date.',
            ],
        ],
    ],

    // ── Document Verification queue ──────────────────────────────────────────
    // Route gate: middleware('permission:verify_user_documents').
    'comp-verification' => [
        'key'         => 'comp-verification',
        'title'       => 'The document verification queue',
        'description' => 'Review and verify the compliance documents your agents upload — FFCs, IDs and more.',
        'route'       => 'compliance.verification.index',
        'permission'  => 'verify_user_documents',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-verification-intro"]',
                'title'   => 'The verification queue',
                'body'    => 'When an agent uploads a compliance document — their FFC, ID, qualification — it lands here for you to check before it counts. You are the gatekeeper that keeps the agency\'s records trustworthy.',
            ],
            [
                'element' => '[data-tour="comp-verification-stats"]',
                'title'   => 'Where things stand',
                'body'    => 'Three counts at a glance: Pending Verification is your to-do list; Verified and Rejected show what you\'ve actioned in the last 7 days. Click Verified or Rejected to expand that history.',
            ],
            [
                'element' => '[data-tour="comp-verification-pending"]',
                'title'   => 'Work the pending list',
                'body'    => 'Each row is one document waiting on you — who uploaded it, the type, and its expiry date (red means expired or expiring soon). Review opens the file so you can verify or reject it. Close this and clear the queue.',
            ],
        ],
    ],

    // ── Seller Information Pack ──────────────────────────────────────────────
    // Route gate: middleware('permission:compliance.whistleblow.view') on the
    // seller-info group.
    'comp-seller-info' => [
        'key'         => 'comp-seller-info',
        'title'       => 'Sending a Seller Information Pack',
        'description' => 'Send a seller a researched pack explaining why proper compliance paperwork protects them.',
        'route'       => 'compliance.seller-info.index',
        'permission'  => 'compliance.whistleblow.view',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="comp-seller-info-intro"]',
                'title'   => 'The Seller Information Pack',
                'body'    => 'A ready-made, legally-researched message you send a seller to explain why proper paperwork — a signed mandate, FICA, a valid FFC — protects them. A polite, professional way to win a hesitant seller over to doing it right.',
            ],
            [
                'element' => '[data-tour="comp-seller-info-tier"]',
                'title'   => 'Pick the issue',
                'body'    => 'Choose what you\'re addressing: no mandate/FICA signed, an agent with no FFC on display, or an agent who appears unregistered. Your choice swaps in the right content and the right tone for the situation.',
            ],
            [
                'element' => '[data-tour="comp-seller-info-property"]',
                'title'   => 'Link a property (optional)',
                'body'    => 'Search for and attach the property. CoreX then auto-loads the sellers linked to it as recipients — so you don\'t retype their details.',
            ],
            [
                'element' => '[data-tour="comp-seller-info-recipients"]',
                'title'   => 'Choose who receives it',
                'body'    => 'Add or tick the people to send to. You can add up to ten by hand, or let the linked property fill them in. Only recipients with an email address are sent the pack.',
            ],
            [
                'element' => '[data-tour="comp-seller-info-actions"]',
                'title'   => 'Preview, send or share',
                'body'    => 'Preview Email shows exactly what they\'ll receive; Send delivers it by email; Copy WhatsApp Link gives you a link to paste into a WhatsApp chat instead. Close this and reach out to your first seller.',
            ],
        ],
    ],

];
