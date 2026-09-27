<?php

/**
 * Guided-tour definitions — the Assistants feature (AT-267).
 *
 * Three tours, one per screen:
 *   assist-admin-create  → admin.assistants.create   (admin adds an assistant)
 *   assist-agent-index   → agent.assistants.index     (agent's "My Assistants")
 *   assist-agent-matrix  → agent.assistants.matrix     (the permission switchboard)
 *
 * Each entry is pure DATA merged by App\Support\Tours\TourRegistry::all() from
 * every file in app/Support/Tours/defs/*.php. Keys are globally unique and
 * namespaced (`assist-…`) so a later file never clobbers an earlier one.
 *
 * Every `element` selector is a dedicated data-tour="…" anchor added to the real
 * DOM of the screen's Blade view — so a markup refactor never silently breaks a
 * step, and the engine deterministically skips any step whose anchor is absent on
 * the current render (e.g. the "new permissions" chip when there is no drift).
 */

return [

    // ── Admin: Add an assistant ───────────────────────────────────────────────
    'assist-admin-create' => [
        'key'         => 'assist-admin-create',
        'title'       => 'Adding an assistant',
        'description' => 'Give a staff member their own login that works on behalf of one of your agents.',
        'route'       => 'admin.assistants.create',
        'permission'  => 'assistants.create',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="assist-create-intro"]',
                'section' => 'Their details',
                'title'   => 'Add an assistant',
                'body'    => 'An assistant is a person who works for one of your agents — a PA, a receptionist, an office administrator. They get their own login, so the work is done under their name but always counts as the agent\'s. This closes the shared-password gap.',
            ],
            [
                'element' => '[data-tour="assist-create-details"]',
                'do'      => ['action' => 'fill', 'say' => 'Type their first name.'],
                'title'   => 'Who they are',
                'body'    => 'Their name, email and cell. We email them a secure link to set their own password — you never handle it. Nothing here is the agent yet; that comes next.',
            ],
            [
                'element'       => '[data-tour="assist-create-surname"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type their surname.'],
                'title'         => 'Surname',
                'body'          => 'Spell it the way it appears on their ID — it shows on everything they do.',
            ],
            [
                'element'       => '[data-tour="assist-create-email"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type their email address.'],
                'title'         => 'Email',
                'body'          => 'Their invite goes to this address, and it becomes their login. Use an address they check.',
            ],
            [
                'element'       => '[data-tour="assist-create-cell"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type their cell number.'],
                'title'         => 'Cell',
                'body'          => 'So the office can reach them, and so clients know who they are dealing with.',
            ],
            [
                'element' => '[data-tour="assist-create-title"]',
                'do'      => ['action' => 'fill', 'say' => 'Type what they are called, e.g. PA — or press Skip this step to keep "Assistant".'],
                'title'   => 'What they are called',
                'body'    => 'Optional. Call them a PA, Receptionist or Secretary if "Assistant" isn\'t the right word for your office. It is a label only — it never changes what they can do. Leave it blank to use "Assistant".',
            ],
            [
                'element' => '[data-tour="assist-create-agent"]',
                'section' => 'Linking to an agent',
                'do'      => ['action' => 'choose', 'say' => 'Pick the agent this assistant works for.'],
                'title'   => 'The agent they work for',
                'body'    => 'This is the heart of it. The assistant starts with a copy of THIS agent\'s permissions and can never do more than them. Everything they do is filed on this agent\'s book. Owners and other assistants can\'t be chosen.',
            ],
            [
                'element' => '[data-tour="assist-create-fica"]',
                'do'      => ['action' => 'choose', 'say' => 'Untick FICA if they never handle client documents — or press Skip this step to leave it as it is.'],
                'title'   => 'FICA verification',
                'body'    => 'Leave on if this person handles client documents — they\'ll be asked for an ID copy and proof of residence, and appear on your compliance dashboards. Turn off for someone who never touches client files.',
            ],
            [
                'element' => '[data-tour="assist-create-submit"]',
                'section' => 'Sending the invite',
                'do'      => ['action' => 'click', 'say' => 'Click Create & send invite — this creates their login and emails them a link to set their password.'],
                'title'   => 'Create and invite',
                'body'    => 'This creates the login and emails the invite. From there, the agent decides exactly what the assistant may do — from their own My Assistants page.',
            ],
            [
                'element' => '[data-tour="assist-create-help"]',
                'section' => 'How assistants work',
                'title'   => 'The short version',
                'body'    => 'These four rules always hold: a copy of the agent\'s permissions, never more than the agent, everything filed on the agent\'s book, and never able to create a listing or count as a billable user.',
            ],
        ],
    ],

    // ── Agent: My Assistants ──────────────────────────────────────────────────
    'assist-agent-index' => [
        'key'         => 'assist-agent-index',
        'title'       => 'Your assistants',
        'description' => 'See who works on your behalf and open their permission switchboard.',
        'route'       => 'agent.assistants.index',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="assist-index-intro"]',
                'section' => 'Your assistants',
                'title'   => 'The people who help you',
                'body'    => 'Anyone set up to work on your behalf appears here. Everything they do is recorded against your name and lands on your book — and they can never do more than you can.',
            ],
            [
                'element' => '[data-tour="assist-index-card"]',
                'title'   => 'One card each',
                'body'    => 'Each assistant shows how many of the things you can do they\'re currently allowed to do. If you\'ve just been given new access, a small amber note tells you there are new permissions you can choose to hand over.',
            ],
            [
                'element' => '[data-tour="assist-index-matrix"]',
                'section' => 'Setting what they can do',
                'do'      => ['action' => 'click', 'say' => 'Click Choose what they can do to open their switchboard.'],
                'title'   => 'You decide what they do',
                'body'    => 'Open the switchboard to turn each capability on or off. You are always in control — an assistant only ever has what you\'ve chosen to give them. Adding or removing an assistant is an admin job.',
            ],
        ],
    ],

    // ── Agent: the permission switchboard (matrix) ────────────────────────────
    // Route has an {assignment} param, so the Guided Tours directory marks it
    // "needs context" and offers no direct link — the tour auto-starts on the
    // page and the header "?" replays it. Ellie's guide buttons send the agent to
    // My Assistants first (pick_from) and start once they open a switchboard.
    'assist-agent-matrix' => [
        'key'         => 'assist-agent-matrix',
        'title'       => 'Choosing what your assistant can do',
        'description' => 'The switchboard: turn each capability on or off, and set how much your assistant can see.',
        'route'       => 'agent.assistants.matrix',
        'pick_from'   => 'agent.assistants.index',
        'pick_note'   => 'Click "Choose what they can do" on the assistant you want to set up and I\'ll pick up from there.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="assist-matrix-intro"]',
                'section' => 'Checking their activity',
                'title'   => 'Everything you can do, in one place',
                'body'    => 'This list is a copy of the things YOU can do. Anything switched on, your assistant may do on your behalf. Switch off anything you don\'t want handed over — you can change it any time.',
            ],
            [
                'element' => '[data-tour="assist-matrix-activity"]',
                'do'      => ['action' => 'click', 'say' => 'Click the Activity tab.', 'until' => '[data-tour="assist-matrix-activity-panel"]'],
                'title'   => 'See what they\'ve done',
                'body'    => 'The Permissions tab is what your assistant CAN do. The Activity tab is what they HAVE done — every property, contact and deal they\'ve opened, edited or deleted on your behalf, newest first. Check it any time to be sure nothing is happening that shouldn\'t.',
            ],
            [
                'element'       => '[data-tour="assist-matrix-activity-panel"]',
                'advanced_only' => true,
                'title'         => 'Their recent work',
                'body'          => 'Newest first: what they opened, edited, created or deleted, with a link to each record. Nothing listed means they have not worked on your book yet.',
            ],
            [
                'element'       => '[data-tour="assist-matrix-permissions-tab"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Permissions tab to go back to the switchboard.', 'until' => '[data-tour="assist-matrix-behaviour"]'],
                'skip_if'       => '[data-tour="assist-matrix-behaviour"]',
                'title'         => 'Back to the switchboard',
                'body'          => 'The Permissions tab is where you choose what they may do.',
            ],
            [
                'element' => '[data-tour="assist-matrix-behaviour"]',
                'section' => 'How they work for you',
                'do'      => ['action' => 'choose', 'say' => 'Switch any of these on or off — each change saves straight away.'],
                'title'   => 'How they work for you',
                'body'    => 'These toggles set the style of help: whether they can edit and delete your records or only add to them, whether their name is tagged on what they touch, whether you\'re notified, and whether they can download document files.',
            ],
            [
                'element' => '[data-tour="assist-matrix-attribution"]',
                'title'   => 'Always filed as yours',
                'body'    => 'This one can\'t be switched off, by design. Contacts, deals, calendar entries and activity your assistant adds all appear on your book as your own work — with a record that they were the one who did it.',
            ],
            [
                'element' => '[data-tour="assist-matrix-features"]',
                'section' => 'Turning things on and off',
                'do'      => ['action' => 'choose', 'say' => 'Click a group, e.g. Contacts.'],
                'title'   => 'Find a capability',
                'body'    => 'Capabilities are grouped by area. Pick a group here, or type in the search box to jump straight to what you\'re looking for.',
            ],
            [
                'element' => '[data-tour="assist-matrix-detail"]',
                'do'      => ['action' => 'choose', 'say' => 'Switch a capability on or off, or pick how much they can see — it saves straight away.'],
                'title'   => 'Turn things on and off',
                'body'    => 'Each row is one capability — flip it Enabled or Disabled. For anything that involves viewing records, choose how much they see: only your records, your branch, or the whole agency (never more than you). A few rows are locked by CoreX and can never be turned on — creating a listing is one.',
            ],
            [
                'element' => '[data-tour="assist-matrix-save"]',
                'do'      => ['action' => 'click', 'target' => 'button', 'say' => 'Click Save to confirm your changes.'],
                'title'   => 'Saved as you go',
                'body'    => 'Changes save automatically, and Save confirms it. Your assistant picks up every change on their next click — turn something off and it\'s gone for them straight away.',
            ],
        ],
    ],

];
