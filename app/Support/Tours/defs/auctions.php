<?php

/**
 * Guided-tour definitions — AUCTIONS (AT-432), advertising-only mode (the default).
 *
 * Pure DATA merged by App\Support\Tours\TourRegistry::all() from every file in
 * app/Support/Tours/defs/*.php. Keys are namespaced `au-…`. Every `element` /
 * `until` / `skip_if` selector is a dedicated data-tour="au-…" (or aset-… / lot-…
 * / prop-auction-…) anchor on the auction views; a removed anchor is caught by
 * tests/Feature/Tours/TourRegistryIntegrityTest.
 *
 * One definition drives all three modes: the click-through Guided Tour, the
 * hands-on Advanced Guide (the `do` steps) and Spot Help (the `section`s).
 * Steps whose anchor is not on screen (e.g. Publish when nothing is publishable,
 * Sale Room tools when the agency is advertising-only) are skipped by the engine.
 *
 * Screens that need a record (an auction, a lot) declare `pick_from` so an
 * outside launch goes via the Auction Diary first.
 */

return [

    // ── The Auction Diary (list) ─────────────────────────────────────────────
    'au-diary' => [
        'key'         => 'au-diary',
        'title'       => 'Finding your auctions',
        'description' => 'The Auction Diary — search, filter and sort every auction, then open one or create a new one.',
        'route'       => 'corex.auctions.index',
        'permission'  => 'access_auctions',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="au-diary-intro"]',
                'section' => 'Reading the diary',
                'title'   => 'Your Auction Diary',
                'body'    => 'Every auction your agency advertises or runs is listed here, with its date, venue, bidding type, number of lots and status. Think of it as the calendar of all your sale days.',
            ],
            [
                'element' => '[data-tour="au-search"]',
                'section' => 'Finding an auction',
                'do'      => ['action' => 'fill', 'say' => 'Type part of a reference, title, venue or auctioneer name, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type a reference, title, venue or auctioneer and the list narrows to match. Clear the box to see everything again.',
            ],
            [
                'element' => '[data-tour="au-status"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a status to narrow the list.'],
                'title'   => 'Filter by status',
                'body'    => 'Draft auctions are still being prepared and are not public. Once you publish the catalogue the auction moves on from draft. Pick a status to see only those auctions.',
            ],
            [
                'element'       => '[data-tour="au-bidding-filter"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick a bidding mode — or press Skip this step.'],
                'title'         => 'Filter by bidding mode',
                'body'          => 'In-room, Online or Hybrid. Most advertised auctions are run by an outside auction house, so this matters most when CoreX runs the sale itself.',
            ],
            [
                'element' => '[data-tour="au-dates"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick a From date — or press Skip this step.', 'target' => 'input[type="date"]'],
                'title'   => 'Filter by auction date',
                'body'    => 'Choose a From and To date to see only auctions in that window — handy for "what is coming up this month".',
            ],
            [
                'element' => '[data-tour="au-sort"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick how you want the list sorted.'],
                'title'   => 'Sort the list',
                'body'    => 'Sort by auction date (the default), reference, title, status or when it was created, then choose oldest-first or newest-first.',
            ],
            [
                'element'       => '[data-tour="au-clear"]',
                'advanced_only' => true,
                'title'         => 'Start again',
                'body'          => 'Clear removes every filter in one go and shows the whole diary again. CoreX remembers your last filters, so use this when the list looks shorter than you expect.',
            ],
            [
                'element' => '[data-tour="au-list"]',
                'section' => 'Opening an auction',
                'do'      => ['action' => 'click', 'say' => 'Click Open on an auction.', 'target' => '[data-tour="au-open"]'],
                'title'   => 'Open an auction',
                'body'    => 'Each row is one auction. Open it to see all its details, attach properties as lots, check the documents and publish the advert.',
            ],
            [
                'element' => '[data-tour="au-new-btn"]',
                'section' => 'Creating an auction',
                'title'   => 'Start a new auction',
                'body'    => 'New Auction opens the form where you set the date, venue and auctioneer. After saving, you add properties as lots and publish. You are ready — close this and create your first auction.',
            ],
        ],
    ],

    // ── New / Edit auction form ──────────────────────────────────────────────
    'au-create' => [
        'key'         => 'au-create',
        'title'       => 'Setting up an auction',
        'description' => 'Create or edit an auction — reference, auctioneer, date and time, registration window, venue, the public advert details and the legal PDFs.',
        'route'       => 'corex.auctions.create',
        'also_on'     => ['corex.auctions.edit'],
        'permission'  => 'auctions.create',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="au-form-intro"]',
                'section' => 'The basics',
                'title'   => 'One auction, one form',
                'body'    => 'An auction is one sale day. Fill in who is running it, when and where. You add the properties (lots) on the next screen, once the auction is saved.',
            ],
            [
                'element' => '[data-tour="au-reference"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a short reference, e.g. AUC-2026-014.'],
                'title'   => 'Reference',
                'body'    => 'Required. A short code your team will recognise — it appears in the diary and on reports. Keep it unique.',
            ],
            [
                'element' => '[data-tour="au-title"]',
                'do'      => ['action' => 'fill', 'say' => 'Type a title for the auction.'],
                'title'   => 'Title',
                'body'    => 'Required. The public name of the sale day, shown on the advert — for example "South Coast Property Auction, October".',
            ],
            [
                'element'       => '[data-tour="au-bidding"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick the bidding mode.'],
                'title'         => 'Bidding mode',
                'body'          => 'In-room, Online or Hybrid. Only the modes enabled in Settings → Auctions appear here.',
            ],
            [
                'element' => '[data-tour="au-auctioneer"]',
                'section' => 'Who runs it',
                'do'      => ['action' => 'choose', 'say' => 'Pick Our auctioneer or Outside auction house.'],
                'title'   => 'The auctioneer',
                'body'    => 'Required. Choose one of your own auctioneers, or an outside auction house. For an outside house, add their company name and licence number — buyers see these on the public advert, and publishing is blocked without them.',
            ],
            [
                'element' => '[data-tour="au-date"]',
                'section' => 'When and where',
                'do'      => ['action' => 'fill', 'say' => 'Pick the auction date from the calendar.', 'target' => 'input[type="date"]'],
                'title'   => 'Auction date and time',
                'body'    => 'Required. Pick the day on the calendar, then the start time next to it. Both are needed.',
            ],
            [
                'element'       => '[data-tour="au-date"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Pick the start time.', 'target' => 'input[type="time"]'],
                'title'         => 'Start time',
                'body'          => 'The time the auction starts, in 24-hour format.',
            ],
            [
                'element' => '[data-tour="au-reg-open"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick when registration opens — or press Skip this step.', 'target' => 'input[type="date"]'],
                'title'   => 'Registration opens',
                'body'    => 'Optional. When buyers can start registering to bid. If you leave the time empty it counts from midnight.',
            ],
            [
                'element' => '[data-tour="au-reg-close"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick when registration closes — or press Skip this step.', 'target' => 'input[type="date"]'],
                'title'   => 'Registration closes',
                'body'    => 'Optional but useful: buyers who enquired get a reminder email within 24 hours of this deadline.',
            ],
            [
                'element' => '[data-tour="au-venue-name"]',
                'do'      => ['action' => 'fill', 'say' => 'Type the venue name — or press Skip this step for an online sale.'],
                'title'   => 'Venue',
                'body'    => 'Where the auction takes place — a hotel, hall or your office. Leave it empty for an online-only sale.',
            ],
            [
                'element'       => '[data-tour="au-venue-addr"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type the venue address — or press Skip this step.'],
                'title'         => 'Venue address',
                'body'          => 'The street address buyers will drive to. It shows on the public advert.',
            ],
            [
                'element' => '[data-tour="au-advert"]',
                'section' => 'The public advert',
                'title'   => 'How buyers reach the auctioneer',
                'body'    => 'This block feeds the public auction page. Buyers see the auctioneer\'s phone, email and register-to-bid link, and can download the legal documents. CoreX does not take bids in advertising mode — it sends buyers to the auctioneer.',
            ],
            [
                'element' => '[data-tour="au-reg-url"]',
                'do'      => ['action' => 'fill', 'say' => 'Paste the auctioneer\'s register-to-bid link — or press Skip this step.'],
                'title'   => 'Register-to-bid link',
                'body'    => 'If the auction house takes registrations on their own website, paste that link. The "Register to bid" button on your advert then goes straight there.',
            ],
            [
                'element'       => '[data-tour="au-contact"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Type the auctioneer\'s phone number — or press Skip this step.'],
                'title'         => 'Auctioneer contact',
                'body'          => 'A phone and email the public can use. Publishing needs at least one for an outside auction house.',
            ],
            [
                'element' => '[data-tour="au-rules"]',
                'section' => 'Legal documents',
                'title'   => 'Rules of Auction (PDF)',
                'body'    => 'Upload the Rules of Auction as a PDF (up to 10 MB). It is stored privately, shown to buyers on the public advert, and you can view or download it from the auction page. The wording is your attorney\'s responsibility.',
            ],
            [
                'element' => '[data-tour="au-conditions"]',
                'title'   => 'Conditions of Sale (PDF)',
                'body'    => 'The same for the Conditions of Sale. Re-uploading replaces the current file.',
            ],
            [
                'element' => '[data-tour="au-save"]',
                'section' => 'Saving',
                'do'      => ['action' => 'click', 'say' => 'Click Create Auction (or Save).'],
                'title'   => 'Save',
                'body'    => 'Saving takes you to the auction page, where you attach properties as lots, check the documents and publish the advert.',
            ],
        ],
    ],

    // ── One auction (lots, documents, publish) ───────────────────────────────
    'au-detail' => [
        'key'         => 'au-detail',
        'title'       => 'Working an auction',
        'description' => 'Everything about one auction — its details and PDFs, attaching properties as lots, and publishing the advert.',
        'route'       => 'corex.auctions.show',
        'permission'  => 'access_auctions',
        'pick_from'   => 'corex.auctions.index',
        'pick_note'   => 'Open the auction you want to work on and I\'ll pick up from there.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="au-actions"]',
                'section' => 'The auction',
                'title'   => 'What you can do here',
                'body'    => 'The buttons change with the auction: View public page once it is published, Edit to change the details, and Publish Catalogue when it is ready to go live.',
            ],
            [
                'element' => '[data-tour="au-info"]',
                'title'   => 'All the details',
                'body'    => 'Date and venue, the auctioneer with licence, phone and email, the registration window, the register-to-bid link and whether the catalogue is published. Check it over before you publish.',
            ],
            [
                'element' => '[data-tour="au-docs"]',
                'section' => 'Documents',
                'title'   => 'Rules and Conditions PDFs',
                'body'    => 'The legal documents you uploaded on the Edit page. View opens the PDF in a new tab; Download saves it. You can check them here before the advert is public. Missing a file? Use Replace documents.',
            ],
            [
                'element' => '[data-tour="au-lots"]',
                'section' => 'Lots',
                'title'   => 'The lots',
                'body'    => 'Each property in this auction is a lot, with its own number, guide price and status. Click a property address to open it in a new tab, or open the lot to see its viewings and history.',
            ],
            [
                'element' => '[data-tour="au-attach-search"]',
                'section' => 'Attaching a property',
                'do'      => ['action' => 'fill', 'say' => 'Type part of the property\'s address or title.', 'until' => '[data-tour="au-attach-search"] button'],
                'title'   => 'Find the property',
                'body'    => 'Type at least two letters of the address or title. You only see properties you are allowed to see, and ones already in this auction are left out.',
            ],
            [
                'element'       => '[data-tour="au-attach-search"]',
                'advanced_only' => true,
                'do'            => ['action' => 'click', 'say' => 'Click the right property in the list.', 'target' => 'button', 'until' => '[data-tour="au-attach-picked"]'],
                'title'         => 'Pick it',
                'body'          => 'Click the property you mean. It then appears underneath the search box.',
            ],
            [
                'element' => '[data-tour="au-attach-picked"]',
                'title'   => 'Your chosen property',
                'body'    => 'The property you picked shows here. Changed your mind? Remove it and search again.',
            ],
            [
                'element' => '[data-tour="au-attach-prices"]',
                'do'      => ['action' => 'fill', 'say' => 'Type an opening bid — or press Skip this step.'],
                'title'   => 'Reserve, opening bid and guide price',
                'body'    => 'Reserve is the lowest price the seller will accept — enter 0 if there is no reserve, because buyers must be told. Opening bid and the guide price range are optional. Only people allowed to see reserves see that field.',
            ],
            [
                'element' => '[data-tour="au-attach-add"]',
                'do'      => ['action' => 'click', 'say' => 'Click Add Lot.'],
                'title'   => 'Add the lot',
                'body'    => 'Adding the lot also marks the property as On Auction. A property can only be a lot once in the same auction.',
            ],
            [
                'element' => '[data-tour="au-publish"]',
                'section' => 'Publishing',
                'title'   => 'Publish the catalogue',
                'body'    => 'Publishing makes the advert public. It is blocked, with plain reasons, until every property has a signed mandate and marketing is ready, every lot says whether it has a reserve, and the agent and auctioneer have valid licences. This button only shows when there are lots to publish.',
            ],
        ],
    ],

    // ── One lot ──────────────────────────────────────────────────────────────
    'au-lot' => [
        'key'         => 'au-lot',
        'title'       => 'Managing a lot',
        'description' => 'One lot in an auction — its prices and status, recording the result, viewings and history.',
        'route'       => 'corex.auctions.lots.show',
        'permission'  => 'access_auctions',
        'pick_from'   => 'corex.auctions.index',
        'pick_note'   => 'Open an auction, then open one of its lots, and I\'ll pick up from there.',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="lot-header"]',
                'section' => 'The lot',
                'title'   => 'One property, one lot',
                'body'    => 'The lot number and the property address — click the address to open the property in a new tab. The pill shows where the lot is in its life: Draft, On Auction, Sold, Passed in or Withdrawn.',
            ],
            [
                'element' => '[data-tour="lot-facts"]',
                'title'   => 'Prices at a glance',
                'body'    => 'The reserve (if you may see it), the guide price, and — after the sale — the sold price, when it happened and whether the reserve was met.',
            ],
            [
                'element' => '[data-tour="lot-record-result"]',
                'section' => 'Recording the result',
                'do'      => ['action' => 'choose', 'say' => 'Pick what happened: Sold, Passed in or Withdrawn.', 'target' => 'select'],
                'title'   => 'What happened at the sale?',
                'body'    => 'The auction itself is run elsewhere, so this is where you tell CoreX the outcome. Choose Sold (and type the price), Passed in, or Withdrawn, add a note if you like, then Record result. The public advert shows the result straight away, and a sale starts the deal.',
            ],
            [
                'element' => '[data-tour="lot-viewings"]',
                'section' => 'Viewings',
                'title'   => 'Viewings before the sale',
                'body'    => 'Open-house or by-appointment viewing windows for this lot. They show on the public lot page and the calendar.',
            ],
            [
                'element' => '[data-tour="lot-viewing-form"]',
                'do'      => ['action' => 'fill', 'say' => 'Pick the viewing start date.', 'target' => 'input[type="date"]'],
                'title'   => 'Add a viewing',
                'body'    => 'Pick the start and end (date and time), tick By appointment if it is not an open house, add a note if needed, and press Add Viewing.',
            ],
            [
                'element' => '[data-tour="lot-history"]',
                'section' => 'History',
                'title'   => 'Every change, on record',
                'body'    => 'Each status change is logged with who made it, when and why — useful if a result is ever questioned.',
            ],
        ],
    ],

    // ── Settings → Auctions ──────────────────────────────────────────────────
    'au-settings' => [
        'key'         => 'au-settings',
        'title'       => 'Setting up Auctions for your agency',
        'description' => 'Choose how your agency uses Auctions — advertising only or running the sale — plus auctioneers, bidding, fees, registration and reserve rules.',
        'route'       => 'corex.settings.auctions.show',
        'permission'  => 'auctions.manage_settings',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="aset-intro"]',
                'section' => 'Your Auctions setup',
                'title'   => 'Agency-wide auction rules',
                'body'    => 'These settings apply to every auction your agency creates. Most agencies only need the first two sections; the rest matter when CoreX runs the sale itself.',
            ],
            [
                'element' => '[data-tour="aset-mode"]',
                'do'      => ['action' => 'choose', 'say' => 'Tick or untick Advertising only.', 'target' => 'input[type="checkbox"]'],
                'title'   => 'Advertise only, or run the sale?',
                'body'    => 'Advertising only (the default) means CoreX advertises your auction properties while the sale itself is run elsewhere — the Sale Room and bidder register stay switched off. Untick it to let CoreX register bidders and run the sale.',
            ],
            [
                'element' => '[data-tour="aset-auctioneer"]',
                'section' => 'Auctioneers',
                'do'      => ['action' => 'choose', 'say' => 'Pick who normally runs your auctions.'],
                'title'   => 'Who runs the auction',
                'body'    => 'Choose whether the auction form offers your own auctioneer, an outside auction house, or asks each time.',
            ],
            [
                'element'       => '[data-tour="aset-bidding"]',
                'section'       => 'Bidding and fees',
                'advanced_only' => true,
                'title'         => 'Where bidding happens',
                'body'          => 'In-room, online or hybrid, plus online auto-extend, proxy and phone bidding. These only come into play when CoreX runs the sale.',
            ],
            [
                'element'       => '[data-tour="aset-fees"]',
                'advanced_only' => true,
                'title'         => 'How the agency is paid',
                'body'          => 'The fee model, buyer\'s premium, seller\'s commission and VAT treatment used when a sale becomes a deal.',
            ],
            [
                'element'       => '[data-tour="aset-registration"]',
                'advanced_only' => true,
                'title'         => 'Bidder registration',
                'body'          => 'When registration opens and closes, and whether FICA, a deposit and signed rules are needed before a bidder gets a paddle.',
            ],
            [
                'element' => '[data-tour="aset-reserve"]',
                'section' => 'Reserve and disclosure',
                'do'      => ['action' => 'choose', 'say' => 'Pick who may see the reserve price.', 'target' => 'select'],
                'title'   => 'Reserve, guide price and disclosure',
                'body'    => 'Reserve visibility decides who sees the reserve amount — and whether buyers see it on the public advert. You can also show a guide price publicly and set the vendor bidding disclosure printed on every advert (have your attorney confirm the wording).',
            ],
            [
                'element'       => '[data-tour="aset-deposit"]',
                'advanced_only' => true,
                'title'         => 'Deposit and settlement',
                'body'          => 'The purchase deposit and balance-due rules applied after the hammer falls.',
            ],
            [
                'element' => '[data-tour="aset-save"]',
                'section' => 'Saving',
                'do'      => ['action' => 'click', 'say' => 'Click Save Auction Settings.'],
                'title'   => 'Save',
                'body'    => 'Changes apply to new auctions straight away. You can find these settings again any time under Settings → Auctions.',
            ],
        ],
    ],

    // ── Auction Results ──────────────────────────────────────────────────────
    'au-results' => [
        'key'         => 'au-results',
        'title'       => 'Reading auction results',
        'description' => 'Every concluded lot — sold, sold subject to confirmation, passed in or withdrawn — with filters and an export.',
        'route'       => 'corex.auctions.results',
        'permission'  => 'auctions.results.view',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="au-res-intro"]',
                'section' => 'The results',
                'title'   => 'What happened to every lot',
                'body'    => 'Once a lot is recorded as sold, passed in or withdrawn it appears here, across all your auctions.',
            ],
            [
                'element' => '[data-tour="au-res-filters"]',
                'section' => 'Filtering',
                'do'      => ['action' => 'fill', 'say' => 'Type a lot number, address, suburb or buyer, then press Enter.', 'target' => 'input[name="search"]'],
                'title'   => 'Search and filter',
                'body'    => 'Narrow the results by status, whether the reserve was met, or an auction date range, then sort them. Press Apply to run the filters.',
            ],
            [
                'element' => '[data-tour="au-res-table"]',
                'title'   => 'The result rows',
                'body'    => 'Each row is a lot: its auction, address, sold price and outcome. Passed-in lots are worth a follow-up — the strongest buyers from the room are your best leads.',
            ],
            [
                'element' => '[data-tour="au-res-export"]',
                'section' => 'Exporting',
                'title'   => 'Export to CSV',
                'body'    => 'Download exactly what you are looking at — the filters you set are applied — for the principal, accountant or the seller. Only people with export permission see this button.',
            ],
        ],
    ],

    // ── Auctions → Properties (the auction view of the property list) ────────
    'au-properties' => [
        'key'         => 'au-properties',
        'title'       => 'Your auction properties',
        'description' => 'The property list limited to auction stock — filter by auction, lot status and reserve.',
        'route'       => 'corex.auctions.properties.index',
        'permission'  => 'access_properties',
        'setup'       => [['action' => 'scrollTop']],
        'steps' => [
            [
                'element' => '[data-tour="au-prop-lens"]',
                'section' => 'Auction stock',
                'title'   => 'Auction properties only',
                'body'    => 'This is your normal property list, limited to properties being sold at auction. Everything works as on the main Properties page — open a card to see the full record, which now has an Auction tab.',
            ],
            [
                'element' => '[data-tour="re-properties-search"]',
                'section' => 'Filtering',
                'do'      => ['action' => 'fill', 'say' => 'Type a suburb, listing name or number, then press Enter.'],
                'title'   => 'Search',
                'body'    => 'Type a title, suburb or reference to jump to a property.',
            ],
            [
                'element' => '[data-tour="au-prop-auction"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick an auction to see only its properties.'],
                'title'   => 'Filter by auction',
                'body'    => 'Show only the properties that are lots in one auction — handy on the run-up to a sale day.',
            ],
            [
                'element' => '[data-tour="au-prop-lotstatus"]',
                'do'      => ['action' => 'choose', 'say' => 'Pick a lot status.'],
                'title'   => 'Filter by lot status',
                'body'    => 'Draft, On Auction, Sold, Passed in or Withdrawn — find the properties that need a follow-up.',
            ],
            [
                'element'       => '[data-tour="au-prop-reserve"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick a reserve filter — or press Skip this step.'],
                'title'         => 'Reserve met or not',
                'body'          => 'After a sale, see which lots made their reserve and which did not.',
            ],
            [
                'element'       => '[data-tour="re-properties-sort"]',
                'advanced_only' => true,
                'do'            => ['action' => 'choose', 'say' => 'Pick how you want the list sorted.'],
                'title'         => 'Sort the list',
                'body'          => 'Put the newest, oldest, most expensive or cheapest properties at the top.',
            ],
        ],
    ],

];
