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
 *
 * Advanced Guide note: where a button LEAVES the page (Create, Launch, Edit,
 * Upload), the explanation step stays a read step and the matching "do" step
 * sits at the END of the tour, so the hands-on run finishes every on-page job
 * before it hands the agent over to the next screen.
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
                'section' => 'Starting a new document',
                'title'   => 'This is your document home',
                'body'    => 'DocuPerfect turns your agency templates into ready-to-use documents — offers, mandates, FICA forms and more. This page lists every document you have personally created.',
            ],
            [
                'element' => '[data-tour="dp-dashboard-create"]',
                'do'      => ['action' => 'click', 'say' => 'Click Create New Document and pick the template you need.'],
                'title'   => 'Start a new document',
                'body'    => 'Click here to pick a template and fill it in. The template does the heavy lifting — you just supply the details for this property, buyer or seller.',
            ],
            [
                'element' => '[data-tour="dp-dashboard-list"]',
                'section' => 'Reopening a document',
                'do'      => ['action' => 'click', 'target' => 'a[href$="/edit"]', 'say' => 'Click Edit on the document you want to reopen.'],
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
        // Launching a pack lands the agent here (?pack_instance=…).
        'pick_from'   => 'docuperfect.packs.index',
        'pick_note'   => 'Launch the document pack you need and I\'ll pick up on its documents page.',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="dp-documents-root"]',
                'section' => 'Renaming a document',
                'title'   => 'The documents in this pack',
                'body'    => 'When you launch a document pack, every document it creates is grouped here so you can work through them in one place.',
            ],
            [
                'element' => '[data-tour="dp-documents-table"]',
                'do'      => ['action' => 'click', 'target' => 'button[title="Rename"]', 'say' => 'Click the small pencil next to a document\'s name — or press Skip this step to keep the names.'],
                'title'   => 'Each document, ready to edit',
                'body'    => 'Open any row to fill it in, or rename it inline using the small pencil. A document already out for signature is marked "Active" and cannot be archived until it is done.',
            ],
            [
                'element' => '[data-tour="dp-documents-table"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Type the new name, then click Save next to it.'],
                'title'   => 'Type the new name',
                'body'    => 'Give it a name you\'ll recognise later, like the address and the document type.',
            ],
            [
                'element' => '[data-tour="dp-documents-combined-pdf"]',
                'section' => 'Getting one PDF',
                'do'      => ['action' => 'click', 'say' => 'Click Download as Single PDF — it saves the whole pack as one file.'],
                'title'   => 'One PDF for the whole pack',
                'body'    => 'This button merges every document in the pack into a single PDF — handy when you need to send the full set to a client or attorney at once.',
            ],
            [
                'element' => '[data-tour="dp-documents-show-all"]',
                'section' => 'Filling in the documents',
                'title'   => 'Back to all documents',
                'body'    => 'Show All takes you out of this pack view to your full document list. Close this and carry on filling in the pack.',
            ],
            [
                'element' => '[data-tour="dp-documents-table"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'target' => 'a[href$="/edit"]', 'say' => 'Click Edit on the document you want to fill in.'],
                'title'   => 'Fill in a document',
                'body'    => 'Edit opens the document with its template ready. Fill in the details, save, then come back here for the next one.',
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
                'section' => 'Adding a template',
                'title'   => 'Your template library',
                'body'    => 'A template is a reusable document — a mandate, an offer to purchase, a FICA form — set up once and used again and again. Every document you create starts from one of these.',
            ],
            [
                // Read-only here: picking a file uploads at once and leaves the
                // page, so the hands-on upload is the LAST step of this tour.
                'element' => '[data-tour="dp-templates-upload"]',
                'title'   => 'Add a new template',
                'body'    => 'Upload a PDF here to turn it into a template. Once uploaded you map where each field, date and signature goes, so it auto-fills next time.',
            ],
            [
                'element' => '[data-tour="dp-templates-filters"]',
                'section' => 'Finding a template',
                'do'      => ['action' => 'fill', 'target' => 'input[name="search"]', 'say' => 'Type part of the template\'s name, then click outside the box to search.'],
                'title'   => 'Find the right template fast',
                'body'    => 'Search by name, or filter by category (Sales or Rentals), type and visibility. The "E-Sign" badge marks templates set up for electronic signing.',
            ],
            [
                'element' => '[data-tour="dp-templates-filters"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Pick a category, type or visibility to narrow the list — or press Skip this step.'],
                'title'   => 'Narrow the list',
                'body'    => 'Each drop-down filters the library straight away. Clear takes you back to every template.',
            ],
            [
                'element' => '[data-tour="dp-templates-view-toggle"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Switch between the grid and the list view.'],
                'title'   => 'Grid or list',
                'body'    => 'The grid shows a preview of each template\'s first page; the list fits more on the screen. CoreX remembers the one you pick.',
            ],
            [
                'element' => '[data-tour="dp-templates-upload"]',
                'section' => 'Adding a template',
                'do'      => ['action' => 'click', 'say' => 'Click Upload Template and pick the PDF — it uploads as soon as you choose the file.'],
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
                'section' => 'Checking progress',
                'title'   => 'Your e-sign control room',
                'body'    => 'Every document you have sent for electronic signature shows up here, grouped by where it is in the process — from draft to fully signed.',
            ],
            [
                'element' => '[data-tour="dp-esign-my-docs-tiles"]',
                'do'      => ['action' => 'click', 'say' => 'Click a tile to jump to that group of documents.'],
                'title'   => 'See status at a glance',
                'body'    => 'These tiles count your documents by stage: Draft, Ready to Sign, Awaiting Signatures, Needs Approval and Completed. Click a tile to jump straight to that group.',
            ],
            [
                // Read-only here: New E-Sign leaves the page, so its click is the
                // last step of this tour.
                'element' => '[data-tour="dp-esign-my-docs-new"]',
                'section' => 'Sending a new document',
                'title'   => 'Send something new',
                'body'    => 'New E-Sign starts a fresh signing flow — you pick the document, add the signers, and CoreX handles delivery, reminders and the legal audit trail.',
            ],
            [
                'element' => '[data-tour="dp-esign-my-docs-tiles"]',
                'section' => 'Chasing signatures',
                'do'      => ['action' => 'click', 'target' => 'a[href="#section-awaiting"]', 'say' => 'Click Awaiting Signatures to see who still has to sign.'],
                'title'   => 'Stay on top of signatures',
                'body'    => 'Close this and check which documents are still waiting — a gentle reminder is one click away on each row.',
            ],
            [
                'element' => '[data-tour="dp-esign-my-docs-awaiting"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'target' => 'form[action*="/send-reminder/"] button', 'say' => 'Click Send Reminder on a document that\'s stuck — this emails that signer a reminder.'],
                'title'   => 'Send a reminder',
                'body'    => 'Each row shows who has signed and whose turn it is. A reminder nudges the person holding things up — CoreX asks you to confirm first.',
            ],
            [
                'element' => '[data-tour="dp-esign-my-docs-new"]',
                'advanced_only' => true,
                'section' => 'Sending a new document',
                'do'      => ['action' => 'click', 'say' => 'Click New E-Sign to start sending a document for signature.'],
                'title'   => 'Start a new e-sign',
                'body'    => 'This opens the step-by-step wizard: pick the document, add the signers, place the signatures and send.',
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
                'section' => 'Sending a document',
                'title'   => 'Sales document tracking',
                'body'    => 'Use this when you send a sales document out to be signed and returned — typically by hand, email or WhatsApp rather than full e-sign. CoreX keeps the whole chain in one place.',
            ],
            [
                // Read-only here: the button leaves the page, so its click is the
                // last step of this tour.
                'element' => '[data-tour="dp-sales-send"]',
                'title'   => 'Upload and send',
                'body'    => 'Upload the document, add your recipients in signing order, and send. Each person only gets it once the person before them has returned theirs.',
            ],
            [
                'element' => '[data-tour="dp-sales-summary"]',
                'section' => 'Chasing returns',
                'title'   => 'Where everything stands',
                'body'    => 'These cards show how many documents are In Progress, Completed, or Expired. "Needs Approval" means a signed copy has come back and is waiting for you to check it.',
            ],
            [
                'element' => '[data-tour="dp-sales-in-progress"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'target' => 'form[action$="/remind"] button', 'say' => 'Click Send Reminder next to anyone holding things up — this emails them a reminder.'],
                'title'   => 'Nudge whoever has it',
                'body'    => 'Each document shows who has it right now and how long they\'ve had it. A reminder goes only to that person.',
            ],
            [
                'element' => '[data-tour="dp-sales-send"]',
                'section' => 'Sending a document',
                'do'      => ['action' => 'click', 'say' => 'Click Upload & Send New to upload the document and add who must sign it.'],
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
                'section' => 'Choosing a pack',
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
                'section' => 'Launching a pack',
                'title'   => 'Launch a pack',
                'body'    => 'Launch Pack walks you through creating every document in the bundle in one go — no need to start each one from scratch.',
            ],
            [
                // Any card's Launch Pack counts — the anchor above is only the first card.
                'element' => '[data-tour="dp-packs-grid"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'target' => 'a[href$="/launch"]', 'say' => 'Click Launch Pack on the pack you need.'],
                'title'   => 'Launch the one you need',
                'body'    => 'CoreX asks for the details the documents share once, then builds every document in the pack for you.',
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
    // The route is the drive list; upload, the breadcrumb and the file list live
    // one screen deeper (documents/shared-drive/drive.blade.php). The hands-on
    // guide opens a drive first and follows the agent onto that screen.
    'docs-shared-drive' => [
        'key'         => 'docs-shared-drive',
        'title'       => 'The agency Shared Drive',
        'description' => 'The team\'s shared filing cabinet — upload, organise into folders, and find shared files.',
        'route'       => 'documents.shared-drive.index',
        // The drive list and the inside of a drive (and its folders) are separate
        // screens — the tour and a running guide carry on into the drive.
        'also_on'     => ['documents.shared-drive.drive', 'documents.shared-drive.folder'],
        'permission'  => 'access_shared_drive',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="docs-shared-drive-header"]',
                'section' => 'Opening a drive',
                'title'   => 'Your shared filing cabinet',
                'body'    => 'The Shared Drive is the whole agency\'s common file store — branch forms, policies, marketing assets and anything the team needs to reach. Files can be up to 50 MB each.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-drives"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'target' => 'a', 'say' => 'Click the drive you want to open.'],
                'title'   => 'Open a drive',
                'body'    => 'Each card is a drive. The default drive is open to everyone in the agency; a padlock means only invited members can see it.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-upload"]',
                'section' => 'Uploading files',
                'do'      => ['action' => 'click', 'say' => 'Click Upload Files and choose the files from your computer.', 'until' => '[data-tour="docs-shared-drive-upload-progress"]'],
                'title'   => 'Add files',
                'body'    => 'Upload from here, or simply drag files anywhere onto the page. They land in whichever folder you are currently viewing.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-new-folder"]',
                'advanced_only' => true,
                'section' => 'Organising into folders',
                'do'      => ['action' => 'click', 'say' => 'Click New Folder.', 'until' => '[data-tour="docs-shared-drive-folder-form"]'],
                'title'   => 'Make a folder',
                'body'    => 'Folders keep a drive tidy — make one for each kind of document your team files.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-folder-form"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Type a name for the folder.'],
                'title'   => 'Name the folder',
                'body'    => 'Keep it short and obvious, like Branch SOPs or Marketing.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-folder-form"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'target' => 'button[type="submit"]', 'say' => 'Click Create.'],
                'title'   => 'Create it',
                'body'    => 'The folder appears straight away. Open it and upload into it to keep those files together.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-breadcrumb"]',
                'section' => 'Finding your way',
                'title'   => 'Know where you are',
                'body'    => 'This trail shows the folder you are in. Click any part of it to jump back up — just like the folders on your computer.',
            ],
            [
                'element' => '[data-tour="docs-shared-drive-files"]',
                'do'      => ['action' => 'click', 'target' => 'tbody', 'say' => 'Click a file\'s name to preview it — or press Skip this step if the folder is empty.'],
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
                'section' => 'Finding a filing',
                'title'   => 'Find any paper file fast',
                'body'    => 'This is the index of your branch\'s physically filed mandates. It tells you exactly which file and sequence number a paper document is stored under, so nobody hunts through cabinets.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-filters"]',
                'do'      => ['action' => 'fill', 'target' => '#search', 'say' => 'Type an address, reference, seller or sequence number.'],
                'title'   => 'Search and narrow down',
                'body'    => 'Search by address, reference, seller or sequence number, and filter by mandate type — OA (Open Authority) or EA (Exclusive Authority) — status, branch or agent.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-filters"]',
                'advanced_only' => true,
                'do'      => ['action' => 'choose', 'say' => 'Pick a type, status, branch or agent — or press Skip this step.'],
                'title'   => 'Narrow it down',
                'body'    => 'Status "Expiring Soon" is the quickest way to see which mandates need renewing this month.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-filters"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'target' => 'button[type="submit"]', 'say' => 'Click Filter.'],
                'title'   => 'Run the search',
                'body'    => 'The register reloads with just the filings that match.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-tiles"]',
                'section' => 'Watching expiries',
                'title'   => 'Watch your expiries',
                'body'    => 'These tiles total your filings and flag mandates Expiring within 30 days or already Expired — your cue to renew before a mandate lapses.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-table"]',
                'title'   => 'Every filing, at a glance',
                'body'    => 'The register lists each filing with its reference, address, agent, expiry and status. Close this and search for the file you need.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-new-btn"]',
                'advanced_only' => true,
                'section' => 'Adding a filing',
                'do'      => ['action' => 'click', 'say' => 'Click New Filing.', 'until' => '[data-tour="docs-filing-register-new-form"]'],
                'skip_if' => '[data-tour="docs-filing-register-new-property"]',
                'title'   => 'Record a new filing',
                'body'    => 'Every time a paper mandate goes into a cabinet, record it here so anyone can find it later.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-new-property"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Search for the property and pick it — or just type the address.'],
                'title'   => 'The property',
                'body'    => 'Pick it from the list and CoreX fills in the branch, agent, seller and mandate expiry for you. No match? Type the address and it still saves.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-new-type"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Pick the mandate type — or press Skip this step if OA is right.'],
                'title'   => 'Mandate type',
                'body'    => 'OA is an Open Authority, EA an Exclusive Authority. Use Other for anything else you file.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-new-file-ref"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Type the file it\'s stored in, e.g. File 3.'],
                'title'   => 'File reference',
                'body'    => 'The label on the physical file or cabinet the paper went into.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-new-sequence"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Type its sequence number in that file.'],
                'title'   => 'Sequence number',
                'body'    => 'Its position inside the file, e.g. 0042 — so the exact page is found in seconds.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-new-expiry"]',
                'advanced_only' => true,
                'do'      => ['action' => 'fill', 'say' => 'Pick the mandate\'s expiry date — or press Skip this step if it\'s already right.'],
                'title'   => 'Expiry date',
                'body'    => 'CoreX fills this from the property\'s mandate when it can. It drives the Expiring and Expired counts above.',
            ],
            [
                'element' => '[data-tour="docs-filing-register-new-save"]',
                'advanced_only' => true,
                'do'      => ['action' => 'click', 'say' => 'Click Save Filing.'],
                'title'   => 'Save the filing',
                'body'    => 'The filing joins the register straight away, ready to be searched.',
            ],
        ],
    ],

];
