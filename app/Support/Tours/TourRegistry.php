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
 * ── ADVANCED GUIDING + SPOT HELP (spec: .ai/specs/advanced-guiding.md) ──────
 * One definition drives three modes, picked from the page's "?" menu, the
 * Guided Tours directory, or an Ellie button:
 *   Guided Tour    — the click-through explanation above (Next → Next → Done).
 *   Advanced Guide — hands-on: each step waits for the agent to actually DO it,
 *                    then moves on by itself.
 *   Spot Help      — Advanced Guide limited to ONE section of the page.
 * All extras are OPTIONAL and additive; a step without them behaves as before.
 *
 *   Per-step:
 *     section       — Spot Help group name, plain words ("Adding spaces").
 *                     Inherited by every following step until the next
 *                     `section`, so set it on the FIRST step of each group.
 *     do            — what the agent must do in Advanced/Spot mode. No `do` =
 *                     a "read" step the agent confirms with "Got it".
 *                       ['action' => 'fill',   'say' => 'Type the headline.']
 *                           moves on when a text/number/select/textarea control
 *                           (the element itself, `target`, or the first control
 *                           inside the element) holds a CHANGED, non-empty value
 *                           the browser accepts (checkValidity), after a short
 *                           typing pause or on blur.
 *                       ['action' => 'choose', 'say' => 'Pick For Sale or For Rental.']
 *                           moves on when any radio/checkbox/select inside the
 *                           element changes, OR any button inside it is clicked
 *                           (for Alpine toggle-button groups).
 *                       ['action' => 'click',  'say' => 'Click Continue.']
 *                           moves on when the element (or `target` inside it) is
 *                           clicked. A click on a form submit button only counts
 *                           when the form is valid.
 *                       ['action' => 'appear', 'say' => 'Add a space — the tile appears here.',
 *                        'until' => '<css>']
 *                           moves on when `until` becomes visible (or, with
 *                           'more' => true, when the number of visible `until`
 *                           matches grows) — for results the agent's action
 *                           creates.
 *                     Optional on any action:
 *                       target — CSS INSIDE the step element to watch/click.
 *                       until  — CSS that must become visible before moving on
 *                                (e.g. click Continue → until the Photos step
 *                                shows). If it never shows, the step stays put
 *                                and tells the agent something needs attention.
 *                       say    — the one-line instruction (imperative). Falls
 *                                back to the step body when absent.
 *     advanced_only — true = shown only in Advanced/Spot mode (a "do" step that
 *                     would be noise in the explanation tour, e.g. "click Save",
 *                     or a later wizard screen not on-screen when a tour starts).
 *     skip_if       — CSS; in Advanced/Spot mode the step is skipped when this is
 *                     already visible (e.g. "open the Spaces tab" when it's open).
 *     prep          — per-step reveal actions, same vocabulary as tour `setup`
 *                     plus ['action' => 'dispatch', 'event' => '<name>', 'detail' => <scalar>]
 *                     (window CustomEvent). Only ever REVEALS (tab, panel);
 *                     never types, picks or saves for the agent.
 *
 *   Per-tour:
 *     also_on       — extra route names that render the SAME screen (e.g. a
 *                     calculator's POST result page), so the "?" and a running
 *                     guide carry on there instead of stopping.
 *
 *   Per-tour (only for tours whose screen needs a record, e.g. one property —
 *   in the URL path or as a required query, e.g. ?pack_instance=):
 *     pick_from     — route name of the list where the agent picks that record.
 *                     Declaring it means every outside launch goes via that list.
 *     pick_note     — what to tell them there ("Open the property you want…").
 *                     The guide starts itself once they open one.
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
                        'section' => 'Required details',
                        'do'      => ['action' => 'click', 'say' => 'Click Add Contact to open the capture panel.', 'until' => '[data-tour="contact-form"]'],
                        'skip_if' => '[data-tour="contact-first-name"]',
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
                        'do'      => ['action' => 'fill', 'say' => 'Type the first name.'],
                        'title'   => 'First name',
                        'body'    => 'Required. Use the person\'s real first name — it feeds documents, e-sign fields and FICA, so spell it the way it appears on their ID.',
                    ],
                    [
                        'element' => '[data-tour="contact-last-name"]',
                        'do'      => ['action' => 'fill', 'say' => 'Type the surname.'],
                        'title'   => 'Surname',
                        'body'    => 'Required. Together with the first name this becomes the contact\'s display name across deals, properties and the calendar.',
                    ],
                    [
                        'element' => '[data-tour="contact-phone"]',
                        'do'      => ['action' => 'fill', 'say' => 'Type the phone number, then click or tab out of the box.'],
                        'title'   => 'Phone number',
                        'body'    => 'Required. When you tab out of this field CoreX instantly checks for an existing contact with the same number — so you never create a duplicate.',
                    ],
                    [
                        'element' => '[data-tour="contact-email"]',
                        'do'      => ['action' => 'fill', 'say' => 'Type their email address — or press Skip this step if you don\'t have it.'],
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
                        'section' => 'Contact type',
                        'do'      => ['action' => 'choose', 'say' => 'Tick at least one type.'],
                        'title'   => 'Contact type',
                        'body'    => 'Pick at least one — Seller, Buyer, Landlord or Tenant. The type tells CoreX how to work this person: it sets their role on e-sign documents and drives the right automation. Choose the one that matches how you\'re dealing with them.',
                    ],
                    [
                        'element' => '[data-tour="contact-save"]',
                        'section' => 'Saving',
                        'do'      => ['action' => 'click', 'say' => 'Click Save Contact.'],
                        'title'   => 'Save the contact',
                        'body'    => 'Saving creates the Contact node — linked to you and your agency — ready to attach to properties, deals and documents. That\'s the whole capture.',
                    ],
                    [
                        'element' => '[data-tour="contact-search"]',
                        'section' => 'Finding contacts',
                        'title'   => 'Finding contacts later',
                        'body'    => 'Search by name, phone or email here any time — CoreX matches across every number and address on the record, not just the one on screen.',
                    ],
                    [
                        'element' => '[data-tour="contact-street-search"]',
                        'title'   => 'Search by street or complex',
                        'body'    => 'Working a street, complex or estate? Click this house icon and type the street or complex name — CoreX finds every contact whose address or linked property matches, across your whole agency book (or your branch, depending on your access). The results open on their own page — each tagged with when you last contacted them — sortable and downloadable as a PDF. You\'re ready — close this and capture your first contact.',
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
                // Advanced Guide walks the WHOLE wizard: the advanced_only steps
                // follow it screen by screen (Continue → Photos → Details →
                // Review), each Continue waiting (`until`) for the next screen.
                // The P24 location steps only move on once a value is PICKED from
                // the list: picking a province enables City, picking a city
                // enables Suburb, and Continue unlocks only when step1Valid().
                'steps' => [
                    [
                        'element' => '[data-tour="wiz-rail"]',
                        'section' => 'Basics',
                        'title'   => 'Four quick steps',
                        'body'    => 'Adding a property is just four steps — Basics, Photos, Details, then a final Review before you publish. This bar always shows where you are. Your work saves as a draft, so you can stop and come back any time. Let\'s do the Basics.',
                    ],
                    [
                        'element' => '[data-tour="wiz-listing-type"]',
                        'do'      => ['action' => 'choose', 'say' => 'Pick For Sale or For Rental.'],
                        'title'   => 'For Sale or For Rental?',
                        'body'    => 'Start here. Your choice changes the fields ahead — a rental asks for the monthly amount and lease details, a sale asks for the asking price.',
                    ],
                    [
                        'element' => '[data-tour="wiz-headline"]',
                        'do'      => ['action' => 'fill', 'say' => 'Type the headline buyers will see.'],
                        'title'   => 'Headline',
                        'body'    => 'Required. The short line buyers see in search — e.g. "Stunning 3 Bed Family Home in Uvongo Beach". Sell the lifestyle; this is marketing, not the street address.',
                    ],
                    [
                        'element' => '[data-tour="wiz-type"]',
                        'do'      => ['action' => 'choose', 'say' => 'Pick the property type.'],
                        'title'   => 'Property type',
                        'body'    => 'Required. House, flat, townhouse, vacant land… This drives buyer matching and how the listing maps onto Property24 and Private Property.',
                    ],
                    [
                        'element' => '[data-tour="wiz-price"]',
                        'do'      => ['action' => 'fill', 'say' => 'Type the price in Rands.'],
                        'title'   => 'Price',
                        'body'    => 'Required. The asking price (or monthly rental) in Rands — just type the number, CoreX formats it for you as you go.',
                    ],
                    [
                        'element' => '[data-tour="wiz-complex"]',
                        'section' => 'Location',
                        'do'      => ['action' => 'fill', 'say' => 'In a complex or estate? Type its name — or press Skip this step.', 'target' => 'input[x-model="s1.complex_name"]'],
                        'title'   => 'Complex or estate?',
                        'body'    => 'If it\'s in a complex, estate or sectional-title scheme, add the unit, block and complex name so the address is complete. Standalone house? You can leave this blank.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-street"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type the street number.', 'target' => 'input[x-model="s1.street_number"]'],
                        'title'         => 'Street number',
                        'body'          => 'The street number and name complete the property\'s address.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-street"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type the street name.', 'target' => 'input[x-model="s1.street_name"]'],
                        'title'         => 'Street name',
                        'body'          => 'e.g. Clarendon Road.',
                    ],
                    [
                        'element' => '[data-tour="wiz-location"]',
                        'do'      => ['action' => 'fill', 'say' => 'Type the province and pick it from the list.', 'target' => 'input[data-loc-field="province"]', 'until' => 'input[data-loc-field="city"]:enabled'],
                        'title'   => 'Province · City · Suburb',
                        'body'    => 'Type to search — these come from Property24\'s official list. You must pick a suburb it recognises (no free text), so your listing maps cleanly to the portals.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-location"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type the city or town and pick it from the list.', 'target' => 'input[data-loc-field="city"]', 'until' => 'input[data-loc-field="suburb"]:enabled'],
                        'title'         => 'City or town',
                        'body'          => 'Only the towns in the province you picked are listed.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-location"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type the suburb and pick it from the list.', 'target' => 'input[data-loc-field="suburb"]', 'until' => '[data-tour="wiz-continue"]:enabled'],
                        'title'         => 'Suburb',
                        'body'          => 'Pick it from the list — typed text on its own won\'t do. Once the suburb and every required box above are in, Continue unlocks.',
                    ],
                    [
                        // Steppers — a count often needs several clicks, so this is a
                        // "Got it" step rather than one that moves on at the first click.
                        'element'       => '[data-tour="wiz-rooms"]',
                        'section'       => 'Rooms',
                        'advanced_only' => true,
                        'title'         => 'Bedrooms, bathrooms, garages',
                        'body'          => 'Click + and − to set how many bedrooms, bathrooms and garages the property has — "Add half" adds a guest toilet. Click Got it when they\'re right.',
                    ],
                    [
                        'element' => '[data-tour="wiz-continue"]',
                        'section' => 'Photos',
                        'do'      => ['action' => 'click', 'say' => 'Click Continue to photos — it saves your draft.', 'until' => '[data-tour="wiz-photos-drop"]'],
                        'skip_if' => '[data-tour="wiz-photos-drop"]',
                        'title'   => 'Continue — and the rest',
                        'body'    => 'When the Basics are in, this saves a draft and moves you to Step 2 · Photos (drag images in), then Step 3 · Details (description, mandate and sizes — plus lease details for a rental; bedrooms, bathrooms and garages were already captured here in the Basics), then Step 4 · Review to check everything before you publish. That\'s the whole capture — close this and add your first listing.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-photos-drop"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'appear', 'say' => 'Drag your photos in, or click to choose them.', 'until' => '[data-tour="wiz-photo"]', 'more' => true],
                        'title'         => 'Add your photos',
                        'body'          => 'Pick as many as you like — they upload straight away. The first photo is the cover; hover any photo and click Cover to change it.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-photos-next"]',
                        'section'       => 'Details',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Click Continue — or Skip for now if you have no photos yet.', 'until' => '[data-tour="wiz-details"]'],
                        'skip_if'       => '[data-tour="wiz-details"]',
                        'title'         => 'On to the details',
                        'body'          => 'You need at least one photo to publish, but you can add them later and save as a draft for now.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-description"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Describe the home — at least a few lines.'],
                        'title'         => 'Description',
                        'body'          => 'Needed before you can publish (30 characters or more). Tell buyers what makes it special — views, light, layout, outdoor space.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-mandate"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Pick the mandate type.'],
                        'title'         => 'Mandate',
                        'body'          => 'Sole, Open or Dual — as signed with the seller.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-sizes"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type the floor size in m² — or press Skip this step.'],
                        'title'         => 'Sizes',
                        'body'          => 'Floor and erf size in square metres. Leave them blank if you don\'t have them yet.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-details-continue"]',
                        'section'       => 'Review & publish',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Continue to review — it saves your details.', 'until' => '[data-tour="wiz-review"]'],
                        'skip_if'       => '[data-tour="wiz-review"]',
                        'title'         => 'On to the review',
                        'body'          => 'The last screen shows what\'s done and what\'s still missing.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-checklist"]',
                        'advanced_only' => true,
                        'title'         => 'Ready to publish?',
                        'body'          => 'Green means done. Anything amber is still missing — click it to jump back and fill it in.',
                    ],
                    [
                        'element'       => '[data-tour="wiz-finish"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Click Save & publish to put it live on your website — or Save as draft to finish later.'],
                        'title'         => 'Publish or save',
                        'body'          => 'Save & publish makes the listing Active and sends it to your agency website — it only unlocks once every item is green. Save as draft keeps it private. Either way you land on the property\'s own page.',
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
                        'section' => 'Reading the board',
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
                        // Any count link inside the table body opens that filtered contact list.
                        'element' => '[data-tour="os-board"]',
                        'section' => 'Opening a list',
                        'do'      => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click any number to open that exact list of contacts.'],
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
                'pick_from'   => 'corex.contacts.index',
                'pick_note'   => 'Open the seller you want to pitch and I\'ll pick up from there.',
                'setup' => [
                    ['action' => 'click', 'selector' => '[data-tour="outreach-tab"]'],
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => '[data-tour="outreach-tab"]',
                        'section' => 'Opening Outreach',
                        'do'      => ['action' => 'click', 'say' => 'Click the Outreach tab.', 'until' => '#tab-outreach'],
                        'skip_if' => '#tab-outreach',
                        'title'   => 'The Outreach tab',
                        'body'    => 'Everything about pitching this seller on WhatsApp lives here — we\'ve opened it for you. The number on the tab is how many times you\'ve reached out.',
                    ],
                    [
                        'element' => '#tab-outreach',
                        'title'   => 'Compose, send & track',
                        'body'    => 'From here you send the seller a WhatsApp asking permission to market their home, see whether they said yes or no, and track every send. CoreX only lets you pitch once you have their consent — it keeps you compliant automatically.',
                    ],
                    // Only on-screen while no consent is recorded yet (or after an opt-out).
                    [
                        'element'       => '[data-tour="outreach-optin-btn"]',
                        'section'       => 'Recording a YES',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'If the seller has already said yes, click this button — otherwise press Skip this step.', 'until' => '[data-tour="outreach-optin-form"]'],
                        'title'         => 'Seller said yes?',
                        'body'          => 'If the seller gave you their go-ahead — by phone, in person or a YES reply — record it here so CoreX knows you may market to them.',
                    ],
                    [
                        'element'       => '[data-tour="outreach-optin-form"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type how and when the seller gave their consent.'],
                        'title'         => 'How they said yes',
                        'body'          => 'One short line is enough, e.g. "Seller agreed by phone on 17 Jun". It becomes part of the seller\'s consent record.',
                    ],
                    [
                        'element'       => '[data-tour="outreach-optin-save"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Confirm opt-in (or Re-enable marketing) and confirm — this records the seller\'s consent on the contact.'],
                        'title'         => 'Save their consent',
                        'body'          => 'CoreX asks you to confirm, then stamps who recorded it and when. Only do this with the seller\'s real consent.',
                    ],
                    // Timeline rows repeat per send, so these steps watch the whole list.
                    [
                        'element'       => '[data-tour="outreach-timeline"]',
                        'section'       => 'Tracking replies',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'target' => 'button', 'say' => 'Click Details on the latest send.'],
                        'title'         => 'Every send, on record',
                        'body'          => 'Each WhatsApp you\'ve sent this seller is listed here, newest first. Open one to see exactly what went out and to note how they responded.',
                    ],
                    [
                        'element'       => '[data-tour="outreach-timeline"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'target' => 'select', 'say' => 'Pick what the seller said in the outcome list.'],
                        'title'         => 'What happened?',
                        'body'          => 'Choose the outcome that matches the seller\'s response. You can add a short note next to it too.',
                    ],
                    [
                        'element'       => '[data-tour="outreach-timeline"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'target' => 'button[type="submit"]', 'say' => 'Click Save next to the outcome.'],
                        'title'         => 'Save the outcome',
                        'body'          => 'The outcome is saved against that send, so you and your manager always know where this seller stands.',
                    ],
                    [
                        'element'       => '[data-tour="outreach-compose-btn"]',
                        'section'       => 'Sending a pitch',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click + Compose pitch to open the composer — nothing is sent yet.'],
                        'title'         => 'Compose your pitch',
                        'body'          => 'This opens the full composer, where you pick WhatsApp or email, check the message and send it. Look for the "?" there to be guided through sending.',
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
                        'section' => 'Choosing whose buyers',
                        'title'   => 'Your buyer pipeline',
                        'body'    => 'Every buyer you\'re working, grouped by how hot they are: New → Warm → Cold → Lost. Buyers land here automatically when you capture what they\'re looking for on their contact, so the board fills itself as you work.',
                    ],
                    [
                        'element' => '[data-tour="buyers-scope"]',
                        'do'      => ['action' => 'click', 'say' => 'Click Mine, Branch or All to choose whose buyers you see.'],
                        'title'   => 'Whose buyers?',
                        'body'    => 'Switch between Mine, your Branch, or All (where you\'re allowed to see them). Start on Mine — that\'s your own list to action.',
                    ],
                    [
                        'element'       => '[data-tour="buyers-lead-type"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Sales or Rentals to narrow the board — or All for both.'],
                        'title'         => 'Sales or rentals',
                        'body'          => 'Buyers and tenants share this board. Narrow it to the kind of lead you\'re working right now.',
                    ],
                    [
                        'element' => '[data-tour="buyers-view"]',
                        'section' => 'Board or list',
                        'do'      => ['action' => 'click', 'say' => 'Click Kanban or List to choose how your buyers are shown.'],
                        'title'   => 'Board or list',
                        'body'    => 'Kanban shows buyers as cards you can drag between stages; List is a compact table. Use whichever you prefer — close this and move a buyer along.',
                    ],
                    [
                        'element'       => '[data-tour="buyers-search"]',
                        'section'       => 'Finding a buyer',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type a buyer\'s name, phone number or email.'],
                        'title'         => 'Search your buyers',
                        'body'          => 'Looking for one person? Search by name, phone or email. The State, Agent and Since boxes next to it narrow the list further.',
                    ],
                    [
                        'element'       => '[data-tour="buyers-search-btn"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Search.'],
                        'title'         => 'Run the search',
                        'body'          => 'The board reloads showing only the buyers that match.',
                    ],
                    // Kanban and List render different containers — only one exists at a time.
                    [
                        'element'       => '[data-tour="buyers-board"]',
                        'section'       => 'Working a buyer',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click a buyer\'s card to open their Buyer Hub.'],
                        'title'         => 'Open a buyer',
                        'body'          => 'Each card is a buyer. Drag a card to another column to move them along (Lost asks you for a reason first), or click it to open everything about that buyer.',
                    ],
                    [
                        'element'       => '[data-tour="buyers-list"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click a buyer\'s name to open their Buyer Hub.'],
                        'title'         => 'Open a buyer',
                        'body'          => 'Each row is a buyer. Click the name to open everything about them — or View Matches to see the properties that fit.',
                    ],
                ],
            ],

            // ── Market Intelligence / MIC (queue #3) ─────────────────────────
            // Flagship: buyer-led prospecting (AT-242). Key kept stable ('mic-work')
            // so the launcher binding + saved progress on market-intelligence.work
            // carry over; the tour now walks the "Prospect for a buyer" flow —
            // today's headline way to work the MIC page.
            'mic-work' => [
                'key'         => 'mic-work',
                'title'       => 'Prospect for a buyer',
                'description' => 'The fastest way to work Market Intelligence: start from a buyer on your books, let CoreX surface the tracked properties that match them, then claim the best lead.',
                'route'       => 'market-intelligence.work',
                'setup'       => [['action' => 'scrollTop']],
                'steps' => [
                    [
                        'element' => '[data-tour="mic-tabs"]',
                        'section' => 'Picking a buyer',
                        'title'   => 'Your prospecting command centre',
                        'body'    => 'This is Market Intelligence. The fastest way to work it is buyer-led — start from a real buyer and let CoreX bring you the stock that fits them. Let\'s walk it.',
                    ],
                    [
                        // Read-only: its header button only collapses the panel.
                        'element' => '[data-tour="mic-prospect-buyer"]',
                        'title'   => 'Start with a buyer',
                        'body'    => 'Instead of scrolling all stock, open "Prospect for buyer". CoreX will narrow the whole list to the tracked properties that match a buyer\'s wishlist — strongest first.',
                    ],
                    [
                        'element' => '[data-tour="mic-buyer-scope"]',
                        'do'      => ['action' => 'click', 'say' => 'Click My buyers, My branch or Whole company to choose whose buyers you pick from.'],
                        'title'   => 'Whose buyers?',
                        'body'    => 'Choose the pool: My buyers, My branch, or Whole company. Same matching engine — it only changes which buyers you can pick from. (Company-wide shows for managers and owners.)',
                    ],
                    [
                        'element' => '[data-tour="mic-buyer-select"]',
                        'do'      => ['action' => 'fill', 'say' => 'Type part of your buyer\'s name to find them.'],
                        'title'   => 'Pick the buyer',
                        'body'    => 'Search and select a buyer. The list instantly re-ranks to the stock that best matches their wishlist, using the same Core Matches score you already trust — no re-scoring, no waiting.',
                    ],
                    [
                        'element'       => '[data-tour="mic-buyer-list"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click the buyer you\'re prospecting for.'],
                        'title'         => 'Choose your buyer',
                        'body'          => 'Pick them from this list. The page reloads with only the stock that matches this buyer, strongest match first.',
                    ],
                    [
                        'element' => '[data-tour="mic-by-region"]',
                        'section' => 'Narrowing the area',
                        'do'      => ['action' => 'click', 'target' => 'div[x-show]', 'say' => 'Click a region to focus on it — or press Skip this step.'],
                        'title'   => 'Narrow by region',
                        'body'    => 'Optionally focus on a region (e.g. Hibiscus Coast). Regions come from your prospecting towns, so you work the buyer\'s matches in the area you actually cover.',
                    ],
                    [
                        'element' => '[data-tour="mic-by-town"]',
                        'do'      => ['action' => 'click', 'target' => 'div[x-show]', 'say' => 'Click a suburb to narrow the list further — or press Skip this step.'],
                        'title'   => '…or drill to a town',
                        'body'    => 'Go finer with a specific town. Buyer, region and town stack — so in two clicks you get to exactly "this buyer\'s matches, in this town".',
                    ],
                    [
                        'element' => '[data-tour="mic-list"]',
                        'title'   => 'Your matched tracked properties',
                        'body'    => 'Each row is a tracked property that matches the buyer — its address (or "Address pending"), suburb, and match strength. The best leads sit at the top.',
                    ],
                    [
                        'element' => '[data-tour="mic-claim"]',
                        'section' => 'Claiming a lead',
                        'do'      => ['action' => 'click', 'target' => 'button', 'say' => 'Click Claim to reserve this lead for later — or press Skip this step to pitch it now.'],
                        'title'   => 'Claim your lead',
                        'body'    => 'Found the one? Claim it to reserve it as yours before another agent does. A claim is time-boxed — work it, or it releases back to the pool.',
                    ],
                    [
                        'element' => '[data-tour="mic-list"]',
                        'section' => 'Pitching the owner',
                        'title'   => 'Pitch the owner',
                        'body'    => 'This is where a lead becomes a conversation. Open any property and hit "Pitch" — CoreX drops in the Prospecting Introduction (Sales & Rentals) template, ready to send to the owner by email or WhatsApp. It introduces you and asks for a short call about marketing their property, and the STOP / opt-out consent is handled for you. Buyer → match → claim → pitch: that\'s the full loop.',
                    ],
                    [
                        'element'       => '[data-tour="mic-listings"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click a property row to open its details.', 'until' => '[data-tour="mic-slideover"]'],
                        'title'         => 'Open the property',
                        'body'          => 'Clicking a row slides its details in from the right — owner, matched buyers, activity and the actions you can take.',
                    ],
                    [
                        // Lives in the slide-over body, which loads once the panel opens.
                        'element'       => '[data-tour="mic-pitch"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Pitch — this opens your pitch to the owner; nothing is sent until you send it there.'],
                        'title'         => 'Start the pitch',
                        'body'          => 'Pitch opens the introduction message for this owner, ready to check and send by email or WhatsApp.',
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
                        'section' => 'Sending online FICA',
                        'title'   => 'FICA compliance',
                        'body'    => 'FICA is the law that says you must verify who your clients are. This screen lists every verification you\'ve started and whether it\'s done.',
                    ],
                    [
                        'element' => '[data-tour="fica-online"]',
                        'do'      => ['action' => 'click', 'say' => 'Click Send Online FICA to open the request form — nothing is sent yet.'],
                        'title'   => 'Send Online FICA',
                        'body'    => 'The easy way: CoreX emails your client a secure link to upload their ID and proof of address themselves. You\'ll see it tick over to verified here — no paper.',
                    ],
                    [
                        'element' => '[data-tour="fica-wetink"]',
                        'section' => 'Capturing in person',
                        'do'      => ['action' => 'click', 'say' => 'Click Create Wet-Ink FICA to capture documents your client gave you in person.'],
                        'title'   => 'Wet-ink FICA',
                        'body'    => 'For a client who hands you physical documents, capture them here instead. Same record, just done in person.',
                    ],
                    [
                        'element' => '[data-tour="fica-rmcp"]',
                        'section' => 'Your risk programme',
                        'do'      => ['action' => 'click', 'say' => 'Click View RMCP to open your agency\'s risk programme.'],
                        'title'   => 'Your risk programme',
                        'body'    => 'View RMCP opens your agency\'s Risk Management & Compliance Programme — the rulebook for how thoroughly each client must be checked. Good to know it\'s there; you rarely need it day to day.',
                    ],
                    [
                        'element'       => '[data-tour="fica-tabs"]',
                        'section'       => 'Tracking requests',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click a status, e.g. Awaiting Client, to see only those requests.'],
                        'title'         => 'Where each one stands',
                        'body'          => 'Each tab is a stage — waiting on the client, waiting for your review, approved, needing corrections. The number shows how many are in each.',
                    ],
                    [
                        'element'       => '[data-tour="fica-search"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type the client\'s name or email.'],
                        'title'         => 'Find a client',
                        'body'          => 'Looking for one person\'s FICA? Search by their name or email.',
                    ],
                    [
                        'element'       => '[data-tour="fica-search-btn"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Search.'],
                        'title'         => 'Run the search',
                        'body'          => 'The list reloads showing only the matching requests.',
                    ],
                    [
                        'element'       => '[data-tour="fica-table"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click View (or Verify) on a request to open it.'],
                        'title'         => 'Open a request',
                        'body'          => 'Opening a request shows the client\'s documents, what\'s still missing and what to do next.',
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
                        'section' => 'Starting a deal',
                        'title'   => 'Your deals',
                        'body'    => 'Every transaction you\'re running, from the moment an offer is signed through to registration at the deeds office. One row per deal, with its current stage.',
                    ],
                    [
                        'element' => '[data-tour="deals-new"]',
                        'do'      => ['action' => 'click', 'say' => 'Click New Deal to start capturing an accepted offer — or press Skip this step.'],
                        'title'   => 'Start a new deal',
                        'body'    => 'Got an accepted offer? Create the deal here. CoreX pulls in the property, the buyer and seller, and the commission — then walks the deal through each stage.',
                    ],
                    [
                        'element' => '[data-tour="deals-filter"]',
                        'section' => 'Finding a deal',
                        'do'      => ['action' => 'fill', 'say' => 'Type a deal reference or property, then click or tab out of the box.'],
                        'title'   => 'Find a deal fast',
                        'body'    => 'Search or filter to jump to a specific deal or stage. Close this and open a deal to see its full timeline.',
                    ],
                    [
                        'element'       => '[data-tour="deals-status"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Pick a status, e.g. Active, to narrow the list.'],
                        'title'         => 'Filter by status',
                        'body'          => 'The list updates as soon as you pick — Active, Completed, On Hold and so on.',
                    ],
                    [
                        'element'       => '[data-tour="deals-table"]',
                        'section'       => 'Opening a deal',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click a deal to open its full timeline.'],
                        'title'         => 'Open a deal',
                        'body'          => 'Each row is one deal. Click it to see every stage, party, document and payment in one place.',
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
                        'section' => 'Filtering reports',
                        'title'   => 'Team feedback',
                        'body'    => 'Anything the team has flagged — a bug, an idea, a request — lands here so nothing gets lost. A shared to-do list for improving CoreX.',
                    ],
                    [
                        'element' => '[data-tour="feedback-filter"]',
                        'do'      => ['action' => 'click', 'target' => '[data-tour="feedback-status"]', 'say' => 'Click a status, e.g. New, to narrow the list.'],
                        'title'   => 'Filter by status',
                        'body'    => 'See what\'s new, being looked at, fixed or parked. Click a status to narrow the list.',
                    ],
                    [
                        'element'       => '[data-tour="feedback-search"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type a word from the report\'s title or description.'],
                        'title'         => 'Search reports',
                        'body'          => 'Looking for a particular report? Search its title or description.',
                    ],
                    [
                        'element'       => '[data-tour="feedback-search-btn"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Search.'],
                        'title'         => 'Run the search',
                        'body'          => 'The list reloads showing only the matching reports.',
                    ],
                    [
                        'element' => '[data-tour="feedback-export"]',
                        'section' => 'Exporting the list',
                        'do'      => ['action' => 'click', 'say' => 'Click Export CSV, MD or JSON to download the list.'],
                        'title'   => 'Export if you need it',
                        'body'    => 'Download the list as a file to share or keep. That\'s it — close this and have a look.',
                    ],
                    [
                        'element'       => '[data-tour="feedback-table"]',
                        'section'       => 'Opening a report',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click a report to open it.'],
                        'title'         => 'Open a report',
                        'body'          => 'Each row is one report. Open it to read the full details, see any screenshots and update its status.',
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
                        'section' => 'Getting around',
                        'title'   => 'Your calendar cockpit',
                        'body'    => 'Everything on one screen: the calendar fills the middle, your agenda-and-details panel is on the right, and a deck of handy tiles sits along the bottom. Nothing scrolls the whole page — each part scrolls on its own.',
                    ],
                    [
                        'element' => '[data-tour="cal-views"]',
                        'do'      => ['action' => 'click', 'say' => 'Click Month, Week or Day to change how much you see.'],
                        'title'   => 'Month, Week or Day',
                        'body'    => 'Switch how much you see here. Month scrolls smoothly downwards week after week; Week scrolls sideways through the days and up and down through the hours. The label at the top always tells you where you are.',
                    ],
                    [
                        'element' => '[data-tour="cal-today"]',
                        'do'      => ['action' => 'click', 'say' => 'Click Today to jump back to now.'],
                        'title'   => 'Back to Today',
                        'body'    => 'Scrolled off into next month? One tap on Today snaps you straight back to now — wherever you\'ve wandered.',
                    ],
                    [
                        'element' => '[data-tour="cal-layers"]',
                        'section' => 'Choosing what shows',
                        'do'      => ['action' => 'click', 'target' => 'button', 'say' => 'Click Layers to open the list.', 'until' => '[data-tour="cal-layers-menu"]'],
                        'skip_if' => '[data-tour="cal-layers-menu"]',
                        'title'   => 'Layers — what shows on the calendar',
                        'body'    => 'Tick and untick the kinds of events you want ON the calendar — viewings, deals, compliance, personal and more. This only changes the calendar. Your deck tiles are separate: untick To-dos here and the To-dos tile still shows them.',
                    ],
                    [
                        'element'       => '[data-tour="cal-layers-menu"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Tick or untick a layer — the calendar updates straight away.'],
                        'title'         => 'Pick your layers',
                        'body'          => 'Each tick is one kind of event. All and None at the bottom switch everything on or off in one go.',
                    ],
                    [
                        'element' => '[data-tour="cal-panel"]',
                        'section' => 'Adding an event',
                        'title'   => 'The right panel',
                        'body'    => 'By default this shows your agenda — today and what\'s coming up. Click any event to see its full details here, and the Add Event button opens the new-event form right in this panel, without leaving the page.',
                    ],
                    [
                        'element' => '[data-tour="cal-add"]',
                        'do'      => ['action' => 'click', 'say' => 'Click Add Event to open the new-event form.', 'until' => '[data-tour="cal-event-form"]'],
                        'skip_if' => '[data-tour="cal-event-form"]',
                        'title'   => 'Add an event — and get reminded',
                        'body'    => 'Book a viewing or meeting and link it to a contact and property. Set a reminder while you\'re there: choose how long before — say an hour — and a popup will find you anywhere in CoreX (even while you\'re busy loading a property), or send you an email.',
                    ],
                    [
                        'element'       => '[data-tour="cal-event-title"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type a short title, e.g. "Viewing — 12 Marine Drive".'],
                        'title'         => 'Title',
                        'body'          => 'Required. This is what shows on the calendar, so keep it short and clear.',
                    ],
                    [
                        'element'       => '[data-tour="cal-event-type"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Pick the type of event, e.g. Viewing or Meeting.'],
                        'title'         => 'Type',
                        'body'          => 'Required. The type sets the event\'s colour and whether CoreX asks you for feedback afterwards.',
                    ],
                    [
                        'element'       => '[data-tour="cal-event-start"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Pick the start time — and change the date if it isn\'t right.'],
                        'title'         => 'When',
                        'body'          => 'Required. The day you clicked (or today) is filled in for you; the end time follows the start automatically.',
                    ],
                    [
                        'element'       => '[data-tour="cal-event-properties"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'appear', 'say' => 'Search for the property and click it in the list — or press Skip this step.', 'until' => '[data-tour="cal-event-property-chip"]', 'more' => true],
                        'title'         => 'Link a property',
                        'body'          => 'Type part of the address or suburb, then pick it from the list. You can link more than one property to the same event.',
                    ],
                    [
                        'element'       => '[data-tour="cal-event-attendees"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'appear', 'say' => 'Search for a contact and click them to add them — or press Skip this step.', 'until' => '[data-tour="cal-event-attendee-chip"]', 'more' => true],
                        'title'         => 'Who\'s coming',
                        'body'          => 'Add the buyer, seller or colleague you\'re meeting, so everyone involved is on the event.',
                    ],
                    [
                        'element'       => '[data-tour="cal-event-reminder"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Switch the reminder on if you want one — or press Skip this step.'],
                        'title'         => 'Get reminded',
                        'body'          => 'Turn it on, choose how long before, and pick a popup, an email or both.',
                    ],
                    [
                        'element'       => '[data-tour="cal-event-save"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Create Event.'],
                        'title'         => 'Save the event',
                        'body'          => 'Your event goes onto the calendar straight away, linked to the property and people you picked.',
                    ],
                    [
                        'element' => '[data-tour="cal-deck"]',
                        'section' => 'Arranging your deck',
                        'title'   => 'Your tile deck',
                        'body'    => 'The tiles along the bottom are yours to arrange. Edit Deck picks which tiles show; drag the divider up or down to give the calendar or the deck more room; drag between tiles to set their widths; collapse the deck or the panel; or go full-calendar for the biggest grid.',
                    ],
                    [
                        'element'       => '[data-tour="cal-deck-edit"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Edit Deck.'],
                        'title'         => 'Edit your deck',
                        'body'          => 'Edit mode lets you add tiles with "+ Add tile" and take away the ones you don\'t use.',
                    ],
                    [
                        'element'       => '[data-tour="cal-deck-edit"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Add or remove tiles, then click Done.'],
                        'title'         => 'Finish editing',
                        'body'          => 'Done closes edit mode and keeps your tiles as you\'ve set them.',
                    ],
                    [
                        'element' => '[data-tour="cal-saveview"]',
                        'do'      => ['action' => 'click', 'say' => 'Click Save default to keep this layout as your own.'],
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
                        'section' => 'Adding a task',
                        'title'   => 'Your to-do list',
                        'body'    => 'Every follow-up and to-do in one place, with what\'s open, overdue and due today right here at the top so you always know where you stand.',
                    ],
                    [
                        'element' => '[data-tour="task-add"]',
                        'do'      => ['action' => 'click', 'say' => 'Click New Task.', 'until' => '[data-tour="task-form"]'],
                        'skip_if' => '[data-tour="task-form"]',
                        'title'   => 'Add a task',
                        'body'    => 'Capture a follow-up in seconds — give it a title, pick the type and priority, set a due date, and tick the reminder so CoreX nudges you when it\'s due.',
                    ],
                    [
                        'element'       => '[data-tour="task-title"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type what needs doing, e.g. "Call seller about offer".'],
                        'title'         => 'What needs doing',
                        'body'          => 'Required. Keep it short and action-first so it reads well on your board.',
                    ],
                    [
                        'element'       => '[data-tour="task-priority"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Pick a priority — or press Skip this step if Normal is fine.'],
                        'title'         => 'Priority',
                        'body'          => 'High and Critical tasks stand out on the board so they get done first.',
                    ],
                    [
                        'element'       => '[data-tour="task-due"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Pick the day it\'s due.'],
                        'title'         => 'Due date',
                        'body'          => 'Set a due date and the task lands in Today, This Week or Overdue at the right time — with a reminder, if you leave that ticked.',
                    ],
                    [
                        'element'       => '[data-tour="task-save"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Create Task.'],
                        'title'         => 'Save the task',
                        'body'          => 'Your task goes onto your list straight away.',
                    ],
                    [
                        'element' => '[data-tour="task-view"]',
                        'section' => 'Board or list',
                        'do'      => ['action' => 'click', 'say' => 'Click Board or List to choose how your tasks are shown.'],
                        'title'   => 'Board or list',
                        'body'    => 'Board groups tasks by status you can drag between; List is a simple checklist. Your pick — close this and clear your first task.',
                    ],
                    [
                        'element'       => '[data-tour="task-search"]',
                        'section'       => 'Finding a task',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type a word from the task, or a contact or address.'],
                        'title'         => 'Search your tasks',
                        'body'          => 'The list narrows as you type — it searches the task title and the contact or property it\'s linked to.',
                    ],
                    [
                        'element'       => '[data-tour="task-filters"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Click Overdue, Today or This Week to see only those tasks.'],
                        'title'         => 'Filter by when it\'s due',
                        'body'          => 'The chips narrow the list by due date, priority or area. Click a chip again to switch it off.',
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
                        'section' => 'Uploading a document',
                        'title'   => 'Shared documents',
                        'body'    => 'Your agency\'s shared files — brochures, forms, anything the team reuses — kept in one place so you\'re not hunting through email.',
                    ],
                    [
                        // The first box in the upload panel is the Document Type picker.
                        'element' => '#upload-library',
                        'do'      => ['action' => 'fill', 'say' => 'Pick the document type.'],
                        'title'   => 'Upload a document',
                        'body'    => 'Pick a type, choose the file and upload. Tagging it by type is what makes it easy to find later.',
                    ],
                    [
                        'element'       => '[data-tour="docs-upload-title"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type a clear title — or press Skip this step to use the file name.'],
                        'title'         => 'Give it a title',
                        'body'          => 'Optional. A clear title like "Sole Mandate — 2026" makes it easier for the team to find than a file name.',
                    ],
                    [
                        'element'       => '[data-tour="docs-upload-file"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Choose the file from your computer.'],
                        'title'         => 'Choose the file',
                        'body'          => 'Required. Pick the document you want to share with the team.',
                    ],
                    [
                        'element'       => '[data-tour="docs-upload-btn"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Upload.'],
                        'title'         => 'Upload it',
                        'body'          => 'The document is added to the library, ready for anyone on the team to use.',
                    ],
                    [
                        'element' => '[data-tour="docs-filter"]',
                        'section' => 'Finding a document',
                        'do'      => ['action' => 'fill', 'say' => 'Type part of the document\'s name or title.'],
                        'title'   => 'Find one fast',
                        'body'    => 'Filter by type to narrow the list. Close this and upload or open a document.',
                    ],
                    [
                        'element'       => '[data-tour="docs-filter-type"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Pick a document type — or press Skip this step for all types.'],
                        'title'         => 'Narrow by type',
                        'body'          => 'Only show one kind of document, e.g. mandates or brochures.',
                    ],
                    [
                        'element'       => '[data-tour="docs-filter-apply"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Apply Filters.'],
                        'title'         => 'Apply',
                        'body'          => 'The list on the right reloads showing only the matching documents.',
                    ],
                    [
                        'element'       => '[data-tour="docs-table"]',
                        'section'       => 'Getting a copy',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click Download next to the document you need.'],
                        'title'         => 'Download a document',
                        'body'          => 'Download saves a copy to your computer, ready to print, email or attach.',
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
                // Each Next posts and lands on the step route — the same builder,
                // so the "?" and a running guide carry on through all six screens.
                'also_on'     => ['docuperfect.esign.step'],
                // Advanced/Spot steps below only WATCH the agent use the real
                // Next button — still no setup or prep. Each screen's steps sit
                // inside that screen's box, so they skip themselves on other
                // screens; each "Next" step skips itself unless its own screen
                // is the one showing (the same button is reused on all six).
                'steps' => [
                    [
                        'element' => '[data-tour="esign-title"]',
                        'section' => 'Getting started',
                        'do'      => ['action' => 'fill', 'say' => 'Type a name for this document — or press Skip this step and CoreX will name it for you.'],
                        'title'   => 'The e-sign builder',
                        'body'    => 'This is where you send a document out for signature online — no printing, no scanning. Give it a name here so you recognise it later.',
                    ],
                    [
                        'element' => '[data-tour="esign-rail"]',
                        'title'   => 'Six guided steps',
                        'body'    => 'Pick the template, attach the property, add who must sign (the recipients), fill the details, place the signature fields, then review and send. The bar shows where you are; the Next and Back buttons in the wizard move you along — work through them in order.',
                    ],
                    // ── Screen 1 · Template ──
                    [
                        'element'       => '[data-tour="esign-template-search"]',
                        'section'       => 'Choosing the template',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Type part of the template\'s name to find it — or press Skip this step.'],
                        'title'         => 'Find the template',
                        'body'          => 'The list narrows as you type. The Sales and Rentals buttons above it filter the list too.',
                    ],
                    [
                        'element'       => '[data-tour="esign-screen-template"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'appear', 'say' => 'Click the template you want to send.', 'until' => '[data-tour="esign-next"]:not([disabled])'],
                        'title'         => 'Pick the template',
                        'body'          => 'Open a group and click the document you need, e.g. a mandate or an offer to purchase. The one you pick is outlined in blue.',
                    ],
                    [
                        'element'       => '[data-tour="esign-next"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Next.', 'until' => '[data-tour="esign-screen-property"]'],
                        'skip_if'       => '[data-tour="esign-screen-property"], [data-tour="esign-screen-recipients"], [data-tour="esign-screen-details"], [data-tour="esign-screen-review"], [data-tour="esign-screen-send"]',
                        'title'         => 'On to the property',
                        'body'          => 'CoreX saves your choice as a draft and moves you to the property screen.',
                    ],
                    // ── Screen 2 · Property ──
                    [
                        'element'       => '[data-tour="esign-property-search"]',
                        'section'       => 'Adding the property',
                        'advanced_only' => true,
                        'do'            => ['action' => 'appear', 'say' => 'Type the address, suburb or erf number and click the property in the list.', 'until' => '[data-tour="esign-property-selected"]'],
                        'title'         => 'Attach the property',
                        'body'          => 'Linking the property fills its address into the document. A green badge confirms the property you picked.',
                    ],
                    [
                        'element'       => '[data-tour="esign-next"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Next.', 'until' => '[data-tour="esign-screen-recipients"]'],
                        'skip_if'       => '[data-tour="esign-screen-template"], [data-tour="esign-screen-recipients"], [data-tour="esign-screen-details"], [data-tour="esign-screen-review"], [data-tour="esign-screen-send"]',
                        'title'         => 'On to the signers',
                        'body'          => 'Next saves the property and takes you to the people who must sign.',
                    ],
                    // ── Screen 3 · Recipients ──
                    [
                        'element'       => '[data-tour="esign-recipients"]',
                        'section'       => 'Adding the signers',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Pick each signer\'s role, e.g. Seller or Buyer.'],
                        'title'         => 'Who signs, and as what',
                        'body'          => 'You are always the first signer. Every other person needs a role that matches the document, or Next stays locked.',
                    ],
                    [
                        'element'       => '[data-tour="esign-recipients"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'appear', 'say' => 'Search for the signer\'s name and click them in the list.', 'until' => '[data-tour="esign-recipient-linked"]', 'more' => true],
                        'title'         => 'Link the contact',
                        'body'          => 'Linking the contact brings in their details, so their name and email are correct on the document. A green Linked badge shows it worked.',
                    ],
                    [
                        'element'       => '[data-tour="esign-next"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Next.', 'until' => '[data-tour="esign-screen-details"]'],
                        'skip_if'       => '[data-tour="esign-screen-template"], [data-tour="esign-screen-property"], [data-tour="esign-screen-details"], [data-tour="esign-screen-review"], [data-tour="esign-screen-send"]',
                        'title'         => 'On to the details',
                        'body'          => 'Next saves your signers and moves you to the deal details.',
                    ],
                    // ── Screen 4 · Details ──
                    [
                        'element'       => '[data-tour="esign-screen-details"]',
                        'section'       => 'Checking the details',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Fill in the price or rental amount — or press Skip this step if it\'s already right.'],
                        'title'         => 'The deal details',
                        'body'          => 'Price, commission, dates and any extra boxes this template needs. Details CoreX already knows from the property are filled in for you — just check them.',
                    ],
                    [
                        'element'       => '[data-tour="esign-next"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Next.', 'until' => '[data-tour="esign-screen-review"]'],
                        'skip_if'       => '[data-tour="esign-screen-template"], [data-tour="esign-screen-property"], [data-tour="esign-screen-recipients"], [data-tour="esign-screen-review"], [data-tour="esign-screen-send"]',
                        'title'         => 'On to fill & review',
                        'body'          => 'Next saves the details and shows you the document itself.',
                    ],
                    // ── Screen 5 · Fill & Review ──
                    [
                        'element'       => '[data-tour="esign-screen-review"]',
                        'section'       => 'Filling in the document',
                        'advanced_only' => true,
                        'do'            => ['action' => 'fill', 'say' => 'Fill in any empty boxes — or press Skip this step if everything is already filled in.'],
                        'title'         => 'Fill & review',
                        'body'          => 'Every box in the document is listed here in order, next to a live preview. Boxes marked for another party are left for them to fill in when they sign.',
                    ],
                    [
                        'element'       => '[data-tour="esign-next"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Next → Signing Setup.', 'until' => '[data-tour="esign-screen-send"]'],
                        'skip_if'       => '[data-tour="esign-screen-template"], [data-tour="esign-screen-property"], [data-tour="esign-screen-recipients"], [data-tour="esign-screen-details"], [data-tour="esign-screen-send"]',
                        'title'         => 'On to signing setup',
                        'body'          => 'Next saves the document and takes you to the last screen, where you choose how it gets signed.',
                    ],
                    // ── Screen 6 · Sign & Send ──
                    [
                        'element'       => '[data-tour="esign-screen-send"]',
                        'section'       => 'Sending for signature',
                        'advanced_only' => true,
                        'do'            => ['action' => 'choose', 'say' => 'Pick E-Signature, Wet Ink or Download Only — or press Skip this step if only one is offered.'],
                        'title'         => 'How it gets signed',
                        'body'          => 'Check the signing order and each signer\'s email address here. Signers are emailed one after the other, in this order.',
                    ],
                    [
                        'element'       => '[data-tour="esign-next"]',
                        'advanced_only' => true,
                        'do'            => ['action' => 'click', 'say' => 'Click Sign Document — CoreX creates the document and you sign first; with E-Signature it is then emailed to the other signers in this order.'],
                        'skip_if'       => '[data-tour="esign-screen-template"], [data-tour="esign-screen-property"], [data-tour="esign-screen-recipients"], [data-tour="esign-screen-details"], [data-tour="esign-screen-review"]',
                        'title'         => 'Sign and send',
                        'body'          => 'This is the step that sends. You sign first; with E-Signature, each signer then gets the document by email, one after the other. Wet Ink sends each party a secure link to print, sign and upload; Download Only just gives you the PDF.',
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
            if (($tour['route'] ?? null) === $routeName
                || in_array($routeName, $tour['also_on'] ?? [], true)) {
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

        // A handful of screens are gated purely in-controller by role (no permission
        // key exists to check, e.g. RcrSubmissionController::assertCompliance()) — a
        // tour def declares that here rather than leaving canOpenRoute() to guess at
        // a middleware that was never applied. See defs/compliance-b.php's 'comp-rcr'.
        $roles = $tour['roles'] ?? null;
        if ($roles && ! (is_string($user->role ?? null) && in_array($user->role, $roles, true)) && empty($user->is_admin)) {
            return false;
        }

        $permission = $tour['permission'] ?? null;
        if (! $permission) {
            return true; // inherit the route's own gate
        }

        return method_exists($user, 'hasPermission')
            && $user->hasPermission($permission) === true;
    }

    // ── Advanced Guiding + Spot Help (spec: .ai/specs/advanced-guiding.md) ──

    /** Pending/active guide state older than this is dropped (client mirrors it). */
    public const GUIDE_TTL_MINUTES = 30;

    /**
     * The tour's steps with every `section` resolved (inherited forward from
     * the last step that declared one), so no consumer re-implements that rule.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolvedSteps(array $tour): array
    {
        $current = null;
        $out = [];
        foreach (array_values($tour['steps'] ?? []) as $step) {
            if (! empty($step['section'])) {
                $current = (string) $step['section'];
            }
            if ($current !== null) {
                $step['section'] = $current;
            }
            $out[] = $step;
        }

        return $out;
    }

    /**
     * Ordered, de-duplicated section names of a tour.
     *
     * @return array<int, string>
     */
    public static function sections(array $tour): array
    {
        $names = [];
        foreach (static::resolvedSteps($tour) as $step) {
            $name = $step['section'] ?? null;
            if ($name !== null && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** Has this tour been written for hands-on guiding (any `do` or `section`)? */
    public static function supportsAdvanced(?array $tour): bool
    {
        if (! $tour || empty($tour['steps'])) {
            return false;
        }
        foreach ($tour['steps'] as $step) {
            if (! empty($step['do']) || ! empty($step['section'])) {
                return true;
            }
        }

        return false;
    }

    /** Spot Help is offered only when a page has at least two sections to choose from. */
    public static function supportsSpotHelp(?array $tour): bool
    {
        return static::supportsAdvanced($tour) && count(static::sections($tour)) >= 2;
    }

    /**
     * The tour as the browser engine receives it: resolved sections plus the
     * menu capabilities, so the client never re-derives either.
     */
    public static function forClient(array $tour): array
    {
        $tour['steps']    = static::resolvedSteps($tour);
        $tour['sections'] = static::sections($tour);
        $tour['advanced'] = static::supportsAdvanced($tour);
        $tour['spot']     = static::supportsSpotHelp($tour);

        return $tour;
    }

    /**
     * Where to send an agent to run this tour in a given mode, or null when it
     * cannot be launched from outside its page.
     *
     * mode: 'tour' | 'advanced' | 'spot' (spot requires a known $section).
     * A route that needs a record (e.g. one property) sends the agent to the
     * tour's `pick_from` list with a note; the guide then starts itself on the
     * record page they open (tour-engine handoff).
     *
     * @return array{url:string, pick:bool, note:?string}|null
     */
    public static function launchTarget(array $tour, string $mode = 'tour', ?string $section = null): ?array
    {
        $routeName = $tour['route'] ?? null;
        if (! $routeName || ! \Illuminate\Support\Facades\Route::has($routeName)) {
            return null;
        }
        if ($mode !== 'tour' && ! static::supportsAdvanced($tour)) {
            return null;
        }
        if ($mode === 'spot' && ($section === null || ! in_array($section, static::sections($tour), true))) {
            return null;
        }

        $query = $mode === 'tour'
            ? ['tour' => $tour['key']]
            : array_filter(['guide' => $tour['key'], 'mode' => $mode, 'section' => $mode === 'spot' ? $section : null]);

        // A screen that needs a record (declared by pick_from) is always reached
        // via its pick list — even when its route resolves without parameters,
        // as a query-bound screen (e.g. ?pack_instance=) would just bounce.
        if (empty($tour['pick_from'])) {
            try {
                return [
                    'url'  => route($routeName, [], false) . '?' . http_build_query($query),
                    'pick' => false,
                    'note' => null,
                ];
            } catch (\Throwable $e) {
                return null; // needs a record, and no pick list to find one
            }
        }

        $pickRoute = $tour['pick_from'];
        if (! $pickRoute || ! \Illuminate\Support\Facades\Route::has($pickRoute)) {
            return null;
        }

        try {
            $note = (string) ($tour['pick_note'] ?? 'Open the record you want to work on and the guide will pick up from there.');

            return [
                'url'  => route($pickRoute, [], false) . '?' . http_build_query($query + ['pick' => 1]),
                'pick' => true,
                'note' => $note,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Can this user open the tour's screen at all? The tour's own gate
     * (visibleTo) PLUS every `permission:<key>` / `owner_only` middleware on its
     * route — and, for record pages, on its `pick_from` list. Used wherever a
     * tour is offered away from its own page (Guided Tours directory, Ellie's
     * guide buttons), since those never pass through the route's middleware.
     */
    public static function canOpen(?array $tour, $user): bool
    {
        if (! static::visibleTo($tour, $user)) {
            return false;
        }
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return true;
        }

        return static::canOpenRoute($tour['route'] ?? null, $user)
            && (empty($tour['pick_from']) || static::canOpenRoute($tour['pick_from'], $user));
    }

    /**
     * Replays a route's own access middleware for this user. A route with no
     * (resolvable) definition or no gate middleware is treated as open — the
     * route is then its own gate and there is none to fail.
     */
    public static function canOpenRoute(?string $routeName, $user): bool
    {
        if (! $routeName) {
            return true;
        }
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);
        if (! $route) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if (str_starts_with($middleware, 'permission:')) {
                // 'permission:deals_v2.view' → 'deals_v2.view' (ignore trailing args).
                $key = strtok(substr($middleware, strlen('permission:')), ',');
                if (! (method_exists($user, 'hasPermission') && $user->hasPermission($key) === true)) {
                    return false;
                }
            } elseif ($middleware === 'owner_only') {
                if (! (method_exists($user, 'isOwnerRole') && $user->isOwnerRole())) {
                    return false;
                }
            } elseif ($middleware === 'deny_assistant') {
                // AT-267 — an agent-personal surface an assistant must never reach,
                // whatever their permission matrix says. Mirrors DenyAssistant::handle().
                if (! empty($user->is_assistant)) {
                    return false;
                }
            } elseif ($middleware === 'deny_assistant_property_write') {
                // Mirrors DenyAssistantPropertyWrite::handle(): an assistant is only
                // let through this route name if it's on the explicit allow list —
                // everything else is a write surface they can never complete.
                if (! empty($user->is_assistant)
                    && ! in_array($routeName, \App\Http\Middleware\DenyAssistantPropertyWrite::assistantMayRouteNames(), true)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Pick-list handoffs, keyed by tour key, for the always-on handoff script:
     * lets the list page show the right note without knowing the tour.
     *
     * @return array<string, array{route:string, note:string}>
     */
    public static function pickNotes(): array
    {
        $out = [];
        foreach (static::all() as $key => $tour) {
            if (! empty($tour['pick_from'])) {
                $out[$key] = [
                    'route' => (string) $tour['route'],
                    'note'  => (string) ($tour['pick_note'] ?? 'Open the record you want to work on and the guide will pick up from there.'),
                ];
            }
        }

        return $out;
    }
}
