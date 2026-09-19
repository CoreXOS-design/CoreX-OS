<?php

/**
 * Guided-tour definition — the PROPERTY PAGE (corex.properties.show).
 *
 * Pure DATA merged by App\Support\Tours\TourRegistry::all() from every file in
 * app/Support/Tours/defs/*.php. The key is namespaced (`re-…`) like the other
 * Real Estate core tours so it never clobbers another file's entry.
 *
 * "Edit property" redirects to this page, so this IS where an agent edits a
 * listing: details, spaces, features, the AI photo scan, the mandate, Save,
 * photos and linking the owner. Every `element` / `until` / `skip_if` selector
 * is a dedicated data-tour="prop-…" anchor on
 * resources/views/corex/properties/show.blade.php.
 *
 * The page is tabbed (Alpine `activeTab`). Each tab job starts with an
 * advanced_only "open the tab" click (skipped when the tab is already open);
 * the first explanation step of each tab job carries a `prep` that dispatches
 * `corex:switch-tab` so the Guided Tour can reach it.
 *
 * Multi-pick jobs (room features, photo tags) highlight the whole panel and
 * move on when the agent clicks the panel's own finishing button — a step
 * that highlights only one chip would block the next pick behind the overlay.
 *
 * The route needs one property in the URL, so Advanced Guide / Spot Help
 * started elsewhere sends the agent to My Listings first (`pick_from`).
 */

// Visible only when the property has NO AI photo suggestions waiting: the
// suggestions button's wrapper is x-show'd off, and the Spaces row after it is
// always on screen. Lets the AI steps skip at once instead of waiting for a box
// that will never appear.
$noAiSuggestions = '[data-tour="prop-ai-wrap"][style*="display: none"] ~ [data-tour="prop-spaces"]';

