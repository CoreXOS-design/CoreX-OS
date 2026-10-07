<?php

/**
 * CoreX Per-Agency Feature Registry — the catalogue.
 *
 * Spec: .ai/specs/corex-feature-registry.md §5.
 *
 * ONE entry per toggleable (or core) feature. This file is the single source of
 * truth that feeds — from one row — nav-gating (@feature), route-gating
 * (feature:<key> middleware), the Settings → Features page, AND the onboarding
 * wizard's capabilities step. Add a feature here and all four surfaces pick it up.
 *
 * The feature gate is ORTHOGONAL to permissions (spec §3.1): nav/route requires
 * permission AND feature. A feature ON never grants a permission. A feature OFF
 * hides the module for the whole agency but NEVER deletes data (spec §3.2).
 *
 * Entry shape (spec §5.1):
 *   'key' => [
 *     'label'            => string,   // plain-English module name (STANDARDS F.8)
 *     'category'         => string,   // grouping label for the Settings page
 *     'explain'          => string,   // full sentence: what the module IS
 *     'affects'          => string,   // "What this changes:" — concrete, observable; NO tautology
 *     'default'          => bool,     // on/off for a BRAND-NEW agency
 *     'core'             => bool,     // core features are never toggleable (always on)
 *     'depends_on'       => array,    // [feature keys] — resolves off if ANY parent is off
 *     'nav_permission'   => array,    // the permission(s) the nav item already checks (for the @feature map)
 *     'sidebar_section'  => ?string,  // sidebar fly-out group key (nav map / guard test)
 *     'settings_section' => ?string,  // $railGroups anchor in corex/settings.blade.php (null if none)
 *     'route_prefixes'   => array,    // verified ->prefix() strings for the phase-4 feature:<key> middleware
 *     'global_flag'      => ?string,  // optional key in config/features.php for the outer AND kill-switch
 *     'route_names'      => array,    // OPTIONAL — Str::is() globs of the signed-in web routes this feature owns.
 *                                     //   App\Http\Middleware\EnforceFeatureRoutes 404s them when the feature is off
 *                                     //   (in addition to any explicit `feature:` middleware already on the route).
 *     'view_dirs'        => array,    // OPTIONAL — views/ path prefixes of the feature's OWN screens. Any other view
 *                                     //   that links to its routes must wrap the link in @feature('<key>')
 *                                     //   (guard: FeatureNavGuardCoverageTest).
 *   ]
 *
 * STANDING RULE (Andre + Johan, 2026-10-07): every sidebar item is switchable on/off here. The sidebar guard
 * (SidebarNavAuditor + FeatureNavGuardCoverageTest) fails when a sidebar link sits outside every @feature
 * wrap and is not one of the explicit core / platform-owner exemptions in
 * App\Support\Navigation\SidebarFeatureCoverage. New sidebar item => new row here, same commit.
 *
 * Resolution order (AgencyFeatureService::enabled, spec §3.5):
 *   unknown key => false | global_flag off => false | core => true
 *   | depends_on parent off => false | agency_features row => row | else default
 *
 * NOTE (Phase boundaries): the six switchboard-origin capability toggles
 * (marketing, syndication-p24, syndication-pp, core-matches, multi-branch,
 * public-website) are catalogue rows here in Phase 1 and resolve via the generic
 * default/agency_features path. Phase 2 wires a store adapter so they read their
 * EXISTING stores (PerformanceSetting keys + agencies columns) live — until then
 * nothing gates on them, so the Phase-1 default resolution is invisible.
 */

