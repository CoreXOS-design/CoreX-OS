<?php

/**
 * AT-41 Guided-Tour pack — Training, AI assistant, Calculators & Tools.
 *
 * Each entry is pure DATA merged by App\Support\Tours\TourRegistry::all().
 * Every `element` selector points at a real data-tour="…" anchor added to the
 * corresponding Blade view, so a markup refactor can never silently break a step.
 *
 * Permission keys are set ONLY where the guarding key was confirmed from
 * routes/web.php and/or the sidebar @permission wrapper. Where the route sits in
 * the broad authenticated group with no specific permission middleware, the key
 * is omitted so the tour inherits the route's own gate (safe default).
 *
 * NOT INCLUDED: tv.index — that screen renders its own standalone HTML document
 * (no corex layout), so the tour engine partial is never loaded there and a tour
 * could not run. Skipped honestly rather than shipped dead.
 *
 * @return array<string,array<string,mixed>>
 */

return [

    // ── Training courses (My Training / LMS) ─────────────────────────────────
    'train-courses' => [
        'key'         => 'train-courses',
        'title'       => 'Your training courses',
        'description' => 'Find, start and finish the courses your agency needs you to complete.',
        'route'       => 'training.index',
        // No specific permission middleware on the route — inherits the route gate.
        'steps' => [
            [
                'element' => '[data-tour="train-courses-intro"]',
                'title'   => 'My Training',
                'body'    => 'This is your learning home. Every course your agency assigns you appears here, so you always know what to complete to stay compliant and sharp.',
            ],
            [
                'element' => '[data-tour="train-courses-grid"]',
                'title'   => 'Your courses',
                'body'    => 'Each card is one course. The "Required" tag means it is compulsory for your role — those are the ones to clear first.',
            ],
            [
                'element' => '[data-tour="train-courses-card"]',
                'title'   => 'Open a course',
                'body'    => 'Tap any course to open it and work through its lessons one at a time. CoreX remembers exactly where you stopped.',
            ],
            [
                'element' => '[data-tour="train-courses-progress"]',
                'title'   => 'Your progress',
                'body'    => 'The bar shows how far you are through that course. "Completed" means you are done; "Expiring" means it needs a refresh soon. Close this and open your first course.',
            ],
        ],
    ],

    // ── Training help / knowledge base (how-to guides) ───────────────────────
    'train-help' => [
        'key'         => 'train-help',
        'title'       => 'Help & how-to guides',
        'description' => 'Search the knowledge base for step-by-step guides on every part of CoreX.',
        'route'       => 'training-help.index',
        // No specific permission middleware on the route — inherits the route gate.
        'steps' => [
            [
                'element' => '[data-tour="train-help-intro"]',
                'title'   => 'Training Centre',
                'body'    => 'This is the knowledge base — written guides that explain how to do anything in CoreX, written in plain language for each role.',
            ],
            [
                'element' => '[data-tour="train-help-search"]',
                'title'   => 'Search the guides',
                'body'    => 'Looking for one thing fast? Click Search docs (or press the / key) and type a few words — it looks inside every guide for you.',
            ],
            [
                'element' => '[data-tour="train-help-progress"]',
                'title'   => 'Required reading',
                'body'    => 'This bar tracks the guides marked compulsory for your role. Keep it at 100% and you have read everything your agency expects of you.',
            ],
            [
                'element' => '[data-tour="train-help-filters"]',
                'title'   => 'Filter by role',
                'body'    => 'Use these tabs to narrow the list. "For Me" shows only the guides written for your role — the quickest way to see what matters to your day.',
            ],
            [
                'element' => '[data-tour="train-help-grid"]',
                'title'   => 'The guides',
                'body'    => 'Each card shows the reading time and your progress. A "Required" tag means it is compulsory for your role.',
            ],
            [
                'element' => '[data-tour="train-help-card"]',
                'title'   => 'Open a guide',
                'body'    => 'Tap a guide to read it. CoreX marks it as read when you finish and flags it again if the guide is later updated. Close this and open one.',
            ],
        ],
    ],

    // ── Ellie, the AI assistant ──────────────────────────────────────────────
    'ai-ellie' => [
        'key'         => 'ai-ellie',
        'title'       => 'Ellie, your AI assistant',
        'description' => 'Ask Ellie about your numbers, listings and next actions — and keep your chats organised.',
        'route'       => 'ellie.index',
        'permission'  => 'access_ellie',
        'steps' => [
            [
                'element' => '[data-tour="ai-ellie-intro"]',
                'title'   => 'Meet Ellie',
                'body'    => 'Ellie is your built-in assistant. Ask her about your performance, your targets, your listings or what to do next — in everyday language.',
            ],
            [
                'element' => '[data-tour="ai-ellie-new"]',
                'title'   => 'Start a new chat',
                'body'    => 'Click "+ New Conversation" to begin a fresh topic. Keeping each subject in its own chat makes it easy to find again later.',
            ],
            [
                'element' => '[data-tour="ai-ellie-conversations"]',
                'title'   => 'Your past chats',
                'body'    => 'Every conversation you have had lives here. Click one to reopen it — Ellie remembers the whole thread.',
            ],
            [
                'element' => '[data-tour="ai-ellie-input"]',
                'title'   => 'Ask your question',
                'body'    => 'Type whatever you need here — for example "How many viewings do I have this week?" or "Draft a follow-up message for a buyer".',
            ],
            [
                'element' => '[data-tour="ai-ellie-send"]',
                'title'   => 'Send it',
                'body'    => 'Press Send and Ellie answers in seconds. Close this and ask her your first question.',
            ],
        ],
    ],

    // ── Calculators hub (commission, bond, transfer costs, overpayment) ──────
    'calc-hub' => [
        'key'         => 'calc-hub',
        'title'       => 'The calculators',
        'description' => 'Work out commission, bond repayments, transfer costs and overpayment savings in seconds.',
        'route'       => 'calculators.index',
        'permission'  => 'access_calculators',
        'steps' => [
            [
                'element' => '[data-tour="calc-hub-intro"]',
                'title'   => 'Calculators',
                'body'    => 'Four everyday property sums in one place. Use these in front of a client to answer money questions on the spot.',
            ],
            [
                'element' => '[data-tour="calc-hub-commission"]',
                'title'   => 'Commission',
                'body'    => 'Enter a sale price and rate to see commission, the 15% VAT and your share at different splits. Handy when quoting a seller.',
            ],
            [
                'element' => '[data-tour="calc-hub-bond"]',
                'title'   => 'Bond repayment',
                'body'    => 'Type a loan amount, interest rate and term to show a buyer their monthly repayment — and what it becomes if rates rise.',
            ],
            [
                'element' => '[data-tour="calc-hub-transfer"]',
                'title'   => 'Transfer & bond costs',
                'body'    => 'Estimate the conveyancing fees, transfer duty and deeds-office costs a buyer pays on top of the price. These are guideline estimates — always confirm with the conveyancer.',
            ],
            [
                'element' => '[data-tour="calc-hub-overpayment"]',
                'title'   => 'Overpayment savings',
                'body'    => 'Show a buyer how paying a little extra each month shortens the bond and saves interest — a powerful closing point. Close this and try a real number.',
            ],
        ],
    ],

    // ── Deposit interest calculator (trust account) ──────────────────────────
    'calc-deposit-interest' => [
        'key'         => 'calc-deposit-interest',
        'title'       => 'Deposit interest calculator',
        'description' => 'Work out the proportional interest owed on a deposit held in the trust account.',
        'route'       => 'deposit-interest-calculator.index',
        'permission'  => 'access_deposit_calculator',
        'steps' => [
            [
                'element' => '[data-tour="calc-deposit-interest-intro"]',
                'title'   => 'Deposit interest',
                'body'    => 'When a deposit sits in the trust account it earns interest. This tool works out exactly how much is owed back, day by day.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-property"]',
                'title'   => 'Which property',
                'body'    => 'Name the property the deposit relates to — for example "12 Marine Drive, Margate". It labels the result and the saved record.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-amount"]',
                'title'   => 'The deposit',
                'body'    => 'Enter the deposit amount in rand. This is the starting balance the interest is calculated on.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-dates"]',
                'title'   => 'The dates',
                'body'    => 'Set the date the deposit was invested and the date it was refunded. The tool counts every day in between at the trust rate that applied.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-topups"]',
                'title'   => 'Top-ups',
                'body'    => 'If more money was added later, add each top-up with its date so the interest stays accurate from that day onward.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-calc"]',
                'title'   => 'Calculate',
                'body'    => 'Press Calculate to see the total interest and a day-by-day breakdown. Close this and run your first calculation.',
            ],
        ],
    ],

    // ── Revenue share calculator ─────────────────────────────────────────────
    'calc-revenue-share' => [
        'key'         => 'calc-revenue-share',
        'title'       => 'Revenue share calculator',
        'description' => 'Explore what the agents you sponsor could earn you through revenue share.',
        'route'       => 'revenue-share.calculator',
        // No specific permission middleware on the route — inherits the route gate.
        'steps' => [
            [
                'element' => '[data-tour="calc-revenue-share-intro"]',
                'title'   => 'Revenue share',
                'body'    => 'This tool shows what the agents you bring into the business could earn you over time. Slide the controls to test different scenarios.',
            ],
            [
                'element' => '[data-tour="calc-revenue-share-scenario"]',
                'title'   => 'Your scenario',
                'body'    => 'Everything here is a "what if". Nothing is saved — it is a sandbox for picturing the size of your network.',
            ],
            [
                'element' => '[data-tour="calc-revenue-share-agents"]',
                'title'   => 'Agents you sponsor',
                'body'    => 'Set how many agents you personally bring in. CoreX then projects the further agents they bring in below them.',
            ],
            [
                'element' => '[data-tour="calc-revenue-share-commission"]',
                'title'   => 'Average commission',
                'body'    => 'Set a realistic average commission per deal in rand. Your share is worked out from this figure.',
            ],
            [
                'element' => '[data-tour="calc-revenue-share-results"]',
                'title'   => 'Your projected share',
                'body'    => 'The cards update live with your monthly and yearly revenue share across all tiers. Close this and slide the controls to explore.',
            ],
        ],
    ],

    // ── Property evaluation / search tool ────────────────────────────────────
    'misc-evaluation' => [
        'key'         => 'misc-evaluation',
        'title'       => 'Property evaluation',
        'description' => 'Look up a property by address, ERF, suburb or owner — and explore the prospecting map.',
        'route'       => 'evaluation.index',
        'permission'  => 'access_evaluation',
        'steps' => [
            [
                'element' => '[data-tour="misc-evaluation-mode"]',
                'title'   => 'Search or map',
                'body'    => 'Two ways to work: "Search" finds a specific property, "Prospecting" opens a live map of the area. Start in Search.',
            ],
            [
                'element' => '[data-tour="misc-evaluation-search"]',
                'title'   => 'The search box',
                'body'    => 'Type an address, ERF number, suburb or an owner\'s name. CoreX searches the KZN South Coast property records as you type.',
            ],
            [
                'element' => '[data-tour="misc-evaluation-types"]',
                'title'   => 'Narrow your search',
                'body'    => 'Tell CoreX what kind of search you are doing — by Person, ERF, Suburb, Street and more — for sharper results.',
            ],
            [
                'element' => '[data-tour="misc-evaluation-suggestions"]',
                'title'   => 'Try an example',
                'body'    => 'New here? Tap any of these examples to see what a result looks like. Close this and search for a real property.',
            ],
        ],
    ],

];
