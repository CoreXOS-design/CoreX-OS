<?php

/*
|--------------------------------------------------------------------------
| CoreX Navigation Atlas
|--------------------------------------------------------------------------
|
| A curated registry of user-facing DESTINATIONS in CoreX — the source of
| truth for Ellie's "where do I go to…" answers.
|
| Each entry is keyed by its Laravel route NAME. This is deliberate:
|   - The live URL is resolved at runtime via route($name, [], false), so it
|     is NEVER stale — rename a URL and the atlas follows automatically.
|   - The required permission is derived at runtime from the route's
|     `permission:` middleware (see NavigationAtlasService), so an agent is
|     never pointed at a page they cannot open. Do NOT duplicate permission
|     keys here.
|
| Only include destinations with NO required route parameters (index / create /
| dashboard landing pages). Action routes (POST/PUT), and routes needing a
| model id, are intentionally excluded — they are not places a user "goes to".
|
| Fields per entry:
|   label    — human name of the destination ("Create a Presentation").
|   category — grouping shown to the user ("Presentations").
|   blurb    — one line: what you DO here. Ellie quotes this.
|   keywords — synonyms / phrasings a user might type. Match quality lives here.
|              Add generously; this is the cheapest way to make Ellie smarter.
|   permission — OPTIONAL. Only for routes gated in-controller rather than by
|              `permission:`/`owner_only` middleware. Set the permission key the
|              user must hold; the service checks it in addition to any middleware.
|              Leave unset when the route's own middleware already gates access.
|
| Spec: .ai/specs/ellie-navigation-atlas.md
|
*/

