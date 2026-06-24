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
                        'element' => '[data-tour="contact-type"]',
                        'title'   => 'Contact type',
                        'body'    => 'The type (Seller, Buyer, Landlord, Tenant…) maps to an e-sign role and drives automation. No types yet? Add them in Settings → Feature Settings → Contacts.',
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

            // ── Property capture ─────────────────────────────────────────────
            // Anchored against the live "New Property" workspace
            // (corex.properties.create → corex/properties/show.blade.php, Info tab).
            // Standard fields use scoped [name="…"] selectors inside the capture
            // form (#prop-update-form); a few section/tab targets use dedicated
            // data-tour / data-prop-tab anchors.
            'property-capture' => [
                'key'   => 'property-capture',
                'title' => 'How to capture a property',
                'route' => 'corex.properties.create',
                'setup' => [
                    ['action' => 'scrollTop'],
                ],
                'steps' => [
                    [
                        'element' => '#prop-update-form [name="title"]',
                        'title'   => 'Listing title',
                        'body'    => 'Required. A short headline buyers see in search — e.g. "Stunning 4 Bed House in Uvongo". This is a marketing line, not the street address.',
                    ],
                    [
                        'element' => '#prop-update-form [name="property_type"]',
                        'title'   => 'Property type',
                        'body'    => 'House, flat, townhouse, sectional title, vacant land… This drives buyer matching and how the listing maps onto Property24 and Private Property.',
                    ],
                    [
                        'element' => '#prop-update-form [name="listing_type"]',
                        'title'   => 'Sale or rental?',
                        'body'    => 'Choose For Sale or For Rental. This unlocks the right fields (e.g. monthly rental and lease dates) and is locked after the first save — duplicate the listing to change it.',
                    ],
                    [
                        'element' => '#prop-update-form [name="price"]',
                        'title'   => 'Price',
                        'body'    => 'Required. The asking price in Rands (e.g. 2 500 000). Rates &amp; taxes, levy and special levy sit alongside it for a complete cost picture.',
                    ],
                    [
                        'element' => '[data-tour="prop-spaces"]',
                        'title'   => 'Spaces',
                        'body'    => 'Tap the +/- counters to set bedrooms, bathrooms, garages and more. These power buyer matching and the portal feeds — get them right.',
                    ],
                    [
                        'element' => '[data-tour="prop-location"]',
                        'title'   => 'Province · City · Suburb',
                        'body'    => 'Type to search — these are backed by Property24\'s official list. You must pick a suburb P24 recognises (no free text), so the listing maps cleanly to the portals.',
                    ],
                    [
                        'element' => '#prop-update-form [name="description"]',
                        'title'   => 'Description',
                        'body'    => 'The full description shown on the listing page. Sell the lifestyle, not just the bricks — this is what turns a view into an enquiry.',
                    ],
                    [
                        'element' => '#prop-update-form [name="mandate_type"]',
                        'title'   => 'Mandate type',
                        'body'    => 'Sole, Joint or Open — the mandate you hold on this property. It drives commission handling and compliance under the Property Practitioners Act.',
                    ],
                    [
                        'element' => '#prop-update-form [name="agent_id"]',
                        'title'   => 'Responsible agent',
                        'body'    => 'The agent who holds this mandate. Their FFC and commission settings flow through to any deal that comes off this listing.',
                    ],
                    [
                        'element' => '[data-prop-tab="gallery"]',
                        'title'   => 'Photos live in the Gallery tab',
                        'body'    => 'Switch to the Gallery tab to add images. On a new property they upload the moment you press Create Property.',
                    ],
                    [
                        'element' => '[data-tour="prop-submit"]',
                        'title'   => 'Create the listing',
                        'body'    => 'This creates the Property in your Agency Stock. If anything required is missing, CoreX tells you exactly what — and takes you straight to it.',
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
}
