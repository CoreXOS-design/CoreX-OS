<?php

/**
 * AT-41 guided-tour pack — Rentals division + Deals v2 (create + detail).
 *
 * Each entry is pure DATA merged by App\Support\Tours\TourRegistry::all().
 * Every `element` selector anchors a dedicated data-tour="…" attribute added to
 * the real DOM of the page, so a markup refactor never silently drops a step.
 *
 * NOTE: deals-v2.index is covered by a SEPARATE pack — not touched here.
 */

return [

    // ── Rentals division dashboard ───────────────────────────────────────────
    'rent-dashboard' => [
        'key'         => 'rent-dashboard',
        'title'       => 'Rental Division at a glance',
        'description' => 'Read the rental dashboard — what each tile counts and where each shortcut takes you.',
        'route'       => 'rental.dashboard',
        'permission'  => 'view_rentals',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="rent-dashboard-intro"]',
                'title'   => 'Your rental home base',
                'body'    => 'This is the Rental Division dashboard — one place to see where every lease stands. Sales deals live elsewhere; this screen is just rentals.',
            ],
            [
                'element' => '[data-tour="rent-dashboard-tiles"]',
                'title'   => 'The number tiles',
                'body'    => 'Each tile is a live count: leases that Need Approval, Drafts, ones Ready to Sign, those Awaiting Signatures, Completed, Active Leases, and any Expiring in the next 90 days. Tap a tile to jump straight to that list.',
            ],
            [
                'element' => '[data-tour="rent-dashboard-signatures"]',
                'title'   => 'Electronic Signatures',
                'body'    => 'This is where you send a lease out for signing and watch each party sign. It is the busiest screen in the division.',
            ],
            [
                'element' => '[data-tour="rent-dashboard-actions"]',
                'title'   => 'Quick actions',
                'body'    => 'Three shortcuts to the work you do most: signing workflows, active leases, and expired leases. Close this and tap a tile to see your leases.',
            ],
        ],
    ],

    // ── Active leases ────────────────────────────────────────────────────────
    'rent-active-leases' => [
        'key'         => 'rent-active-leases',
        'title'       => 'Working active leases',
        'description' => 'See every signed, in-force lease — and renew or check the history of each one.',
        'route'       => 'rental.active-leases',
        'permission'  => 'view_rentals',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="rent-active-leases-intro"]',
                'title'   => 'Active leases',
                'body'    => 'Every lease here is signed and currently in force. This is your live rent roll.',
            ],
            [
                'element' => '[data-tour="rent-active-leases-upload"]',
                'title'   => 'Upload & Send a lease',
                'body'    => 'Already have a lease document ready? Use this to upload it and send it out for electronic signing in one step.',
            ],
            [
                'element' => '[data-tour="rent-active-leases-list"]',
                'title'   => 'The lease cards',
                'body'    => 'Each card shows the property, tenant, landlord and monthly rental in Rands. The coloured badge is the warning level: green "Active" is healthy, amber "Nd left" means it expires within 90 days, red "Expired" needs action. Close this and open the one expiring soonest to renew it.',
            ],
        ],
    ],

    // ── Expired leases ───────────────────────────────────────────────────────
    'rent-expired-leases' => [
        'key'         => 'rent-expired-leases',
        'title'       => 'Reviewing expired leases',
        'description' => 'Review leases that have ended or been terminated — and renew the ones still worth saving.',
        'route'       => 'rental.expired-leases',
        'permission'  => 'view_rentals',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="rent-expired-leases-intro"]',
                'title'   => 'Expired leases',
                'body'    => 'These leases have run their full term or been terminated early. Nothing here is in force — it is your record of what has ended.',
            ],
            [
                'element' => '[data-tour="rent-expired-leases-list"]',
                'title'   => 'What the badges mean',
                'body'    => 'A red "Expired" badge means the term simply ran out. A grey "Terminated" badge means the lease was ended early. Each card still shows the property, tenant, landlord and rental for your records.',
            ],
            [
                'element' => '[data-tour="rent-expired-leases-actions"]',
                'title'   => 'What you can still do',
                'body'    => 'Even on an ended lease you can pull the signed Audit trail, download the PDF, view the History, or hit Renew Lease to start a fresh term with the same parties. Close this and renew any lease the tenant is staying on.',
            ],
        ],
    ],

    // ── Electronic signatures (rental) — POINT-ONLY (live signing status) ─────
    'rent-signatures' => [
        'key'         => 'rent-signatures',
        'title'       => 'Rental signing workflow',
        'description' => 'Follow a rental lease from draft to fully signed — and read each party\'s live signing status.',
        'route'       => 'rental.signatures',
        'permission'  => 'view_rentals',
        // POINT-ONLY: this screen shows live, in-flight signing status. No setup
        // clicks, no state changes — we only spotlight and explain.
        'steps' => [
            [
                'element' => '[data-tour="rent-signatures-intro"]',
                'title'   => 'Electronic Signatures',
                'body'    => 'This screen runs every rental lease through signing — and shows you exactly where each one is, right now, in real time.',
            ],
            [
                'element' => '[data-tour="rent-signatures-cards"]',
                'title'   => 'The stages of signing',
                'body'    => 'A lease moves left to right: Draft (fields still being filled), Ready to Sign, Awaiting Signatures (out with the tenant or landlord), then Completed. "Needs Approval" means a signed copy is waiting for you to check it. Tap any card to scroll to that group.',
            ],
            [
                'element' => '[data-tour="rent-signatures-upload"]',
                'title'   => 'Send a lease for signing',
                'body'    => 'Start a new signing run here — upload the lease, choose who signs, and CoreX emails each party their turn. Close this when you are ready to send your first lease.',
            ],
        ],
    ],

    // ── Rental stock register ────────────────────────────────────────────────
    'rent-stock' => [
        'key'         => 'rent-stock',
        'title'       => 'The rentals register',
        'description' => 'Read the rentals register — total rentals, commission, the per-agent split, and every rental row.',
        'route'       => 'rentals.index',
        'permission'  => 'view_rentals',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="rent-stock-intro"]',
                'title'   => 'Rentals Register',
                'body'    => 'This is the full register of every rental assigned to the agency — not a monthly view, but the complete list you can work from.',
            ],
            [
                'element' => '[data-tour="rent-stock-new"]',
                'title'   => 'Capture a new rental',
                'body'    => 'Use this to record a new rental — the property, the lease dates and the commission split. It then appears in the table below.',
            ],
            [
                'element' => '[data-tour="rent-stock-summary"]',
                'title'   => 'The two headline numbers',
                'body'    => 'At a glance: how many rentals you hold in total, and the total commission earned excluding VAT, shown in Rands.',
            ],
            [
                'element' => '[data-tour="rent-stock-per-agent"]',
                'title'   => 'Per-agent breakdown',
                'body'    => 'This breaks the register down by agent — how many rentals each one carries and the commission attached. Useful for splits and for seeing who is carrying the rent roll.',
            ],
            [
                'element' => '[data-tour="rent-stock-table"]',
                'title'   => 'The rental rows',
                'body'    => 'Every rental, one per row: address, lease start and end, whether it is month-to-month, whether it is still active, and the commission excluding VAT. Close this and capture a rental to add your first row.',
            ],
        ],
    ],

    // ── New-deal wizard (deals v2) ───────────────────────────────────────────
    'deals-create' => [
        'key'         => 'deals-create',
        'title'       => 'Capturing a new deal',
        'description' => 'Walk the new-deal wizard step by step — property, parties, commission, then the pipeline.',
        'route'       => 'deals-v2.create',
        'permission'  => 'deals_v2.create',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="deals-create-intro"]',
                'title'   => 'New Deal',
                'body'    => 'This wizard turns an accepted offer into a tracked deal. You capture the property, the parties and the commission, and CoreX builds the deal pipeline for you.',
            ],
            [
                'element' => '[data-tour="deals-create-rail"]',
                'title'   => 'The five steps',
                'body'    => 'Property → Contacts → Details → Pipeline → Confirm. The highlighted step is where you are now; a green tick marks a finished one. You can click back to any step you have already passed.',
            ],
            [
                'element' => '[data-tour="deals-create-step1"]',
                'title'   => 'Step 1 — the property',
                'body'    => 'Every deal hangs off a property, so that is first. The deal will link straight to the property record you pick here — no re-typing the address.',
            ],
            [
                'element' => '[data-tour="deals-create-property-search"]',
                'title'   => 'Find the property',
                'body'    => 'Start typing the address. Matches from your stock appear below — pick the right one and its listing price and agent come across automatically.',
            ],
            [
                'element' => '[data-tour="deals-create-next"]',
                'title'   => 'Move to the parties',
                'body'    => 'Once a property is selected this Next button lights up and carries you to Contacts, where you add the buyers and sellers. Close this and search for your property to begin.',
            ],
        ],
    ],

    // ── Deal detail / timeline (deals v2) — CONTEXT-BOUND ────────────────────
    'deals-detail' => [
        'key'         => 'deals-detail',
        'title'       => 'Reading a deal',
        'description' => 'Open a deal, then tap ? to follow this — the summary, the status, and the pipeline tracker.',
        'route'       => 'deals-v2.show',
        'permission'  => 'deals_v2.view',
        // CONTEXT-BOUND: this tour needs a specific deal loaded. It binds by route
        // name and runs once the agent is on a deal record.
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="deals-detail-intro"]',
                'title'   => 'The deal record',
                'body'    => 'This is one deal, end to end. The header carries the deal reference and the property address, and stays pinned as you scroll so you never lose your place.',
            ],
            [
                'element' => '[data-tour="deals-detail-status"]',
                'title'   => 'Status and health',
                'body'    => 'The pill shows the deal status — Active, Completed, On Hold or Cancelled. The small dot beside it is the health light: green is on track, amber needs attention, red is overdue and pulses to catch your eye.',
            ],
            [
                'element' => '[data-tour="deals-detail-summary"]',
                'title'   => 'The deal summary',
                'body'    => 'Four cards give you the whole picture: the property, the parties involved, the commission in Rands plus VAT, and the key dates including how many days the deal has been running.',
            ],
            [
                'element' => '[data-tour="deals-detail-pipeline"]',
                'title'   => 'The pipeline tracker',
                'body'    => 'This is the deal\'s spine — every step from offer to registration, each with its own status and due date. Overdue steps turn red; a star marks a milestone. Close this and open the active step to log your progress.',
            ],
        ],
    ],

];
