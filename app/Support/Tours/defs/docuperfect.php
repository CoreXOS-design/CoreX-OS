<?php

/**
 * DocuPerfect & Documents guided-tour pack (AT-41).
 *
 * Returns array<key, definition> merged into TourRegistry::all(). Every step
 * anchors on a real data-tour="…" element added to the matching Blade view, so
 * a markup refactor can never silently strand a step.
 *
 * Scope note: docuperfect.esign.create (the e-sign wizard) and
 * documents.library.index are covered by a separate pack and are NOT defined
 * here. The e-sign "My Documents" screen is a read-only signing-status surface,
 * so its tour is point-only — no setup clicks, no actions that touch state.
 */

return [

    // ── DocuPerfect dashboard (My Documents) ─────────────────────────────────
    'dp-dashboard' => [
        'key'         => 'dp-dashboard',
        'title'       => 'Your DocuPerfect home',
        'description' => 'Where every document you build from a template lives, and how to start a new one.',
        'route'       => 'docuperfect.dashboard',
        'permission'  => 'access_docuperfect',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="dp-dashboard-header"]',
                'title'   => 'This is your document home',
                'body'    => 'DocuPerfect turns your agency templates into ready-to-use documents — offers, mandates, FICA forms and more. This page lists every document you have personally created.',
            ],
            [
                'element' => '[data-tour="dp-dashboard-create"]',
                'title'   => 'Start a new document',
                'body'    => 'Click here to pick a template and fill it in. The template does the heavy lifting — you just supply the details for this property, buyer or seller.',
            ],
            [
                'element' => '[data-tour="dp-dashboard-list"]',
                'title'   => 'Your saved documents',
                'body'    => 'Each row is a document you can reopen and edit, or archive once it is done. Nothing is ever truly deleted — archived documents can always be recovered.',
            ],
            [
                'element' => '[data-tour="dp-dashboard-create"]',
                'title'   => 'Ready when you are',
                'body'    => 'Close this and press Create New Document to build your first one from a template.',
            ],
        ],
    ],

    // ── Generated documents list (pack-instance view) ────────────────────────
    // Context-bound: the bare /documents route redirects to the dashboard. This
    // screen only renders when opened for a specific document pack instance.
    'dp-documents' => [
        'key'         => 'dp-documents',
        'title'       => 'Documents in a pack',
        'description' => 'Open a launched document pack (from Document Packs), then tap ? to see how to work the documents inside it.',
        'route'       => 'docuperfect.documents.index',
        'permission'  => 'access_docuperfect',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="dp-documents-root"]',
                'title'   => 'The documents in this pack',
                'body'    => 'When you launch a document pack, every document it creates is grouped here so you can work through them in one place.',
            ],
            [
                'element' => '[data-tour="dp-documents-table"]',
                'title'   => 'Each document, ready to edit',
                'body'    => 'Open any row to fill it in, or rename it inline using the small pencil. A document already out for signature is marked "Active" and cannot be archived until it is done.',
            ],
            [
                'element' => '[data-tour="dp-documents-combined-pdf"]',
                'title'   => 'One PDF for the whole pack',
                'body'    => 'This button merges every document in the pack into a single PDF — handy when you need to send the full set to a client or attorney at once.',
            ],
            [
                'element' => '[data-tour="dp-documents-show-all"]',
                'title'   => 'Back to all documents',
                'body'    => 'Show All takes you out of this pack view to your full document list. Close this and carry on filling in the pack.',
            ],
        ],
    ],

    // ── Templates ────────────────────────────────────────────────────────────
    'dp-templates' => [
        'key'         => 'dp-templates',
        'title'       => 'Document templates',
        'description' => 'How the agency\'s reusable document templates are uploaded, found and organised.',
        'route'       => 'docuperfect.templates.index',
        'permission'  => 'access_docuperfect',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="dp-templates-header"]',
                'title'   => 'Your template library',
                'body'    => 'A template is a reusable document — a mandate, an offer to purchase, a FICA form — set up once and used again and again. Every document you create starts from one of these.',
            ],
            [
                'element' => '[data-tour="dp-templates-upload"]',
                'title'   => 'Add a new template',
                'body'    => 'Upload a PDF here to turn it into a template. Once uploaded you map where each field, date and signature goes, so it auto-fills next time.',
            ],
            [
                'element' => '[data-tour="dp-templates-filters"]',
                'title'   => 'Find the right template fast',
                'body'    => 'Search by name, or filter by category (Sales or Rentals), type and visibility. The "E-Sign" badge marks templates set up for electronic signing.',
            ],
            [
                'element' => '[data-tour="dp-templates-upload"]',
                'title'   => 'Keep your library tidy',
                'body'    => 'Close this and upload or organise the templates your branch uses most.',
            ],
        ],
    ],

    // ── E-Sign: My Documents (READ-ONLY signing-status surface) ──────────────
    'dp-esign-my-docs' => [
        'key'         => 'dp-esign-my-docs',
        'title'       => 'Track your e-sign documents',
        'description' => 'See the live signing status of every document you have sent out for electronic signature.',
        'route'       => 'docuperfect.esign.myDocuments',
        'permission'  => 'access_docuperfect',
        // Point-only: this screen shows live signing status. No setup actions.
        'steps' => [
            [
                'element' => '[data-tour="dp-esign-my-docs-header"]',
                'title'   => 'Your e-sign control room',
                'body'    => 'Every document you have sent for electronic signature shows up here, grouped by where it is in the process — from draft to fully signed.',
            ],
            [
                'element' => '[data-tour="dp-esign-my-docs-tiles"]',
                'title'   => 'See status at a glance',
                'body'    => 'These tiles count your documents by stage: Draft, Ready to Sign, Awaiting Signatures, Needs Approval and Completed. Click a tile to jump straight to that group.',
            ],
            [
                'element' => '[data-tour="dp-esign-my-docs-new"]',
                'title'   => 'Send something new',
                'body'    => 'New E-Sign starts a fresh signing flow — you pick the document, add the signers, and CoreX handles delivery, reminders and the legal audit trail.',
            ],
            [
                'element' => '[data-tour="dp-esign-my-docs-tiles"]',
                'title'   => 'Stay on top of signatures',
                'body'    => 'Close this and check which documents are still waiting — a gentle reminder is one click away on each row.',
            ],
        ],
    ],

    // ── Sales documents (send & track) ───────────────────────────────────────
    'dp-sales' => [
        'key'         => 'dp-sales',
        'title'       => 'Send & track sales documents',
        'description' => 'Upload a sales document, send it to clients in order, and track who has signed and returned it.',
        'route'       => 'docuperfect.sales',
        'permission'  => 'access_docuperfect',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="dp-sales-header"]',
                'title'   => 'Sales document tracking',
                'body'    => 'Use this when you send a sales document out to be signed and returned — typically by hand, email or WhatsApp rather than full e-sign. CoreX keeps the whole chain in one place.',
            ],
            [
                'element' => '[data-tour="dp-sales-send"]',
                'title'   => 'Upload and send',
                'body'    => 'Upload the document, add your recipients in signing order, and send. Each person only gets it once the person before them has returned theirs.',
            ],
            [
                'element' => '[data-tour="dp-sales-summary"]',
                'title'   => 'Where everything stands',
                'body'    => 'These cards show how many documents are In Progress, Completed, or Expired. "Needs Approval" means a signed copy has come back and is waiting for you to check it.',
            ],
            [
                'element' => '[data-tour="dp-sales-send"]',
                'title'   => 'Keep deals moving',
                'body'    => 'Close this and upload your next document — or send a reminder to anyone holding things up.',
            ],
        ],
    ],

    // ── Document packs ───────────────────────────────────────────────────────
    'dp-packs' => [
        'key'         => 'dp-packs',
        'title'       => 'Document packs',
        'description' => 'Bundle several templates into one pack and create them all in a single step.',
        'route'       => 'docuperfect.packs.index',
        'permission'  => 'access_docuperfect',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="dp-packs-header"]',
                'title'   => 'Create a whole set at once',
                'body'    => 'A pack bundles the documents that always go together — say a mandate, a FICA form and a marketing consent. Launch the pack and CoreX builds every one of them in a single step.',
            ],
            [
                'element' => '[data-tour="dp-packs-grid"]',
                'title'   => 'Your available packs',
                'body'    => 'Each card shows what is inside a pack and who can use it. The "E-Sign" badge means the documents in that pack can be sent for electronic signing.',
            ],
            [
                'element' => '[data-tour="dp-packs-launch"]',
                'title'   => 'Launch a pack',
                'body'    => 'Launch Pack walks you through creating every document in the bundle in one go — no need to start each one from scratch.',
            ],
            [
                'element' => '[data-tour="dp-packs-header"]',
                'title'   => 'Save yourself the repetition',
                'body'    => 'Close this and launch the pack your deals use most often.',
            ],
        ],
    ],

    // ── Lease records — DELIBERATELY SKIPPED (AT-41) ─────────────────────────
    // docuperfect.leases.index renders docuperfect/signatures/placeholder.blade.php,
    // an explicit "This feature is under construction" stub with no workflow on
    // screen. A tour here would only narrate an empty placeholder, which fails the
    // CoreX production-quality bar. No tour is registered until the real leases
    // screen ships. (The placeholder's data-tour anchors are harmless if present.)

    // ── Shared Drive ─────────────────────────────────────────────────────────
    'docs-shared-drive' => [
        'key'         => 'docs-shared-drive',
        'title'       => 'The agency Shared Drive',
        'description' => 'The team\'s shared filing cabinet — upload, organise into folders, and find shared files.',
        'route'       => 'documents.shared-drive.index',
        'permission'  => 'access_shared_drive',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="docs-shared-drive-header"]',
                'title'   => 'Your shared filing cabinet',
                'body'    => 'The Shared Drive is the whole agency\'s common file store — branch forms, policies, marketing assets and anything the team needs to reach. Files can be up to 50 MB each.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-upload"]',
                'title'   => 'Add files',
                'body'    => 'Upload from here, or simply drag files anywhere onto the page. They land in whichever folder you are currently viewing.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-breadcrumb"]',
                'title'   => 'Know where you are',
                'body'    => 'This trail shows the folder you are in. Click any part of it to jump back up — just like the folders on your computer.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-files"]',
                'title'   => 'Open or download',
                'body'    => 'Click a file name to preview it right here, or use the download icon to save a copy. Close this and have a look around your branch\'s folders.',
            ],
        ],
    ],

    // ── Filing Register (manager/compliance) ─────────────────────────────────
    'docs-filing-register' => [
        'key'         => 'docs-filing-register',
        'title'       => 'The physical filing register',
        'description' => 'The searchable index of physically filed mandates — find any paper file and track expiry.',
        'route'       => 'filing-register.index',
        'permission'  => 'access_filing_register',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="docs-filing-register-header"]',
                'title'   => 'Find any paper file fast',
                'body'    => 'This is the index of your branch\'s physically filed mandates. It tells you exactly which file and sequence number a paper document is stored under, so nobody hunts through cabinets.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-filters"]',
                'title'   => 'Search and narrow down',
                'body'    => 'Search by address, reference, seller or sequence number, and filter by mandate type — OA (Open Authority) or EA (Exclusive Authority) — status, branch or agent.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-tiles"]',
                'title'   => 'Watch your expiries',
                'body'    => 'These tiles total your filings and flag mandates Expiring within 30 days or already Expired — your cue to renew before a mandate lapses.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-table"]',
                'title'   => 'Every filing, at a glance',
                'body'    => 'The register lists each filing with its reference, address, agent, expiry and status. Close this and search for the file you need.',
            ],
        ],
    ],

];
