<?php

/**
 * AT-41 guided-tour pack — Deals v2 (create + detail).
 *
 * Each entry is pure DATA merged by App\Support\Tours\TourRegistry::all().
 * Every `element` selector anchors a dedicated data-tour="…" attribute added to
 * the real DOM of the page, so a markup refactor never silently drops a step.
 *
 * NOTE: deals-v2.index is covered by a SEPARATE pack — not touched here.
 *
 * AT-439 (2026-10-05) — the 5 Rentals-division tour entries this file used to
 * carry (rent-dashboard, rent-active-leases, rent-expired-leases,
 * rent-signatures, rent-stock) are removed: every route they anchored on
 * (rental.dashboard/.active-leases/.expired-leases/.signatures, rentals.index)
 * is retired and now redirects instantly, so none of their `element` selectors
 * could ever be found on screen again. Superseded by the Lease Hub and the
 * Rental Command Centre, neither of which has a tour pack of its own yet.
 */

return [

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
