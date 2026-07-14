<?php

namespace App\Support\Tours;

/**
 * Tour registry — the interactive help-tour catalogue.
 *
 * A tour is DATA, not code: add an entry here and it becomes (a) auto-launchable
 * on its `route`, and (b) re-launchable from the "?" help launcher on that page.
 * No controller or JS edit is required to add a tour.
 *
 * Definition shape:
 *   key    — stable string id, stored in user_tour_progress.tour_key
 *   title  — human label for the launcher
 *   route  — the route name this tour auto-launches on (and the launcher binds to)
 *   setup  — OPTIONAL ordered list of declarative DOM prep actions run before the
 *            tour starts. Supported (bounded) vocabulary:
 *              ['action' => 'alpineSet', 'selector' => '<css>', 'prop' => '<key>', 'value' => <bool|string>]
 *              ['action' => 'click',     'selector' => '<css>']
 *              ['action' => 'scrollTop']
 *   steps  — ordered spotlight steps:
 *              element — CSS selector to highlight (data-tour="…" anchors live in the views)
 *              title   — popover heading
 *              body    — popover body (plain text; kept short)
 *
 * Selectors target dedicated data-tour="…" anchors added to the real DOM of each
 * screen, NOT volatile utility classes — so a Tailwind/markup refactor never
 * silently breaks a tour.
 *
 * EXTERNAL-LINK entries (e.g. defs/mobile-app.php) are the one other shape: they
 * declare `external_url` (https) and an optional `cta` INSTEAD of `route`/`steps`,
 * and render in the Guided Tours directory as a card that opens that URL in a new
 * tab. They drive nothing on-page — forRoute() never matches them (no route), the
 * spotlight engine never sees them, and Ellie's TourKnowledgeService skips them
 * (it requires steps).
 */
