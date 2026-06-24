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
 */
class TourRegistry
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            // ── Contact capture ──────────────────────────────────────────────
            'contact-capture' => [
                'key'   => 'contact-capture',
                'title' => 'How to capture a contact',
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

            // ── Presentations / CMA — start a new presentation (queue #1) ────
            // Entry screen presentations.create (single form). Sets the
            // expectation that evidence upload + analysis happen on the NEXT
            // screen. Field anchors use scoped [name="…"] selectors on the form.
            'presentation-create' => [
                'key'   => 'presentation-create',
                'title' => 'Starting a CMA / presentation',
                'route' => 'presentations.create',
                'setup' => [
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => 'form [name="title"]',
                        'title'   => 'Name the presentation',
                        'body'    => 'A label just for you to find it later — e.g. "21 Dee Road — Seller Presentation". This isn\'t shown to the seller.',
                    ],
                    [
                        'element' => 'form [name="property_address"]',
                        'title'   => 'Property address',
                        'body'    => 'Required. The street address of the home you\'re pitching to list. It anchors the whole CMA.',
                    ],
                    [
                        'element' => 'form [name="suburb"]',
                        'title'   => 'Suburb',
                        'body'    => 'Required. CoreX pulls comparable sales from this suburb to value the property — so get it right.',
                    ],
                    [
                        'element' => 'form [name="property_type"]',
                        'title'   => 'Property type',
                        'body'    => 'House, flat, townhouse… The CMA compares like with like, so a flat is valued against flats, not freestanding houses.',
                    ],
                    [
                        'element' => 'form [name="asking_price_inc"]',
                        'title'   => 'Asking price',
                        'body'    => 'Required. The price the seller has in mind (or your opening estimate). The analysis will test it against the real market and suggest a realistic range.',
                    ],
                    [
                        'element' => 'form [name="bedrooms"]',
                        'title'   => 'Beds, baths & size',
                        'body'    => 'Fill in the basics — beds, baths, erf and floor size, garages. The closer these match the real home, the sharper the comparable-sales match.',
                    ],
                    [
                        'element' => '[data-tour="pres-submit"]',
                        'title'   => 'Create — then the clever part',
                        'body'    => 'This saves the property details and takes you to the next screen, where you upload the comparable-sales evidence and run the analysis that builds the seller\'s valuation. That\'s the start — close this and create your first presentation.',
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
