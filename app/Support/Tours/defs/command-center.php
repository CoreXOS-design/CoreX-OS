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
                'section' => 'Your day at a glance',
                'title'   => 'This is your day, at a glance',
                'body'    => 'Today is where you start every morning. It greets you by name and shows today\'s date so you always know exactly where you stand.',
            ],
            [
                'element' => '[data-tour="cc-today-board"]',
                'title'   => 'Your day on one screen',
                'body'    => 'CoreX reads across all your work — appointments, buyers, documents, listings — and lays it out without scrolling: today\'s appointments hour by hour on the left with a line at the current time, everything waiting on you stacked on the right with the most urgent on top, and your numbers along the bottom. You do not go hunting; the work comes to you.',
            ],
            [
                'element' => '[data-tour="cc-today-refresh"]',
                'section' => 'Getting the latest',
                'do'      => ['action' => 'click', 'say' => 'Click Refresh to pull in the latest.'],
                'title'   => 'Always up to date',
                'body'    => 'The board refreshes itself every minute. Out and about, then back at your desk? Tap Refresh to pull the very latest before you plan your next move.',
            ],
            [
                'element' => '[data-tour="cc-today-greeting"]',
                'section' => 'Working your queues',
                'title'   => 'Make it a habit',
                'body'    => 'Open this first thing, work the queues on the right from the top down, and the rest of your day follows. Close this and have a look at what is waiting for you today.',
            ],
            [
                'element'       => '[data-tour="cc-today-rail"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the top item in your queue — it opens in a new tab.'],
                'title'         => 'Start at the top',
                'body'          => 'The most urgent item is always first. Each one opens in a new tab, so this board stays right here for when you come back for the next.',
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
                'section' => 'Choosing the period',
                'title'   => 'Your numbers, your name',
                'body'    => 'This page is about you and only you. It shows how you have been working over the period shown next to your name.',
            ],
            [
                'element' => '[data-tour="cc-my-performance-range"]',
                'do'      => ['action' => 'click', 'say' => 'Click 7d, 30d, 90d or Year — the page reloads with that period.'],
                'title'   => 'Choose the period',
                'body'    => 'Switch between the last 7 days, 30 days, 90 days or the full year. Every number on the page updates to match the period you pick.',
            ],
            [
                'element' => '[data-tour="cc-my-performance-activity"]',
                'section' => 'Reading your numbers',
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
                'section' => 'Filtering the board',
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
                'do'      => ['action' => 'fill', 'say' => 'Pick a category — the list reloads to show only that kind of work.'],
                'title'   => 'By category',
                'body'    => 'Filter to one type of outstanding work — for example overdue feedback or compliance items — when you want to clear one thing across the whole team.',
            ],
            [
                'element' => '[data-tour="cc-oversight-agent"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick an agent — the list reloads to show only what is on their plate.'],
                'title'   => 'By agent',
                'body'    => 'Pick a single agent to review everything sitting on their plate before a one-on-one or a check-in.',
            ],
            [
                'element' => '[data-tour="cc-oversight-table"]',
                'section' => 'Nudging an agent',
                'title'   => 'Severity tells you what to do first',
                'body'    => 'Each row shows the agent, the item, how long it has been waiting, and a Severity badge: High needs attention now, Medium can wait a little. The Nudge button sends that agent a direct, pre-written prompt to action it.',
            ],
            // Nudge exists only for managers who may nudge, and only when there are
            // rows. skip_if = "no Nudge button anywhere below the header", so these
            // three steps are skipped at once instead of each waiting to time out.
            [
                'element'       => '[data-tour="cc-oversight-nudge"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Nudge on this item.', 'until' => '[data-tour="cc-oversight-nudge-form"]'],
                'skip_if'       => '[data-tour="cc-oversight-header"]:not(:has(~ * [data-tour="cc-oversight-nudge"]))',
                'title'         => 'Nudge the agent',
                'body'          => 'Nudge opens a short, pre-written reminder to the agent about this exact item.',
            ],
            [
                'element'       => '[data-tour="cc-oversight-nudge-form"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'target' => 'textarea', 'say' => 'Add a personal word to the message if you like — or press Skip this step to keep it as is.'],
                'skip_if'       => '[data-tour="cc-oversight-header"]:not(:has(~ * [data-tour="cc-oversight-nudge"]))',
                'title'         => 'Check the message',
                'body'          => 'CoreX has already written the reminder with the agent\'s name and the item. Keep it short and friendly.',
            ],
            [
                'element'       => '[data-tour="cc-oversight-nudge-send"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Send Nudge — this emails the agent and drops a note in their CoreX notifications.'],
                'skip_if'       => '[data-tour="cc-oversight-header"]:not(:has(~ * [data-tour="cc-oversight-nudge"]))',
                'title'         => 'Send the nudge',
                'body'          => 'The agent gets your message by email and in their notifications, so they know exactly what to action.',
            ],
            [
                'element' => '[data-tour="cc-oversight-settings"]',
                'section' => 'Setting your scope',
                'do'      => ['action' => 'click', 'say' => 'Click Oversight Settings to choose the agents and categories you watch.'],
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
                'section' => 'Your scorecard',
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
                'section' => 'Logging your day',
                'do'      => ['action' => 'click', 'say' => 'Click Capture Daily Activity to log today\'s work — or press Skip this step to carry on here.'],
                'title'   => 'Log your day',
                'body'    => 'Capture Daily Activity is how you record the work you have done so it counts towards your points. Get into the habit at the end of each day.',
            ],
            [
                'element' => '[data-tour="cc-performance-prop-health"]',
                'section' => 'Properties needing attention',
                'title'   => 'Properties needing attention',
                'body'    => 'CoreX scores each of your listings on its health and surfaces the ones slipping — Critical first, then Attention. Close this and clear the Critical ones; a healthy listing sells faster.',
            ],
            [
                'element'       => '[data-tour="cc-performance-prop-link"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click this property to open it and fix what is flagged.'],
                'skip_if'       => '[data-tour="cc-performance-prop-health"]:not(:has([data-tour="cc-performance-prop-link"]))',
                'title'         => 'Open the listing that needs you most',
                'body'          => 'The lowest score sits at the top. Open it, deal with what is flagged in red or amber, and its health recovers.',
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
                'section' => 'Your invitations',
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
                'section' => 'Replying to an invitation',
                'do'      => ['action' => 'click', 'say' => 'Click Accept, or Tentative if you are not sure — your reply goes straight back to the agent who invited you.'],
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
                'section' => 'Reading notifications',
                'title'   => 'Everything CoreX wants to tell you',
                'body'    => 'This is the full list behind the bell icon — reminders, alerts and updates from across the system, newest at the top.',
            ],
            [
                'element' => '[data-tour="cc-notifications-list"]',
                'title'   => 'Unread stands out',
                'body'    => 'An unread notification has a coloured dot and a coloured edge; once read it goes quiet and grey. Glance down and you instantly see what is new.',
            ],
            [
                'element'       => '[data-tour="cc-notifications-mark-read"]',
                'section'       => 'Clearing your list',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the tick to mark this notification as read.'],
                'skip_if'       => '[data-tour="cc-notifications-list"]:not(:has([data-tour="cc-notifications-mark-read"]))',
                'title'         => 'Mark one as read',
                'body'          => 'Dealt with a single item? The tick marks just that notification as read and leaves the rest waiting for you.',
            ],
            [
                'element' => '[data-tour="cc-notifications-mark-all"]',
                'do'      => ['action' => 'click', 'say' => 'Click Mark all read to clear your unread count.'],
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
                'section' => 'Setting your reminders',
                'title'   => 'Make CoreX work the way you do',
                'body'    => 'These are your personal settings — your reminders, alerts and calendar preferences. If your agency has locked some of these, you will see an amber note, and your admin manages those for you.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-idle"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick Enabled and pick the day you want the reminder — or press Skip this step to keep it as is.'],
                'skip_if' => '[data-tour="cc-user-settings-locked"]',
                'title'   => 'Property Idle Alerts',
                'body'    => 'Get nudged about listings that have gone quiet — for example, remind me every Wednesday about any property untouched for two weeks. Stale listings are how deals go cold.',
            ],
            [
                'element'       => '[data-tour="cc-user-settings-docs"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Tick Enabled to get document reminders — or press Skip this step to keep it as is.'],
                'skip_if'       => '[data-tour="cc-user-settings-locked"]',
                'title'         => 'Document reminders',
                'body'          => 'Get a reminder before a document you are working on falls due. Set how many hours ahead you want the warning.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-compliance"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick the reminders you want — lease expiry, FICA and FFC — or press Skip this step to keep them as they are.'],
                'skip_if' => '[data-tour="cc-user-settings-locked"]',
                'title'   => 'Compliance reminders',
                'body'    => 'Turn on reminders for lease expiries, FICA documents and your FFC. These keep you on the right side of the rules without you having to diarise dates by hand.',
            ],
            [
                'element'       => '[data-tour="cc-user-settings-tasks"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Tick Enabled to get task and event reminders — or press Skip this step to keep it as is.'],
                'skip_if'       => '[data-tour="cc-user-settings-locked"]',
                'title'         => 'Task and event reminders',
                'body'          => 'Get an email and a notification before a task or calendar event is due, so nothing sneaks up on you.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-calendar"]',
                'section' => 'Calendar preferences',
                'do'      => ['action' => 'choose', 'say' => 'Pick how your calendar opens and tick whether weekends show — or press Skip this step to keep it as is.'],
                'skip_if' => '[data-tour="cc-user-settings-locked"]',
                'title'   => 'Calendar preferences',
                'body'    => 'Set how your calendar opens — month, week, day or agenda — your working hours, and whether weekends show. Small touches that make your diary feel like yours.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-channels"]',
                'section' => 'How alerts reach you',
                'do'      => ['action' => 'choose', 'say' => 'Tick the ways you want your alerts to reach you.'],
                'title'   => 'How you want to be reached',
                'body'    => 'Choose whether alerts come via the in-app bell, email, or mobile push. Open Hours quietens email outside the window you set, so you are not pinged at midnight.',
            ],
            [
                'element' => '[data-tour="cc-user-settings-save"]',
                'section' => 'Saving your settings',
                'do'      => ['action' => 'click', 'say' => 'Click Save Settings.'],
                'title'   => 'Save your choices',
                'body'    => 'Nothing changes until you save. Close this, set things up the way you like, then press Save Settings — you only need to do this once.',
            ],
        ],
    ],

];
