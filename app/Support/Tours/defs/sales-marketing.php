<?php

/**
 * Guided-tour definitions — SALES & MARKETING group (AT-478 coverage pass, spec advanced-guiding.md §12).
 *
 * Features covered: Road to Sold, Marketing, Public website, Marketing Suppressions, Imported Stock,
 * Viewing Packs, Deeds Capture, Address Review.
 *
 * Pure DATA merged by App\Support\Tours\TourRegistry::all() from every file in
 * app/Support/Tours/defs/*.php. Keys are namespaced `smk-…`. Every `element` / `target` / `until` /
 * `skip_if` selector is a dedicated data-tour="smk-…" anchor on the matching screen; a removed anchor
 * is caught by tests/Feature/Tours/TourRegistryIntegrityTest.
 *
 * One definition drives all three modes: the click-through Guided Tour, the hands-on Advanced Guide
 * (the `do` steps) and Spot Help (the `section`s). The guide never types, picks or saves for the agent.
 * Wording is neutral for any agency (multi-agency rule).
 */

return [

    // ══ ROAD TO SOLD ════════════════════════════════════════════════════════

    // ── The daily call list ──────────────────────────────────────────────────
    'smk-rts-list' => [
        'key'         => 'smk-rts-list',
        'title'       => 'Working your Road to Sold list',
        'description' => 'Your daily list of properties to phone, how to choose and search the list, and how to record each call.',
        'route'       => 'road-to-sold.index',
        'permission'  => 'access_road_to_sold',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-rts-banner"]',
                'section' => 'Your daily list',
                'title'   => 'Road to Sold',
                'body'    => 'Every day CoreX gives you a list of properties to phone so you can win more stock. Your agency sets a target for each weekday, and this screen is where you work through that list and record how every call went.',
            ],
            [
                'element' => '[data-tour="smk-rts-summary"]',
                'title'   => 'How you are doing this month',
                'body'    => 'Each block is one step on the road from a phone call to a sale. It shows what you have done this month against where you should be by today. Green means on track, amber slightly behind and red behind. Click a block to see the exact rows behind the number.',
            ],
            [
                'element' => '[data-tour="smk-rts-view"]',
                'section' => 'Choosing your list',
                'do'      => ['action' => 'choose', 'say' => 'Pick which list you want to look at.'],
                'title'   => 'Show',
                'body'    => 'Today\'s list is what you owe for the day. The other lists bring back properties where nobody answered, the number was wrong, or no owner is on file yet. Pick a list and the screen reloads with it.',
            ],
            [
                'element' => '[data-tour="smk-rts-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address, suburb, owner name or phone number — or press Skip this step.'],
                'title'   => 'Search',
                'body'    => 'Type an address, suburb, owner or phone number and press Search. On today\'s list you can also narrow by status (open, worked, missed), pick another day and change the sort order.',
            ],
            [
                'element' => '[data-tour="smk-rts-progress"]',
                'section' => 'Working the list',
                'title'   => 'Your progress for the day',
                'body'    => 'This line counts how many properties you have worked out of the target for the day. If your agency allows it you can add a few more from another selection on top of the target — those extras are counted separately.',
            ],
            [
                'element' => '[data-tour="smk-rts-list"]',
                'title'   => 'The properties to phone',
                'body'    => 'One row per property: the address, asking price, days on the market, which agency has it listed, why CoreX suggested it, the owner and phone number if we have them, and the result of the last call. No owner on file? Use Find the owner.',
            ],
            [
                'element' => '[data-tour="smk-rts-contact"]',
                'do'      => ['action' => 'click', 'say' => 'Click Contacted client on a property you have just phoned.', 'until' => '[data-tour="smk-rts-contact-form"]'],
                'title'   => 'Contacted client',
                'body'    => 'After every call, press Contacted client and record what happened. That is what moves the numbers at the top, so do it for every call — even the ones nobody answered.',
            ],
            [
                'element'       => '[data-tour="smk-rts-outcome-pick"]',
                'section'       => 'Recording a call',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick what happened on the call.'],
                'title'         => 'What happened?',
                'body'          => 'Choose the result that fits. A conversation means you spoke to the owner; an attempt means you tried but did not get hold of anyone. Some results ask for a date to bring the property back on, or let you book an evaluation.',
            ],
            [
                'element'       => '[data-tour="smk-rts-note"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type a short note about the call — or press Skip this step.'],
                'title'         => 'Note',
                'body'          => 'Optional but worth it. Whatever you write shows in the property\'s history, so the next agent who phones knows where things stand.',
            ],
            [
                'element'       => '[data-tour="smk-rts-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save to record the call.'],
                'title'         => 'Save the call',
                'body'          => 'Save records the call against the property and your day\'s target.',
            ],
            [
                'element' => '[data-tour="smk-rts-feeds"]',
                'section' => 'Other follow-ups',
                'title'   => 'Other work waiting for you',
                'body'    => 'Below the list CoreX shows buyers to follow up, enquiries waiting for a reply and mandates ending soon. These are not counted against your daily target, but they are the next calls worth making.',
            ],
            [
                'element' => '[data-tour="smk-rts-report-btn"]',
                'title'   => 'The report',
                'body'    => 'Report shows every agent\'s calls, conversations and outcomes for a period, so a manager can see who is on the road and who is behind.',
            ],
        ],
    ],

    // ── The Road to Sold report ──────────────────────────────────────────────
    'smk-rts-report' => [
        'key'         => 'smk-rts-report',
        'title'       => 'Reading the Road to Sold report',
        'description' => 'See every agent\'s calls and outcomes for a period, compare periods and open the rows behind any figure.',
        'route'       => 'road-to-sold.report',
        'permission'  => 'access_road_to_sold',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-rr-banner"]',
                'section' => 'Reading the report',
                'title'   => 'The Road to Sold report',
                'body'    => 'One row per agent, showing how much of the road each one has walked in the chosen period — calls made, conversations held and where the calls ended up. You see your own row, or your whole team if you manage one.',
            ],
            [
                'element' => '[data-tour="smk-rr-actions"]',
                'section' => 'Choosing the period',
                'title'   => 'Period, comparison and exports',
                'body'    => 'Pick the period you want from the period selector, and switch on a comparison to see how it stacks up against an earlier one. Print, PDF and Excel export exactly what is on screen.',
            ],
            [
                'element' => '[data-tour="smk-rr-filters"]',
                'section' => 'Finding an agent',
                'do'      => ['action' => 'fill', 'say' => 'Type an agent\'s name — or press Skip this step.', 'target' => 'input[name="q"]'],
                'title'   => 'Search agents',
                'body'    => 'Type part of an agent\'s name and press Search to see just that agent. If your agency has more than one branch you can also narrow to a single branch.',
            ],
            [
                'element' => '[data-tour="smk-rr-table"]',
                'section' => 'The figures',
                'title'   => 'The table',
                'body'    => 'Click a column heading to sort by it; click again to reverse the order. Shaded columns are the call outcomes.',
            ],
            [
                'element' => '[data-tour="smk-rr-row"]',
                'do'      => ['action' => 'click', 'say' => 'Click any figure to open the rows behind it.', 'target' => 'button'],
                'title'   => 'Every number is clickable',
                'body'    => 'Click a figure and a panel opens listing the actual properties and calls that make up that number — so you can always see where a figure came from.',
            ],
            [
                'element' => '[data-tour="smk-rr-total"]',
                'title'   => 'The total',
                'body'    => 'The bottom row adds everyone up. It only appears when the report covers more than one agent.',
            ],
        ],
    ],

    // ── Weekday targets (settings) ───────────────────────────────────────────
    'smk-rts-targets' => [
        'key'         => 'smk-rts-targets',
        'title'       => 'Setting Road to Sold targets',
        'description' => 'Set how many properties each agent must phone on each weekday — an agency default, then personal numbers where they differ.',
        'route'       => 'road-to-sold.settings.targets.index',
        'permission'  => 'road_to_sold.manage_targets',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-rt-default"]',
                'section' => 'The agency default week',
                'title'   => 'The default week',
                'body'    => 'This is the number of properties an agent should phone on each weekday unless you give them their own numbers. Most agents will simply follow this week.',
            ],
            [
                'element'       => '[data-tour="smk-rt-default-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Change a day above if you need to, then click Save default — or press Skip this step.'],
                'title'         => 'Save default',
                'body'          => 'Saves the default week. Give every agent this week replaces everyone\'s own numbers with it, so use that one with care.',
            ],
            [
                'element' => '[data-tour="smk-rt-filters"]',
                'section' => 'Finding an agent',
                'title'   => 'Filters',
                'body'    => 'Narrow the grid to the agents you want. You can also look at cleared numbers, which are personal numbers that were removed and can be brought back.',
            ],
            [
                'element' => '[data-tour="smk-rt-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an agent\'s name or email — or press Skip this step.'],
                'title'   => 'Search',
                'body'    => 'Type part of an agent\'s name or email address and press Apply.',
            ],
            [
                'element' => '[data-tour="smk-rt-mode"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick which agents to show — or press Skip this step.'],
                'title'   => 'Numbers',
                'body'    => 'Show everyone, only agents with their own numbers, or only agents who follow the agency default.',
            ],
            [
                'element' => '[data-tour="smk-rt-grid"]',
                'section' => 'Each agent\'s week',
                'title'   => 'The grid',
                'body'    => 'One row per agent, one box per weekday. A blank box means the agent follows the agency default for that day, which is shown faintly in the box.',
            ],
            [
                'element' => '[data-tour="smk-rt-day"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a number of properties for this agent on this day.'],
                'title'   => 'A day\'s target',
                'body'    => 'Type how many properties this agent should phone on that weekday. Use 0 for a day they do not work the list.',
            ],
            [
                'element' => '[data-tour="smk-rt-week"]',
                'title'   => 'Week and month totals',
                'body'    => 'Week is what the agent owes in a normal week. The month figure shows what they should have done by today and for the whole month, after public holidays and approved leave are taken off.',
            ],
            [
                'element'       => '[data-tour="smk-rt-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save on this agent\'s row.'],
                'title'         => 'Save the row',
                'body'          => 'Saves this agent\'s numbers. Monday to Friday copies the Monday number across the week, and To all agents gives everyone the same numbers.',
            ],
        ],
    ],

    // ══ MARKETING SUPPRESSIONS ══════════════════════════════════════════════

    'smk-sup-register' => [
        'key'         => 'smk-sup-register',
        'title'       => 'Checking who has opted out of marketing',
        'description' => 'The register of every email address and phone number that has opted out of marketing, and how to lift one.',
        'route'       => 'admin.marketing-suppressions.index',
        'permission'  => 'marketing_suppressions.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-sup-banner"]',
                'section' => 'The opt-out register',
                'title'   => 'Marketing Suppressions',
                'body'    => 'When someone opts out of marketing, their email address or phone number is written down here and blocked across the whole agency. That keeps you on the right side of POPIA: nobody who said no gets another campaign, even if they are imported again later.',
            ],
            [
                'element' => '[data-tour="smk-sup-count"]',
                'title'   => 'How many',
                'body'    => 'The number of records that match the filter you have chosen below.',
            ],
            [
                'element' => '[data-tour="smk-sup-search"]',
                'section' => 'Finding an opt-out',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an email address or phone number — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Search',
                'body'    => 'Type an email address or phone number, or part of one, and press Search to check whether it is blocked.',
            ],
            [
                'element' => '[data-tour="smk-sup-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick Active, Lifted or All statuses.'],
                'title'   => 'Filter by status',
                'body'    => 'Active are the people currently blocked from marketing. Lifted are opt-outs that were later reversed. All statuses shows both.',
            ],
            [
                'element' => '[data-tour="smk-sup-table"]',
                'section' => 'Reading the register',
                'title'   => 'The register',
                'body'    => 'Each row is one blocked email address or number, with where the opt-out came from, the contact it belongs to (if CoreX could match one) and when it was recorded. Nothing here is ever deleted.',
            ],
            [
                'element' => '[data-tour="smk-sup-badge"]',
                'title'   => 'Active or lifted',
                'body'    => 'Active means the person is blocked from marketing. Lifted means they opted back in and can receive marketing again.',
            ],
            [
                'element'       => '[data-tour="smk-sup-lift"]',
                'section'       => 'Lifting a block',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Only if the person has asked to opt back in: click Lift — or press Skip this step.'],
                'title'         => 'Lift',
                'body'          => 'Lift only when the person has told you they want marketing again. CoreX asks you to confirm, and the person can receive campaigns from then on. You will only see this button if you are allowed to lift blocks.',
            ],
        ],
    ],

    // ══ IMPORTED STOCK ══════════════════════════════════════════════════════
    // Renders on the shared Properties list view, so this tour reuses that screen's own
    // re-properties-* anchors (no view change needed).

    'smk-imp-list' => [
        'key'         => 'smk-imp-list',
        'title'       => 'Finding imported stock',
        'description' => 'The off-market listings brought in from the property portals — search, filter and open one.',
        'route'       => 'corex.properties.imported-stock',
        'permission'  => 'access_imported_stock',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="re-properties-intro"]',
                'section' => 'What imported stock is',
                'title'   => 'Imported Stock',
                'body'    => 'These are listings that came into CoreX from the property portals but are no longer being marketed — withdrawn, sold, expired and so on. They are kept here as history and as a source of leads, so they stay out of your live stock list. Imported listings that are still active stay on Properties.',
            ],
            [
                'element' => '[data-tour="re-properties-search"]',
                'section' => 'Finding a listing',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address, suburb or reference — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Search',
                'body'    => 'Type an address, suburb or reference to narrow the list to matching listings.',
            ],
            [
                'element' => '[data-tour="re-properties-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status — or press Skip this step.'],
                'title'   => 'Filter by status',
                'body'    => 'Show only withdrawn, sold, expired or other off-market listings. Choosing a status reloads the list straight away.',
            ],
            [
                'element' => '[data-tour="re-properties-sort"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how you want the list sorted — or press Skip this step.'],
                'title'   => 'Sort the list',
                'body'    => 'Sort by newest, price or the date a listing was imported, to find the most recent arrivals first.',
            ],
            [
                'element' => '[data-tour="re-properties-viewtoggle"]',
                'section' => 'Reading the results',
                'do'      => ['action' => 'choose', 'say' => 'Switch between cards and a table.'],
                'title'   => 'Cards or table',
                'body'    => 'Cards show a photo for each listing; the table fits more on screen and shows the imported date.',
            ],
            [
                'element' => '[data-tour="re-properties-list"]',
                'title'   => 'The listings',
                'body'    => 'Each card is one imported listing, with its price, address and the date it was brought into CoreX. Click one to open it. Imported listings arrive from the portal sync — you cannot add them by hand.',
            ],
        ],
    ],

    // ══ VIEWING PACKS ═══════════════════════════════════════════════════════

    // ── The list of packs ────────────────────────────────────────────────────
    'smk-vp-list' => [
        'key'         => 'smk-vp-list',
        'title'       => 'Finding your viewing packs',
        'description' => 'Search and filter the buyer packs you have assembled for viewings, then open one or archive it.',
        'route'       => 'corex.viewing-packs.index',
        'permission'  => 'access_viewing_packs',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-vp-banner"]',
                'section' => 'Your viewing packs',
                'title'   => 'Viewing Packs',
                'body'    => 'A viewing pack is the set of properties you take a buyer to see, with the documents to hand over. You build one from a buyer\'s record in the Buyer Pipeline; every pack you have built is listed here.',
            ],
            [
                'element' => '[data-tour="smk-vp-search"]',
                'section' => 'Finding a pack',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a pack title or buyer name — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Search',
                'body'    => 'Type a pack title or a buyer\'s name and press Search.',
            ],
            [
                'element' => '[data-tour="smk-vp-agent"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick an agent — or press Skip this step.'],
                'title'   => 'Filter by agent',
                'body'    => 'See one agent\'s packs. This only shows if you are allowed to see packs belonging to other agents.',
            ],
            [
                'element' => '[data-tour="smk-vp-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status — or press Skip this step.'],
                'title'   => 'Filter by status',
                'body'    => 'A pack is either still being put together (draft) or ready to hand over. Pick one to see only those packs.',
            ],
            [
                'element' => '[data-tour="smk-vp-table"]',
                'section' => 'Opening a pack',
                'title'   => 'Your packs',
                'body'    => 'Each row shows the pack, the buyer, the agent, how many properties are in it, its status and when it was created.',
            ],
            [
                'element' => '[data-tour="smk-vp-open"]',
                'do'      => ['action' => 'click', 'say' => 'Click Open on a pack.'],
                'title'   => 'Open a pack',
                'body'    => 'Open takes you into the pack to choose properties, pick documents, download the buyer pack and book the viewing.',
            ],
            [
                'element' => '[data-tour="smk-vp-archive"]',
                'title'   => 'Archive a pack',
                'body'    => 'Archive moves a pack you no longer need out of the way. Nothing is deleted — choose View archived at the top and use Recover to bring it back.',
            ],
        ],
    ],

    // ── One viewing pack ─────────────────────────────────────────────────────
    'smk-vp-pack' => [
        'key'         => 'smk-vp-pack',
        'title'       => 'Building a viewing pack',
        'description' => 'Choose the properties for a buyer, pick the documents to share, download the buyer pack and book the viewing.',
        'route'       => 'corex.viewing-packs.show',
        'permission'  => 'access_viewing_packs',
        'pick_from'   => 'corex.viewing-packs.index',
        'pick_note'   => 'Open the viewing pack you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-vps-banner"]',
                'section' => 'The pack',
                'title'   => 'This buyer\'s viewing pack',
                'body'    => 'The header shows who the pack is for and its status. Build the pack below, then use the buttons up here to download it.',
            ],
            [
                'element' => '[data-tour="smk-vps-title"]',
                'do'      => ['action' => 'fill', 'say' => 'Give the pack a clear title — or press Skip this step.'],
                'title'   => 'Title',
                'body'    => 'A name you will recognise later, for example the buyer\'s name and the day of the viewing.',
            ],
            [
                'element' => '[data-tour="smk-vps-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Set the status — or press Skip this step.'],
                'title'   => 'Status',
                'body'    => 'Keep it as draft while you are still choosing, and set it to ready once the pack is complete.',
            ],
            [
                'element'       => '[data-tour="smk-vps-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save to keep the title and status.'],
                'title'         => 'Save',
                'body'          => 'Saves the title and status. Adding and removing properties below saves by itself.',
            ],
            [
                'element' => '[data-tour="smk-vps-matches"]',
                'section' => 'Choosing properties',
                'title'   => 'Core Matches',
                'body'    => 'These are the properties CoreX thinks suit this buyer, scored by how well they fit. Search, filter by suburb or price and sort them, then press Add on the ones you want to show.',
            ],
            [
                'element' => '[data-tour="smk-vps-adhoc"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an address, suburb or reference — or press Skip this step.', 'target' => 'input[type="text"]'],
                'title'   => 'Add any property',
                'body'    => 'Want to show something that is not a match? Search for it here and press Add. It is added to the pack anyway.',
            ],
            [
                'element' => '[data-tour="smk-vps-selected"]',
                'title'   => 'Selected properties',
                'body'    => 'The properties in this pack, in viewing order. Drag them to change the route you will take, or press Remove to take one out.',
            ],
            [
                'element' => '[data-tour="smk-vps-docs"]',
                'section' => 'Documents and the viewing',
                'title'   => 'Buyer-pack documents',
                'body'    => 'Choose which documents for each property go to the buyer. Only documents that are allowed to be shared are listed — identity and compliance documents never appear. If a document needs private details hidden, use Redact to black them out first.',
            ],
            [
                'element' => '[data-tour="smk-vps-appointment"]',
                'title'   => 'Viewing appointment',
                'body'    => 'Once the pack has properties, Schedule Viewing opens the calendar already filled in with the buyer and these properties. If the pack is already linked to an appointment, you can update it instead.',
            ],
            [
                'element' => '[data-tour="smk-vps-buyer-pack"]',
                'section' => 'Handing it over',
                'title'   => 'Download Buyer Pack',
                'body'    => 'The file you give the buyer: the properties and the documents you chose. It appears once the pack has at least one property.',
            ],
            [
                'element' => '[data-tour="smk-vps-agent-sheet"]',
                'title'   => 'Download Agent Sheet',
                'body'    => 'A separate, confidential sheet for you only, with notes the buyer must never see. Never hand it to the buyer.',
            ],
        ],
    ],

    // ══ DEEDS CAPTURE ═══════════════════════════════════════════════════════

    'smk-dc-queue' => [
        'key'         => 'smk-dc-queue',
        'title'       => 'Reviewing deeds captures',
        'description' => 'Work through the properties captured from deeds lookups — check whether each is new or already yours, then add it or remove it.',
        'route'       => 'corex.deeds-capture.index',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'deeds-capture',
        'permission'  => 'deeds_capture.access',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-dc-banner"]',
                'section' => 'What this screen is',
                'title'   => 'Deeds Capture',
                'body'    => 'When an agent captures a property from a deeds lookup, it lands here to be checked before anything is added to your books. This is the waiting room: you decide whether each one is a new property, one you already have, or a mistake.',
            ],
            [
                'element' => '[data-tour="smk-dc-scope"]',
                'section' => 'Finding a capture',
                'do'      => ['action' => 'choose', 'say' => 'Pick Own, Branch or All — or press Skip this step.'],
                'title'   => 'Whose captures',
                'body'    => 'Choose whether you see only your own captures, your branch\'s or the whole agency\'s. You only get the choices you are allowed to see.',
            ],
            [
                'element' => '[data-tour="smk-dc-search"]',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an address or contact name — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Search',
                'body'    => 'Type part of an address or a contact\'s name and press Search to find one capture quickly.',
            ],
            [
                'element' => '[data-tour="smk-dc-agent"]',
                'title'   => 'Filter by agent',
                'body'    => 'Show only the captures made by one agent, or the ones with no agent on file.',
            ],
            [
                'element' => '[data-tour="smk-dc-counts"]',
                'section' => 'Working the queue',
                'title'   => 'What is waiting',
                'body'    => 'A quick count of the queue: new properties, ones that may be the same as a property you already have, ones that need a closer look, and standalone contact numbers.',
            ],
            [
                'element' => '[data-tour="smk-dc-queue"]',
                'do'      => ['action' => 'click', 'say' => 'Click a capture in the list.', 'target' => '.deeds-queue-row'],
                'title'   => 'The waiting list',
                'body'    => 'Each row is one captured property, with the owner and who captured it. The coloured tag says what CoreX thinks: new, possibly the same as something you already have, or in need of a look. Click a row to read it on the right.',
            ],
            [
                'element' => '[data-tour="smk-dc-inspector"]',
                'section' => 'Deciding what to do',
                'title'   => 'The capture in full',
                'body'    => 'This is everything captured for the selected property: the address, the owners and their contact numbers, and what changed compared with what you already knew. CoreX says in plain words whether it is new to you or looks like a property already on your books. Add as a new property creates it and links the owner; if it looks like an existing property, confirm it is the same one (or say it is different). Remove takes a wrong capture off the list — nothing is permanently deleted.',
            ],
        ],
    ],

    // ══ ADDRESS REVIEW ══════════════════════════════════════════════════════

    // ── The list of unreadable addresses ─────────────────────────────────────
    'smk-ar-list' => [
        'key'         => 'smk-ar-list',
        'title'       => 'Fixing addresses CoreX could not read',
        'description' => 'The list of addresses CoreX was not sure about — find them, open the record and fix or dismiss each one.',
        'route'       => 'corex.address-review.index',
        'permission'  => 'address_review.manage',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-ar-banner"]',
                'section' => 'What this list is',
                'title'   => 'Address Review',
                'body'    => 'CoreX reads every address it receives so it can match the same property across your listings and captured data. When an address is too unclear to read with confidence, it is parked here for a person to correct. Nothing on this list is ever deleted.',
            ],
            [
                'element' => '[data-tour="smk-ar-search"]',
                'section' => 'Finding an address',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a street, suburb, complex or erf number — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Search',
                'body'    => 'Type a street, number, suburb, complex name, erf number or record number, then press Apply.',
            ],
            [
                'element' => '[data-tour="smk-ar-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick which addresses to show — or press Skip this step.'],
                'title'   => 'Show',
                'body'    => 'Needs attention shows everything still waiting. You can also look at the ones that need a look, the ones with nothing readable, the ones a colleague has fixed, or the ones that were dismissed.',
            ],
            [
                'element' => '[data-tour="smk-ar-reason"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a reason — or press Skip this step.'],
                'title'   => 'Reason',
                'body'    => 'Narrow the list to one kind of problem, for example a missing street name or a suburb CoreX does not recognise.',
            ],
            [
                'element' => '[data-tour="smk-ar-kind"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a record type — or press Skip this step.'],
                'title'   => 'Record type',
                'body'    => 'Properties are the listings on your books. Captured properties are the ones brought in from lookups and captures.',
            ],
            [
                'element' => '[data-tour="smk-ar-dates"]',
                'title'   => 'Updated between',
                'body'    => 'Show only addresses that changed between two dates, handy for checking what came in this week.',
            ],
            [
                'element' => '[data-tour="smk-ar-table"]',
                'section' => 'Fixing an address',
                'title'   => 'The addresses',
                'body'    => 'Each row shows the address as CoreX holds it, its suburb, its status and why it was parked. Click a column heading to sort by it.',
            ],
            [
                'element' => '[data-tour="smk-ar-fix"]',
                'do'      => ['action' => 'click', 'say' => 'Click Fix on an address.'],
                'title'   => 'Fix',
                'body'    => 'Fix opens a small form where you correct the street, suburb and other details. Open shows the property or capture itself in a new tab, which helps when you need to see more.',
            ],
            [
                'element' => '[data-tour="smk-ar-dismiss"]',
                'title'   => 'Dismiss',
                'body'    => 'If an address is fine as it is and does not need fixing, Dismiss takes it off the list. It is not deleted — choose Dismissed in the Show box and use Restore to bring it back.',
            ],
        ],
    ],

    // ── Fix one address ──────────────────────────────────────────────────────
    'smk-ar-fix' => [
        'key'         => 'smk-ar-fix',
        'title'       => 'Correcting one address',
        'description' => 'See why CoreX could not read an address, correct the details and mark it as checked.',
        'route'       => 'corex.address-review.edit',
        'permission'  => 'address_review.manage',
        'pick_from'   => 'corex.address-review.index',
        'pick_note'   => 'Click Fix on the address you want to correct — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-ar-why"]',
                'section' => 'Understanding the problem',
                'title'   => 'Why it is on the list',
                'body'    => 'This box says why CoreX was unsure, and shows how it reads the address right now — the street, the unit and whether the suburb was recognised. Use Open the record to look at the property itself.',
            ],
            [
                'element' => '[data-tour="smk-ar-form"]',
                'section' => 'Correcting the details',
                'do'      => ['action' => 'fill', 'say' => 'Type the correct street number.', 'target' => 'input[name="street_number"]'],
                'title'   => 'Street number',
                'body'    => 'The number on the gate, for example 19, 12A or 1/3. Leave it empty if the property has none.',
            ],
            [
                'element' => '[data-tour="smk-ar-form"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the correct street name.', 'target' => 'input[name="street_name"]'],
                'title'   => 'Street name',
                'body'    => 'The street, with or without its type, for example Main Road or just Main.',
            ],
            [
                'element' => '[data-tour="smk-ar-form"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the suburb exactly as the property portal spells it — or press Skip this step.', 'target' => 'input[name="suburb"]'],
                'title'   => 'Suburb',
                'body'    => 'Spell the suburb exactly as the property portals do — that is how CoreX recognises it and matches the property to others in the same area.',
            ],
            [
                'element'       => '[data-tour="smk-ar-save"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save and mark as checked.'],
                'title'         => 'Save and mark as checked',
                'body'          => 'CoreX reads the corrected address again straight away and records that a person checked it, so it comes off the list.',
            ],
        ],
    ],

    // ══ MARKETING ═══════════════════════════════════════════════════════════

    // ── Market a property (social ad builder) ────────────────────────────────
    'smk-mk-hub' => [
        'key'         => 'smk-mk-hub',
        'title'       => 'Marketing a property on social media',
        'description' => 'Connect your page, write or generate the ad, choose the photos, preview it and publish — then watch how it performs.',
        'route'       => 'corex.properties.marketing.index',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'marketing',
        'permission'  => 'access_properties',
        'pick_from'   => 'corex.properties.index',
        'pick_note'   => 'Open the listing you want to market, then press Marketing on its page — the guide starts once the marketing page opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-mk-banner"]',
                'section' => 'Getting started',
                'title'   => 'Market This Property',
                'body'    => 'Build a social media ad for this one listing right here, publish it to your agency\'s page, and keep a record of every post and how it performed.',
            ],
            [
                'element' => '[data-tour="smk-mk-accounts"]',
                'title'   => 'Connect your page',
                'body'    => 'Posts go out on your agency\'s Facebook Page, so connect it once here. It must be a Facebook Page, not a personal profile. After that it shows as Connected and you can disconnect it at any time.',
            ],
            [
                'element' => '[data-tour="smk-mk-mode"]',
                'section' => 'Writing the ad',
                'do'      => ['action' => 'choose', 'say' => 'Choose to write your own ad or have Ellie generate one.'],
                'title'   => 'How do you want to write it?',
                'body'    => 'Write your own headline and copy from scratch, or let Ellie draft an ad from this property\'s details and then edit it. Once you have chosen, the headline and copy boxes open.',
            ],
            [
                'element'       => '[data-tour="smk-mk-headline"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type a short headline for the ad.'],
                'title'         => 'Headline',
                'body'          => 'A short line that stops the scroll — the price, the suburb or the best feature of the home.',
            ],
            [
                'element'       => '[data-tour="smk-mk-copy"]',
                'advanced_only' => true,
                'title'         => 'Ad copy',
                'body'          => 'The words that go with your post. Edit anything Ellie wrote — you are always the one who approves the final wording. If you used Ellie you can ask for a fresh version with Regenerate.',
            ],
            [
                'element' => '[data-tour="smk-mk-media"]',
                'section' => 'Choosing the picture',
                'title'   => 'Photos or a template',
                'body'    => 'Use the listing\'s own photos, or switch to Templates for a ready-made design. Open the photo list and tick the pictures you want in the post.',
            ],
            [
                'element' => '[data-tour="smk-mk-preview"]',
                'title'   => 'Live preview',
                'body'    => 'This is what the post will look like on Facebook, updated as you type. Check it before you publish.',
            ],
            [
                'element' => '[data-tour="smk-mk-publish"]',
                'section' => 'Publishing',
                'title'   => 'Publish',
                'body'    => 'Tick Facebook and press Publish Now. CoreX posts it to your connected page and shows straight away whether it worked.',
            ],
            [
                'element' => '[data-tour="smk-mk-history"]',
                'section' => 'Results',
                'title'   => 'Marketing history',
                'body'    => 'Every post made for this property, with its status and how many people it reached, liked and clicked. Failed posts show the reason so you can fix it and try again.',
            ],
        ],
    ],

    // ══ PUBLIC WEBSITE ══════════════════════════════════════════════════════
    // The agency manages its public website from Edit Agency → API Access (signed-in admin screen).
    // The API Access tab is hidden when the page opens, so the first step in the website section
    // `prep`s it open (a reveal only — the guide never types or saves for the agent).

    'smk-web-site' => [
        'key'         => 'smk-web-site',
        'title'       => 'Putting your agency website online',
        'description' => 'Switch your public website on or off, show your agents on it, and create the key a website uses to receive your listings.',
        'route'       => 'agencies.edit',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'public-website',
        'permission'  => 'manage_performance_settings',
        'pick_from'   => 'agencies.index',
        'pick_note'   => 'Open the agency you want to work on (Edit) — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="smk-web-banner"]',
                'section' => 'Where the website is managed',
                'title'   => 'Your agency\'s website',
                'body'    => 'Your public website shows your agents, branches and live listings straight from CoreX, so you only ever update them in one place. Everything that controls it lives on this page, under the API Access tab.',
            ],
            [
                'element'  => '[data-tour="smk-web-tabs"]',
                'skip_if'  => '[data-tour="smk-web-switch"]',
                'do'       => ['action' => 'click', 'say' => 'Click the API Access tab.', 'target' => '[data-tab-btn="api-access"]', 'until' => '[data-tour="smk-web-switch"]'],
                'title'    => 'The API Access tab',
                'body'     => 'The tabs split this page into Company, Branding, Branches, Syndication and API Access. The website controls are under API Access, which you only see if you are allowed to manage them.',
            ],
            [
                'element' => '[data-tour="smk-web-switch"]',
                'section' => 'Taking the website live',
                'prep'    => [['action' => 'alpineSet', 'selector' => '[data-tour="smk-web-tabs"]', 'prop' => 'activeTab', 'value' => 'api-access']],
                'title'   => 'The master switch',
                'body'    => 'This is the on/off switch for the whole website. While it is off, no website receives any data from CoreX, even if keys have been created. It shows LIVE or OFFLINE so you can see the state at a glance.',
            ],
            [
                'element' => '[data-tour="smk-web-toggle"]',
                'title'   => 'Make live or take offline',
                'body'    => 'Press Make website live when your branding, agents and listings are ready for the public. Take website offline switches the site off again without touching any of your internal data. Only people with permission to manage the website see this button.',
            ],
            [
                'element' => '[data-tour="smk-web-agents"]',
                'title'   => 'Show your agents',
                'body'    => 'Show all agents on website puts every active agent on the site in one go. You can then hide individual agents with the Show on website toggle on each agent\'s profile.',
            ],
            [
                'element' => '[data-tour="smk-web-keys"]',
                'section' => 'Connecting a website',
                'title'   => 'Websites and their keys',
                'body'    => 'Each website that receives your data has its own key, shown here with its status and when it was last used. Use Test connection to check a site is talking to CoreX, Regenerate secret if a key was leaked, or Revoke to cut a site off.',
            ],
            [
                'element' => '[data-tour="smk-web-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the name of the website — or press Skip this step.', 'target' => 'input[name="name"]'],
                'title'   => 'Name the website',
                'body'    => 'Give the new website a name your team will recognise. This name also appears as the website\'s publishing option on each property.',
            ],
            [
                'element' => '[data-tour="smk-web-scopes"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick what this website may receive — or press Skip this step.'],
                'title'   => 'What it may receive',
                'body'    => 'Tick only what the website needs, for example listings and agency details. A website can never read anything you have not ticked here.',
            ],
            [
                'element' => '[data-tour="smk-web-webhook"]',
                'do'      => ['action' => 'fill', 'say' => 'Paste the website\'s webhook address — or press Skip this step.', 'target' => 'input[name="webhook_url"]'],
                'title'   => 'Webhook address (optional)',
                'body'    => 'If your website should be told the moment something changes, paste the address it listens on. Leave it empty if the site simply checks for updates itself.',
            ],
            [
                'element'       => '[data-tour="smk-web-generate"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Generate API Key — or press Skip this step.'],
                'title'         => 'Generate the key',
                'body'          => 'CoreX creates the key and shows its secret once only. Copy it straight away and give it to whoever builds your website.',
            ],
            [
                'element' => '[data-tour="smk-web-setup"]',
                'section' => 'For your web developer',
                'title'   => 'Setup notes',
                'body'    => 'Open this panel to see the technical steps your web developer needs to connect a site. It is written for them, not for you — send them here or copy it across.',
            ],
        ],
    ],

];
