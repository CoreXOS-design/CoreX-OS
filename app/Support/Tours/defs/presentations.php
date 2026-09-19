<?php

/**
 * Guided tours — Presentations module (AT-41 full-coverage pass).
 *
 * Each entry is pure DATA merged by App\Support\Tours\TourRegistry::all().
 * Keys are namespaced with the `pres-` prefix to stay globally unique.
 *
 * NOTE: the old presentations.create ("New Presentation" form) tour was REMOVED
 * with the AT-17 standalone-presentation retirement (2026-07-10) — presentations
 * are property-first only. No create tour lives here or in TourRegistry::core().
 *
 * Every step anchors on a real [data-tour="..."] element added to the live
 * Blade view, so a markup refactor can never silently drop a step.
 */

return [

    // ── Presentations list ──────────────────────────────────────────────────
    'pres-list' => [
        'key'         => 'pres-list',
        'title'       => 'Find your way around Presentations',
        'description' => 'The home for every seller presentation — search, filter and open your pricing pitches.',
        'route'       => 'presentations.index',
        'permission'  => 'access_presentations',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="pres-list-intro"]',
                'section' => 'Finding a presentation',
                'title'   => 'Your presentations live here',
                'body'    => 'A presentation is the seller pitch you build for a property — the pricing analysis, comparable sales and the report you share. Every one you create lands on this list.',
            ],
            [
                'element' => '[data-tour="pres-list-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an address, a seller\'s name or a suburb.'],
                'title'   => 'Find one fast',
                'body'    => 'Once you have a few presentations, search by the property address, the seller\'s name or the suburb to jump straight to the right one.',
            ],
            [
                'element' => '[data-tour="pres-list-search-btn"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Search.'],
                'title'   => 'Run the search',
                'body'    => 'The list reloads with just the presentations that match. Clear takes you back to the full list.',
            ],
            [
                'element' => '[data-tour="pres-list-filters"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick Active or Archived, or a status, to narrow the list.'],
                'title'   => 'Narrow the list',
                'body'    => 'Switch between Active and Archived, or filter by status, property type and agent. "Active" shows your live work; "Archived" holds the ones you\'ve put away.',
            ],
            [
                'element' => '[data-tour="pres-list-table"]',
                'section' => 'Opening a presentation',
                'title'   => 'Read a row at a glance',
                'body'    => 'Each row shows the property, the seller and a status badge — Draft while you\'re building it, Presented once it\'s shared, and Locked when the numbers are frozen.',
            ],
            [
                'element' => '[data-tour="pres-list-actions"]',
                'title'   => 'Open and keep working',
                'body'    => 'Use Open to step into a presentation and pick up where you left off. Close this and open one to see the analysis screen for yourself.',
            ],
            [
                'element' => '[data-tour="pres-list-open"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Open to step into this presentation.'],
                'title'   => 'Open one',
                'body'    => 'This takes you into the presentation — its overview, the market analysis and the report you share with the seller.',
            ],
        ],
    ],

    // ── Presentations analytics dashboard ───────────────────────────────────
    'pres-analytics' => [
        'key'         => 'pres-analytics',
        'title'       => 'Read the presentations funnel',
        'description' => 'See how your pitches move from generated to shared, viewed and won.',
        'route'       => 'corex.presentations.analytics.index',
        'permission'  => 'access_presentations',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="pres-analytics-intro"]',
                'section' => 'Choosing your window',
                'title'   => 'The full picture',
                'body'    => 'This dashboard follows every presentation through its life: generated, shared with the seller, viewed by them, leads it brought in, and the final outcome.',
            ],
            [
                'element' => '[data-tour="pres-analytics-filters"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a From date.'],
                'title'   => 'Choose your window',
                'body'    => 'Pick a date range to focus on, then Apply. If you manage a team, you can also narrow to a single agent.',
            ],
            [
                'element' => '[data-tour="pres-analytics-to"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Pick a To date.'],
                'title'   => 'To date',
                'body'    => 'The last day to include. Leave it on today to see everything up to now.',
            ],
            [
                'element' => '[data-tour="pres-analytics-agent"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Pick an agent, or press Skip this step to keep everyone.'],
                'title'   => 'One agent',
                'body'    => 'If you manage a team, you can focus the whole dashboard on one agent\'s presentations.',
            ],
            [
                'element' => '[data-tour="pres-analytics-apply"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Apply to update the numbers.'],
                'title'   => 'Apply',
                'body'    => 'Every tile and the funnel below are worked out again for the window you picked.',
            ],
            [
                'element' => '[data-tour="pres-analytics-tiles"]',
                'section' => 'Reading the funnel',
                'title'   => 'The headline numbers',
                'body'    => 'These tiles count each stage and show the percentage that carried through. "Win rate" is the share of recorded outcomes that became a mandate or a sale.',
            ],
            [
                'element' => '[data-tour="pres-analytics-funnel"]',
                'title'   => 'Where pitches drop off',
                'body'    => 'The funnel shows how many move from one stage to the next. A big drop between Shared and Viewed tells you sellers aren\'t opening the link — a cue to follow up.',
            ],
        ],
    ],

    // ── Outcomes dashboard ──────────────────────────────────────────────────
    'pres-outcomes' => [
        'key'         => 'pres-outcomes',
        'title'       => 'Track wins and losses',
        'description' => 'Record what happened to each pitch and learn why mandates were won or lost.',
        'route'       => 'corex.presentations.outcomes.index',
        'permission'  => 'access_presentations',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="pres-outcomes-intro"]',
                'section' => 'Filtering outcomes',
                'title'   => 'How your pitches landed',
                'body'    => 'Every presentation ends one of two ways — you won the mandate or you didn\'t. This dashboard gathers those outcomes so you can see your win rate and learn from the losses.',
            ],
            [
                'element' => '[data-tour="pres-outcomes-filters"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick an outcome or a loss reason to filter by.'],
                'title'   => 'Slice the results',
                'body'    => 'Filter by date, by outcome, or by the reason a mandate was lost. Managers can also focus on one agent. Apply to update everything below.',
            ],
            [
                'element' => '[data-tour="pres-outcomes-apply"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Apply to update the dashboard.'],
                'title'   => 'Apply the filters',
                'body'    => 'The scorecard, the table and the loss-reasons chart all update to match. Clear takes you back to everything.',
            ],
            [
                'element' => '[data-tour="pres-outcomes-metrics"]',
                'section' => 'Reading your results',
                'title'   => 'Your scorecard',
                'body'    => 'Won mandates, losses to a competitor, deals where the seller made no decision, and how long outcomes typically take. The badges read green for a win and amber or grey for a loss — never an error, just the result.',
            ],
            [
                'element' => '[data-tour="pres-outcomes-loss-reasons"]',
                'title'   => 'Why deals slip away',
                'body'    => 'This chart ranks the reasons mandates were lost — price disputes, commission concerns, a competitor undercutting you. Spotting a pattern here is how you fix your pitch.',
            ],
            [
                'element' => '[data-tour="pres-outcomes-table"]',
                'section' => 'Opening a presentation',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click a property to open its presentation.', 'target' => 'tbody a'],
                'title'   => 'Open the presentation',
                'body'    => 'Each row is one recorded outcome. Open it to see the pitch behind the result.',
            ],
        ],
    ],

    // ── Refresh requests ────────────────────────────────────────────────────
    'pres-refresh-requests' => [
        'key'         => 'pres-refresh-requests',
        'title'       => 'Handle seller refresh requests',
        'description' => 'When a seller asks for updated figures on their shared link, action it here.',
        'route'       => 'corex.presentations.refresh-requests.index',
        'permission'  => 'access_presentations',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="pres-refresh-intro"]',
                'section' => 'Your request queue',
                'title'   => 'Requests to refresh a link',
                'body'    => 'When you share a presentation, the seller gets a link. If they ask for updated figures, that request lands here for you to action.',
            ],
            [
                'element' => '[data-tour="pres-refresh-tabs"]',
                'do'      => ['action' => 'click', 'say' => 'Click the Open tab to see what still needs you.'],
                'title'   => 'Stay on top of the queue',
                'body'    => 'These tabs split requests by where they stand. "Open" is your to-do list; "Resolved" and "Declined" keep the history. The numbers tell you how many sit in each.',
            ],
            [
                'element' => '[data-tour="pres-refresh-table"]',
                'title'   => 'Read each request',
                'body'    => 'Each row shows the property, who asked, their message and a status badge — amber Pending needs attention, navy Acknowledged means you\'ve seen it, green Resolved is done.',
            ],
            [
                'element' => '[data-tour="pres-refresh-actions"]',
                'title'   => 'Acknowledge, refresh or decline',
                'body'    => 'Acknowledge to mark a request seen, Issue refresh to send a fresh link with updated numbers, or Decline with a reason if the current report still holds. Close this and clear your Open queue.',
            ],
            [
                'element' => '[data-tour="pres-refresh-ack"]',
                'section' => 'Issuing a refresh',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Acknowledge to mark the top request as seen.'],
                'title'   => 'Acknowledge it',
                'body'    => 'Tells CoreX you\'ve seen the request, so it moves from amber Pending to Acknowledged. Nothing is sent to the seller.',
            ],
            [
                'element' => '[data-tour="pres-refresh-issue"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Issue refresh.', 'until' => '[data-tour="pres-refresh-resolve-note"]'],
                'skip_if' => '[data-tour="pres-refresh-resolve-note"]',
                'title'   => 'Issue a refresh',
                'body'    => 'Opens a short form under the request to create a fresh link with up-to-date numbers.',
            ],
            [
                'element' => '[data-tour="pres-refresh-keep-old"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Tick this only if the old link must keep working, or press Skip this step.'],
                'title'   => 'Keep the old link?',
                'body'    => 'Normally the old link is retired and anyone who opens it lands on the new one. Tick this only if the old link must stay live as well.',
            ],
            [
                'element' => '[data-tour="pres-refresh-resolve-note"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Type a short note on what you updated, or press Skip this step.'],
                'title'   => 'Add a note',
                'body'    => 'Optional — one line on what changed, kept with the request for the record.',
            ],
            [
                'element' => '[data-tour="pres-refresh-resolve-submit"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Issue refresh & resolve — this creates a new link and points the old one to it.'],
                'title'   => 'Resolve it',
                'body'    => 'CoreX creates the fresh link and marks the request Resolved. Find it under View new link on the Resolved tab to send to the seller.',
            ],
        ],
    ],

    // ── Market analysis (context-bound: needs a specific presentation) ───────
    //
    // POINT-ONLY by design. This screen can be a confirmed/locked snapshot —
    // the numbers are frozen once the agent confirms. We never trigger Run,
    // Confirm & Generate, or Re-open from a tour: those mutate state or reload.
    // Every step here just spotlights and explains. The Advanced Guide still
    // never presses those buttons itself — its advanced_only steps ask the
    // agent to press Run / Confirm & Generate; Re-open is never guided.
    'pres-analysis' => [
        'key'         => 'pres-analysis',
        'title'       => 'Build the market analysis',
        'description' => 'The pricing engine of a presentation. Open a presentation, then tap ? to follow this.',
        'route'       => 'presentations.analysis',
        'permission'  => 'access_presentations',
        'pick_from'   => 'presentations.index',
        'pick_note'   => 'Open the presentation you want to price, then click View Analysis or Run Analysis — I\'ll pick up from there.',
        // No setup actions — this screen may be a frozen snapshot. Point-only.
        'steps' => [
            [
                'element' => '[data-tour="pres-analysis-intro"]',
                'section' => 'Setting the price',
                'title'   => 'The heart of the pitch',
                'body'    => 'This is where a presentation becomes a pricing argument — your asking price weighed against the comparable sales and live listings in the suburb.',
            ],
            [
                'element' => '[data-tour="pres-analysis-run"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the asking price you recommend.'],
                'title'   => 'Set the asking price',
                'body'    => 'Enter the asking price you\'re recommending. Running the analysis freezes a snapshot of the numbers so the report you share can never quietly change underneath the seller.',
            ],
            [
                'element' => '[data-tour="pres-analysis-run-btn"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Run Analysis to freeze the numbers.'],
                'title'   => 'Run the analysis',
                'body'    => 'CoreX saves the price and takes a snapshot of the comparable sales and live listings, so the report can\'t change under the seller.',
            ],
            [
                'element' => '[data-tour="pres-analysis-sections"]',
                'section' => 'Choosing sections',
                'do'      => ['action' => 'choose', 'say' => 'Tick or untick the sections the seller should see.'],
                'title'   => 'Choose what the seller sees',
                'body'    => 'These toggles decide which sections appear in the generated report. A few core sections are always shown and stay locked. (If this presentation is confirmed, it\'s locked — re-open it first to make changes.)',
            ],
            [
                'element' => '[data-tour="pres-analysis-readiness"]',
                'section' => 'Finishing up',
                'title'   => 'Check you have the evidence',
                'body'    => 'A tick means that piece is in place; a circle tells you exactly what to add — usually a CMA or vicinity-sales upload. Fill the gaps before you generate, then close this and finish your analysis.',
            ],
            [
                'element' => '[data-tour="pres-analysis-confirm"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Confirm & Generate — this locks the numbers and writes the executive summary.'],
                'title'   => 'Confirm & Generate',
                'body'    => 'Freezes this version, writes the executive summary from the confirmed numbers and takes you to the overview, ready to share. You can re-open it later if something needs to change.',
            ],
        ],
    ],

];
