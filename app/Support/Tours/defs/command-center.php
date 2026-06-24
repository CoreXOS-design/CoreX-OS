<?php

/**
 * Guided tours — Command Center (AT-41).
 *
 * Each entry returns a DATA-only tour definition merged by TourRegistry::all().
 * Keys are namespaced `cc-*` so they never collide with other modules' defs.
 * Anchors are dedicated data-tour="…" attributes added to the real DOM of each
 * Command Center screen — a markup refactor cannot silently break a tour, and a
 * missing anchor is simply skipped by the engine.
 *
 * Voice: a calm expert showing a brand-new South African estate agent the ropes.
 */

return [

    // ── Today (the agent's daily landing page) ───────────────────────────────
    'cc-today' => [
        'key'         => 'cc-today',
        'title'       => 'Your daily Today board',
        'description' => 'Read your morning briefing — what needs action now, what is for today, and your snapshot.',
        'route'       => 'command-center.today',
        // No permission: Today is every agent's home screen.
        'setup' => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="cc-today-header"]',
                'title'   => 'This is your day, at a glance',
                'body'    => 'Today is where you start every morning. It greets you by name and shows today\'s date so you always know exactly where you stand.',
            ],
            [
                'element' => '[data-tour="cc-today-board"]',
                'title'   => 'Your work, sorted by urgency',
                'body'    => 'CoreX reads across all your work — appointments, buyers, documents, listings — and lays it out in three bands: Action Required (deal with now), Today, and a Snapshot to glance at. You do not go hunting; the work comes to you.',
            ],
            [
                'element' => '[data-tour="cc-today-refresh"]',
                'title'   => 'Always up to date',
                'body'    => 'The board refreshes itself every minute. Out and about, then back at your desk? Tap Refresh to pull the very latest before you plan your next move.',
            ],
            [
                'element' => '[data-tour="cc-today-greeting"]',
                'title'   => 'Make it a habit',
                'body'    => 'Open this first thing, clear the Action Required band, and the rest of your day follows. Close this and have a look at what is waiting for you today.',
            ],
        ],
    ],

    // ── My Performance (reporting dashboard for the agent) ────────────────────
    'cc-my-performance' => [
        'key'         => 'cc-my-performance',
        'title'       => 'Reading your performance dashboard',
        'description' => 'See your own activity and pipeline numbers — viewings, presentations, feedback rate and lost deals.',
        'route'       => 'command-center.reporting.agent',
        // No permission: every agent may view their own performance.
        'setup' => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="cc-my-performance-header"]',
                'title'   => 'Your numbers, your name',
                'body'    => 'This page is about you and only you. It shows how you have been working over the period shown next to your name.',
            ],
            [
                'element' => '[data-tour="cc-my-performance-range"]',
                'title'   => 'Choose the period',
                'body'    => 'Switch between the last 7 days, 30 days, 90 days or the full year. Every number on the page updates to match the period you pick.',
            ],
            [
                'element' => '[data-tour="cc-my-performance-activity"]',
                'title'   => 'What you did',
                'body'    => 'Your activity at a glance: events completed, viewings held and presentations given. This is the work that builds deals.',
            ],
            [
                'element' => '[data-tour="cc-my-performance-feedback"]',
                'title'   => 'Feedback Rate — keep it green',
                'body'    => 'This is the share of your viewings where you logged feedback to the seller. Green means you are at 70% or better; amber means catch up. Sellers judge you on feedback, so keep this tile green.',
            ],
            [
                'element' => '[data-tour="cc-my-performance-pipeline"]',
                'title'   => 'Where your deals stand',
                'body'    => 'Active buyers you are working, buyers at risk of going cold, deals lost and the rand value of those losses. Watch High-Risk Buyers — those are the ones to phone today. Close this and check who needs a call.',
            ],
        ],
    ],

    // ── Manager Oversight (manager / team-lead screen) ────────────────────────
    'cc-oversight' => [
        'key'         => 'cc-oversight',
        'title'       => 'Working the Manager Oversight board',
        'description' => 'For managers: see every outstanding item across your agents, filter it, and nudge the right person.',
        'route'       => 'corex.dashboard.oversight',
        // Route is gated by permission:dashboard.oversight.view — mirror it so the
        // directory only offers this manager tour to people who can reach the page.
        'permission'  => 'dashboard.oversight.view',
        'setup' => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="cc-oversight-header"]',
                'title'   => 'Your team, in one view',
                'body'    => 'This board gathers everything outstanding for the agents in your scope — so nothing slips through while you are managing several people at once.',
            ],
            [
                'element' => '[data-tour="cc-oversight-filters"]',
                'title'   => 'Narrow it down',
                'body'    => 'Use these filters to focus. Choose a category or a single agent and the list updates the moment you change a filter.',
            ],
            [
                'element' => '[data-tour="cc-oversight-category"]',
                'title'   => 'By category',
                'body'    => 'Filter to one type of outstanding work — for example overdue feedback or compliance items — when you want to clear one thing across the whole team.',
            ],
            [
                'element' => '[data-tour="cc-oversight-agent"]',
                'title'   => 'By agent',
                'body'    => 'Pick a single agent to review everything sitting on their plate before a one-on-one or a check-in.',
            ],
            [
                'element' => '[data-tour="cc-oversight-table"]',
                'title'   => 'Severity tells you what to do first',
                'body'    => 'Each row shows the agent, the item, how long it has been waiting, and a Severity badge: High needs attention now, Medium can wait a little. The Nudge button sends that agent a direct, pre-written prompt to action it.',
            ],
            [
                'element' => '[data-tour="cc-oversight-settings"]',
                'title'   => 'Tune your scope',
                'body'    => 'Oversight Settings is where you decide which agents and which categories appear here. Close this, set your scope once, and the board does the watching for you.',
            ],
        ],
    ],

    // ── Performance (this-week scorecard + activity points) ───────────────────
    'cc-performance' => [
        'key'         => 'cc-performance',
        'title'       => 'Your weekly scorecard',
        'description' => 'Track your weekly score, your activity points against target, and properties needing attention.',
        'route'       => 'command-center.performance',
        // Route is gated by permission:view_dashboard — mirror it.
        'permission'  => 'view_dashboard',
        'setup' => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="cc-performance-header"]',
                'title'   => 'How you are tracking',
                'body'    => 'This page tells you, plainly, whether you are on track this week and this month. No guessing — the numbers are right here.',
            ],
            [
                'element' => '[data-tour="cc-performance-scorecard"]',
                'title'   => 'My Scorecard',
                'body'    => 'Your overall score for the week, with the parts that make it up: tasks completed, properties attended, events completed and how quickly you respond. The colour is never red for a neutral number — green is strong, amber means there is room to push.',
            ],
            [
                'element' => '[data-tour="cc-performance-points"]',
                'title'   => 'Activity Points vs target',
                'body'    => 'Points you have earned this month against your target, with a progress bar. Doing the right activities — viewings, follow-ups, captures — is what moves this bar.',
            ],
            [
                'element' => '[data-tour="cc-performance-capture"]',
                'title'   => 'Log your day',
                'body'    => 'Capture Daily Activity is how you record the work you have done so it counts towards your points. Get into the habit at the end of each day.',
            ],
            [
                'element' => '[data-tour="cc-performance-prop-health"]',
                'title'   => 'Properties needing attention',
                'body'    => 'CoreX scores each of your listings on its health and surfaces the ones slipping — Critical first, then Attention. Close this and clear the Critical ones; a healthy listing sells faster.',
            ],
        ],
    ],

    // ── Calendar Invitations (inbox of event invites from other agents) ───────
    'cc-invitations' => [
        'key'         => 'cc-invitations',
        'title'       => 'Responding to calendar invitations',
        'description' => 'Accept, tentatively hold or decline events other agents invite you to — and spot clashes before you say yes.',
        'route'       => 'command-center.calendar.invitations',
        // No permission: any agent receives invitations.
        'setup' => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="cc-invitations-header"]',
                'title'   => 'Invitations from your colleagues',
                'body'    => 'When another agent adds you to an event — a joint viewing, a meeting — it lands here for you to respond to. Nothing goes on your calendar until you say yes.',
            ],
            [
                'element' => '[data-tour="cc-invitations-list"]',
                'title'   => 'Your pending invitations',
                'body'    => 'Each invitation shows the event, when it is, and who invited you. If CoreX spots that the time clashes with something already in your diary, it warns you with an amber Conflicts note — so you never double-book.',
            ],
            [
                'element' => '[data-tour="cc-invitations-actions"]',
                'title'   => 'Accept, Tentative or Decline',
                'body'    => 'Accept to put it firmly in your diary, Tentative if you might make it, or Decline if you cannot. Your reply is sent straight back to the agent who invited you. Close this and clear your invitations so your calendar is honest.',
            ],
        ],
    ],

    // ── Notifications (in-app notification list) ──────────────────────────────
    'cc-notifications' => [
        'key'         => 'cc-notifications',
        'title'       => 'Reading your notifications',
        'description' => 'See what CoreX has flagged for you, tell unread from read, and clear them down to zero.',
        'route'       => 'command-center.notifications',
        // No permission: every agent has a notifications inbox.
        'setup' => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="cc-notifications-header"]',
                'title'   => 'Everything CoreX wants to tell you',
                'body'    => 'This is the full list behind the bell icon — reminders, alerts and updates from across the system, newest at the top.',
            ],
            [
                'element' => '[data-tour="cc-notifications-list"]',
                'title'   => 'Unread stands out',
                'body'    => 'An unread notification has a coloured dot and a coloured edge; once read it goes quiet and grey. Glance down and you instantly see what is new.',
            ],
            [
                'element' => '[data-tour="cc-notifications-mark-all"]',
                'title'   => 'Clear them in one tap',
                'body'    => 'When you have caught up, Mark all read empties your unread count in one go. Close this and clear the list — a tidy bell means nothing has been missed.',
            ],
        ],
    ],

    // ── Dashboard Settings (per-user reminders & calendar preferences) ────────
    'cc-user-settings' => [
        'key'         => 'cc-user-settings',
        'title'       => 'Setting up your reminders',
        'description' => 'Tune your own idle alerts, compliance reminders, calendar defaults and which channels reach you.',
        'route'       => 'command-center.user-settings',
        // No permission: every agent controls their own dashboard settings.
        'setup' => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="cc-user-settings-header"]',
                'title'   => 'Make CoreX work the way you do',
                'body'    => 'These are your personal settings — your reminders, alerts and calendar preferences. If your agency has locked some of these, you will see an amber note, and your admin manages those for you.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-idle"]',
                'title'   => 'Property Idle Alerts',
                'body'    => 'Get nudged about listings that have gone quiet — for example, remind me every Wednesday about any property untouched for two weeks. Stale listings are how deals go cold.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-compliance"]',
                'title'   => 'Compliance reminders',
                'body'    => 'Turn on reminders for lease expiries, FICA documents and your FFC. These keep you on the right side of the rules without you having to diarise dates by hand.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-calendar"]',
                'title'   => 'Calendar preferences',
                'body'    => 'Set how your calendar opens — month, week, day or agenda — your working hours, and whether weekends show. Small touches that make your diary feel like yours.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-channels"]',
                'title'   => 'How you want to be reached',
                'body'    => 'Choose whether alerts come via the in-app bell, email, or mobile push. Open Hours quietens email outside the window you set, so you are not pinged at midnight.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-save"]',
                'title'   => 'Save your choices',
                'body'    => 'Nothing changes until you save. Close this, set things up the way you like, then press Save Settings — you only need to do this once.',
            ],
        ],
    ],

];
