<?php

/**
 * Guided-tour definitions — REPORTS & TOOLS (AT-478 coverage pass, spec .ai/specs/advanced-guiding.md §12).
 *
 * Pure DATA merged by App\Support\Tours\TourRegistry::all() from every file in
 * app/Support/Tours/defs/*.php. Keys are namespaced `rpt-…`; every selector is a
 * dedicated data-tour="rpt-…" anchor on the report / tool views. One definition
 * drives all three modes: the click-through Guided Tour, the hands-on Advanced
 * Guide (the `do` steps) and Spot Help (the `section`s).
 *
 * Features covered: buyers-report, lead-response-report, performance-roi-report,
 * contact-governance, misfiled-documents, soft-deletes, title-deeds, knowledge-base,
 * guided-tours, image-converter, tv-display.
 *
 * Wording is neutral for any agency (multi-agency rule). The guide never types,
 * picks or saves for the agent.
 */

return [

    // ── Buyers Report ────────────────────────────────────────────────────────
    'rpt-buyers' => [
        'key'         => 'rpt-buyers',
        'title'       => 'Reading the Buyers Report',
        'description' => 'See what happened to your buyers in a period, who needs attention, and what buyers want — then click any figure to see the buyers behind it.',
        'route'       => 'buyers-report.index',
        'permission'  => 'view_buyers_report',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-br-intro"]',
                'section' => 'Reading the report',
                'title'   => 'Your Buyers Report',
                'body'    => 'This report shows how your buyers are moving: who was added, who was won, who was lost and who has gone quiet. The line under the title tells you whose buyers you are looking at (your own, your branch or the whole agency) and for which period.',
            ],
            [
                'element' => '[data-tour="rpt-br-type"]',
                'section' => 'Choosing what to see',
                'do'      => ['action' => 'choose', 'say' => 'Pick a type to narrow the report — or press Skip this step.', 'target' => 'select'],
                'title'   => 'Filter by type',
                'body'    => 'Show every buyer, or only one type of contact such as buyers or leads. The page reloads with the figures for that type only.',
            ],
            [
                'element' => '[data-tour="rpt-period"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a period — or press Skip this step.', 'target' => 'select[name="period"]'],
                'title'   => 'Choose the period',
                'body'    => 'This month, last month, this quarter, a custom range and more. Every figure on the page follows this choice. "Compare to" shows how the period stacks up against an earlier one.',
            ],
            [
                'element' => '[data-tour="rpt-br-export"]',
                'title'   => 'Print or download',
                'body'    => 'Print opens a clean print view; Download PDF saves it as a file. Both use exactly the filters you have on screen right now, so what you print is what you see.',
            ],
            [
                'element' => '[data-tour="rpt-br-tiles"]',
                'section' => 'The figures',
                'do'      => ['action' => 'click', 'say' => 'Click a figure to see the buyers behind it.', 'target' => 'button', 'until' => '[data-tour="rpt-drill"]'],
                'title'   => 'What happened to buyers',
                'body'    => 'Buyers held, added, won, appointments, emails, WhatsApps and losses for the period. "Real" losses are buyers an agent marked as lost; "Auto" losses are buyers who went quiet. Every figure is clickable and opens the list of buyers behind it.',
            ],
            [
                'element'       => '[data-tour="rpt-drill"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Look through the list, then click the cross to close it.', 'target' => 'button[aria-label="Close"]'],
                'title'         => 'The buyers behind the number',
                'body'          => 'This list always matches the figure you clicked. Close it when you are done.',
            ],
            [
                'element' => '[data-tour="rpt-br-attention"]',
                'section' => 'Who needs attention',
                'title'   => 'Needs Attention',
                'body'    => 'Four short lists to work through: cold and lost buyers (longest-stuck first), buyers parked on purpose, buyers who viewed a property but have no feedback captured, and recent losses with the reason and the latest note. Each list shows the top ten; use "View all" for the rest.',
            ],
            [
                'element' => '[data-tour="rpt-br-agents"]',
                'section' => 'By agent',
                'title'   => 'The agent table',
                'body'    => 'One row per agent with their buyers held, added, won, appointments, losses and value lost. Click an agent or a branch name to open their own report; click a number to see the buyers behind it.',
            ],
            [
                'element' => '[data-tour="rpt-br-pipeline"]',
                'section' => 'Buyers right now',
                'title'   => 'What buyers do we have now',
                'body'    => 'A snapshot of today, not of the period: how many buyers sit in each stage of the Buyer Pipeline. These counts match the pipeline board exactly.',
            ],
            [
                'element' => '[data-tour="rpt-br-demand"]',
                'section' => 'Demand analysis',
                'title'   => 'What would we sell them?',
                'body'    => 'A tool for finding stock: tick the property types and set a price range to see how many of your current buyers would consider something there. Useful before you go looking for new mandates.',
            ],
            [
                'element' => '[data-tour="rpt-br-demand-types"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick a property type — or press Skip this step.'],
                'title'   => 'Pick property types',
                'body'    => 'Tick one or more types. The buyer count below updates straight away.',
            ],
            [
                'element' => '[data-tour="rpt-br-demand-price"]',
                'do'      => ['action' => 'choose', 'say' => 'Drag a price slider — or press Skip this step.'],
                'title'   => 'Set the price range',
                'body'    => 'Drag the two sliders to the lowest and highest price. A buyer counts if their budget overlaps your range at all, not only if it sits fully inside it.',
            ],
        ],
    ],

    // ── Lead Response Report ─────────────────────────────────────────────────
    'rpt-lead-response' => [
        'key'         => 'rpt-lead-response',
        'title'       => 'Reading the Lead Response report',
        'description' => 'See how quickly new enquiries get a first reply, by agent and by source, and click any figure to see the leads behind it.',
        'route'       => 'lead-response-report.index',
        'permission'  => 'view_buyers_report',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'lead-response-report',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-lr-intro"]',
                'section' => 'Reading the report',
                'title'   => 'Your Lead Response report',
                'body'    => 'How fast do new enquiries from the portals, your website and shared links get a first reply? The line under the title shows whose leads you are looking at and for which period.',
            ],
            [
                'element' => '[data-tour="rpt-period"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a period — or press Skip this step.', 'target' => 'select[name="period"]'],
                'title'   => 'Choose the period',
                'body'    => 'This month, last month, a custom range and more. Every figure follows this choice. "Compare to" sets the period against an earlier one — for response time, lower is better.',
            ],
            [
                'element' => '[data-tour="rpt-lr-export"]',
                'title'   => 'Print or download',
                'body'    => 'Print or save as PDF exactly what is on screen, with the same period and filters.',
            ],
            [
                'element' => '[data-tour="rpt-lr-tiles"]',
                'section' => 'The figures',
                'do'      => ['action' => 'click', 'say' => 'Click a figure to see the leads behind it.', 'target' => 'button', 'until' => '[data-tour="rpt-drill"]'],
                'title'   => 'How fast you replied',
                'body'    => 'Leads received, how many were answered inside your target time, how many late, how many still waiting, and the average and median time to a first reply. Your target time is set by your agency. Every figure opens the list of leads behind it.',
            ],
            [
                'element'       => '[data-tour="rpt-drill"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Look through the leads, then click the cross to close.', 'target' => 'button[aria-label="Close"]'],
                'title'         => 'The leads behind the number',
                'body'          => 'This list always matches the figure you clicked.',
            ],
            [
                'element' => '[data-tour="rpt-lr-agents"]',
                'section' => 'By agent',
                'title'   => 'Response by agent',
                'body'    => 'One row per agent: how many leads they received and how many they answered in time, late or not yet. Use it to see who needs help keeping up with enquiries.',
            ],
            [
                'element' => '[data-tour="rpt-lr-sources"]',
                'section' => 'By source',
                'title'   => 'Response by source',
                'body'    => 'The same figures grouped by where the enquiry came from — a portal, your website or a shared link — so you can see which source is being answered slowest.',
            ],
        ],
    ],

    // ── Performance & ROI Report ─────────────────────────────────────────────
    'rpt-performance' => [
        'key'         => 'rpt-performance',
        'title'       => 'Reading the Performance & ROI report',
        'description' => 'See what the agency earned, how each branch and agent contributed, and click any figure to see the deals behind it.',
        'route'       => 'performance.agency-report',
        'permission'  => 'view_performance',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'performance-roi-report',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-perf-intro"]',
                'section' => 'Reading the report',
                'title'   => 'Agency Performance & ROI',
                'body'    => 'The return the agency is getting: commission earned, activity and results, rolled up by company, branch and agent. The line under the title shows the period and how much of the agency you are allowed to see.',
            ],
            [
                'element' => '[data-tour="rpt-period"]',
                'section' => 'Period and printing',
                'do'      => ['action' => 'choose', 'say' => 'Pick a period — or press Skip this step.', 'target' => 'select[name="period"]'],
                'title'   => 'Choose the period',
                'body'    => 'This month, last month, a quarter, a year or a custom range. Everything below follows it. "Compare to" shows the change against an earlier period, with arrows and percentages.',
            ],
            [
                'element' => '[data-tour="rpt-perf-reset"]',
                'title'   => 'Reset view',
                'body'    => 'Puts the period, sorting, filters and deal-status ticks back to their defaults. Use it when the numbers look different from what you expect.',
            ],
            [
                'element' => '[data-tour="rpt-perf-print"]',
                'title'   => 'Print the report',
                'body'    => 'Opens a print-ready version of the whole-company report for the period you have chosen.',
            ],
            [
                'element' => '[data-tour="rpt-perf-hero"]',
                'section' => 'The headline',
                'title'   => 'The answer first',
                'body'    => 'One sentence with the commission the agency earned in the period, and whether that is up or down on the comparison. The detail below explains how it got there.',
            ],
            [
                'element' => '[data-tour="rpt-perf-company"]',
                'section' => 'Company totals',
                'do'      => ['action' => 'click', 'say' => 'Click a figure to see what is behind it.', 'target' => 'button'],
                'title'   => 'Company totals',
                'body'    => 'Each box is one measure for the whole agency. Click a box to open the list of items that make up the number. Commission shows the gross amount and how it splits between the agent and the company.',
            ],
            [
                'element' => '[data-tour="rpt-perf-branch"]',
                'section' => 'By branch',
                'title'   => 'Branches side by side',
                'body'    => 'The chart compares commission per branch against the comparison period. The branch table below it has the full set of figures; click a column heading to sort it.',
            ],
            [
                'element' => '[data-tour="rpt-perf-agent-search"]',
                'section' => 'By agent',
                'do'      => ['action' => 'fill', 'say' => 'Type part of an agent\'s name — or press Skip this step.'],
                'title'   => 'Find an agent',
                'body'    => 'Type a name to narrow the agent table. You can also filter by branch or by a minimum amount of activity. "Clear" brings everyone back.',
            ],
            [
                'element' => '[data-tour="rpt-perf-agents"]',
                'title'   => 'The agent table',
                'body'    => 'One row per agent. Click a column heading to sort, click a number to see what is behind it, and click an agent\'s name to open their own report.',
            ],
        ],
    ],

    // ── Contact Governance ───────────────────────────────────────────────────
    'rpt-contact-governance' => [
        'key'         => 'rpt-contact-governance',
        'title'       => 'Setting your contact rules',
        'description' => 'Decide the default buyer view, how duplicate contacts are handled, when buyers go cold, and how long records are kept.',
        'route'       => 'command-center.settings.contact-governance',
        'permission'  => 'command_center.settings',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'contact-governance',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-cg-intro"]',
                'section' => 'What this page does',
                'title'   => 'Contact Governance',
                'body'    => 'These are the agency-wide rules for contacts: who sees buyers by default, what happens when someone captures a contact you already have, when a quiet buyer is treated as cold or lost, and how long records are kept. They apply to everyone in your agency.',
            ],
            [
                'element' => '[data-tour="rpt-cg-pipeline"]',
                'section' => 'Buyer pipeline view',
                'do'      => ['action' => 'choose', 'say' => 'Pick what agents see first on the Buyer Pipeline.'],
                'title'   => 'Default Buyer Pipeline view',
                'body'    => 'Choose whether agents open the Buyer Pipeline on their own buyers, their branch\'s buyers or the whole agency\'s buyers. Agents can still switch views if their role allows it. Owners and admins always see everything.',
            ],
            [
                'element' => '[data-tour="rpt-cg-duplicates"]',
                'section' => 'Duplicate contacts',
                'do'      => ['action' => 'choose', 'say' => 'Pick how a duplicate contact should be handled.', 'target' => 'input[name="duplicate_mode"]'],
                'title'   => 'When a contact already exists',
                'body'    => 'Auto-Link quietly uses the existing contact. Soft Warn shows the agent a "duplicate found" message and lets them decide. The two Hard Block options stop the agent from creating it — either an admin can override with a reason, or the agent can ask the owner for access.',
            ],
            [
                'element' => '[data-tour="rpt-cg-match"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick or untick what counts as a match.'],
                'title'   => 'What counts as a duplicate',
                'body'    => 'Two contacts are treated as the same person when they share any ticked field. Phone and email are the usual choices; add ID number if your team captures it reliably.',
            ],
            [
                'element' => '[data-tour="rpt-cg-address"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how strictly to warn about a property already on file — or press Skip this step.'],
                'title'   => 'Property address guard',
                'body'    => 'When an agent creates a property from a contact\'s address, this decides how readily CoreX warns that the property may already exist. Standard is recommended. The two ticks below it also warn about addresses you already hold and turn portal enquiries into buyers automatically.',
            ],
            [
                'element' => '[data-tour="rpt-cg-freshness"]',
                'section' => 'When buyers go quiet',
                'do'      => ['action' => 'fill', 'say' => 'Change the number of days — or press Skip this step.', 'target' => 'input[name="buyer_warm_days"]'],
                'title'   => 'Buyer freshness windows',
                'body'    => 'How many days without any activity before a warm buyer becomes cold, and a cold buyer becomes lost. These drive the Buyer Pipeline columns and the Buyers Report. Shorter windows chase buyers sooner.',
            ],
            [
                'element' => '[data-tour="rpt-cg-outreach"]',
                'section' => 'Seller outreach',
                'title'   => 'Waiting for a reply',
                'body'    => 'After a consent request is sent, the contact cannot be messaged again until they reply or this many days pass with no response. It stops the same person being chased twice.',
            ],
            [
                'element' => '[data-tour="rpt-cg-retention"]',
                'section' => 'How long records are kept',
                'title'   => 'Retention policy',
                'body'    => 'How many years contact records, consent records and the access log are kept before they are purged. The law (POPIA, FICA and the Property Practitioners Act) requires at least five years, so the boxes will not go lower.',
            ],
            [
                'element'       => '[data-tour="rpt-cg-save"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Save Settings when you are happy with your choices.', 'target' => 'button'],
                'title'         => 'Save your rules',
                'body'          => 'Nothing changes until you save. The new rules apply straight away.',
            ],
        ],
    ],

    // ── Misfiled Documents ───────────────────────────────────────────────────
    'rpt-misfiled' => [
        'key'         => 'rpt-misfiled',
        'title'       => 'Refiling a misfiled document',
        'description' => 'Find ID, FICA and proof-of-residence documents filed to a property or to no one, and move each to the right person.',
        'route'       => 'admin.misfiled-documents.index',
        'permission'  => 'access_misfiled_documents',
        // Counted for this feature in the rollout tour report (FeatureTourCoverage); a label only, never a gate.
        'feature'     => 'misfiled-documents',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-mf-intro"]',
                'section' => 'What this page is for',
                'title'   => 'Misfiled Documents',
                'body'    => 'Some documents belong to a person — an ID, a FICA form, a proof of residence — but were saved against a property, or against nobody. This page lists them so each can be moved to the right contact. Nothing is ever deleted.',
            ],
            [
                'element' => '[data-tour="rpt-mf-kpi"]',
                'title'   => 'How many are left',
                'body'    => 'The first number is how many documents still need refiling; the others split them by document type. When this block is gone, there is nothing left to fix.',
            ],
            [
                'element' => '[data-tour="rpt-mf-table"]',
                'section' => 'Reading the list',
                'title'   => 'One document per row',
                'body'    => 'Each row shows the document, its type, where it is filed now and who uploaded it. A property link with no contact is the usual cause — the document was saved to the property instead of the owner or tenant.',
            ],
            [
                'element' => '[data-tour="rpt-mf-doc"]',
                'do'      => ['action' => 'click', 'say' => 'Click the document name to open it and check whose it is.'],
                'title'   => 'Open the document',
                'body'    => 'Open the file to see who it belongs to before you refile it. It opens in a new tab.',
            ],
            [
                'element' => '[data-tour="rpt-mf-refile"]',
                'section' => 'Refiling',
                'do'      => ['action' => 'choose', 'say' => 'Pick the contact the document belongs to.', 'target' => 'select'],
                'title'   => 'Pick the right person',
                'body'    => 'The list shows the contacts already linked to that property. Pick the person the document really belongs to (hold Ctrl to pick more than one). If nobody suitable is listed, add the contact to the property first.',
            ],
            [
                'element'       => '[data-tour="rpt-mf-refile"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Refile to move the document.', 'target' => 'button[type="submit"]'],
                'title'         => 'Refile it',
                'body'          => 'The document moves onto the contact and the wrong property link is removed, following the document type\'s saving rule. It then leaves this list.',
            ],
        ],
    ],

    // ── Soft Deletes: the categories ─────────────────────────────────────────
    'rpt-soft-deletes' => [
        'key'         => 'rpt-soft-deletes',
        'title'       => 'Finding an archived record',
        'description' => 'Nothing in CoreX is truly deleted. Find archived records here, by type, so they can be restored.',
        'route'       => 'admin.soft-deletes.index',
        'permission'  => 'access_soft_deletes',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-sd-intro"]',
                'section' => 'What this page is for',
                'title'   => 'Soft Deletes',
                'body'    => 'When someone deletes a contact, property, deal, document or anything else in CoreX, it is archived, not destroyed. This page is where an admin finds it again and brings it back.',
            ],
            [
                'element' => '[data-tour="rpt-sd-count"]',
                'title'   => 'How many are archived',
                'body'    => 'The total number of archived records you are allowed to restore.',
            ],
            [
                'element' => '[data-tour="rpt-sd-group"]',
                'section' => 'Finding the right type',
                'title'   => 'Grouped by area',
                'body'    => 'Archived records are grouped by area of CoreX, with a count beside each group. Inside a group, every card is one type of record and shows how many of that type are archived.',
            ],
            [
                'element' => '[data-tour="rpt-sd-intro"]',
                'title'   => 'Restoring is for admins',
                'body'    => 'Only people with access to this page can bring records back. A restored record comes back as it was.',
            ],
            [
                'element' => '[data-tour="rpt-sd-model"]',
                'do'      => ['action' => 'click', 'say' => 'Click a type to see its archived records.'],
                'title'   => 'Open a type',
                'body'    => 'Click a card to see every archived record of that type. There you choose which one to restore.',
            ],
        ],
    ],

    // ── Soft Deletes: one type's archived records ────────────────────────────
    'rpt-soft-deletes-list' => [
        'key'         => 'rpt-soft-deletes-list',
        'title'       => 'Restoring an archived record',
        'description' => 'See the archived records of one type, check when each was archived, and restore the one you need.',
        'route'       => 'admin.soft-deletes.show',
        'permission'  => 'access_soft_deletes',
        'pick_from'   => 'admin.soft-deletes.index',
        'pick_note'   => 'Open the type of record you want to restore — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-sdl-intro"]',
                'section' => 'The archived list',
                'title'   => 'Archived records',
                'body'    => 'Every archived record of this type is listed here. The arrow at the top takes you back to all the types.',
            ],
            [
                'element' => '[data-tour="rpt-sdl-table"]',
                'title'   => 'Which one is it?',
                'body'    => 'Each row shows the record\'s name, its number and the date and time it was archived. Use the archived date to tell two similar records apart.',
            ],
            [
                'element' => '[data-tour="rpt-sdl-table"]',
                'title'   => 'Long lists',
                'body'    => 'The newest archived records come first. If there are many, the list continues on the following pages at the bottom of the box.',
            ],
            [
                'element' => '[data-tour="rpt-sdl-restore"]',
                'section' => 'Restoring',
                'do'      => ['action' => 'click', 'say' => 'Click Restore on the record you want back.', 'until' => '[data-tour="rpt-sdl-confirm"]'],
                'title'   => 'Restore',
                'body'    => 'Restore asks you to confirm first. Some records can be locked for a while — an agent who has just left cannot be reinstated until their hold ends, and the row will say so.',
            ],
            [
                'element'       => '[data-tour="rpt-sdl-confirm"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Restore to bring it back — or Cancel to leave it archived.', 'target' => 'button'],
                'title'         => 'Confirm',
                'body'          => 'Restoring puts the record back exactly where it was. If you are not sure, press Cancel; nothing changes.',
            ],
        ],
    ],

    // ── Title Deeds: the register ────────────────────────────────────────────
    'rpt-title-deeds' => [
        'key'         => 'rpt-title-deeds',
        'title'       => 'Tracking your title deeds',
        'description' => 'The register of title deeds held for owners — what is waiting, what has been collected, and who has been told.',
        'route'       => 'title-deeds.index',
        'permission'  => 'title_deeds.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-td-intro"]',
                'section' => 'What this page is for',
                'title'   => 'Title Deeds',
                'body'    => 'When an attorney sends the agency a title deed for an owner, it is logged here. The register shows who was told, where the deed is kept, and exactly when and by whom it was collected — so nothing sits in a drawer unnoticed.',
            ],
            [
                'element' => '[data-tour="rpt-td-new"]',
                'title'   => 'Log a deed',
                'body'    => 'Click this the day a deed arrives. You choose the property and the owners, say where it is kept, and CoreX can tell the owners it is ready.',
            ],
            [
                'element' => '[data-tour="rpt-td-export"]',
                'title'   => 'Download as a spreadsheet',
                'body'    => 'Saves the list you are looking at as a CSV file, with the same search and filters applied.',
            ],
            [
                'element' => '[data-tour="rpt-td-tiles"]',
                'section' => 'The overview',
                'do'      => ['action' => 'click', 'say' => 'Click a box to show just those deeds.', 'target' => 'a'],
                'title'   => 'Where your deeds stand',
                'body'    => 'Counts of deeds where the owner has not been told yet, deeds waiting for collection, deeds collected, and archived ones. Click a box to jump straight to that list.',
            ],
            [
                'element' => '[data-tour="rpt-td-search"]',
                'section' => 'Finding a deed',
                'do'      => ['action' => 'fill', 'say' => 'Type a reference, street, owner, attorney or collector — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Search',
                'body'    => 'Type part of a reference, a property address, an owner, an attorney firm or the name of whoever collected it.',
            ],
            [
                'element' => '[data-tour="rpt-td-from"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a Received-from date — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Date received',
                'body'    => 'Narrow the list to deeds that arrived between two dates. Leave both empty to see everything.',
            ],
            [
                'element' => '[data-tour="rpt-td-apply"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Apply the filters — or press Skip this step.'],
                'title'   => 'Apply',
                'body'    => 'Applies your search and dates. Use "Clear the filters" beside it to start again.',
            ],
            [
                'element' => '[data-tour="rpt-td-ticks"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick or untick a kind of deed to show or hide it.'],
                'title'   => 'Choose what to show',
                'body'    => 'Tick the kinds of deed you want on screen: owner not yet told, waiting for collection, collected, or archived. Each ticked kind gets its own list below.',
            ],
            [
                'element' => '[data-tour="rpt-td-section"]',
                'section' => 'Working a deed',
                'title'   => 'One list per kind',
                'body'    => 'The heading shows how many deeds are in the list. Click the heading to open or close it, and click a column title to sort. "Days waiting" tells you how long a deed has been sitting at the agency.',
            ],
            [
                'element' => '[data-tour="rpt-td-row"]',
                'do'      => ['action' => 'click', 'say' => 'Click a deed to open it.'],
                'title'   => 'Open a deed',
                'body'    => 'The button at the end of each row is the next step for that deed — notify the owners, or hand it over. Click anywhere else on the row to open the full record.',
            ],
        ],
    ],

    // ── Title Deeds: log / edit a deed ───────────────────────────────────────
    'rpt-title-deed-form' => [
        'key'         => 'rpt-title-deed-form',
        'title'       => 'Logging a title deed',
        'description' => 'Record a title deed the day it arrives — the property, its owners, where it is kept and an optional scan.',
        'route'       => 'title-deeds.create',
        'also_on'     => ['title-deeds.edit'],
        'permission'  => 'title_deeds.create',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-tdf-intro"]',
                'section' => 'The basics',
                'title'   => 'One deed, one form',
                'body'    => 'Fields marked required must be filled in before the deed can be saved. Once the deed is logged, the owners can be told it has arrived.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-deal"]',
                'do'      => ['action' => 'fill', 'say' => 'Search for a deal — or press Skip this step.', 'target' => '#td-deal-q'],
                'title'   => 'Link a deal (optional)',
                'body'    => 'If the deed belongs to a registered sale, find the deal here and CoreX fills in the property, owners, agents and attorney for you.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-property"]',
                'section' => 'Property and owners',
                'do'      => ['action' => 'fill', 'say' => 'Start typing the property address.', 'target' => '#td-property-q'],
                'title'   => 'Property (required)',
                'body'    => 'Start typing a street name or number and pick the property from the list. If a deed for the same property is already logged and not yet collected, you will be warned.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-owners"]',
                'do'      => ['action' => 'fill', 'say' => 'Search your contacts for an owner.', 'target' => '#td-owner-q'],
                'title'   => 'Owners (required)',
                'body'    => 'Add every owner who must be told. Search your contacts by name and pick each one. These are the people who will be notified and who can collect the deed.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-agent"]',
                'section' => 'Who and where',
                'do'      => ['action' => 'choose', 'say' => 'Check the responsible agent — or press Skip this step.', 'target' => 'select'],
                'title'   => 'Responsible agent',
                'body'    => 'Filled in from the deal or property. Change it if someone else looks after this deed — they are the one reminded about it.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-received"]',
                'title'   => 'Date received (required)',
                'body'    => 'Today\'s date is filled in. Change it if the deed actually arrived earlier — the "days waiting" count starts from here.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-attorney"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the attorney firm — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Attorney firm (optional)',
                'body'    => 'The firm that sent the deed, so anyone can phone them with a question.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-location"]',
                'do'      => ['action' => 'choose', 'say' => 'Choose where the deed is kept.', 'target' => 'select'],
                'title'   => 'Where it is kept (required)',
                'body'    => 'Pick the safe, cabinet or office from your agency\'s own list. If the list is empty, an admin has to add storage locations first.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-notes"]',
                'section' => 'Notes and scan',
                'do'      => ['action' => 'fill', 'say' => 'Add a note — or press Skip this step.', 'target' => 'textarea'],
                'title'   => 'Notes (optional)',
                'body'    => 'Anything the next person should know — for example that the deed arrived with a covering letter.',
            ],
            [
                'element' => '[data-tour="rpt-tdf-scan"]',
                'title'   => 'Scan (optional)',
                'body'    => 'Attach a scan or photo of the deed (PDF or image). You can also add or replace it later from the deed\'s page.',
            ],
            [
                'element'       => '[data-tour="rpt-tdf-save"]',
                'section'       => 'Saving',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Log the deed when everything is filled in.', 'target' => 'button'],
                'title'         => 'Save',
                'body'          => 'The deed appears in the register. Then open it to tell the owners it has arrived.',
            ],
        ],
    ],

    // ── Title Deeds: one deed ────────────────────────────────────────────────
    'rpt-title-deed-record' => [
        'key'         => 'rpt-title-deed-record',
        'title'       => 'Working with one title deed',
        'description' => 'Tell the owners a deed has arrived, hand it over, add a scan, or edit the details.',
        'route'       => 'title-deeds.show',
        'permission'  => 'title_deeds.view',
        'pick_from'   => 'title-deeds.index',
        'pick_note'   => 'Open the title deed you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-tds-head"]',
                'section' => 'The deed at a glance',
                'title'   => 'Where this deed stands',
                'body'    => 'The property, the reference number, the current status and how many days it has been waiting. The arrow takes you back to the register.',
            ],
            [
                'element' => '[data-tour="rpt-tds-actions"]',
                'section' => 'What you can do',
                'title'   => 'The next step',
                'body'    => 'The highlighted button is the next step for this deed: "Notify the owners" when it has just arrived, then "Hand over" when someone comes to collect. Edit the details, send or reissue the PIN, or archive the deed from here too. Which buttons you see depends on your permissions.',
            ],
            [
                'element' => '[data-tour="rpt-tds-actions"]',
                'do'      => ['action' => 'click', 'say' => 'Click Edit the details to correct something — or press Skip this step.', 'target' => 'a[href$="/edit"]'],
                'title'   => 'Correcting a detail',
                'body'    => 'Edit the details lets you change the property, owners, attorney, storage place or notes. Once a deed is collected only the notes can be changed.',
            ],
            [
                'element' => '[data-tour="rpt-tds-actions"]',
                'title'   => 'Handing the deed over',
                'body'    => 'When an owner or their representative comes to collect, use "Hand over". You record who collected it and how they were identified, and the deed is then marked as collected.',
            ],
            [
                'element' => '[data-tour="rpt-tds-details"]',
                'section' => 'The details',
                'title'   => 'Owners and details',
                'body'    => 'Who the owners are with their contact details, the responsible agent, the attorney, where the deed is kept and when the owners were first notified.',
            ],
            [
                'element' => '[data-tour="rpt-tds-scan"]',
                'section' => 'The scan',
                'do'      => ['action' => 'click', 'say' => 'Click Choose file to attach a scan — or press Skip this step.', 'target' => 'input[type="file"]'],
                'title'   => 'Scan of the deed',
                'body'    => 'Open or download the scan, or upload one. Uploading a new scan keeps the old one under "Earlier scans". Once the deed is collected the scan can no longer be changed.',
            ],
        ],
    ],

    // ── Knowledge Base ───────────────────────────────────────────────────────
    'rpt-knowledge-base' => [
        'key'         => 'rpt-knowledge-base',
        'title'       => 'Adding to the Knowledge Base',
        'description' => 'Upload your agency\'s documents into categories so Ellie and your agents can find the answer.',
        'route'       => 'admin.knowledge.index',
        'permission'  => 'access_knowledge_base',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-kb-intro"]',
                'section' => 'What this page is for',
                'title'   => 'The Knowledge Base',
                'body'    => 'Your agency\'s own reference material — procedures, policies, forms, guides — kept in one place. Documents are sorted into categories, and each one can be made available to Ellie so she can answer questions from them.',
            ],
            [
                'element' => '[data-tour="rpt-kb-new"]',
                'section' => 'Setting up categories',
                'do'      => ['action' => 'click', 'say' => 'Click New Category to see the form.', 'until' => '[data-tour="rpt-kb-modal"]'],
                'title'   => 'Make a category first',
                'body'    => 'Categories are the folders: for example Sales procedures or Compliance. Every document has to sit in one, so create the categories you need before uploading.',
            ],
            [
                'element'       => '[data-tour="rpt-kb-modal"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type a category name — then click Create Category, or press Escape to close without saving.', 'target' => 'input[name="name"]'],
                'title'         => 'Name the category',
                'body'          => 'Give it a short name your team will recognise. A description and an icon are optional. Nothing is saved until you click Create Category.',
            ],
            [
                'element' => '[data-tour="rpt-kb-upload"]',
                'section' => 'Uploading a document',
                'title'   => 'Upload a document',
                'body'    => 'Add one document at a time. PDF, Word, text and Markdown files are accepted, up to 20 MB each. CoreX reads the text so it can be searched.',
            ],
            [
                'element' => '[data-tour="rpt-kb-title"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a title for the document.'],
                'title'   => 'Title (required)',
                'body'    => 'The name people will see. Make it specific, such as "Rental deposit procedure".',
            ],
            [
                'element' => '[data-tour="rpt-kb-category"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the category.'],
                'title'   => 'Category (required)',
                'body'    => 'Which folder the document belongs in. If the right one is not listed, create it first.',
            ],
            [
                'element' => '[data-tour="rpt-kb-file"]',
                'do'      => ['action' => 'click', 'say' => 'Click Choose File and pick the document.'],
                'title'   => 'File (required)',
                'body'    => 'Pick the PDF, Word, text or Markdown file from your computer.',
            ],
            [
                'element'       => '[data-tour="rpt-kb-upload-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Upload Document when the form is complete.'],
                'title'         => 'Upload',
                'body'          => 'The document is added and read in the background. Its status shows as processing, then ready.',
            ],
            [
                'element' => '[data-tour="rpt-kb-stats"]',
                'section' => 'How much is in there',
                'title'   => 'The totals',
                'body'    => 'How many documents you have and how many are ready, how many searchable text segments they were split into, and how many Ellie is allowed to search. If a document shows an error, open its category and reprocess it.',
            ],
            [
                'element' => '[data-tour="rpt-kb-categories"]',
                'section' => 'Your categories',
                'do'      => ['action' => 'click', 'say' => 'Click a category to open its documents.', 'target' => 'a'],
                'title'   => 'Categories',
                'body'    => 'Each card is a category with its document count. Open one to see and manage its documents. The arrows reorder the categories, the pencil edits one, and a category can only be deleted once it is empty.',
            ],
        ],
    ],

    // ── Knowledge Base: one category's documents ─────────────────────────────
    'rpt-knowledge-category' => [
        'key'         => 'rpt-knowledge-category',
        'title'       => 'Managing a category\'s documents',
        'description' => 'Upload to a category, check each document\'s status, and choose which ones Ellie can use.',
        'route'       => 'admin.knowledge.category',
        'permission'  => 'access_knowledge_base',
        'pick_from'   => 'admin.knowledge.index',
        'pick_note'   => 'Open the category you want to work on — the guide starts once it opens.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-kbc-intro"]',
                'section' => 'The category',
                'title'   => 'One category',
                'body'    => 'The name of the category and how many documents it holds. "Back" returns you to all the categories.',
            ],
            [
                'element' => '[data-tour="rpt-kbc-upload"]',
                'section' => 'Adding a document',
                'title'   => 'Upload here',
                'body'    => 'Same upload form as the main page, with this category already chosen. Fill in the title and pick the file.',
            ],
            [
                'element' => '[data-tour="rpt-kbc-table"]',
                'section' => 'The documents',
                'title'   => 'Your documents',
                'body'    => 'Each row shows the document\'s type, size, status, how many searchable segments it produced, who uploaded it and when. Status moves from processing to ready; an error means the text could not be read.',
            ],
            [
                'element' => '[data-tour="rpt-kbc-ellie"]',
                'do'      => ['action' => 'click', 'say' => 'Click the Ellie switch to allow or stop Ellie using that document — or press Skip this step.', 'target' => 'button'],
                'title'   => 'Let Ellie use it',
                'body'    => 'When on, Ellie can search this document to answer questions. Turn it off for anything that should stay for people only.',
            ],
            [
                'element' => '[data-tour="rpt-kbc-active"]',
                'do'      => ['action' => 'click', 'say' => 'Click the Active switch to hide or show it — or press Skip this step.', 'target' => 'button'],
                'title'   => 'Active or not',
                'body'    => 'An inactive document is kept but no longer used. Use it for out-of-date procedures you do not want to lose.',
            ],
            [
                'element' => '[data-tour="rpt-kbc-preview"]',
                'do'      => ['action' => 'click', 'say' => 'Click Preview to see how CoreX read the document.'],
                'title'   => 'Preview, reprocess, delete',
                'body'    => 'Preview shows the text CoreX extracted. Reprocess reads the file again if something went wrong. Delete asks you to confirm, then takes the document out of the Knowledge Base.',
            ],
        ],
    ],

    // ── Image Converter ──────────────────────────────────────────────────────
    'rpt-image-converter' => [
        'key'         => 'rpt-image-converter',
        'title'       => 'Converting photos to another format',
        'description' => 'Turn iPhone HEIC photos and other images into PNG, JPG or WEBP — one file or a batch.',
        'route'       => 'tools.image_converter.index',
        'permission'  => 'access_image_converter',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-ic-intro"]',
                'section' => 'What this tool does',
                'title'   => 'Image Converter',
                'body'    => 'Changes the file type of a photo so it works where you need it — for example an iPhone HEIC photo that a portal or email will not accept. Nothing is stored in CoreX; you get the converted files straight back.',
            ],
            [
                'element' => '[data-tour="rpt-ic-when"]',
                'title'   => 'When to use it',
                'body'    => 'Typical jobs: HEIC to JPG or PNG, a JPG for email, or old TIFF and BMP scans to a web-friendly format. Photos taken sideways are turned the right way up. Several files at once come back as a ZIP.',
            ],
            [
                'element' => '[data-tour="rpt-ic-files"]',
                'section' => 'Converting',
                'do'      => ['action' => 'click', 'say' => 'Click Choose Files and pick one or more photos.'],
                'title'   => 'Choose your photos',
                'body'    => 'Pick up to 50 images, each up to 50 MB. Hold Ctrl or Shift to select several at once.',
            ],
            [
                'element' => '[data-tour="rpt-ic-format"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the format you want back.'],
                'title'   => 'Output format',
                'body'    => 'PNG keeps full quality and transparency. JPG is smaller but has no transparency. WEBP is modern and compact, though some older programs cannot open it.',
            ],
            [
                'element'       => '[data-tour="rpt-ic-convert"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Convert & Download.'],
                'title'         => 'Convert',
                'body'          => 'A progress bar shows the upload and conversion, then the converted file downloads to your computer.',
            ],
        ],
    ],

    // ── Guided Tours directory ───────────────────────────────────────────────
    // No `permission`: the page is open to everyone signed in; each card is shown only if the person can open it.
    'rpt-guided-tours' => [
        'key'         => 'rpt-guided-tours',
        'title'       => 'Finding and starting a guide',
        'description' => 'Browse every guide for the parts of CoreX you can use, and pick how you want to be guided.',
        'route'       => 'corex.guided-tours.index',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-gt-intro"]',
                'section' => 'The three ways to be guided',
                'title'   => 'Guided Tours',
                'body'    => 'A guide for each part of CoreX. There are three ways to use it. Guided Tour explains the screen, one part at a time. Advanced Guide walks you through actually doing the job and moves on as you do each step. Spot Help shows just the one part you are stuck on.',
            ],
            [
                'element' => '[data-tour="rpt-gt-search"]',
                'section' => 'Finding a guide',
                'do'      => ['action' => 'fill', 'say' => 'Type a word like "lease" or "report" — or press Skip this step.', 'target' => 'input'],
                'title'   => 'Search',
                'body'    => 'Type a word and the cards below narrow as you type. Clear the box to see every guide again.',
            ],
            [
                'element' => '[data-tour="rpt-gt-group"]',
                'title'   => 'Grouped by area',
                'body'    => 'Guides are grouped by area of CoreX, with the number of guides in each group beside its name. You only see the guides for screens your role can open.',
            ],
            [
                'element' => '[data-tour="rpt-gt-card"]',
                'section' => 'Starting a guide',
                'title'   => 'One card per guide',
                'body'    => 'Each card says what the guide covers and how many steps it has. A note on the card tells you when you have to open a record first — the guide then starts on that record.',
            ],
            [
                'element' => '[data-tour="rpt-gt-modes"]',
                'title'   => 'Guided Tour or Advanced Guide',
                'body'    => 'The left button starts the Guided Tour, a read-through of the screen. The right button starts the Advanced Guide, where each step waits until you have done it. Both open the screen itself and run there.',
            ],
            [
                'element' => '[data-tour="rpt-gt-spot"]',
                'do'      => ['action' => 'click', 'say' => 'Click Spot Help to see the parts you can jump to.', 'until' => '[data-tour="rpt-gt-spot-list"]'],
                'title'   => 'Spot Help',
                'body'    => 'Opens a list of the parts of that screen. Pick one and the guide covers only that part — the quickest way to get unstuck.',
            ],
            [
                'element' => '[data-tour="rpt-gt-spot-list"]',
                'advanced_only' => true,
                'title'   => 'Pick a part',
                'body'    => 'Each name here is one part of the screen. Click one to start a short guide for just that part. You can also start any guide later from the "?" button at the top of its screen.',
            ],
        ],
    ],

    // ── TV Display: messages for the office TV ───────────────────────────────
    'rpt-tv-messages' => [
        'key'         => 'rpt-tv-messages',
        'title'       => 'Setting up messages for the office TV',
        'description' => 'Write the messages and live figures that scroll on the wall-mounted TV, for the whole agency or one branch.',
        'route'       => 'admin.tv-messages',
        'permission'  => 'manage_tv_messages',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="rpt-tv-intro"]',
                'section' => 'What the TV shows',
                'title'   => 'TV Messages',
                'body'    => 'The office TV shows your leaderboards plus the messages you write here. A message can go to every TV in the agency or to one branch only. To put the TV itself on screen, open the TV page on the TV\'s browser (the address ends in /tv) and enter the access code from the Performance page under TV Access Codes.',
            ],
            [
                'element' => '[data-tour="rpt-tv-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Switch between Active and Archived — or press Skip this step.'],
                'title'   => 'Active or archived',
                'body'    => 'Active messages are live or ready to go. Archived ones are deleted messages that you can restore.',
            ],
            [
                'element' => '[data-tour="rpt-tv-add"]',
                'section' => 'Adding a message',
                'title'   => 'Add a message',
                'body'    => 'Write the message, choose who sees it and where it appears, then add it. It is then included in what the TV shows.',
            ],
            [
                'element' => '[data-tour="rpt-tv-branch"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick Global or one branch.'],
                'title'   => 'Who sees it',
                'body'    => 'Leave it on Global for every TV in the agency, or pick a branch so only that branch\'s TV shows it.',
            ],
            [
                'element' => '[data-tour="rpt-tv-message"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the message.'],
                'title'   => 'The message',
                'body'    => 'A motivational line, an announcement, a reminder about an open day. Keep it short enough to read from across the room.',
            ],
            [
                'element' => '[data-tour="rpt-tv-values"]',
                'do'      => ['action' => 'click', 'say' => 'Click a value to drop it into your message — or press Skip this step.', 'target' => 'button'],
                'title'   => 'Insert live figures',
                'body'    => 'These grey tags are placeholders that the TV replaces with real numbers, such as deals so far this period or active listings. Click one to add it to the message where your cursor is.',
            ],
            [
                'element' => '[data-tour="rpt-tv-showon"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick where it shows on the TV.'],
                'title'   => 'Where on the screen',
                'body'    => 'Hero is the main message area, Ticker is the message strip. The default shows it in both.',
            ],
            [
                'element'       => '[data-tour="rpt-tv-addbtn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Add Message when you are ready.'],
                'title'         => 'Add it',
                'body'          => 'The message appears in the list below.',
            ],
            [
                'element' => '[data-tour="rpt-tv-list"]',
                'section' => 'Messages you already have',
                'title'   => 'Your messages',
                'body'    => 'Every message, with who created it and whether it is global or for a branch. Each row can be edited in place. Switch a message off with its tick, or delete it — deleted messages are archived, not lost.',
            ],
            [
                'element' => '[data-tour="rpt-tv-row"]',
                'do'      => ['action' => 'fill', 'say' => 'Change the wording of a message — or press Skip this step.', 'target' => 'input[name="message"]'],
                'title'   => 'Edit a message',
                'body'    => 'Change the text, branch, placement or the On tick, then click Save on that row.',
            ],
        ],
    ],

];