return [

    're-property-page' => [
        'key'         => 're-property-page',
        'title'       => 'Working on a property',
        'description' => 'Edit a listing on its own page — details, spaces and features, the AI photo scan, mandate, photos and linking the owner.',
        'route'       => 'corex.properties.show',
        'permission'  => 'access_properties',
        'pick_from'   => 'corex.properties.index',
        'pick_note'   => 'Open the property you want to work on and I\'ll pick up from there.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [

            // ── Property details (Info tab) ──────────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-info"]',
                'section'       => 'Property details',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Info tab.', 'until' => '[data-tour="prop-info-title"]'],
                'skip_if'       => '[data-tour="prop-info-title"]',
                'title'         => 'Open the Info tab',
                'body'          => 'The Info tab holds the listing\'s details — title, price, rooms, features and the mandate.',
            ],
            [
                'element' => '[data-tour="prop-info-title"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'info']],
                'do'      => ['action' => 'fill', 'say' => 'Type the listing title — or press Skip this step if it\'s already right.'],
                'title'   => 'Working on a property',
                'body'    => 'This is the property\'s own page — where you edit the listing. Most of the work happens here on the Info tab. Start with the title: the short headline buyers see on your website and the portals.',
            ],
            [
                'element' => '[data-tour="prop-info-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the property type.'],
                'title'   => 'Property type',
                'body'    => 'House, flat, townhouse, vacant land… It drives buyer matching and how the listing maps onto Property24 and Private Property.',
            ],
            [
                'element' => '[data-tour="prop-info-price"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the price in Rands.'],
                'title'   => 'Price',
                'body'    => 'The asking price (or monthly rent) in Rands. The small cog next to it opens extra pricing options, like Price on Application.',
            ],
            [
                'element' => '[data-tour="prop-info-description"]',
                'do'      => ['action' => 'fill', 'say' => 'Type or improve the description buyers will read.'],
                'title'   => 'Description',
                'body'    => 'The full write-up buyers read on the website and portals. Sell the lifestyle — views, light, layout, outdoor space.',
            ],
            [
                'element' => '[data-tour="prop-address-internal"]',
                'do'      => ['action' => 'click', 'say' => 'Click the Internal address row.', 'until' => '[data-tour="prop-address-modal"]'],
                'title'   => 'The address',
                'body'    => 'Internal is the real address, for your office. Public is what buyers see on the portals — there you can hide the street or unit number.',
            ],
            [
                'element'       => '[data-tour="prop-address-modal"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Fill in the street and suburb, then click Done.', 'target' => '[data-tour="prop-address-done"]'],
                'title'         => 'Complete the address',
                'body'          => 'Add the street, complex and unit if there is one. The suburb must be picked from Property24\'s list so the listing maps cleanly to the portals.',
            ],

            // ── Adding spaces ────────────────────────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-info"]',
                'section'       => 'Adding spaces',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Info tab.', 'until' => '[data-tour="prop-spaces"]'],
                'skip_if'       => '[data-tour="prop-spaces"]',
                'title'         => 'Open the Info tab',
                'body'          => 'Spaces live on the Info tab, under Property Details.',
            ],
            [
                'element' => '[data-tour="prop-space-add"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'info']],
                'do'      => ['action' => 'click', 'say' => 'Click the Add tile at the end of the row.', 'until' => '[data-tour="prop-space-add-modal"]'],
                'title'   => 'Adding a space',
                'body'    => 'Spaces are the rooms and areas of the property — bedrooms, bathrooms, garages, a pool. Each tile shows one space and how many there are; the bedroom, bathroom and garage counts become the listing\'s headline stats. The Add tile adds another.',
            ],
            [
                'element'       => '[data-tour="prop-space-types"]',
                'advanced_only' => true,
                'do'            => ['action' => 'appear', 'say' => 'Click the space you want to add — pick one not marked Added.', 'until' => '[data-tour="prop-space-tile"]', 'more' => true],
                'title'         => 'Pick the space',
                'body'          => 'Choose what you\'re adding — a study, a scullery, a pool. Spaces already on the property show "Added ✓". Your new space opens straight away so you can set it up.',
            ],
            [
                'element'       => '[data-tour="prop-space-modal"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Set how many with + or −, then click Done.', 'target' => '[data-tour="prop-space-done"]'],
                'title'         => 'How many?',
                'body'          => 'Set how many of this space the property has. Bathrooms can take a half — a guest toilet — with the ½ Toggle. Done adds it to the row.',
            ],

            // ── Room features (per-space) ────────────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-info"]',
                'section'       => 'Room features',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Info tab.', 'until' => '[data-tour="prop-spaces"]'],
                'skip_if'       => '[data-tour="prop-spaces"]',
                'title'         => 'Open the Info tab',
                'body'          => 'Room features are set on each space tile, on the Info tab.',
            ],
            [
                'element' => '[data-tour="prop-space-tiles"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'info']],
                'do'      => ['action' => 'choose', 'say' => 'Click the space you want to describe, e.g. Bedroom.', 'until' => '[data-tour="prop-space-modal"]'],
                'skip_if' => '[data-tour="prop-space-modal"]',
                'title'   => 'Describe a room',
                'body'    => 'Click any space tile to open it. Inside you set the count and add features — to all rooms of that type, or to one room only, like an en-suite on Bedroom 1.',
            ],
            [
                'element'       => '[data-tour="prop-space-add-feature"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click + Add Feature.', 'until' => '[data-tour="prop-space-feature-picker"]'],
                'title'         => 'Add room features',
                'body'          => 'This adds features to every room of this type. For one room only, use + Feature next to that room further down.',
            ],
            [
                'element'       => '[data-tour="prop-space-modal"]',
                'advanced_only' => true,
                'do'            => ['action' => 'appear', 'say' => 'Click each feature these rooms have, then click Back.', 'until' => '[data-tour="prop-space-features-all"]'],
                'title'         => 'Pick the features',
                'body'          => 'Click a feature to switch it on; click it again to switch it off. Back takes you to the room with your features listed.',
            ],
            [
                'element'       => '[data-tour="prop-space-description"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Add a short description of these rooms — or press Skip this step.'],
                'title'         => 'Room description (optional)',
                'body'          => 'A line about these rooms, e.g. "Spacious bedrooms with sea views". Optional.',
            ],
            [
                'element'       => '[data-tour="prop-space-done"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Done to close this space.'],
                'title'         => 'Close the space',
                'body'          => 'Your features stay on the space and show in the Feature Summary. They\'re kept when you save the property.',
            ],

            // ── Property features (property-wide) ────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-info"]',
                'section'       => 'Property features',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Info tab.', 'until' => '[data-tour="prop-features-box"]'],
                'skip_if'       => '[data-tour="prop-features-box"]',
                'title'         => 'Open the Info tab',
                'body'          => 'Property-wide features are on the Info tab, under the spaces.',
            ],
            [
                'element' => '[data-tour="prop-features-box"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'info']],
                'do'      => ['action' => 'choose', 'say' => 'Click a category, then a feature the property has.', 'target' => '[data-tour="prop-feature-chips"]'],
                'title'   => 'Property-wide features',
                'body'    => 'Features for the whole property — pool, security, fibre, sea view. Pick a category tab (Outdoor, Security, Connectivity…), then click each feature the property has. Click it again to switch it off.',
            ],
            [
                // The whole Spaces & Features area, so the agent can keep picking
                // features while reading this step.
                'element' => '[data-tour="prop-spaces-features"]',
                'title'   => 'Feature Summary',
                'body'    => 'Keep picking until you have them all — every feature from the rooms and the categories collects in the Feature Summary at the bottom, and goes to Property24, Private Property and your website. Click a feature in the summary to remove it.',
            ],

            // ── AI photo scan ────────────────────────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-info"]',
                'section'       => 'AI photo scan',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Info tab.', 'until' => '[data-tour="prop-spaces"]'],
                'skip_if'       => '[data-tour="prop-spaces"]',
                'title'         => 'Open the Info tab',
                'body'          => 'The AI photo suggestions sit above the spaces on the Info tab.',
            ],
            [
                'element' => '[data-tour="prop-ai-btn"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'info']],
                'do'      => ['action' => 'click', 'say' => 'Click AI photo suggestions.', 'until' => '[data-tour="prop-ai-modal"]'],
                'skip_if' => '[data-tour="prop-ai-modal"], ' . $noAiSuggestions,
                'title'   => 'AI photo scan',
                'body'    => 'When photos are uploaded, CoreX\'s AI looks at them and suggests the spaces and features it spotted. This button shows only while there are suggestions to review — they also pop up by themselves when you open the property.',
            ],
            [
                'element'       => '[data-tour="prop-ai-modal"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click ✓ on each suggestion that\'s right and ✕ on the rest, then click Done.', 'target' => '[data-tour="prop-ai-done"]'],
                'skip_if'       => $noAiSuggestions,
                'title'         => 'Accept or drop',
                'body'          => '✓ on a space adds it; ✓ on a feature adds it to the property, or drag it onto a space to add it to that room. Anything you leave is dropped. If you deal with every suggestion the window closes by itself — then press Skip this step.',
            ],

            // ── Mandate & agent ──────────────────────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-info"]',
                'section'       => 'Mandate & agent',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Info tab.', 'until' => '[data-tour="prop-status"]'],
                'skip_if'       => '[data-tour="prop-status"]',
                'title'         => 'Open the Info tab',
                'body'          => 'The mandate and the listing agent are at the bottom of the Info tab.',
            ],
            [
                'element' => '[data-tour="prop-status"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'info']],
                'do'      => ['action' => 'choose', 'say' => 'Pick the listing\'s status.'],
                'title'   => 'Status',
                'body'    => 'Where the listing stands — Draft, Active (on the market), Sold and so on. The banner next to it (Reduced Price and the like) only works on an Active listing.',
            ],
            [
                'element' => '[data-tour="prop-mandate-type"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the mandate type.'],
                'title'   => 'Mandate type',
                'body'    => 'Sole, Open or Dual — as signed with the seller.',
            ],
            [
                'element' => '[data-tour="prop-expiry"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the date the mandate ends.'],
                'title'   => 'Mandate expiry',
                'body'    => 'When the mandate runs out. Click the box for quick 3, 6 or 12-month buttons.',
            ],
            [
                'element' => '[data-tour="prop-agent"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick the listing\'s main agent.'],
                'title'   => 'Primary agent',
                'body'    => 'The agent whose name, photo and contact details go on the listing and the portals.',
            ],

            // ── Saving (header button — works from any tab) ──────────────────
            [
                'element' => '[data-tour="prop-save"]',
                'section' => 'Saving your changes',
                'do'      => ['action' => 'click', 'say' => 'Click Save Changes to keep everything you\'ve changed.'],
                'title'   => 'Save your changes',
                'body'    => 'Nothing on the Info tab is kept until you click Save Changes up here — it works from any tab. If something is missing, CoreX lists exactly what to fix.',
            ],

            // ── Photos (Gallery tab) ─────────────────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-gallery"]',
                'section'       => 'Photos',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Gallery tab.', 'until' => '[data-tour="prop-gallery-upload"]'],
                'skip_if'       => '[data-tour="prop-gallery-upload"]',
                'title'         => 'Open the Gallery tab',
                'body'          => 'The listing\'s photos live on the Gallery tab.',
            ],
            [
                'element' => '[data-tour="prop-gallery-upload"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'gallery']],
                'do'      => ['action' => 'appear', 'say' => 'Click here and choose the photos to add.', 'until' => '[data-tour="prop-gallery-upload-btn"]'],
                'title'   => 'Adding photos',
                'body'    => 'Pick as many photos as you like in one go. Save any Info changes first — uploading reloads the page.',
            ],
            [
                'element'       => '[data-tour="prop-gallery-upload-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Upload Images — the page reloads with your new photos.'],
                'title'         => 'Upload them',
                'body'          => 'Your photos go up straight away. The first photo is the cover — drag photos in the gallery to change the order.',
            ],

            // ── Tagging photos ───────────────────────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-gallery"]',
                'section'       => 'Tagging photos',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Gallery tab.', 'until' => '[data-tour="prop-gallery-tag-btn"]'],
                'skip_if'       => '[data-tour="prop-gallery-tag-btn"]',
                'title'         => 'Open the Gallery tab',
                'body'          => 'Photo tags are set on the Gallery tab.',
            ],
            [
                'element' => '[data-tour="prop-gallery-tag-btn"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'gallery']],
                'do'      => ['action' => 'click', 'say' => 'Click Tag Images.', 'until' => '[data-tour="prop-gallery-tagbar"]'],
                'skip_if' => '[data-tour="prop-gallery-empty"]',
                'title'   => 'Tag your photos',
                'body'    => 'Tags say which room each photo shows — Kitchen, Lounge, Bedroom. They group the gallery and become the photo captions on the portals.',
            ],
            [
                'element'       => '[data-tour="prop-gallery"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click a tag, then each photo of that room — repeat per room, then click Done Tagging.', 'target' => '[data-tour="prop-gallery-tag-btn"]'],
                'skip_if'       => '[data-tour="prop-gallery-empty"]',
                'title'         => 'Tag room by room',
                'body'          => 'Pick a tag in the bar, then click every photo of that room — each one saves as you click it. Pick the next tag and carry on.',
            ],

            // ── Linking the owner (Contacts tab) ─────────────────────────────
            [
                'element'       => '[data-tour="prop-tab-contacts"]',
                'section'       => 'Linking the owner',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the Contacts tab.', 'until' => '[data-tour="prop-contacts-link"]'],
                'skip_if'       => '[data-tour="prop-contacts-link"]',
                'title'         => 'Open the Contacts tab',
                'body'          => 'The people linked to this property — seller, owner, landlord, buyer — are on the Contacts tab.',
            ],
            [
                'element' => '[data-tour="prop-contacts-link"]',
                'prep'    => [['action' => 'dispatch', 'event' => 'corex:switch-tab', 'detail' => 'contacts']],
                'do'      => ['action' => 'fill', 'say' => 'Type the owner\'s name, phone number or email.', 'until' => '[data-tour="prop-contacts-results"]'],
                'title'   => 'Link the owner',
                'body'    => 'Every listing needs its seller or landlord linked — it drives the compliance checks and FICA. Search for them here. Someone new? Use "Create new contact & link" below instead.',
            ],
            [
                'element'       => '[data-tour="prop-contacts-results"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Click the right person in the list.', 'until' => '[data-tour="prop-contacts-confirm"]'],
                'title'         => 'Pick the person',
                'body'          => 'Choosing someone doesn\'t link them yet — you confirm their role first.',
            ],
            [
                'element'       => '[data-tour="prop-contacts-role"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick their role — or press Skip this step if it\'s already right.'],
                'title'         => 'Their role',
                'body'          => 'Seller, Owner, Landlord, Buyer… Sellers, owners and landlords drive the compliance checks and FICA.',
            ],
            [
                'element'       => '[data-tour="prop-contacts-link-btn"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click Link — they\'re linked to this property straight away.'],
                'title'         => 'Link them',
                'body'          => 'The page refreshes with them on the list. That\'s the whole property page — you\'re ready to work any listing.',
            ],
        ],
    ],

];
