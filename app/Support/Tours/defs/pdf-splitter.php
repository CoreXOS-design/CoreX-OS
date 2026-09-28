<?php

/**
 * AT-105 Guided Tours — PDF Pack Splitter pack.
 *
 * The splitter flow spans TWO real screens, so it ships as two coordinated
 * tours (the engine filters out any step whose data-tour anchor isn't on the
 * current page — so each tour anchors only on elements its own screen renders):
 *
 *   tools-pdf-splitter         → the upload screen (tools.pdf_splitter.index)
 *   tools-pdf-splitter-review  → the review + assign screen (tools.pdf_splitter.review)
 *
 * Both auto-launch once on their route, are re-launchable from the page's "?"
 * launcher, and appear in the Guided Tours directory (the `tools-` key prefix
 * groups them under "Tools & Calculators"). Pure data merged by
 * App\Support\Tours\TourRegistry::all() — no engine fork. Every `element`
 * points at a real data-tour="…" anchor in the splitter Blades.
 *
 * Permission `access_pdf_splitter` is the exact route middleware key, so the
 * catalogue only lists these to a user who can reach the screen.
 *
 * @return array<string,array<string,mixed>>
 */

return [

    // ── Screen 1 — upload the pack ───────────────────────────────────────────
    'tools-pdf-splitter' => [
        'key'         => 'tools-pdf-splitter',
        'title'       => 'Splitting a PDF document pack',
        'description' => 'Upload a multi-document PDF, then review, assign and file each page.',
        'route'       => 'tools.pdf_splitter.index',
        'permission'  => 'access_pdf_splitter',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="splitter-base-name"]',
                'section' => 'Uploading the pack',
                'do'      => ['action' => 'fill', 'say' => 'Type a short name for the pack — or press Skip this step to use the file\'s own name.'],
                'title'   => 'Name the pack',
                'body'    => 'Drop in a whole pack — a mandate, FICA, ID, proof of residence, an OTP — all in one PDF. Give it a short name so the split files are easy to recognise later, e.g. "OceanView_Pack".',
            ],
            [
                'element' => '[data-tour="splitter-file"]',
                // A file picker holds no typed value, so wait for the chosen-file list instead.
                'do'      => ['action' => 'appear', 'say' => 'Choose the scanned PDF from your computer.', 'until' => '[data-tour="splitter-file-list"]'],
                'title'   => 'Choose the PDF',
                'body'    => 'Pick the scanned pack (up to 50 MB). It can be every document for a deal in one file — CoreX works out where each one starts and ends.',
            ],
            [
                'element' => '[data-tour="splitter-upload-btn"]',
                'do'      => ['action' => 'click', 'say' => 'Click Upload & Split — CoreX reads every page and opens the Review screen.'],
                'title'   => 'Upload & Split',
                'body'    => 'CoreX reads every page with OCR and splits the pack into labelled documents. Next you\'ll land on the Review screen to check each page\'s type, tick which contact(s) it belongs to, then either Link it into CoreX (files it + starts FICA) or just Download the split ZIP. Run an upload to see it.',
            ],
        ],
    ],

    // ── Screen 2 — review, assign, file ──────────────────────────────────────
    'tools-pdf-splitter-review' => [
        'key'         => 'tools-pdf-splitter-review',
        'title'       => 'Reviewing & assigning a split pack',
        'description' => 'Correct page types, assign each page to its contact(s), then Link to CoreX or Download ZIP.',
        'route'       => 'tools.pdf_splitter.review',
        'permission'  => 'access_pdf_splitter',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="spr-property"]',
                'section' => 'Linking a property',
                'do'      => ['action' => 'appear', 'say' => 'Search for the property and pick it from the list — or press Skip this step to just download the files.', 'until' => '[data-tour="spr-property-picked"]'],
                'title'   => 'Link to a property',
                'body'    => 'Search and pick the property this pack belongs to. Its linked people — sellers, buyers, tenants — become the contacts you can assign each page to. (Just want the files? You can skip this and use Download ZIP.)',
            ],
            [
                'element' => '[data-tour="spr-doctype"]',
                'section' => 'Checking page types',
                'title'   => 'Check the document types',
                'body'    => 'OCR auto-labels every page (Mandate, FICA, ID, OTP…). On the rows below, fix any page whose type is wrong — the list is alphabetical so it\'s quick to find. Use these bulk tools to set or reset many pages at once.',
            ],
            [
                'element' => '[data-tour="spr-page-type"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Fix the type of any page that\'s wrong — or press Skip this step if they\'re all right.'],
                'title'   => 'Fix a page\'s type',
                'body'    => 'Each page has its own drop-down. "Same as prev" and "Same as next" copy the type from the page above or below.',
            ],
            [
                'element' => '[data-tour="spr-assign"]',
                'section' => 'Assigning contacts',
                'title'   => 'Assign each page to its contact(s)',
                'body'    => 'For every page, tick which party it belongs to. A page can go to MANY people across roles — an Offer to Purchase ticks all the sellers AND all the buyers at once. Your last set of ticks carries forward to the next page of the same type, so you rarely re-tick. If a party isn\'t on the property yet, use "Select existing / Create new" right here — it links them in the right role and they appear to tick.',
            ],
            [
                'element' => '[data-tour="spr-assign-cell"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Tick the contact(s) this page belongs to, then do the same for each page below.'],
                'title'   => 'Tick who it belongs to',
                'body'    => 'Ticks carry forward to the next page of the same type. A red dot means that person isn\'t FICA-verified yet.',
            ],
            [
                'element' => '[data-tour="spr-fica"]',
                'section' => 'Filing to CoreX',
                'do'      => ['action' => 'choose', 'say' => 'Leave FICA ticked to start verification, or untick it — press Skip this step to keep it as it is.'],
                'title'   => 'Start FICA from the pack',
                'body'    => 'Leave this ticked to start a wet-ink FICA verification — one per party who has a FICA, ID or Proof-of-Residence page assigned. Each party is verified individually.',
            ],
            [
                'element' => '[data-tour="spr-link"]',
                'do'      => ['action' => 'click', 'say' => 'Click Link — this files every page to the property and contacts you ticked, and starts FICA.'],
                'title'   => 'Link to CoreX',
                'body'    => 'This files every page to the property and/or the contact(s) you ticked, and starts the FICA verification(s) — each party\'s ID and Proof of Residence auto-attached from the pages you assigned to them. The "Open to finish" links open in new tabs, so you can complete each party\'s FICA without losing the others.',
            ],
            [
                'element' => '[data-tour="spr-zip"]',
                'section' => 'Downloading the files',
                'do'      => ['action' => 'click', 'say' => 'Click Download ZIP to get the split files — nothing is filed.'],
                'title'   => 'Or just Download ZIP',
                'body'    => 'Only need the split files? Download ZIP gives you the separated PDFs in one zip — no filing, no FICA. Two clear choices: Link files it into CoreX; Download just hands you the documents.',
            ],
        ],
    ],
];