class TourRegistry
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $tours = static::core();

        // Modular definitions (AT-41 full-coverage pass): every file in
        // app/Support/Tours/defs/*.php returns an array<key,definition> and is
        // merged in. This lets the catalogue grow per-module without one giant
        // file (and lets parallel work land without merge conflicts). Keys are
        // globally unique; a later file silently overriding an earlier key is a
        // bug, so we keep keys namespaced by module in each file.
        foreach (glob(__DIR__ . '/defs/*.php') as $defFile) {
            $defs = require $defFile;
            if (is_array($defs)) {
                $tours += $defs; // '+' preserves earlier keys — never clobber core
            }
        }

        return $tours;
    }

    /**
     * The original hand-authored core tours (queue #1–#9). Kept inline as the
     * canonical reference set; module packs live in defs/.
     *
     * @return array<string,array<string,mixed>>
     */
    protected static function core(): array
    {
        return [
            // ── Contact capture ──────────────────────────────────────────────
            'contact-capture' => [
                'key'   => 'contact-capture',
                'title' => 'How to capture a contact',
                'description' => 'Capture a buyer, seller, tenant or landlord — the four fields a contact needs.',
                'route' => 'corex.contacts.index',
                // The capture panel is collapsed by default — open it for the tour.
                'setup' => [
                    ['action' => 'alpineSet', 'selector' => '[data-tour-root="contacts"]', 'prop' => 'showAdd', 'value' => true],
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => '[data-tour="contact-add-btn"]',
                        'title'   => 'Add a contact',
                        'body'    => 'Every person CoreX knows — buyer, seller, tenant, landlord — is a Contact. This button opens the capture panel. We\'ve opened it for you so you can see each field.',
                    ],
                    [
                        'element' => '[data-tour="contact-form"]',
                        'title'   => 'The capture panel',
                        'body'    => 'A contact needs just four things to exist: a name, a surname and a phone number. Everything else enriches the record over time.',
                    ],
                    [
                        'element' => '[data-tour="contact-first-name"]',
                        'title'   => 'First name',
                        'body'    => 'Required. Use the person\'s real first name — it feeds documents, e-sign fields and FICA, so spell it the way it appears on their ID.',
                    ],
                    [
                        'element' => '[data-tour="contact-last-name"]',
                        'title'   => 'Surname',
                        'body'    => 'Required. Together with the first name this becomes the contact\'s display name across deals, properties and the calendar.',
                    ],
                    [
                        'element' => '[data-tour="contact-phone"]',
                        'title'   => 'Phone number',
                        'body'    => 'Required. When you tab out of this field CoreX instantly checks for an existing contact with the same number — so you never create a duplicate.',
                    ],
                    [
                        'element' => '[data-tour="contact-email"]',
                        'title'   => 'Email (optional)',
                        'body'    => 'Optional, but worth adding — it\'s also duplicate-checked and is what e-sign uses to deliver documents for signature.',
                    ],
                    [
                        // FIX 1 (AT-41): agent-safe copy. The old text told the user to add
                        // contact types in Settings — but only admins/owners can do that, and
                        // the tour's audience is agents. Role-aware copy would need engine
                        // support (static registry strings can't branch trivially), so per
                        // the brief this is the pick-one version shown to everyone.
                        'element' => '[data-tour="contact-type"]',
                        'title'   => 'Contact type',
                        'body'    => 'Pick at least one — Seller, Buyer, Landlord or Tenant. The type tells CoreX how to work this person: it sets their role on e-sign documents and drives the right automation. Choose the one that matches how you\'re dealing with them.',
                    ],
                    [
                        'element' => '[data-tour="contact-save"]',
                        'title'   => 'Save the contact',
                        'body'    => 'Saving creates the Contact node — linked to you and your agency — ready to attach to properties, deals and documents. That\'s the whole capture.',
                    ],
                    [
                        'element' => '[data-tour="contact-search"]',
                        'title'   => 'Finding contacts later',
                        'body'    => 'Search by name, phone or email here any time. You\'re ready — close this and capture your first contact.',
                    ],
                ],
            ],

            // ── Property capture (the 4-step WIZARD) ─────────────────────────
            // FIX 2 (AT-41): re-pointed from the old single-form
            // corex.properties.create (now the secondary "Classic form") to the
            // canonical New Property WIZARD (corex.properties.wizard →
            // corex/properties/wizard.blade.php). The prominent "New Property"
            // CTA on the listings page goes here.
            //
            // Multi-step approach: the wizard advances only via its validated
            // "Continue" button (goToStep is gated by canJumpTo/draft existence),
            // and forcing the Alpine `step` mid-tour fights driver.js's popover
            // positioning on x-show-hidden sections. So the tour anchors on
            // Step 1 (Basics — where every real capture decision lives) plus the
            // always-visible 4-step rail, and the rail + the Continue step narrate
            // the Photos → Details → Review progression. Every anchor is present
            // on the wizard's initial render — no empty spotlights.
            'property-capture' => [
                'key'   => 'property-capture',
                'title' => 'How to capture a property',
                'description' => 'Add a listing through the 4-step New Property wizard.',
                'route' => 'corex.properties.wizard',
                'setup' => [
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => '[data-tour="wiz-rail"]',
                        'title'   => 'Four quick steps',
                        'body'    => 'Adding a property is just four steps — Basics, Photos, Details, then a final Review before you publish. This bar always shows where you are. Your work saves as a draft, so you can stop and come back any time. Let\'s do the Basics.',
                    ],
                    [
                        'element' => '[data-tour="wiz-listing-type"]',
                        'title'   => 'For Sale or For Rental?',
                        'body'    => 'Start here. Your choice changes the fields ahead — a rental asks for the monthly amount and lease details, a sale asks for the asking price.',
                    ],
                    [
                        'element' => '[data-tour="wiz-headline"]',
                        'title'   => 'Headline',
                        'body'    => 'Required. The short line buyers see in search — e.g. "Stunning 3 Bed Family Home in Uvongo Beach". Sell the lifestyle; this is marketing, not the street address.',
                    ],
                    [
                        'element' => '[data-tour="wiz-type"]',
                        'title'   => 'Property type',
                        'body'    => 'Required. House, flat, townhouse, vacant land… This drives buyer matching and how the listing maps onto Property24 and Private Property.',
                    ],
                    [
                        'element' => '[data-tour="wiz-price"]',
                        'title'   => 'Price',
                        'body'    => 'Required. The asking price (or monthly rental) in Rands — just type the number, CoreX formats it for you as you go.',
                    ],
                    [
                        'element' => '[data-tour="wiz-complex"]',
                        'title'   => 'Complex or estate?',
                        'body'    => 'If it\'s in a complex, estate or sectional-title scheme, add the unit, block and complex name so the address is complete. Standalone house? You can leave this blank.',
                    ],
                    [
                        'element' => '[data-tour="wiz-location"]',
                        'title'   => 'Province · City · Suburb',
                        'body'    => 'Type to search — these come from Property24\'s official list. You must pick a suburb it recognises (no free text), so your listing maps cleanly to the portals.',
                    ],
                    [
                        'element' => '[data-tour="wiz-continue"]',
                        'title'   => 'Continue — and the rest',
                        'body'    => 'When the Basics are in, this saves a draft and moves you to Step 2 · Photos (drag images in), then Step 3 · Details (beds, baths, mandate, agent), then Step 4 · Review to check everything before you publish. That\'s the whole capture — close this and add your first listing.',
                    ],
                ],
            ],

            // ── WhatsApp Outreach Summary board (AT-91) ──────────────────────
            // Read-only board, but the drill-through interaction is worth a short
            // orientation. Gated by the board's own permission key.
            'outreach-summary' => [
                'key'        => 'outreach-summary',
                'title'      => 'Reading the outreach board',
                'description' => 'Read the WhatsApp outreach scoreboard and drill into any agent/outcome.',
                'route'      => 'corex.outreach-summary.index',
                'permission' => 'outreach.summary.view',
                'setup'      => [
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => '[data-tour="os-intro"]',
                        'title'   => 'Your WhatsApp pitch scoreboard',
                        'body'    => 'This board shows, at a glance, where every seller you\'ve pitched on WhatsApp stands. One row per agent, one number per outcome. You only see what\'s yours — your own pipeline as an agent, your branch as a manager.',
                    ],
                    [
                        'element' => '[data-tour="os-columns"]',
                        'title'   => 'What each column means',
                        'body'    => 'Awaiting reply = pitched, no answer yet. Confirmed = they said yes. No response — lapsed = the reply window passed. Opted out = they said no. Hover any heading for the full definition.',
                    ],
                    [
                        'element' => '[data-tour="os-total"]',
                        'title'   => 'Total contacted',
                        'body'    => 'Everyone you\'ve pitched on WhatsApp. The small "+ awaiting reply" line underneath is people who engaged (e.g. clicked the link) but haven\'t said yes or no yet — so every send is accounted for.',
                    ],
                    [
                        'element' => '[data-tour="os-board"]',
                        'title'   => 'Every number is a doorway',
                        'body'    => 'Click any count and CoreX opens that exact list of contacts — already filtered to that agent, that outcome and WhatsApp. No searching. That\'s the whole board — close this and click a number to dive in.',
                    ],
                ],
            ],


            // ── Outreach composer — the WhatsApp pitch (queue #1) ────────────
            // On the contact's Outreach tab (gated outreach.compose). Setup opens
            // that tab so the composer panel is on-screen for the spotlight.
            'outreach-composer' => [
                'key'         => 'outreach-composer',
                'title'       => 'Pitching a seller on WhatsApp',
                'description' => 'How to ask a seller for permission and send your WhatsApp pitch from a contact.',
                'route'       => 'corex.contacts.show',
                'permission'  => 'outreach.compose',
                'setup' => [
                    ['action' => 'click', 'selector' => '[data-tour="outreach-tab"]'],
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => '[data-tour="outreach-tab"]',
                        'title'   => 'The Outreach tab',
                        'body'    => 'Everything about pitching this seller on WhatsApp lives here — we\'ve opened it for you. The number on the tab is how many times you\'ve reached out.',
                    ],
                    [
                        'element' => '#tab-outreach',
                        'title'   => 'Compose, send & track',
                        'body'    => 'From here you send the seller a WhatsApp asking permission to market their home, see whether they said yes or no, and track every send. CoreX only lets you pitch once you have their consent — it keeps you compliant automatically.',
                    ],
                ],
            ],

            // ── Buyer Pipeline (queue #2) ────────────────────────────────────
            'buyer-pipeline' => [
                'key'         => 'buyer-pipeline',
                'title'       => 'Working your buyer pipeline',
                'description' => 'Track buyers from New to Warm, Cold or Lost, and switch between your own, branch or agency view.',
                'route'       => 'command-center.buyers.pipeline',
                'setup'       => [['action' => 'scrollTop']],
                'steps' => [
                    [
                        'element' => '[data-tour="buyers-intro"]',
                        'title'   => 'Your buyer pipeline',
                        'body'    => 'Every buyer you\'re working, grouped by how hot they are: New → Warm → Cold → Lost. Buyers land here automatically when you capture what they\'re looking for on their contact, so the board fills itself as you work.',
                    ],
                    [
                        'element' => '[data-tour="buyers-scope"]',
                        'title'   => 'Whose buyers?',
                        'body'    => 'Switch between Mine, your Branch, or All (where you\'re allowed to see them). Start on Mine — that\'s your own list to action.',
                    ],
                    [
                        'element' => '[data-tour="buyers-view"]',
                        'title'   => 'Board or list',
                        'body'    => 'Kanban shows buyers as cards you can drag between stages; List is a compact table. Use whichever you prefer — close this and move a buyer along.',
                    ],
                ],
            ],

            // ── Market Intelligence / MIC (queue #3) ─────────────────────────
            'mic-work' => [
                'key'         => 'mic-work',
                'title'       => 'Using Market Intelligence',
                'description' => 'Your daily prospecting worklist — what to action next, plus uploading a CMA.',
                'route'       => 'market-intelligence.work',
                'setup'       => [['action' => 'scrollTop']],
                'steps' => [
                    [
                        'element' => '[data-tour="mic-tabs"]',
                        'title'   => 'Market Intelligence',
                        'body'    => 'Your prospecting command centre. Work is your daily to-do list of listings to act on; Opportunities, Analyse and Market Pulse sit alongside it on these tabs.',
                    ],
                    [
                        'element' => '[data-tour="mic-hero"]',
                        'title'   => 'This week',
                        'body'    => 'Your hottest prompts for the week — the handful of listings CoreX thinks deserve your attention first, ranked by the suggested next step.',
                    ],
                    [
                        'element' => '[data-tour="mic-upload"]',
                        'title'   => 'Got a CMA? Drop it here',
                        'body'    => 'Upload a CMA or sales report and CoreX reads it for you — pulling out the comparable sales and feeding them into your valuations. No retyping.',
                    ],
                    [
                        'element' => '[data-tour="mic-list"]',
                        'title'   => 'Your worklist',
                        'body'    => 'Each row is a property with a suggested next move. Filter on the left, click a row to see why it matched and what to do. Work top-down — close this and action your first one.',
                    ],
                ],
            ],

            // ── Compliance / FICA (queue #4) ─────────────────────────────────
            'fica-capture' => [
                'key'         => 'fica-capture',
                'title'       => 'Sending a FICA request',
                'description' => 'Start an online or wet-ink FICA verification and track where each one stands.',
                'route'       => 'compliance.fica.index',
                'setup'       => [['action' => 'scrollTop']],
                'steps' => [
                    [
                        'element' => '[data-tour="fica-intro"]',
                        'title'   => 'FICA compliance',
                        'body'    => 'FICA is the law that says you must verify who your clients are. This screen lists every verification you\'ve started and whether it\'s done.',
                    ],
                    [
                        'element' => '[data-tour="fica-online"]',
                        'title'   => 'Send Online FICA',
                        'body'    => 'The easy way: CoreX emails your client a secure link to upload their ID and proof of address themselves. You\'ll see it tick over to verified here — no paper.',
                    ],
                    [
                        'element' => '[data-tour="fica-wetink"]',
                        'title'   => 'Wet-ink FICA',
                        'body'    => 'For a client who hands you physical documents, capture them here instead. Same record, just done in person.',
                    ],
                    [
                        'element' => '[data-tour="fica-rmcp"]',
                        'title'   => 'Your risk programme',
                        'body'    => 'View RMCP opens your agency\'s Risk Management & Compliance Programme — the rulebook for how thoroughly each client must be checked. Good to know it\'s there; you rarely need it day to day.',
                    ],
                ],
            ],

            // ── Deals V2 — deal register (queue #5) ──────────────────────────
            'deals-register' => [
                'key'         => 'deals-register',
                'title'       => 'The deal register',
                'description' => 'Track a transaction from offer through to registration, and start a new deal.',
                'route'       => 'deals-v2.index',
                'setup'       => [['action' => 'scrollTop']],
                'steps' => [
                    [
                        'element' => '[data-tour="deals-intro"]',
                        'title'   => 'Your deals',
                        'body'    => 'Every transaction you\'re running, from the moment an offer is signed through to registration at the deeds office. One row per deal, with its current stage.',
                    ],
                    [
                        'element' => '[data-tour="deals-new"]',
                        'title'   => 'Start a new deal',
                        'body'    => 'Got an accepted offer? Create the deal here. CoreX pulls in the property, the buyer and seller, and the commission — then walks the deal through each stage.',
                    ],
                    [
                        'element' => '[data-tour="deals-filter"]',
                        'title'   => 'Find a deal fast',
                        'body'    => 'Search or filter to jump to a specific deal or stage. Close this and open a deal to see its full timeline.',
                    ],
                ],
            ],

            // ── Feedback Reports (queue #7, lower) ───────────────────────────
            'feedback-reports' => [
                'key'         => 'feedback-reports',
                'title'       => 'Feedback & bug reports',
                'description' => 'Review feedback, enhancement ideas and bug reports raised by the team.',
                'route'       => 'command-center.feedback-reports',
                'setup'       => [['action' => 'scrollTop']],
                'steps' => [
                    [
                        'element' => '[data-tour="feedback-intro"]',
                        'title'   => 'Team feedback',
                        'body'    => 'Anything the team has flagged — a bug, an idea, a request — lands here so nothing gets lost. A shared to-do list for improving CoreX.',
                    ],
                    [
                        'element' => '[data-tour="feedback-filter"]',
                        'title'   => 'Filter by status',
                        'body'    => 'See what\'s new, being looked at, fixed or parked. Click a status to narrow the list.',
                    ],
                    [
                        'element' => '[data-tour="feedback-export"]',
                        'title'   => 'Export if you need it',
                        'body'    => 'Download the list as a file to share or keep. That\'s it — close this and have a look.',
                    ],
                ],
            ],

            // ── Calendar (queue #6) ──────────────────────────────────────────
            // ── Calendar cockpit (AT-164 redesign) ───────────────────────────
            // Guided tour of the new calendar: cockpit layout, continuous scroll,
            // layers vs tiles, right panel, deck, save/reset and event reminders.
            // Anchors are cockpit-level data-tour hooks already on-screen (the
            // cockpit is a fixed viewport-height frame), so driver.js never scrolls
            // to them — the hardened outer frame (banner/toolbar/weekday header
            // pinned outside the scrollers) is never displaced. Steps skip
            // gracefully if a region is collapsed.
            'calendar' => [
                'key'         => 'calendar',
                'title'       => 'The new calendar',
                'description' => 'A guided tour of the calendar cockpit — scroll, layers, tiles, the deck, saving your view and event reminders.',
                'route'       => 'command-center.calendar',
                'setup'       => [
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => '[data-tour="cal-cockpit"]',
                        'title'   => 'Your calendar cockpit',
                        'body'    => 'Everything on one screen: the calendar fills the middle, your agenda-and-details panel is on the right, and a deck of handy tiles sits along the bottom. Nothing scrolls the whole page — each part scrolls on its own.',
                    ],
                    [
                        'element' => '[data-tour="cal-views"]',
                        'title'   => 'Month, Week or Day',
                        'body'    => 'Switch how much you see here. Month scrolls smoothly downwards week after week; Week scrolls sideways through the days and up and down through the hours. The label at the top always tells you where you are.',
                    ],
                    [
                        'element' => '[data-tour="cal-today"]',
                        'title'   => 'Back to Today',
                        'body'    => 'Scrolled off into next month? One tap on Today snaps you straight back to now — wherever you\'ve wandered.',
                    ],
                    [
                        'element' => '[data-tour="cal-layers"]',
                        'title'   => 'Layers — what shows on the calendar',
                        'body'    => 'Tick and untick the kinds of events you want ON the calendar — viewings, deals, compliance, personal and more. This only changes the calendar. Your deck tiles are separate: untick To-dos here and the To-dos tile still shows them.',
                    ],
                    [
                        'element' => '[data-tour="cal-panel"]',
                        'title'   => 'The right panel',
                        'body'    => 'By default this shows your agenda — today and what\'s coming up. Click any event to see its full details here, and the Add Event button opens the new-event form right in this panel, without leaving the page.',
                    ],
                    [
                        'element' => '[data-tour="cal-add"]',
                        'title'   => 'Add an event — and get reminded',
                        'body'    => 'Book a viewing or meeting and link it to a contact and property. Set a reminder while you\'re there: choose how long before — say an hour — and a popup will find you anywhere in CoreX (even while you\'re busy loading a property), or send you an email.',
                    ],
                    [
                        'element' => '[data-tour="cal-deck"]',
                        'title'   => 'Your tile deck',
                        'body'    => 'The tiles along the bottom are yours to arrange. Edit Deck picks which tiles show; drag the divider up or down to give the calendar or the deck more room; drag between tiles to set their widths; collapse the deck or the panel; or go full-calendar for the biggest grid.',
                    ],
                    [
                        'element' => '[data-tour="cal-saveview"]',
                        'title'   => 'Your view, saved',
                        'body'    => 'However you arrange it, CoreX remembers your layout automatically — it\'s there next time you open the calendar. Changed your mind? Reset view puts everything back to the default. That\'s the tour — enjoy your new calendar.',
                    ],
                ],
            ],

            // ── Tasks (queue #6) ─────────────────────────────────────────────
            'tasks' => [
                'key'         => 'tasks',
                'title'       => 'Staying on top of tasks',
                'description' => 'Track your to-dos on a board or list and capture a new task.',
                'route'       => 'command-center.tasks',
                'steps' => [
                    [
                        'element' => '[data-tour="task-intro"]',
                        'title'   => 'Your to-do list',
                        'body'    => 'Every follow-up and to-do in one place, with what\'s open, overdue and due today right here at the top so you always know where you stand.',
                    ],
                    [
                        'element' => '[data-tour="task-add"]',
                        'title'   => 'Add a task',
                        'body'    => 'Capture a follow-up in seconds — give it a due date and link it to a contact, property or deal so it shows up where you\'ll need it.',
                    ],
                    [
                        'element' => '[data-tour="task-view"]',
                        'title'   => 'Board or list',
                        'body'    => 'Board groups tasks by status you can drag between; List is a simple checklist. Your pick — close this and clear your first task.',
                    ],
                ],
            ],

            // ── Documents Library (queue #8) ─────────────────────────────────
            'documents-library' => [
                'key'         => 'documents-library',
                'title'       => 'The document library',
                'description' => 'Upload, find and manage shared documents.',
                'route'       => 'documents.library.index',
                'setup'       => [['action' => 'scrollTop']],
                'steps' => [
                    [
                        'element' => '[data-tour="docs-intro"]',
                        'title'   => 'Shared documents',
                        'body'    => 'Your agency\'s shared files — brochures, forms, anything the team reuses — kept in one place so you\'re not hunting through email.',
                    ],
                    [
                        'element' => '#upload-library',
                        'title'   => 'Upload a document',
                        'body'    => 'Pick a type, choose the file and upload. Tagging it by type is what makes it easy to find later.',
                    ],
                    [
                        'element' => '[data-tour="docs-filter"]',
                        'title'   => 'Find one fast',
                        'body'    => 'Filter by type to narrow the list. Close this and upload or open a document.',
                    ],
                ],
            ],

            // ── E-sign builder (queue #9) — READ-ONLY (P0-safe) ──────────────
            // Strictly point-only: NO setup clicks, no step changes — the tour
            // never touches the wizard's state or the signing surface.
            'esign-wizard' => [
                'key'         => 'esign-wizard',
                'title'       => 'Sending a document to sign',
                'description' => 'A read-only walkthrough of the e-sign builder — how a document gets signed online.',
                'route'       => 'docuperfect.esign.create',
                'steps' => [
                    [
                        'element' => '[data-tour="esign-title"]',
                        'title'   => 'The e-sign builder',
                        'body'    => 'This is where you send a document out for signature online — no printing, no scanning. Give it a name here so you recognise it later.',
                    ],
                    [
                        'element' => '[data-tour="esign-rail"]',
                        'title'   => 'Six guided steps',
                        'body'    => 'Pick the template, attach the property, add who must sign (the recipients), fill the details, place the signature fields, then review and send. The bar shows where you are; the Next and Back buttons in the wizard move you along — work through them in order.',
                    ],
                ],
            ],
        ];
    }

    /**
     * The single tour bound to a route name (or null).
     */
    public static function forRoute(?string $routeName): ?array
    {
        if (! $routeName) {
            return null;
        }

        foreach (static::all() as $tour) {
            if (($tour['route'] ?? null) === $routeName) {
                return $tour;
            }
        }

        return null;
    }

    public static function find(?string $key): ?array
    {
        if (! $key) {
            return null;
        }

        return static::all()[$key] ?? null;
    }

    /**
     * Role-gating for a tour. A tour MAY declare an optional `permission`
     * (single key) the viewer must hold to see/auto-run it. With no
     * `permission` set, the tour is visible to anyone who can reach its
     * route — the route's own middleware is the gate, so the tour inherits
     * it and we don't double-maintain access rules. System Owners always see
     * every tour.
     */
    public static function visibleTo(?array $tour, $user): bool
    {
        if (! $tour || ! $user) {
            return false;
        }

        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return true;
        }

        $permission = $tour['permission'] ?? null;
        if (! $permission) {
            return true; // inherit the route's own gate
        }

        return method_exists($user, 'hasPermission')
            && $user->hasPermission($permission) === true;
    }
}
