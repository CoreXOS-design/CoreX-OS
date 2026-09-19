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
                'section' => 'Your courses',
                'title'   => 'My Training',
                'body'    => 'This is your learning home. Every course your agency assigns you appears here, so you always know what to complete to stay compliant and sharp.',
            ],
            [
                'element' => '[data-tour="train-courses-grid"]',
                'title'   => 'Your courses',
                'body'    => 'Each card is one course. The "Required" tag means it is compulsory for your role — those are the ones to clear first.',
            ],
            [
                // Opening a course leaves this page, so in Advanced/Spot mode this
                // explanation is skipped (the card is always visible when it runs)
                // and the agent is asked to open a course as the LAST step instead,
                // after they have read their progress below.
                'element' => '[data-tour="train-courses-card"]',
                'section' => 'Starting a course',
                'skip_if' => '[data-tour="train-courses-card"]',
                'title'   => 'Open a course',
                'body'    => 'Tap any course to open it and work through its lessons one at a time. CoreX remembers exactly where you stopped.',
            ],
            [
                'element' => '[data-tour="train-courses-progress"]',
                'title'   => 'Your progress',
                'body'    => 'The bar shows how far you are through that course. "Completed" means you are done; "Expiring" means it needs a refresh soon. Close this and open your first course.',
            ],
            [
                'element'       => '[data-tour="train-courses-card"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click a course to open it and start the first lesson.'],
                'title'         => 'Open your course',
                'body'          => 'Start with a course tagged "Required". It opens on the lesson where you left off.',
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
                'section' => 'Searching the guides',
                'title'   => 'Training Centre',
                'body'    => 'This is the knowledge base — written guides that explain how to do anything in CoreX, written in plain language for each role.',
            ],
            [
                'element' => '[data-tour="train-help-search"]',
                'do'      => ['action' => 'click', 'say' => 'Click Search docs to open the search box.', 'until' => '[data-tour="train-help-search-input"]'],
                'skip_if' => '[data-tour="train-help-search-input"]',
                'title'   => 'Search the guides',
                'body'    => 'Looking for one thing fast? Click Search docs (or press the / key) and type a few words — it looks inside every guide for you.',
            ],
            [
                'element'       => '[data-tour="train-help-search-input"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type a few words about what you want to do, e.g. "add a contact".'],
                'title'         => 'Type what you need',
                'body'          => 'Plain words work best. CoreX searches inside every guide as you type.',
            ],
            [
                'element'       => '[data-tour="train-help-search-input"]',
                'advanced_only' => true,
                'do'            => ['action' => 'appear', 'say' => 'Wait a moment — the matching guides appear under the box.', 'until' => '[data-tour="train-help-search-result"], [data-tour="train-help-search-empty"]'],
                'title'         => 'The matches',
                'body'          => 'Each match shows the guide and the part of it that answers your question. Nothing found? Try fewer or different words.',
            ],
            [
                'element'       => '[data-tour="train-help-search-input"]',
                'advanced_only' => true,
                'title'         => 'Open it or carry on',
                'body'          => 'Click a match to jump straight to that part of the guide. To browse the full list instead, press Esc to close the search.',
            ],
            [
                'element' => '[data-tour="train-help-progress"]',
                'section' => 'Browsing by role',
                'title'   => 'Required reading',
                'body'    => 'This bar tracks the guides marked compulsory for your role. Keep it at 100% and you have read everything your agency expects of you.',
            ],
            [
                'element' => '[data-tour="train-help-filters"]',
                'do'      => ['action' => 'click', 'target' => 'a[href*="filter=for-me"]', 'say' => 'Click For Me to see only the guides written for your role.'],
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
                'do'      => ['action' => 'click', 'say' => 'Click a guide to open and read it.'],
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
                'section' => 'Starting a chat',
                'title'   => 'Meet Ellie',
                'body'    => 'Ellie is your built-in assistant. Ask her about your performance, your targets, your listings or what to do next — in everyday language.',
            ],
            [
                'element' => '[data-tour="ai-ellie-new"]',
                'do'      => ['action' => 'click', 'say' => 'Click + New Conversation to start a fresh chat.'],
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
                'section' => 'Asking Ellie',
                'do'      => ['action' => 'fill', 'say' => 'Type your question for Ellie.'],
                'title'   => 'Ask your question',
                'body'    => 'Type whatever you need here — for example "How many viewings do I have this week?" or "Draft a follow-up message for a buyer".',
            ],
            [
                'element' => '[data-tour="ai-ellie-send"]',
                'do'      => ['action' => 'click', 'say' => 'Click Send.'],
                'title'   => 'Send it',
                'body'    => 'Press Send and Ellie answers in seconds. Close this and ask her your first question.',
            ],
            [
                'element'       => '[data-tour="ai-ellie-messages"]',
                'advanced_only' => true,
                'title'         => 'Ellie\'s answer',
                'body'          => 'Your question and Ellie\'s reply show here. Any page link in her answer is clickable — Ellie follows you there with the same steps.',
            ],
            [
                'element'       => '[data-tour="ai-ellie-rename"]',
                'section'       => 'Naming a chat',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type a short name for this chat, e.g. "Marine Drive buyers".'],
                'title'         => 'Give it a name',
                'body'          => 'A clear name makes this chat easy to find in your list later.',
            ],
            [
                'element'       => '[data-tour="ai-ellie-rename-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Rename to save the name.'],
                'title'         => 'Save the name',
                'body'          => 'The new name shows in your Conversations list straight away.',
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
                'section' => 'Working out commission',
                'title'   => 'Calculators',
                'body'    => 'Four everyday property sums in one place. Use these in front of a client to answer money questions on the spot.',
            ],
            [
                'element' => '[data-tour="calc-hub-commission"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the sale price in the Sale Price box.'],
                'title'   => 'Commission',
                'body'    => 'Enter a sale price and rate to see commission, the 15% VAT and your share at different splits. Handy when quoting a seller.',
            ],
            [
                'element'       => '[data-tour="calc-hub-comm-rate"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click 7.5% or 10% — or type your own rate in the Custom box.'],
                'title'         => 'Pick the rate',
                'body'          => '7.5% is the usual residential rate and 10% suits commercial or vacant land. The rate is before VAT.',
            ],
            [
                'element'       => '[data-tour="calc-hub-comm-calc"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Calculate.', 'until' => '[data-tour="calc-hub-comm-result"]'],
                'title'         => 'Work it out',
                'body'          => 'CoreX adds the VAT and works out your share in a second.',
            ],
            [
                'element'       => '[data-tour="calc-hub-comm-result"]',
                'advanced_only' => true,
                'title'         => 'Your commission',
                'body'          => 'Commission before and after VAT, plus what the agent takes home at a 50%, 60% and 70% split.',
            ],
            [
                'element' => '[data-tour="calc-hub-bond"]',
                'section' => 'Bond repayments',
                'do'      => ['action' => 'fill', 'say' => 'Type the loan amount.'],
                'title'   => 'Bond repayment',
                'body'    => 'Type a loan amount, interest rate and term to show a buyer their monthly repayment — and what it becomes if rates rise.',
            ],
            [
                'element'       => '[data-tour="calc-hub-bond-rate"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Change the rate if the bank quoted a different one — or press Skip this step to keep prime.'],
                'title'         => 'Interest rate',
                'body'          => 'This starts on today\'s prime rate. Use the buyer\'s quoted rate if they have one.',
            ],
            [
                'element'       => '[data-tour="calc-hub-bond-term"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick the loan term — or press Skip this step to keep 20 years.'],
                'title'         => 'Loan term',
                'body'          => 'Most South African bonds run over 20 years. A longer term lowers the monthly repayment but costs more interest.',
            ],
            [
                'element'       => '[data-tour="calc-hub-bond-calc"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Calculate.', 'until' => '[data-tour="calc-hub-bond-result"]'],
                'title'         => 'Work it out',
                'body'          => 'CoreX works out the repayment over the full term.',
            ],
            [
                'element'       => '[data-tour="calc-hub-bond-result"]',
                'advanced_only' => true,
                'title'         => 'The monthly repayment',
                'body'          => 'The monthly repayment, the total repaid and the total interest, plus what the repayment becomes if rates go up by 1% or 2%.',
            ],
            [
                'element' => '[data-tour="calc-hub-transfer"]',
                'section' => 'Transfer costs',
                'do'      => ['action' => 'fill', 'say' => 'Type the purchase price.'],
                'title'   => 'Transfer & bond costs',
                'body'    => 'Estimate the conveyancing fees, transfer duty and deeds-office costs a buyer pays on top of the price. These are guideline estimates — always confirm with the conveyancer.',
            ],
            [
                'element'       => '[data-tour="calc-hub-transfer-needs-bond"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Untick this for a cash buyer — or press Skip this step if they need a bond.'],
                'title'         => 'Cash or bond?',
                'body'          => 'A buyer taking a bond also pays bond registration costs, so CoreX adds those when this is ticked.',
            ],
            [
                'element'       => '[data-tour="calc-hub-transfer-bond-amount"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type the bond amount — or press Skip this step to use the full price.'],
                'title'         => 'Bond amount',
                'body'          => 'Leave it blank and CoreX assumes the bond is for the full purchase price.',
            ],
            [
                'element'       => '[data-tour="calc-hub-transfer-calc"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Calculate.', 'until' => '[data-tour="calc-hub-transfer-result"]'],
                'title'         => 'Work it out',
                'body'          => 'CoreX adds up the fees, VAT, deeds-office costs and transfer duty.',
            ],
            [
                'element'       => '[data-tour="calc-hub-transfer-result"]',
                'advanced_only' => true,
                'title'         => 'What the buyer pays',
                'body'          => 'Transfer costs, bond registration costs and the grand total. Remember these are estimates — the conveyancer gives the final figure.',
            ],
            [
                'element' => '[data-tour="calc-hub-overpayment"]',
                'section' => 'Overpayment savings',
                'do'      => ['action' => 'fill', 'say' => 'Type the loan amount.'],
                'title'   => 'Overpayment savings',
                'body'    => 'Show a buyer how paying a little extra each month shortens the bond and saves interest — a powerful closing point. Close this and try a real number.',
            ],
            [
                'element'       => '[data-tour="calc-hub-overpay-rate"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Change the rate if the bank quoted a different one — or press Skip this step to keep prime.'],
                'title'         => 'Interest rate',
                'body'          => 'This starts on today\'s prime rate. Use the buyer\'s quoted rate if they have one.',
            ],
            [
                'element'       => '[data-tour="calc-hub-overpay-term"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick the loan term — or press Skip this step to keep 20 years.'],
                'title'         => 'Loan term',
                'body'          => 'The length of the bond before any extra payments.',
            ],
            [
                'element'       => '[data-tour="calc-hub-overpay-extra"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type how much extra the buyer would pay each month.'],
                'title'         => 'The extra payment',
                'body'          => 'Even R500 a month makes a real difference. The quick amounts below the box fill it in for you.',
            ],
            [
                'element'       => '[data-tour="calc-hub-overpay-calc"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Calculate Savings.', 'until' => '[data-tour="calc-hub-overpay-result"]'],
                'title'         => 'Work it out',
                'body'          => 'CoreX compares the normal bond with the faster one.',
            ],
            [
                'element'       => '[data-tour="calc-hub-overpay-result"]',
                'advanced_only' => true,
                'title'         => 'The saving',
                'body'          => 'The normal bond next to the faster one, and in green how many years and how much interest the buyer saves.',
            ],
            [
                'element'       => '[data-tour="calc-hub-overpay-table-toggle"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Show year-by-year comparison table.', 'until' => '[data-tour="calc-hub-overpay-table"]'],
                'skip_if'       => '[data-tour="calc-hub-overpay-table"]',
                'title'         => 'Year by year',
                'body'          => 'The table shows both balances for every year, so the buyer can see the gap grow.',
            ],
        ],
    ],

    // ── Deposit interest calculator (trust account) ──────────────────────────
    'calc-deposit-interest' => [
        'key'         => 'calc-deposit-interest',
        'title'       => 'Deposit interest calculator',
        'description' => 'Work out the proportional interest owed on a deposit held in the trust account.',
        'route'       => 'deposit-interest-calculator.index',
        // Calculate posts back and re-renders this same screen with the result —
        // the guide carries on there (result, breakdown, statement, save).
        'also_on'     => ['deposit-interest-calculator.calculate'],
        'permission'  => 'access_deposit_calculator',
        'steps' => [
            [
                'element' => '[data-tour="calc-deposit-interest-intro"]',
                'section' => 'Entering the deposit',
                'title'   => 'Deposit interest',
                'body'    => 'When a deposit sits in the trust account it earns interest. This tool works out exactly how much is owed back, day by day.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-property"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the property address.'],
                'title'   => 'Which property',
                'body'    => 'Name the property the deposit relates to — for example "12 Marine Drive, Margate". It labels the result and the saved record.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-amount"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the deposit amount in rand.'],
                'title'   => 'The deposit',
                'body'    => 'Enter the deposit amount in rand. This is the starting balance the interest is calculated on.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-dates"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the date the deposit was invested.'],
                'title'   => 'The dates',
                'body'    => 'Set the date the deposit was invested and the date it was refunded. The tool counts every day in between at the trust rate that applied.',
            ],
            [
                'element'       => '[data-tour="calc-deposit-interest-refund"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Pick the date the deposit was refunded — or press Skip this step if it was today.'],
                'title'         => 'Date refunded',
                'body'          => 'This starts on today\'s date. Change it to the day the money actually left the trust account.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-topups"]',
                'section' => 'Adding top-ups',
                'do'      => ['action' => 'click', 'target' => 'button', 'say' => 'Click + Add Topup if more money was paid in later — otherwise press Skip this step.', 'until' => '[data-tour="calc-deposit-interest-topup-row"]'],
                'title'   => 'Top-ups',
                'body'    => 'If more money was added later, add each top-up with its date so the interest stays accurate from that day onward.',
            ],
            // The top-up rows only exist once one has been added; with none added
            // the engine finds no anchor and skips these two steps.
            [
                'element'       => '[data-tour="calc-deposit-interest-topup-row"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'input[type="date"]', 'say' => 'Pick the date the top-up was paid in.'],
                'title'         => 'Top-up date',
                'body'          => 'Interest on the extra money counts from this day.',
            ],
            [
                'element'       => '[data-tour="calc-deposit-interest-topup-row"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'input[type="number"]', 'say' => 'Type the top-up amount in rand.'],
                'title'         => 'Top-up amount',
                'body'          => 'Add another row the same way for each extra payment.',
            ],
            [
                'element' => '[data-tour="calc-deposit-interest-calc"]',
                'section' => 'Getting the result',
                'do'      => ['action' => 'click', 'say' => 'Click Calculate.'],
                'title'   => 'Calculate',
                'body'    => 'Press Calculate to see the total interest and a day-by-day breakdown. Close this and run your first calculation.',
            ],
            [
                'element'       => '[data-tour="calc-deposit-interest-result"]',
                'advanced_only' => true,
                'title'         => 'The totals',
                'body'          => 'The total deposit, the interest it earned and the grand total owed back.',
            ],
            [
                'element'       => '[data-tour="calc-deposit-interest-breakdown"]',
                'advanced_only' => true,
                'title'         => 'The breakdown',
                'body'          => 'Every deposit, top-up and interest posting in date order, with the share of interest that belongs to this deposit.',
            ],
            [
                'element'       => '[data-tour="calc-deposit-interest-tenant-pdf"]',
                'section'       => 'Saving the statement',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Download Tenant Statement to download the PDF for the tenant.'],
                'title'         => 'Statement for the tenant',
                'body'          => 'A clean PDF you can send the tenant with their refund. Download Full Statement gives the detailed version.',
            ],
            [
                'element'       => '[data-tour="calc-deposit-interest-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save to History to keep this calculation.'],
                'title'         => 'Keep a record',
                'body'          => 'Saved calculations are listed under History, so you can find this one again later.',
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
                'section' => 'Setting your scenario',
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
                'do'      => ['action' => 'fill', 'say' => 'Type how many agents you would sponsor.'],
                'title'   => 'Agents you sponsor',
                'body'    => 'Set how many agents you personally bring in. CoreX then projects the further agents they bring in below them.',
            ],
            [
                'element'       => '[data-tour="calc-revenue-share-deals"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type how many deals an agent does in an average month.'],
                'title'         => 'Deals per month',
                'body'          => 'Be realistic — one or two deals a month per agent is a fair starting point.',
            ],
            [
                'element' => '[data-tour="calc-revenue-share-commission"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a realistic average commission per deal, rounded to the nearest R5,000.'],
                'title'   => 'Average commission',
                'body'    => 'Set a realistic average commission per deal in rand. Your share is worked out from this figure.',
            ],
            [
                'element' => '[data-tour="calc-revenue-share-results"]',
                'section' => 'Reading your projection',
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
                'section' => 'Searching for a property',
                'do'      => ['action' => 'click', 'target' => 'button:first-child', 'say' => 'Click Search.'],
                'title'   => 'Search or map',
                'body'    => 'Two ways to work: "Search" finds a specific property, "Prospecting" opens a live map of the area. Start in Search.',
            ],
            [
                'element' => '[data-tour="misc-evaluation-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an address, ERF number, suburb or owner\'s name.'],
                'title'   => 'The search box',
                'body'    => 'Type an address, ERF number, suburb or an owner\'s name. CoreX searches the KZN South Coast property records as you type.',
            ],
            [
                'element' => '[data-tour="misc-evaluation-types"]',
                'do'      => ['action' => 'choose', 'say' => 'Click the kind of search you are doing, e.g. Suburb or ERF.'],
                'title'   => 'Narrow your search',
                'body'    => 'Tell CoreX what kind of search you are doing — by Person, ERF, Suburb, Street and more — for sharper results.',
            ],
            [
                // Only on screen while the search box is empty — skipped once the
                // agent has typed a search above.
                'element' => '[data-tour="misc-evaluation-suggestions"]',
                'do'      => ['action' => 'choose', 'say' => 'Tap one of the examples to try it.'],
                'title'   => 'Try an example',
                'body'    => 'New here? Tap any of these examples to see what a result looks like. Close this and search for a real property.',
            ],
            [
                'element'       => '[data-tour="misc-evaluation-body"]',
                'advanced_only' => true,
                'do'            => ['action' => 'appear', 'say' => 'Wait a moment — the matching properties appear here.', 'until' => '[data-tour="misc-evaluation-result"], [data-tour="misc-evaluation-noresults"]'],
                'title'         => 'The results',
                'body'          => 'Each card shows the address, ERF, stand size, last sale and whether the property is bond free.',
            ],
            [
                'element'       => '[data-tour="misc-evaluation-results"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'target' => '[data-tour="misc-evaluation-result"]', 'say' => 'Click a property to see its details.', 'until' => '[data-tour="misc-evaluation-detail"]'],
                'title'         => 'Pick a property',
                'body'          => 'Its full details open in the panel on the right.',
            ],
            [
                'element'       => '[data-tour="misc-evaluation-detail"]',
                'advanced_only' => true,
                'title'         => 'The property at a glance',
                'body'          => 'Municipal value, last sale and stand size across the top, with the full record underneath.',
            ],
            [
                'element'       => '[data-tour="misc-evaluation-sections"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click a heading, e.g. Sale Information, to open it.'],
                'title'         => 'Dig into the record',
                'body'          => 'Sale history, municipal value, transfers, and street and suburb sales — one heading at a time.',
            ],
            [
                'element'       => '[data-tour="misc-evaluation-mode"]',
                'section'       => 'Prospecting map',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'target' => 'button:last-child', 'say' => 'Click Prospecting to open the map.', 'until' => '[data-tour="misc-evaluation-map"]'],
                'skip_if'       => '[data-tour="misc-evaluation-map"]',
                'title'         => 'Open the map',
                'body'          => 'The Prospecting map shows the area around you, so you can spot where to farm.',
            ],
            [
                'element'       => '[data-tour="misc-evaluation-map-tabs"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click a map view, e.g. Sales & Transfers.'],
                'title'         => 'Pick a view',
                'body'          => 'Each view puts different information on the map — suburbs, recent sales, prospects or documents.',
            ],
            [
                'element'       => '[data-tour="misc-evaluation-legend"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click a layer in the key to show or hide it.'],
                'title'         => 'Map layers',
                'body'          => 'Switch layers on and off to keep the map clear while you prospect.',
            ],
        ],
    ],

];
