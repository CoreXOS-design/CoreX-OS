<?php

/**
 * Guided-tour definitions — Real Estate core screens (AT-41 full-coverage pass).
 *
 * Each entry is pure DATA merged by App\Support\Tours\TourRegistry::all() from
 * every file in app/Support/Tours/defs/*.php. Keys are globally unique and
 * namespaced (`re-…`) so a later file never clobbers an earlier one.
 *
 * Every `element` selector is a dedicated data-tour="…" anchor added to the real
 * DOM of the screen's Blade view — so a Tailwind/markup refactor never silently
 * breaks a step, and the engine deterministically skips any step whose anchor is
 * absent on the current render (e.g. data-dependent rows on an empty screen).
 *
 * Permission keys mirror the sidebar @permission(...) gate for each route:
 *   Properties + Map → access_properties (Map shares the Properties key)
 *   Core Matches     → access_core_matches
 *   Portal Leads     → access_portal_leads
 *   Commercial Evals → access_commercial_evaluations
 */

return [

    // ── Properties — the agency-stock list (My Listings) ─────────────────────
    // NB: a separate tour covers the New Property wizard. This one is about the
    // LIST screen — finding, filtering, reading statuses, opening a listing.
    're-properties' => [
        'key'         => 're-properties',
        'title'       => 'Finding & filtering your listings',
        'description' => 'Search, filter and read your property list — statuses, the KPI tiles, and switching grid or list view.',
        'route'       => 'corex.properties.index',
        'permission'  => 'access_properties',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="re-properties-intro"]',
                'title'   => 'Your listings live here',
                'body'    => 'This is your agency stock — every formal mandate you and the team are working. Use the New Property button up top to add a listing; this tour is about finding and managing the ones already here.',
            ],
            [
                'element' => '[data-tour="re-properties-kpis"]',
                'title'   => 'The numbers at a glance',
                'body'    => 'A quick count of your stock by status: Total, Active (live), Draft (not finished), Sold, and Published (synced to the website and portals). Click any tile to filter the list down to just those listings.',
            ],
            [
                'element' => '[data-tour="re-properties-search"]',
                'title'   => 'Search fast',
                'body'    => 'Type a title, suburb, or a Property24 reference to jump straight to a listing. It searches as you go — no need to scroll the whole list.',
            ],
            [
                'element' => '[data-tour="re-properties-status"]',
                'title'   => 'Filter by status',
                'body'    => 'Narrow the list to Active, Draft, Sold or Withdrawn. Withdrawn means the mandate ended without a sale — it stays on record but is off the market.',
            ],
            [
                'element' => '[data-tour="re-properties-viewtoggle"]',
                'title'   => 'Grid or list',
                'body'    => 'Switch between picture cards (grid) and a compact table (list). CoreX remembers which you prefer for next time.',
            ],
            [
                'element' => '[data-tour="re-properties-list"]',
                'title'   => 'Open a listing',
                'body'    => 'Each card is one property. Click it to open the full record — photos, price, the owner, documents and the deal it belongs to. Close this and open one to see how it all connects.',
            ],
        ],
    ],

    // ── Map — spatial view of stock, sold comps and prospects ────────────────
    're-map' => [
        'key'         => 're-map',
        'title'       => 'Reading the property map',
        'description' => 'See your stock, sold comparables and prospecting candidates on a map — switch layers, base maps and the seller-safe view.',
        'route'       => 'corex.map.index',
        'permission'  => 'access_properties',
        'steps' => [
            [
                'element' => '[data-tour="re-map-intro"]',
                'title'   => 'Your area, on a map',
                'body'    => 'A spatial picture of the property data CoreX holds — your own listings, sold comparables, and prospecting candidates — all pinned where they actually are. Great for spotting clusters and pricing a new mandate.',
            ],
            [
                'element' => '[data-tour="re-map-layers"]',
                'title'   => 'Turn layers on and off',
                'body'    => 'Each icon is a type of pin: HFC Listings (your stock), Sold Comps (recent sales), Portal Stock (competitor listings from Property24 and Private Property), and more. Click one to show or hide that layer so the map stays readable.',
            ],
            [
                'element' => '[data-tour="re-map-viewmode"]',
                'title'   => 'Seller view keeps you safe',
                'body'    => 'Seller View hides owner and contact details, so you can turn your screen to a seller without exposing anyone\'s private information — that keeps you on the right side of POPIA. Agent View (where you have access) shows the full picture.',
            ],
            [
                'element' => '[data-tour="re-map-baselayer"]',
                'title'   => 'Streets or satellite',
                'body'    => 'Swap the background between a clean street map and a satellite photo. Satellite is handy for showing a seller the actual plot and surroundings.',
            ],
            [
                'element' => '[data-tour="re-map-search"]',
                'title'   => 'Jump to a place',
                'body'    => 'Search an address, a sectional-title scheme, or an agent to recentre the map. Close this and have a look around your patch.',
            ],
        ],
    ],

    // ── Core Matches — buyer ↔ property matching ─────────────────────────────
    're-core-matches' => [
        'key'         => 're-core-matches',
        'title'       => 'Matching buyers to property',
        'description' => 'See every buyer/renter search you\'ve saved against a contact, and how many properties match each one right now.',
        'route'       => 'corex.core-matches.index',
        'permission'  => 'access_core_matches',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="re-core-matches-intro"]',
                'title'   => 'Buyers, matched to stock',
                'body'    => 'When you save what a buyer or renter is looking for on their contact, it appears here as a saved search. CoreX then keeps an eye on every property and tells you which ones fit — so you stop hunting manually.',
            ],
            [
                'element' => '[data-tour="re-core-matches-card"]',
                'title'   => 'One contact, their searches',
                'body'    => 'Each block is a buyer or renter, with every search criteria you\'ve saved for them underneath — price range, suburb, property type, beds and so on.',
            ],
            [
                'element' => '[data-tour="re-core-matches-counts"]',
                'title'   => 'What "visible" and "hidden" mean',
                'body'    => 'The chips show how many properties match. "Visible" are the ones the client can see; "hidden" are matches you\'ve chosen to keep back from this buyer. The total counts every match, shown or not.',
            ],
            [
                'element' => '[data-tour="re-core-matches-view"]',
                'title'   => 'Open the matches',
                'body'    => 'Click View Matches to see the actual properties that fit this search — ready to send to the buyer. Close this and open one of your buyers to see who you can move on today.',
            ],
        ],
    ],

    // ── Portal Leads — enquiries from Property24 / Private Property ───────────
    're-portal-leads' => [
        'key'         => 're-portal-leads',
        'title'       => 'Handling portal leads',
        'description' => 'Read and filter buyer enquiries that came in from Property24 and Private Property, and see if each is a new contact or one you already have.',
        'route'       => 'corex.portal-leads.index',
        'permission'  => 'access_portal_leads',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="re-portal-leads-intro"]',
                'title'   => 'Leads that came to you',
                'body'    => 'Every buyer enquiry that arrives from Property24 (P24) and Private Property (PP) lands here automatically — name, contact details, the listing they asked about and their message. No copying from email.',
            ],
            [
                'element' => '[data-tour="re-portal-leads-filters"]',
                'title'   => 'Narrow it down',
                'body'    => 'Filter by portal, a date range, the agent, or status — then press Apply. Useful when you only want to action today\'s P24 enquiries.',
            ],
            [
                'element' => '[data-tour="re-portal-leads-status"]',
                'title'   => 'New or already known?',
                'body'    => '"New Contact" means this person isn\'t in CoreX yet — capture them so they\'re not lost. "Already Exists" means they\'re a contact you (or a colleague) already have, so you can pick up the conversation.',
            ],
            [
                'element' => '[data-tour="re-portal-leads-table"]',
                'title'   => 'Work the list',
                'body'    => 'Each row is one enquiry. The Property column links straight to the listing they asked about. Close this and follow up your freshest lead while it\'s still warm.',
            ],
        ],
    ],

    // ── Commercial Evaluations — commercial/industrial/agri valuations ───────
    're-commercial-evals' => [
        'key'         => 're-commercial-evals',
        'title'       => 'Commercial market evaluations',
        'description' => 'Track valuations for commercial, industrial, hospitality and agricultural properties, and start a new one.',
        'route'       => 'commercial-evaluations.index',
        'permission'  => 'access_commercial_evaluations',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="re-commercial-evals-intro"]',
                'title'   => 'For the bigger properties',
                'body'    => 'Residential CMAs don\'t fit a warehouse, a guesthouse or a farm. This is where you build a proper market evaluation for commercial, industrial, hospitality and agricultural property.',
            ],
            [
                'element' => '[data-tour="re-commercial-evals-new"]',
                'title'   => 'Start a new evaluation',
                'body'    => 'Click New Evaluation to capture the property and work through its valuation — CoreX gives you a recommended price range you can take to the owner.',
            ],
            [
                'element' => '[data-tour="re-commercial-evals-filter"]',
                'title'   => 'Active vs archived',
                'body'    => 'Switch between the evaluations you\'re still working (Active) and ones you\'ve put away (Archived). Archived stays on record — nothing is ever deleted — and can be restored.',
            ],
            [
                'element' => '[data-tour="re-commercial-evals-list"]',
                'title'   => 'Open an evaluation',
                'body'    => 'Each row shows the property, its type, the asking price and CoreX\'s recommended range. Click View to open it, or Edit to keep refining. Close this and start one for your next commercial mandate.',
            ],
        ],
    ],

];