return [

    // ══════════════════════════════════════════════════════════════════════
    // CORE — never toggleable (spec §5.2). enabled() short-circuits to true.
    // Carried as rows so nav/onboarding can reference them; no toggle rendered.
    // ══════════════════════════════════════════════════════════════════════

    'dashboard' => [
        'label' => 'Dashboard', 'category' => 'Core',
        'explain' => 'The Command Center home — today\'s work, calendar, tasks and performance.',
        'affects' => 'Always available. This is every user\'s home screen and cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['view_dashboard'], 'sidebar_section' => 'command-center',
        'settings_section' => 'feature-dashboard', 'route_prefixes' => ['command-center'], 'global_flag' => null,
    ],
    'properties' => [
        'label' => 'Properties', 'category' => 'Core',
        'explain' => 'The Property pillar — your listing stock and everything attached to it.',
        'affects' => 'Always available. Properties are a core pillar and cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['access_properties'], 'sidebar_section' => 'real-estate',
        'settings_section' => 'feature-properties', 'route_prefixes' => ['properties', 'map'], 'global_flag' => null,
    ],
    'contacts' => [
        'label' => 'Contacts', 'category' => 'Core',
        'explain' => 'The Contact pillar — buyers, sellers, tenants, landlords and everyone else.',
        'affects' => 'Always available. Contacts are a core pillar and cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['access_contacts'], 'sidebar_section' => 'real-estate',
        'settings_section' => 'feature-contacts', 'route_prefixes' => ['contacts'], 'global_flag' => null,
    ],
    'deals' => [
        'label' => 'Deal Register', 'category' => 'Core',
        'explain' => 'The Deal pillar — the register of every sale and rental transaction.',
        'affects' => 'Always available. Deals are a core pillar and cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['view_deals'], 'sidebar_section' => 'agency-tracker',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'agents' => [
        'label' => 'Users & Agents', 'category' => 'Core',
        'explain' => 'The Agent pillar — your people, their roles, FFCs and performance.',
        'affects' => 'Always available. Managing your people is core and cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'my-portal' => [
        'label' => 'My Portal', 'category' => 'Core',
        'explain' => 'Each user\'s personal home — their tools, links and settings.',
        'affects' => 'Always available. Every user\'s personal portal cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['access_my_portal'], 'sidebar_section' => null,
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'settings' => [
        'label' => 'Settings', 'category' => 'Core',
        'explain' => 'The settings area where your agency is configured.',
        'affects' => 'Always available. You configure CoreX here; it cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['access_settings'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'company-settings' => [
        'label' => 'Company Settings', 'category' => 'Core',
        'explain' => 'Your agency\'s company identity, branches and branding.',
        'affects' => 'Always available. Core tenant configuration cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['manage_performance_settings'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'role-manager' => [
        'label' => 'Roles & Permissions', 'category' => 'Core',
        'explain' => 'The Role Manager that decides what each person in your agency may do.',
        'affects' => 'Always available. Access governance is core and cannot be switched off.',
        'default' => true, 'core' => true, 'depends_on' => [],
        'nav_permission' => ['access_role_manager'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],

    // ══════════════════════════════════════════════════════════════════════
    // SWITCHBOARD-ORIGIN CAPABILITY TOGGLES (the first six registry rows, spec §7).
    // Backing store kept; Phase 2 wires the live adapter. No dedicated nav panel
    // / route prefix for most — these are cross-cutting capabilities, not pages.
    // ══════════════════════════════════════════════════════════════════════

    'marketing' => [
        'label' => 'Marketing', 'category' => 'Listings & Marketing',
        'explain' => 'CoreX\'s marketing tooling for your listings — social posts, brochures and campaign tracking attached to each property.',
        'affects' => 'Whether the Marketing area and its buttons appear for your agents when they open a listing. Off hides the tools; nothing already created is deleted.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'real-estate',
        'settings_section' => 'feature-properties', 'route_prefixes' => [], 'global_flag' => null,
    ],
    'syndication-p24' => [
        'label' => 'Publish to Property24', 'category' => 'Listings & Marketing',
        'explain' => 'Whether CoreX pushes your active mandates to Property24 automatically when a listing is marked to syndicate.',
        'affects' => 'Whether a syndicating listing is sent to Property24. Nothing sends with this off, even with your P24 credentials saved.',
        'default' => false, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'real-estate',
        'settings_section' => 'feature-properties', 'route_prefixes' => [], 'global_flag' => null,
    ],
    'syndication-pp' => [
        'label' => 'Publish to Private Property', 'category' => 'Listings & Marketing',
        'explain' => 'Whether CoreX pushes your active mandates to Private Property automatically when a listing is marked to syndicate.',
        'affects' => 'Whether a syndicating listing is sent to Private Property. Needs your PP credentials saved against the agency first.',
        'default' => false, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'real-estate',
        'settings_section' => 'feature-properties', 'route_prefixes' => [], 'global_flag' => null,
    ],
    'core-matches' => [
        'label' => 'Core Matches', 'category' => 'Buyers & Matching',
        'explain' => 'Whether CoreX matches every new listing against your buyers\' wishlists in the background, so the right buyer surfaces the moment a fitting property lands.',
        'affects' => 'Whether your agents are told who to call when a new listing lands. Off means no match alerts.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_core_matches'], 'sidebar_section' => 'real-estate',
        'settings_section' => 'feature-matches', 'route_prefixes' => ['corex/core-matches'], 'global_flag' => null,
    ],
    'multi-branch' => [
        'label' => 'Multi-branch offices', 'category' => 'People & Payroll',
        'explain' => 'Whether your agency runs as more than one branch, each with its own agents and its own book of properties, contacts and deals.',
        'affects' => 'Whether agents are grouped by branch and whether a branch is credited on commission. With it on, an agent in one branch will not see another branch\'s data.',
        'default' => false, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => null,
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'public-website' => [
        'label' => 'Public website', 'category' => 'Listings & Marketing',
        'explain' => 'Whether your agency\'s public CoreX website — your agents, listings and branches — is live and reachable to the public.',
        'affects' => 'Whether your public site is online or offline. Off takes the whole public site down without touching any internal data.',
        'default' => false, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => null,
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],

    // ══════════════════════════════════════════════════════════════════════
    // MODULE / PAGE FEATURES (new agency_features rows).
    // ══════════════════════════════════════════════════════════════════════

    // ── Prospecting & Outreach ──
    'prospecting' => [
        'label' => 'Market Intelligence / Prospecting', 'category' => 'Prospecting & Outreach',
        'explain' => 'The prospecting engine — Market Intelligence Centre, tracked properties, and canvassing intelligence that turns portal and captured data into leads.',
        'affects' => 'Whether the Market Intelligence and Prospecting areas appear and whether CoreX builds its property-intelligence dataset from your activity. Off hides them; captured data is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_prospecting'], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['prospecting', 'corex/market-intelligence', 'corex/tracked-properties'], 'global_flag' => null,
    ],
    'portal-leads' => [
        'label' => 'Portal Leads', 'category' => 'Prospecting & Outreach',
        'explain' => 'The inbox of buyer enquiries that arrive from Property24 and Private Property, ready to be actioned and assigned.',
        'affects' => 'Whether the Portal Leads inbox appears for your agents. Off hides it; incoming leads are still recorded.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_portal_leads'], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['real-estate/portal-leads'], 'global_flag' => null,
    ],
    'outreach' => [
        'label' => 'Seller Outreach & Canvassing', 'category' => 'Prospecting & Outreach',
        'explain' => 'The outreach workflow — canvassing, the WhatsApp outreach summary, the outreach queue, and standalone seller info packs.',
        'affects' => 'Whether the Outreach and Canvassing areas appear and whether CoreX runs its seller-outreach queue. Off hides them; existing outreach history is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['outreach.summary.view'], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['real-estate/outreach-summary', 'real-estate/outreach-canvassing', 'real-estate/outreach-queue', 'compliance/seller-info'], 'global_flag' => null,
    ],

    // ── Presentations & Valuations ──
    'presentations' => [
        'label' => 'Presentations & CMA', 'category' => 'Presentations & Valuations',
        'explain' => 'The seller-presentation and Comparative Market Analysis suite that builds the evidenced valuation you put in front of a seller.',
        'affects' => 'Whether the Presentations area, analytics and CMA tools appear for your agents. Off hides them; published presentations are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_presentations'], 'sidebar_section' => 'real-estate',
        'settings_section' => 'feature-presentations', 'route_prefixes' => ['presentations', 'corex/presentations/refresh-requests'], 'global_flag' => 'presentations',
    ],
    'commercial-evaluations' => [
        'label' => 'Commercial Evaluations', 'category' => 'Presentations & Valuations',
        'explain' => 'The commercial-property evaluation tool for valuing income-producing and commercial stock.',
        'affects' => 'Whether the Commercial Evaluations area appears for your agents. Off hides it; saved evaluations are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_commercial_evaluations'], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['commercial-evaluations'], 'global_flag' => null,
    ],
    'viewing-packs' => [
        'label' => 'Viewing Packs', 'category' => 'Presentations & Valuations',
        'explain' => 'Curated packs of properties to take a buyer on a viewing day.',
        'affects' => 'Whether the Viewing Packs area appears for your agents. Off hides it; saved packs are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_viewing_packs'], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['viewing-packs'], 'global_flag' => null,
    ],

    // ── Documents & E-Sign ──
    'docuperfect' => [
        'label' => 'DocuPerfect (documents & e-sign)', 'category' => 'Documents & E-Sign',
        'explain' => 'The document suite — create mandates, offers and leases from templates, manage clauses and packs, and send documents for e-signature.',
        'affects' => 'Whether the Documents (DocuPerfect) area and e-signature appear for your agents. Off hides them; existing documents and signatures are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_docuperfect'], 'sidebar_section' => 'documents',
        'settings_section' => 'feature-documents', 'route_prefixes' => ['docuperfect'], 'global_flag' => null,
    ],
    'document-library' => [
        'label' => 'Document Library', 'category' => 'Documents & E-Sign',
        'explain' => 'A searchable library of the agency\'s documents, filed and retrievable in one place.',
        'affects' => 'Whether the Document Library appears in Tools. Off hides it; filed documents are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_document_library'], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['documents'], 'global_flag' => 'document_library_v1',
    ],
    'shared-drive' => [
        'label' => 'Shared Drive', 'category' => 'Documents & E-Sign',
        'explain' => 'A shared file drive for the agency\'s general documents outside the deal flow.',
        'affects' => 'Whether the Shared Drive appears for your agents. Off hides it; stored files are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_shared_drive'], 'sidebar_section' => 'documents',
        'settings_section' => null, 'route_prefixes' => ['documents/shared-drive'], 'global_flag' => null,
    ],
    'filing-register' => [
        'label' => 'Filing Register', 'category' => 'Documents & E-Sign',
        'explain' => 'The register that tracks where every filed document lives for audit and retrieval.',
        'affects' => 'Whether the Filing Register appears in Tools. Off hides it; the register data is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_filing_register'], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['filing-register'], 'global_flag' => null,
    ],

    // ── Compliance ──
    'compliance' => [
        'label' => 'Compliance', 'category' => 'Compliance',
        'explain' => 'The compliance suite — FICA verification, RMCP, POPIA policies, staff screening, document verification and whistleblower reporting.',
        'affects' => 'Whether the Compliance area appears for your agents and whether CoreX runs its compliance checklists. Off hides it; compliance records are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_compliance'], 'sidebar_section' => 'compliance',
        'settings_section' => null, 'route_prefixes' => ['compliance/fica', 'compliance/rmcp-dashboard', 'compliance/policy-dashboard', 'compliance/screenings', 'compliance/screening-dashboard', 'compliance/verification-queue', 'compliance/document-types', 'compliance/agency-settings', 'compliance/whistleblow'], 'global_flag' => null,
    ],

    // ── Communications ──
    'communications' => [
        'label' => 'Communications', 'category' => 'Communications',
        'explain' => 'WhatsApp and email capture, the immutable message archive, triage and the flagged-message register — the FICA/POPIA communication evidence backbone.',
        'affects' => 'Whether the Communications area appears and whether CoreX captures and archives messages. Off hides it; the existing archive is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_communication', 'triage_communications', 'access_communication_archive'], 'sidebar_section' => 'communication',
        'settings_section' => null, 'route_prefixes' => ['communications/wa-devices', 'communications/wa-link', 'communications/capture', 'communications/triage', 'compliance/communications', 'compliance/communication-archive', 'compliance/communication-mailboxes', 'compliance/communication-flags', 'settings/email-setup', 'my-portal/communication-capture'], 'global_flag' => null,
    ],

    // ── Deals & Commission ──
    'agency-tracker' => [
        'label' => 'Agency Tracker', 'category' => 'Deals & Commission',
        'explain' => 'The performance spine — worksheets, targets, daily-activity capture, listing stock and the deal register that drive your agency\'s numbers.',
        'affects' => 'Whether the Agency Tracker area (worksheets, targets, performance, deal register) appears for your agents. Off hides it; the underlying deals and stats are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_agency_tracker'], 'sidebar_section' => 'agency-tracker',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'commission-management' => [
        'label' => 'Commission Management', 'category' => 'Deals & Commission',
        'explain' => 'The principal\'s commission overview and management tools that sit on top of the deal register.',
        'affects' => 'Whether the Commission Management area appears for principals. Off hides it; commission calculations are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['agency-tracker'],
        'nav_permission' => [], 'sidebar_section' => 'agency-tracker',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
        'route_names' => ['commission.dashboard'],
        'view_dirs' => ['commission/'],
    ],
    'proforma-invoices' => [
        'label' => 'Proforma Invoices', 'category' => 'Deals & Commission',
        'explain' => 'Generate proforma invoices for a deal\'s commission and fees.',
        'affects' => 'Whether the Proforma Invoices area appears for your admins. Off hides it; issued invoices are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['proforma.manage'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['proforma'], 'global_flag' => null,
    ],

    // ── People & Payroll ──
    'payroll' => [
        'label' => 'Payroll', 'category' => 'People & Payroll',
        'explain' => 'The payroll module — employees, earning and deduction types, and payroll runs.',
        'affects' => 'Whether the Payroll area appears for your managers and whether CoreX runs payroll. Off hides it; payroll records are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['manage_payroll', 'run_payroll', 'view_payroll_reports'], 'sidebar_section' => 'payroll',
        'settings_section' => null, 'route_prefixes' => ['payroll'], 'global_flag' => null,
    ],
    'leave' => [
        'label' => 'Leave Management', 'category' => 'People & Payroll',
        'explain' => 'Leave applications, balances, leave types, public holidays and leave reporting.',
        'affects' => 'Whether the Leave Management area appears and whether staff can apply for leave. Off hides it; leave history is kept.',
        'default' => true, 'core' => false, 'depends_on' => ['payroll'],
        'nav_permission' => ['manage_leave', 'approve_leave', 'apply_for_leave'], 'sidebar_section' => 'leave',
        'settings_section' => null, 'route_prefixes' => ['payroll/leave', 'my-portal/leave'], 'global_flag' => null,
    ],
    'staff-take-on' => [
        'label' => 'Staff Take-On', 'category' => 'People & Payroll',
        'explain' => 'The onboarding workflow for taking on a new staff member into the agency.',
        'affects' => 'Whether the Staff Take-On area appears for your admins. Off hides it; take-on records are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['manage_staff_take_on'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['staff-take-on'], 'global_flag' => null,
    ],
    'agent-onboarding' => [
        'label' => 'Agent Onboarding', 'category' => 'People & Payroll',
        'explain' => 'The pipeline for agent applications and bringing new agents on board.',
        'affects' => 'Whether the Agent Onboarding area appears for your admins. Off hides it; applications are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'admin',
        'settings_section' => null, 'route_prefixes' => ['onboarding'], 'global_flag' => null,
    ],
    'rentals' => [
        'label' => 'Rentals', 'category' => 'Rentals',
        'explain' => 'The whole Rentals area — leases, applications, inspections, maintenance, and the rental views of your contacts, properties and pipeline. This is the master switch for every rental module listed under it.',
        'affects' => 'Whether the Rentals menu and every rental screen appear for your users, and whether CoreX chases rental renewals. Off hides all of Rentals at once (the individual rental modules below switch off with it); existing leases and records are untouched and reappear when you turn it back on.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['view_rentals'], 'sidebar_section' => 'rental-applications',
        'settings_section' => 'feature-rentals', 'route_prefixes' => ['rental', 'rentals'], 'global_flag' => null,
        // Settings-page configuration routes (rental.settings.*) are deliberately NOT owned here: an agency can still
        // configure Rentals while it is switched off, and the Settings screens stay reachable.
        'route_names' => ['corex.rentals.contacts.*', 'corex.rentals.properties.*', 'corex.rentals.pipeline.*', 'corex.rentals.core-matches.*', 'rental.dashboard', 'rental.active-leases', 'rental.expired-leases', 'rental.signatures', 'rentals.permissions*'],
        // corex/contacts/index is the shared Contacts list; it only emits rentals.contacts links when opened through the Rentals lens route itself.
        'view_dirs' => ['rental/', 'rentals/', 'corex/contacts/index.blade.php'],
    ],

    // ── Tools & Calculators ──
    'pdf-suite' => [
        'label' => 'PDF Suite', 'category' => 'Tools & Calculators',
        'explain' => 'The PDF toolkit — merge, split, redact, convert and enhance PDF documents.',
        'affects' => 'Whether the PDF Suite appears in Tools. Off hides it; it stores no data.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_pdf_suite', 'access_pdf_splitter'], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['tools/pdf-suite'], 'global_flag' => null,
    ],
    'image-converter' => [
        'label' => 'Image Converter', 'category' => 'Tools & Calculators',
        'explain' => 'A tool that converts images between formats for listings and documents.',
        'affects' => 'Whether the Image Converter appears in Tools. Off hides it; it stores no data.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_image_converter'], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['tools/image-converter'], 'global_flag' => null,
    ],
    'calculators' => [
        'label' => 'Calculators', 'category' => 'Tools & Calculators',
        'explain' => 'The calculator set — commission, CMA certificate, deposit interest and related quick tools.',
        'affects' => 'Whether the Calculators appear for your agents. Off hides them; they store no data.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_calculators', 'access_deposit_calculator'], 'sidebar_section' => 'trust-interest',
        'settings_section' => null, 'route_prefixes' => ['deposit-interest-calculator', 'calculators'], 'global_flag' => null,
    ],
    'trust-interest' => [
        'label' => 'Trust Interest Register', 'category' => 'Tools & Calculators',
        'explain' => 'The register of interest earned on trust-account deposits, for compliance and reconciliation.',
        'affects' => 'Whether the Trust Interest Register appears for your admins. Off hides it; register data is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_trust_interest'], 'sidebar_section' => 'trust-interest',
        'settings_section' => null, 'route_prefixes' => ['admin/deposit-trust-interest'], 'global_flag' => null,
    ],
    'tv-display' => [
        'label' => 'TV Display', 'category' => 'Tools & Calculators',
        'explain' => 'Office TV messages and leaderboards shown on a wall-mounted display.',
        'affects' => 'Whether the TV Display and its message manager appear. Off hides them; saved messages are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['manage_tv_messages'], 'sidebar_section' => 'agency-tracker',
        'settings_section' => null, 'route_prefixes' => [], 'global_flag' => null,
    ],
    'guided-tours' => [
        'label' => 'Guided Tours', 'category' => 'Tools & Calculators',
        'explain' => 'Interactive in-app walkthroughs that teach agents how to use each area.',
        'affects' => 'Whether the guided tours and their launcher are available. Off hides them; they store no agency data.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['corex/guided-tours'], 'global_flag' => null,
    ],

    // ── Learning & AI ──
    'ellie' => [
        'label' => 'Ellie AI', 'category' => 'Learning & AI',
        'explain' => 'The CoreX AI assistant that answers questions and helps agents get work done by voice or chat.',
        'affects' => 'Whether Ellie appears for your agents. Off hides the assistant; it stores no listings or contacts.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_ellie'], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['ellie'], 'global_flag' => null,
        'route_names' => ['admin.ellie.reference-sources.*'],
        'view_dirs' => ['admin/ellie/'],
    ],
    'training' => [
        'label' => 'Training', 'category' => 'Learning & AI',
        'explain' => 'The learning centre — training courses, help guides and their management.',
        'affects' => 'Whether the Training area appears for your agents. Off hides it; course progress is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['training', 'training-help'], 'global_flag' => null,
    ],
    'knowledge-base' => [
        'label' => 'Knowledge Base', 'category' => 'Learning & AI',
        'explain' => 'A searchable knowledge base of agency articles, procedures and reference material.',
        'affects' => 'Whether the Knowledge Base appears for your admins. Off hides it; articles are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_knowledge_base'], 'sidebar_section' => 'admin',
        'settings_section' => null, 'route_prefixes' => ['admin/knowledge'], 'global_flag' => null,
    ],

    // ── Marketing add-ons ──
    'ad-manager' => [
        'label' => 'Ad Manager', 'category' => 'Listings & Marketing',
        'explain' => 'The ad-manager surface for building and tracking listing adverts across channels.',
        'affects' => 'Whether the Ad Manager appears for your agents. Off hides it; created ads are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['marketing'],
        'nav_permission' => ['access_ad_manager'], 'sidebar_section' => 'tools',
        'settings_section' => null, 'route_prefixes' => ['tools/ad-manager'], 'global_flag' => null,
    ],
    'marketing-suppressions' => [
        'label' => 'Marketing Suppressions', 'category' => 'Listings & Marketing',
        'explain' => 'The suppression list of contacts who have opted out of marketing communications.',
        'affects' => 'Whether the Marketing Suppressions area appears for your admins. Off hides it; the opt-out list is kept and still honoured.',
        'default' => true, 'core' => false, 'depends_on' => ['marketing'],
        'nav_permission' => ['marketing_suppressions.view'], 'sidebar_section' => 'admin',
        'settings_section' => null, 'route_prefixes' => ['admin/marketing-suppressions'], 'global_flag' => null,
    ],


    // ══════════════════════════════════════════════════════════════════════
    // SIDEBAR-COMPLETENESS TOGGLES — added 2026-10-07 (Andre + Johan: every
    // sidebar item must be switchable under Settings → Features on/off).
    // All default ON so nothing disappears for an existing agency. Each owns
    // its signed-in web routes via `route_names` (404 when off, enforced by
    // App\Http\Middleware\EnforceFeatureRoutes) and its sidebar item via an
    // @feature wrap; other screens that link to it wrap the link too.
    // ══════════════════════════════════════════════════════════════════════

    // ── Rentals (children of the 'rentals' master switch; one per sidebar item) ──
    'rental-command-centre' => [
        'label' => 'Rental Command Centre', 'category' => 'Rentals',
        'explain' => 'The one-screen overview of every rental property — what is due, overdue or needs attention across leases, inspections, faults and maintenance.',
        'affects' => 'Whether the Command Centre appears at the top of the Rentals menu. Off hides it and its print view; the leases and records it summarises are untouched.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_command_centre.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rentals/command-centre'], 'global_flag' => null,
        'route_names' => ['corex.rentals.command-centre.*'],
        'view_dirs' => ['corex/rentals/command-centre/'],
    ],
    'rental-reports' => [
        'label' => 'Rental Reports', 'category' => 'Rentals',
        'explain' => 'The rental reports — property history, tenancy and portfolio reports with print versions.',
        'affects' => 'Whether Reports appears in the Rentals menu. Off hides the reports; no rental data is removed.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_reports.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rentals/reports'], 'global_flag' => null,
        'route_names' => ['corex.rentals.reports.*'],
        'view_dirs' => ['corex/rentals/reports/'],
    ],
    'rental-applications' => [
        'label' => 'Rental Applications', 'category' => 'Rentals',
        'explain' => 'Tenant rental applications — send the application link, collect documents, assess affordability and have an authoriser approve or decline.',
        'affects' => 'Whether Rental Applications and Rental Application Authorisation appear in the Rentals menu, and the Applications tab on a contact. Off hides them; submitted applications are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_applications.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-applications'], 'global_flag' => null,
        'route_names' => ['corex.rental-applications.*'],
        'view_dirs' => ['corex/rental-applications/'],
    ],
    'rental-leases' => [
        'label' => 'Leases', 'category' => 'Rentals',
        'explain' => 'Lease capture and management — create a lease, activate it, renew or cancel it, send notices and track the tenancy.',
        'affects' => 'Whether Leases appears in the Rentals menu and whether lease links appear on properties and rental screens. Off hides them; existing leases and their dates are untouched.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['leases.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/leases'], 'global_flag' => null,
        'route_names' => ['corex.leases.*'],
        'view_dirs' => ['corex/leases/'],
    ],
    'rental-take-on-import' => [
        'label' => 'Rental Take-On Import', 'category' => 'Rentals',
        'explain' => 'Bulk import of an existing rental portfolio (properties, tenants and leases) from a spreadsheet when you move onto CoreX.',
        'affects' => 'Whether Rental Take-On Import appears in the Rentals menu. Off hides the importer; already-imported rentals are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rentals_take_on_import.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rentals/take-on-import'], 'global_flag' => null,
        'route_names' => ['corex.rentals.take-on-import.*'],
        'view_dirs' => ['corex/rentals/take-on-import/'],
    ],
    'rental-inspections' => [
        'label' => 'Rental Inspections & Inventories', 'category' => 'Rentals',
        'explain' => 'Move-in, periodic and move-out inspections and inventories — room-by-room condition, photos, signatures and deposit comparison.',
        'affects' => 'Whether Rental Inspections appears in the Rentals menu and whether inspection and inventory links appear on leases, properties and contacts. Off hides them; completed inspections and reports are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_inspections.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-inspections', 'corex/rental-inventories'], 'global_flag' => null,
        'route_names' => ['corex.rental-inspections.*', 'corex.rental-inventories.*'],
        'view_dirs' => ['corex/rental-inspections/', 'corex/rental-inventories/'],
    ],
    'rental-faults' => [
        'label' => 'Rental Fault Reports & Types', 'category' => 'Rentals',
        'explain' => 'Maintenance fault reporting — the fault types you offer and the fault reports raised against a rental property.',
        'affects' => 'Whether Rental Fault Types and Rental Fault Reports appear in the Rentals menu and whether fault links appear on rental screens. Off hides them; logged faults are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_fault_reports.view', 'rental_fault_types.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-fault-reports', 'corex/rental-fault-types'], 'global_flag' => null,
        'route_names' => ['corex.rental-fault-reports.*', 'corex.rental-fault-types.*'],
        'view_dirs' => ['corex/rental-fault-reports/', 'corex/rental-fault-types/'],
    ],
    'rental-work-orders' => [
        'label' => 'Rental Work Orders', 'category' => 'Rentals',
        'explain' => 'Work orders for rental maintenance — quotes, landlord approval, supplier assignment, variations and completion.',
        'affects' => 'Whether Rental Work Orders appears in the Rentals menu and whether work-order links appear on fault reports, leases and properties. Off hides them; existing work orders are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_work_orders.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-work-orders'], 'global_flag' => null,
        'route_names' => ['corex.rental-work-orders.*'],
        'view_dirs' => ['corex/rental-work-orders/', 'corex/rental-completion/'],
    ],
    'rental-notices' => [
        'label' => 'Rental Notices', 'category' => 'Rentals',
        'explain' => 'Formal notices to tenants and landlords — generated from templates, sent and filed against the lease.',
        'affects' => 'Whether Rental Notices appears in the Rentals menu. Off hides it; notices already sent stay filed on their leases.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals', 'rental-leases'],
        'nav_permission' => ['rental_notices.create'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-notices'], 'global_flag' => null,
        'route_names' => ['corex.rental-notices.*'],
        'view_dirs' => ['corex/rental-notices/'],
    ],
    'rental-job-cards' => [
        'label' => 'Rental Job Cards', 'category' => 'Rentals',
        'explain' => 'Job cards for the maintenance work itself — crew assignment, parts and labour lines, pricing, tenant confirmation and sign-off.',
        'affects' => 'Whether Job Cards appears in the Rentals menu and whether job-card links appear on leases, inspections and work orders. Off hides them; existing job cards are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_job_cards.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-job-cards'], 'global_flag' => null,
        'route_names' => ['corex.rental-job-cards.*'],
        'view_dirs' => ['corex/rental-job-cards/', 'corex/leases/_job-cards-panel.blade.php'],
    ],
    'rental-catalogue' => [
        'label' => 'Parts & Labour Catalogue', 'category' => 'Rentals',
        'explain' => 'Your catalogue of priced parts and labour items used to build job-card lines, with bulk import.',
        'affects' => 'Whether the Parts & Labour Catalogue appears in the Rentals menu. Off hides it; catalogue items and the prices already on job cards are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_catalogue.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-catalogue-items'], 'global_flag' => null,
        'route_names' => ['corex.rental-catalogue-items.*'],
        'view_dirs' => ['corex/rental-catalogue-items/'],
    ],
    'rental-crews' => [
        'label' => 'Rental Crews', 'category' => 'Rentals',
        'explain' => 'Your in-house maintenance crews and their members, who can be assigned to job cards and given a secure link to their jobs.',
        'affects' => 'Whether Rental Crews appears in the Rentals menu and whether crew assignment appears on job cards. Off hides them; crews and members are kept.',
        'default' => true, 'core' => false, 'depends_on' => ['rentals'],
        'nav_permission' => ['rental_catalogue.view'], 'sidebar_section' => 'rental-applications',
        'settings_section' => null, 'route_prefixes' => ['corex/rental-crews'], 'global_flag' => null,
        'route_names' => ['corex.rental-crews.*'],
        'view_dirs' => ['corex/rental-crews/'],
    ],

    // ── Real Estate group ──
    'deeds-capture' => [
        'label' => 'Deeds Capture', 'category' => 'Prospecting & Outreach',
        'explain' => 'Review and promote property and owner details captured from the Deeds Office into your tracked properties.',
        'affects' => 'Whether Deeds Capture appears in the Real Estate menu. Off hides the review screen; captured deeds data is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['deeds_capture.access'], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['corex/deeds-capture'], 'global_flag' => null,
        'route_names' => ['corex.deeds-capture.*'],
        'view_dirs' => ['corex/deeds-capture/'],
    ],
    'imported-stock' => [
        'label' => 'Imported Stock (other agency stock)', 'category' => 'Listings & Marketing',
        'explain' => 'The list of properties brought in from other agencies\' portal stock, kept apart from your own listings.',
        'affects' => 'Whether Imported Stock appears in the Real Estate menu. Off hides the list; the imported properties are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_imported_stock'], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['corex/properties/imported-stock'], 'global_flag' => null,
        'route_names' => ['corex.properties.imported-stock'],
        'view_dirs' => [],
    ],
    'buyer-pipeline' => [
        'label' => 'Buyer Pipeline', 'category' => 'Buyers & Matching',
        'explain' => 'The buyer pipeline board — move each buyer through your stages, record why one is lost, and open a buyer\'s detail page.',
        'affects' => 'Whether Buyer Pipeline appears in the Real Estate menu (and Rental Pipeline in Rentals), and whether buyer-pipeline buttons appear on Core Matches, contacts and viewing packs. Off hides them; each buyer\'s stage is kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'real-estate',
        'settings_section' => null, 'route_prefixes' => ['corex/command-center/buyers', 'corex/rentals/pipeline'], 'global_flag' => null,
        'route_names' => ['command-center.buyers.*', 'corex.rentals.pipeline.*'],
        'view_dirs' => ['command-center/buyers/'],
    ],

    // ── Reports & Performance ──
    'performance-roi-report' => [
        'label' => 'Performance & ROI Report', 'category' => 'Reports & Performance',
        'explain' => 'The agency-wide performance and return-on-investment report, with branch and agent drill-downs and a print version.',
        'affects' => 'Whether Performance & ROI Report appears in the Reports menu. Off hides it; the figures it is built from are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['view_performance'], 'sidebar_section' => 'reports',
        'settings_section' => null, 'route_prefixes' => ['corex/performance/agency-report'], 'global_flag' => null,
        'route_names' => ['performance.agency-report*'],
        'view_dirs' => ['performance/'],
    ],
    'buyers-report' => [
        'label' => 'Buyers Report', 'category' => 'Reports & Performance',
        'explain' => 'The buyer demand and activity report, with branch and agent drill-downs, PDF and print.',
        'affects' => 'Whether Buyers Report appears in the Reports menu. Off hides it; buyer records are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['view_buyers_report'], 'sidebar_section' => 'reports',
        'settings_section' => null, 'route_prefixes' => ['corex/buyers-report'], 'global_flag' => null,
        'route_names' => ['buyers-report.*'],
        'view_dirs' => ['buyers-report/'],
    ],
    'performance-dashboards' => [
        'label' => 'Performance Dashboards', 'category' => 'Reports & Performance',
        'explain' => 'The Dashboard\'s performance pages — My Performance, Branch Report, Agency Report, Lost Deals, Oversight and Performance.',
        'affects' => 'Whether those pages appear in the Dashboard menu and whether their links appear on the Dashboard. Off hides them; Today, Calendar and Tasks always stay.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['view_dashboard'], 'sidebar_section' => 'command-center',
        'settings_section' => null, 'route_prefixes' => ['corex/command-center/reporting', 'corex/command-center/performance', 'corex/dashboard/oversight'], 'global_flag' => null,
        'route_names' => ['command-center.reporting.*', 'command-center.performance*', 'command-center.lost-deals', 'corex.dashboard.oversight*'],
        'view_dirs' => ['command-center/reporting/', 'command-center/performance', 'command-center/lost-deals', 'corex/dashboard/oversight/'],
    ],

    // ── HR ──
    'ppra-employment-letters' => [
        'label' => 'PPRA Employment Letters', 'category' => 'People & Payroll',
        'explain' => 'The PPRA FFC employment letter — agents request their letter in My Portal and the office registers, signs and files it.',
        'affects' => 'Whether PPRA FFC Letter appears under HR and whether agents see the request in My Portal. Off hides both; issued letters and signed copies are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['ppra_employment_letters.manage'], 'sidebar_section' => 'hr-documents',
        'settings_section' => null, 'route_prefixes' => ['admin/ppra-employment-letters', 'corex/my-portal/ppra-employment-letters'], 'global_flag' => null,
        'route_names' => ['admin.ppra-employment-letters.*', 'ppra-employment-letters.*'],
        'view_dirs' => ['admin/ppra-employment-letters/', 'compliance/ppra-employment-letters/'],
    ],

    // ── Company & Admin ──
    'billing' => [
        'label' => 'Billing', 'category' => 'Company & Admin',
        'explain' => 'What your agency pays for CoreX each month — the plan, the per-user charges and the amount payable.',
        'affects' => 'Whether Billing appears in the Company menu. Off hides the page; invoices and the subscription itself are unaffected.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['billing.view'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['corex/billing'], 'global_flag' => null,
        'route_names' => ['billing.*'],
        'view_dirs' => ['billing/'],
    ],
    'assistants' => [
        'label' => 'Assistants', 'category' => 'Company & Admin',
        'explain' => 'Personal assistants who each work for one agent — they start with a copy of that agent\'s permissions and the agent switches off whatever they do not want to hand over.',
        'affects' => 'Whether Assistants appears in the Company menu and My Assistants in My Portal, and whether assistants can act for their agents. Off hides both and assistants lose access; existing assistant links are kept and return when it is switched back on. This is the same switch as Company Settings → Assistants — you change it there.',
        'default' => false, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['assistants.view'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['admin/assistants', 'my-portal/assistants'], 'global_flag' => null,
        'route_names' => ['admin.assistants.*', 'agent.assistants.*'],
        'view_dirs' => ['admin/assistants/', 'agent/assistants/'],
    ],
    'soft-deletes' => [
        'label' => 'Soft Deletes (recover archived records)', 'category' => 'Company & Admin',
        'explain' => 'The recovery screen — everything in CoreX is archived rather than deleted, and anything archived shows here so an admin can restore it.',
        'affects' => 'Whether Soft Deletes appears in the Company menu. Off hides the recovery screen; archived records stay archived and are not lost.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_soft_deletes'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['admin/soft-deletes'], 'global_flag' => null,
        'route_names' => ['admin.soft-deletes.*'],
        'view_dirs' => ['admin/soft-deletes/'],
    ],
    'ppra-inspection-pack' => [
        'label' => 'PPRA Inspection Pack', 'category' => 'Company & Admin',
        'explain' => 'Builds the pack of registers, samples and evidence you hand to a PPRA inspector.',
        'affects' => 'Whether PPRA Inspection Pack appears in the Company menu and agency documents. Off hides it; previously generated packs are kept.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['ppra_inspection_pack.view'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['admin/ppra-inspection-pack'], 'global_flag' => null,
        'route_names' => ['admin.ppra-inspection-pack.*'],
        'view_dirs' => ['admin/ppra-inspection-pack/', 'components/ppra-sample-picker'],
    ],
    'misfiled-documents' => [
        'label' => 'Misfiled Documents', 'category' => 'Company & Admin',
        'explain' => 'Contact-only documents (ID, FICA, proof of residence) that were filed to a property or left with no contact, so each can be re-filed to the right person.',
        'affects' => 'Whether Misfiled Documents appears in the Company menu. Off hides the queue; the documents stay where they are.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_misfiled_documents'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['admin/misfiled-documents'], 'global_flag' => null,
        'route_names' => ['admin.misfiled-documents.*'],
        'view_dirs' => ['admin/misfiled-documents/'],
    ],
    'finance-engine' => [
        'label' => 'Finance Engine', 'category' => 'Company & Admin',
        'explain' => 'The registered finance formulas and the audit tools that recalculate and check a deal\'s commission and fee figures.',
        'affects' => 'Whether Finance Engine appears in the Company menu. Off hides the definitions and audit screens; the figures on deals are unchanged.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => ['access_finance_engine'], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['admin/finance'], 'global_flag' => null,
        'route_names' => ['admin.finance.*'],
        'view_dirs' => ['admin/finance/'],
    ],
    'contact-governance' => [
        'label' => 'Contact Governance', 'category' => 'Company & Admin',
        'explain' => 'Agency-wide sharing, duplicate, freshness and retention rules for contacts.',
        'affects' => 'Whether Contact Governance appears in the Company menu. Off hides the rules screen; the rules already set keep applying to contacts.',
        'default' => true, 'core' => false, 'depends_on' => [],
        'nav_permission' => [], 'sidebar_section' => 'company',
        'settings_section' => null, 'route_prefixes' => ['corex/command-center/settings/contact-governance'], 'global_flag' => null,
        'route_names' => ['command-center.settings.contact-governance*'],
        'view_dirs' => ['command-center/settings/contact-governance'],
    ],

];