return [

    // ── Command Center / daily ──────────────────────────────────────────
    'command-center.today' => [
        'label' => 'Command Center (Today)',
        'category' => 'Command Center',
        'blurb' => 'Your daily cockpit — today\'s priorities, tasks, and activity in one place.',
        'keywords' => ['command center', 'today', 'home', 'dashboard', 'daily', 'my day', 'cockpit', 'start'],
    ],
    'command-center.calendar' => [
        'label' => 'Calendar',
        'category' => 'Command Center',
        'blurb' => 'View and manage appointments, viewings, and scheduled events.',
        'keywords' => ['calendar', 'diary', 'appointments', 'schedule', 'viewings', 'events', 'meetings'],
    ],
    'command-center.tasks' => [
        'label' => 'Tasks',
        'category' => 'Command Center',
        'blurb' => 'Your to-do list and follow-ups across deals and contacts.',
        'keywords' => ['tasks', 'to do', 'todo', 'follow ups', 'reminders', 'action items'],
    ],
    'command-center.buyers.pipeline' => [
        'label' => 'Buyer Pipeline',
        'category' => 'Command Center',
        'blurb' => 'Track buyers through the pipeline from lead to matched to offer.',
        'keywords' => ['buyer pipeline', 'buyers', 'leads', 'buyer leads', 'pipeline', 'prospective buyers'],
    ],
    'command-center.performance' => [
        'label' => 'Performance',
        'category' => 'Command Center',
        'blurb' => 'See your targets, activity points, and how you are tracking this month.',
        'keywords' => ['performance', 'my performance', 'targets', 'stats', 'kpi', 'points', 'how am i doing'],
    ],

    // ── Property & Market Intelligence ──────────────────────────────────
    'corex.properties.index' => [
        'label' => 'Properties (My Listings)',
        'category' => 'Properties',
        'blurb' => 'Agency stock / mandated listings — add, edit, and manage properties you are selling.',
        'keywords' => ['properties', 'my listings', 'listings', 'stock', 'add property', 'new listing', 'mandates', 'homes', 'houses'],
    ],
    'corex.map.index' => [
        'label' => 'Map',
        'category' => 'Properties',
        'blurb' => 'See properties and market intelligence plotted on a map.',
        'keywords' => ['map', 'map view', 'location', 'plot', 'geographic'],
    ],
    'market-intelligence.work' => [
        'label' => 'Market Intelligence Centre (MIC)',
        'category' => 'Market Intelligence',
        'blurb' => 'Buyer-to-property matching, market narratives, and suburb intelligence.',
        'keywords' => ['market intelligence', 'mic', 'matching', 'matches', 'market', 'suburb intelligence', 'narratives', 'intelligence centre'],
    ],
    'corex.core-matches.index' => [
        'label' => 'Core Matches',
        'category' => 'Market Intelligence',
        'blurb' => 'The strongest buyer↔property matches surfaced by the matching engine.',
        'keywords' => ['core matches', 'matches', 'best matches', 'buyer matches', 'property matches'],
    ],
    'corex.outreach-canvassing.index' => [
        'label' => 'Prospecting / Canvassing',
        'category' => 'Prospecting',
        'blurb' => 'Work tracked properties and canvassing lists to win new mandates.',
        'keywords' => ['prospecting', 'canvassing', 'tracked properties', 'outreach', 'farming', 'door knocking', 'seller outreach'],
    ],
    'corex.outreach-queue.index' => [
        'label' => 'Outreach Queue',
        'category' => 'Prospecting',
        'blurb' => 'Your queued outreach actions to owners and prospects.',
        'keywords' => ['outreach queue', 'queue', 'outreach', 'contact queue'],
    ],
    'corex.viewing-packs.index' => [
        'label' => 'Viewing Packs',
        'category' => 'Properties',
        'blurb' => 'Prepare and manage viewing packs for property showings.',
        'keywords' => ['viewing packs', 'viewing pack', 'show house pack', 'viewings pack'],
    ],
    'corex.portal-leads.index' => [
        'label' => 'Portal Leads',
        'category' => 'Prospecting',
        'blurb' => 'Leads captured from Property24 / portals, ready to convert to contacts.',
        'keywords' => ['portal leads', 'p24 leads', 'property24 leads', 'web leads', 'portal enquiries'],
    ],

    // ── Presentations ───────────────────────────────────────────────────
    'presentations.create' => [
        'label' => 'Create a Presentation',
        'category' => 'Presentations',
        'blurb' => 'Start a new property presentation / CMA. You can also start one from any property\'s page via "Generate Presentation".',
        'keywords' => ['new presentation', 'create presentation', 'make a presentation', 'presentation for a property', 'cma', 'cma presentation', 'listing presentation', 'proposal', 'valuation presentation', 'do a presentation'],
    ],
    'presentations.index' => [
        'label' => 'Presentations',
        'category' => 'Presentations',
        'blurb' => 'Browse and manage all your property presentations and CMAs.',
        'keywords' => ['presentations', 'my presentations', 'cma', 'view presentations', 'presentation list'],
    ],
    'corex.presentations.analytics.index' => [
        'label' => 'Presentation Analytics',
        'category' => 'Presentations',
        'blurb' => 'See how recipients engaged with your presentations.',
        'keywords' => ['presentation analytics', 'presentation views', 'engagement', 'who viewed'],
    ],
    'corex.presentations.outcomes.index' => [
        'label' => 'Presentation Outcomes',
        'category' => 'Presentations',
        'blurb' => 'Record and review the outcomes of your presentations.',
        'keywords' => ['presentation outcomes', 'outcomes', 'presentation results', 'won lost'],
    ],
    'commercial-evaluations.index' => [
        'label' => 'Commercial Evaluations',
        'category' => 'Presentations',
        'blurb' => 'Build evaluations for commercial, agricultural, and specialised properties.',
        'keywords' => ['commercial evaluations', 'commercial', 'agricultural', 'industrial', 'evaluation'],
    ],

    // ── Contacts ────────────────────────────────────────────────────────
    'corex.contacts.index' => [
        'label' => 'Contacts',
        'category' => 'Contacts',
        'blurb' => 'Your people — owners, buyers, tenants, landlords. Add and manage contacts here.',
        'keywords' => ['contacts', 'add contact', 'new contact', 'people', 'clients', 'owners', 'buyers', 'tenants', 'database', 'crm'],
    ],

    // ── Communications ──────────────────────────────────────────────────
    'communications.triage.index' => [
        'label' => 'Communications Triage',
        'category' => 'Communications',
        'blurb' => 'Review and route captured WhatsApp and email communications.',
        'keywords' => ['communications', 'triage', 'messages', 'whatsapp', 'email', 'comms', 'inbox'],
    ],
    'communications.wa-devices.index' => [
        'label' => 'WhatsApp Devices',
        'category' => 'Communications',
        'blurb' => 'Link and manage the WhatsApp devices that capture messages into CoreX.',
        'keywords' => ['whatsapp devices', 'wa devices', 'link whatsapp', 'connect whatsapp', 'device'],
    ],
    'communications.capture.my' => [
        'label' => 'My Communications Capture',
        'category' => 'Communications',
        'blurb' => 'Review your own captured messages before they are filed.',
        'keywords' => ['my comms', 'my messages', 'capture', 'my whatsapp', 'my capture'],
    ],

    // ── Deals & Commission ──────────────────────────────────────────────
    'deals-v2.create' => [
        'label' => 'Create a Deal',
        'category' => 'Deals',
        'blurb' => 'Register a new deal / transaction (sale, rental, mandate, offer).',
        'keywords' => ['new deal', 'create deal', 'register deal', 'add deal', 'capture deal', 'new sale', 'log a sale', 'offer'],
    ],
    'deals-v2.index' => [
        'label' => 'Deals',
        'category' => 'Deals',
        'blurb' => 'Browse and manage all deals in the register.',
        'keywords' => ['deals', 'deal register', 'transactions', 'sales', 'my deals'],
    ],
    'deals-v2.pipeline.index' => [
        'label' => 'Deal Pipeline',
        'category' => 'Deals',
        'blurb' => 'Track deals through each stage of the pipeline.',
        'keywords' => ['deal pipeline', 'pipeline', 'deal stages', 'deals in progress'],
    ],
    'commission.dashboard' => [
        'label' => 'Commission',
        'category' => 'Deals',
        'blurb' => 'View commission earned, splits, and settlement.',
        'keywords' => ['commission', 'my commission', 'earnings', 'splits', 'payout', 'commission dashboard'],
    ],

    // ── Documents & E-Sign ──────────────────────────────────────────────
    'docuperfect.dashboard' => [
        'label' => 'DocuPerfect',
        'category' => 'Documents',
        'blurb' => 'Create, manage, and e-sign documents (OTPs, mandates, agreements).',
        'keywords' => ['docuperfect', 'documents', 'contracts', 'agreements', 'otp', 'mandate document', 'paperwork'],
    ],
    'docuperfect.create' => [
        'label' => 'Create a Document',
        'category' => 'Documents',
        'blurb' => 'Start a new document from a template.',
        'keywords' => ['new document', 'create document', 'make a document', 'draft a contract', 'new otp', 'new mandate'],
    ],
    'docuperfect.esign.create' => [
        'label' => 'Start an E-Signature',
        'category' => 'Documents',
        'blurb' => 'Send a document out for electronic signature.',
        'keywords' => ['e-sign', 'esign', 'e signature', 'electronic signature', 'send for signing', 'sign a document', 'get signatures'],
    ],
    'docuperfect.templates.index' => [
        'label' => 'Document Templates',
        'category' => 'Documents',
        'blurb' => 'Manage the templates documents are built from.',
        'keywords' => ['templates', 'document templates', 'template'],
    ],
    'docuperfect.packs.index' => [
        'label' => 'Document Packs',
        'category' => 'Documents',
        'blurb' => 'Bundle multiple documents into a pack for signing/filing.',
        'keywords' => ['document packs', 'packs', 'bundle documents'],
    ],
    'documents.library.index' => [
        'label' => 'Document Library',
        'category' => 'Documents',
        'blurb' => 'The filed, searchable store of all documents linked to deals, contacts, and properties.',
        'keywords' => ['document library', 'library', 'filed documents', 'find a document', 'stored documents'],
    ],
    'filing-register.index' => [
        'label' => 'Filing Register',
        'category' => 'Documents',
        'blurb' => 'The compliance filing register of documents and their metadata.',
        'keywords' => ['filing register', 'filing', 'register', 'file register'],
    ],

    // ── Compliance ──────────────────────────────────────────────────────
    'compliance.fica.index' => [
        'label' => 'FICA',
        'category' => 'Compliance',
        'blurb' => 'Manage FICA verification and documents for contacts.',
        'keywords' => ['fica', 'fica verification', 'kyc', 'verify identity', 'fica documents'],
    ],
    'compliance.agents' => [
        'label' => 'Agent Compliance',
        'category' => 'Compliance',
        'blurb' => 'Track agent FFCs, CPD, and compliance status.',
        'keywords' => ['agent compliance', 'ffc', 'fidelity fund', 'cpd', 'agent status'],
    ],
    'compliance.rmcp.dashboard.index' => [
        'label' => 'RMCP',
        'category' => 'Compliance',
        'blurb' => 'Risk Management & Compliance Programme dashboard and screening.',
        'keywords' => ['rmcp', 'risk management', 'compliance programme', 'screening'],
    ],
    'compliance.whistleblow.index' => [
        'label' => 'Whistleblower',
        'category' => 'Compliance',
        'blurb' => 'Confidential whistleblower reporting channel.',
        'keywords' => ['whistleblower', 'whistleblow', 'report misconduct', 'anonymous report'],
    ],

    // ── Rentals ─────────────────────────────────────────────────────────
    'rentals.index' => [
        'label' => 'Rentals',
        'category' => 'Rentals',
        'blurb' => 'Manage rental listings, leases, and tenants.',
        'keywords' => ['rentals', 'rental', 'lettings', 'let', 'tenant', 'landlord', 'lease'],
    ],
    'rental.active-leases' => [
        'label' => 'Active Leases',
        'category' => 'Rentals',
        'blurb' => 'View currently active lease agreements.',
        'keywords' => ['active leases', 'leases', 'current leases', 'lease agreements'],
    ],

    // ── HR / Payroll ────────────────────────────────────────────────────
    'payroll.employees.index' => [
        'label' => 'Payroll Employees',
        'category' => 'Payroll & Leave',
        'blurb' => 'Manage employees for payroll processing.',
        'keywords' => ['payroll', 'employees', 'staff', 'salaries'],
    ],
    'payroll.leave.dashboard' => [
        'label' => 'Leave',
        'category' => 'Payroll & Leave',
        'blurb' => 'Apply for and manage leave; view balances.',
        'keywords' => ['leave', 'annual leave', 'sick leave', 'apply for leave', 'leave balance', 'time off', 'holiday'],
    ],
    'payroll.runs.index' => [
        'label' => 'Payroll Runs',
        'category' => 'Payroll & Leave',
        'blurb' => 'Process and review payroll runs.',
        'keywords' => ['payroll runs', 'pay run', 'run payroll', 'payslips'],
    ],

    // ── Tools ───────────────────────────────────────────────────────────
    'tools.cma' => [
        'label' => 'CMA Tool',
        'category' => 'Tools',
        'blurb' => 'Quick comparative market analysis tool.',
        'keywords' => ['cma tool', 'cma', 'comparative market analysis', 'quick cma'],
    ],
    'tools.pdf_suite.hub' => [
        'label' => 'PDF Suite',
        'category' => 'Tools',
        'blurb' => 'Merge, split, redact, and convert PDFs.',
        'keywords' => ['pdf', 'pdf suite', 'merge pdf', 'split pdf', 'redact', 'edit pdf'],
    ],
    'tools.image_converter.index' => [
        'label' => 'Image Converter',
        'category' => 'Tools',
        'blurb' => 'Convert and resize images (e.g. to portal-ready formats).',
        'keywords' => ['image converter', 'convert image', 'resize image', 'webp', 'jpeg'],
    ],
    'calculators.index' => [
        'label' => 'Calculators',
        'category' => 'Tools',
        'blurb' => 'Bond, transfer cost, and other property calculators.',
        'keywords' => ['calculators', 'calculator', 'bond calculator', 'transfer cost', 'affordability'],
    ],
    'revenue-share.calculator' => [
        'label' => 'Revenue Share Calculator',
        'category' => 'Tools',
        'blurb' => 'Model revenue-share splits.',
        'keywords' => ['revenue share', 'rev share', 'revenue share calculator'],
    ],
    'deposit-interest-calculator.index' => [
        'label' => 'Deposit Interest Calculator',
        'category' => 'Tools',
        'blurb' => 'Calculate interest on trust/deposit balances.',
        'keywords' => ['deposit interest', 'trust interest', 'interest calculator'],
    ],
    'tools.ad-manager' => [
        'label' => 'Ad Manager / Syndication',
        'category' => 'Tools',
        'blurb' => 'Push and manage listings on Property24 and Private Property.',
        'keywords' => ['ad manager', 'syndication', 'property24', 'p24', 'private property', 'publish listing', 'portals', 'advertise'],
    ],

    // ── Learning & Help ─────────────────────────────────────────────────
    'training.index' => [
        'label' => 'Training',
        'category' => 'Learning',
        'blurb' => 'Courses and lessons in the CoreX learning centre.',
        'keywords' => ['training', 'courses', 'lessons', 'learn', 'lms'],
    ],
    'training-help.index' => [
        'label' => 'Help & Guides',
        'category' => 'Learning',
        'blurb' => 'How-to guides and reference for using CoreX.',
        'keywords' => ['help', 'guides', 'how to', 'documentation', 'manual', 'support'],
    ],
    'ellie.index' => [
        'label' => 'Ellie',
        'category' => 'Learning',
        'blurb' => 'Chat with Ellie, your CoreX AI assistant.',
        'keywords' => ['ellie', 'ai assistant', 'chat', 'ask ellie'],
    ],

    // ── People / onboarding ─────────────────────────────────────────────
    'onboarding.index' => [
        'label' => 'Agent Onboarding',
        'category' => 'People',
        'blurb' => 'Onboard new agents — applications, documents, and activation.',
        'keywords' => ['onboarding', 'onboard agent', 'new agent', 'agent application', 'take on agent'],
    ],
    'staff-take-on.index' => [
        'label' => 'Staff Take-On',
        'category' => 'People',
        'blurb' => 'Take on new staff members.',
        'keywords' => ['staff take-on', 'take on staff', 'new staff', 'new employee'],
    ],

    // ── Admin & Settings ────────────────────────────────────────────────
    'corex.settings' => [
        'label' => 'Settings',
        'category' => 'Admin',
        'blurb' => 'Your CoreX settings and preferences.',
        'keywords' => ['settings', 'preferences', 'my settings', 'configure'],
    ],
    'admin.company-settings' => [
        'label' => 'Company Settings',
        'category' => 'Admin',
        'blurb' => 'Agency-wide settings — branding, branches, and configuration.',
        'keywords' => ['company settings', 'agency settings', 'branding', 'colours', 'logo', 'branches'],
    ],
    'admin.knowledge.index' => [
        'label' => 'Knowledge Base (Admin)',
        'category' => 'Admin',
        'blurb' => 'Upload and manage the documents Ellie learns from.',
        'keywords' => ['knowledge base', 'knowledge', 'ellie knowledge', 'upload documents', 'train ellie'],
    ],
    'admin.ai-usage.index' => [
        'label' => 'AI Usage & Costs',
        'category' => 'Admin',
        'blurb' => 'Monitor AI usage and spend against the agency budget.',
        'keywords' => ['ai usage', 'ai cost', 'ai spend', 'ai budget', 'ellie cost'],
    ],
    'admin.api.catalog' => [
        'label' => 'API Catalog',
        'category' => 'Admin',
        'blurb' => 'Browse the registered CoreX API endpoints.',
        'keywords' => ['api', 'api catalog', 'endpoints', 'integrations api'],
    ],
    'agencies.index' => [
        'label' => 'Agencies',
        'category' => 'Admin',
        'blurb' => 'Manage agencies on the platform (super admin).',
        'keywords' => ['agencies', 'tenants', 'manage agencies'],
    ],
    'corex.role-manager' => [
        'label' => 'Roles & Permissions',
        'category' => 'Admin',
        'blurb' => 'Manage roles and permission assignments.',
        'keywords' => ['roles', 'permissions', 'role manager', 'access', 'user permissions'],
    ],
    'admin.tv-messages' => [
        'label' => 'TV Messages',
        'category' => 'Admin',
        'blurb' => 'Manage messages shown on the office TV display.',
        'keywords' => ['tv messages', 'tv display', 'office tv', 'noticeboard'],
    ],
    'corex.guided-tours.index' => [
        'label' => 'Guided Tours',
        'category' => 'Admin',
        'blurb' => 'Manage the in-app guided tours.',
        'keywords' => ['guided tours', 'tours', 'walkthroughs', 'help tour'],
    ],
];
