<?php

/**
 * ══════════════════════════════════════════════════════════════════
 * CoreX OS — Permission Definitions (Single Source of Truth)
 * ══════════════════════════════════════════════════════════════════
 *
 * This config defines every permission in the system.
 * The artisan command `corex:sync-permissions` reads this file
 * and upserts into the `nexus_permissions` table.
 *
 * Adding a new permission:
 *   1. Add it to the 'permissions' array below
 *   2. Optionally add it to 'role_defaults' if roles should get it on fresh install
 *   3. Deploy — the sync command runs automatically and the permission
 *      appears in Role Manager, ready to be assigned.
 *
 * type = 'access'  → sidebar / menu visibility
 * type = 'action'  → granular CRUD
 * module groups permissions together in the Role Manager matrix UI
 *
 * !! NEVER edit role_permissions directly — use Role Manager UI !!
 * !! role_defaults below are ONLY for fresh installs              !!
 * ══════════════════════════════════════════════════════════════════
 */

return [

    // ──────────────────────────────────────────────────────────
    // Permission definitions
    // ──────────────────────────────────────────────────────────

    'permissions' => [

        // ── Dashboard ──
        ['key' => 'view_dashboard',          'label' => 'View Dashboard',                  'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 1],
        ['key' => 'view_dashboard_kpis',     'label' => 'View KPI Cards',                  'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 2],
        ['key' => 'view_dashboard_charts',   'label' => 'View Charts & Analytics',         'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 3],
        ['key' => 'export_reports',          'label' => 'Export Reports',                  'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 4],
        ['key' => 'dashboard.oversight.view',   'label' => 'View Manager Oversight',          'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 5],
        ['key' => 'dashboard.oversight.manage', 'label' => 'Manage Oversight (nudge/reassign)','section' => 'dashboard',       'type' => 'action',  'module' => 'dashboard',        'sort_order' => 6],

        // ── Agency Tracker — Menu Access ──
        ['key' => 'access_agency_tracker',    'label' => 'Access Agency Tracker',           'section' => 'agency-tracker',   'type' => 'access',  'module' => 'agency_tracker',   'sort_order' => 1],
        ['key' => 'access_daily_activity',    'label' => 'Access Daily Activity Capture',   'section' => 'agency-tracker',   'type' => 'access',  'module' => 'daily_activity',   'sort_order' => 2],
        ['key' => 'access_deal_register',     'label' => 'Access Deal Register',            'section' => 'agency-tracker',   'type' => 'access',  'module' => 'deals',            'sort_order' => 3],
        ['key' => 'access_listing_stock',     'label' => 'Access Listing Stock',            'section' => 'agency-tracker',   'type' => 'access',  'module' => 'listings',         'sort_order' => 4],
        ['key' => 'access_tv_messages',       'label' => 'Access TV Messages',              'section' => 'agency-tracker',   'type' => 'access',  'module' => 'tv_messages',      'sort_order' => 5],
        ['key' => 'access_import_listings',   'label' => 'Access Import Listings',          'section' => 'agency-tracker',   'type' => 'access',  'module' => 'listings',         'sort_order' => 6],
        ['key' => 'access_worksheet_market',  'label' => 'Access Worksheet Market',         'section' => 'agency-tracker',   'type' => 'access',  'module' => 'agency_tracker',   'sort_order' => 7],
        ['key' => 'access_branch_assignments','label' => 'Access Branch Assignments',       'section' => 'agency-tracker',   'type' => 'access',  'module' => 'agency_tracker',   'sort_order' => 8],
        ['key' => 'access_rental_signatures', 'label' => 'Access Rental Signatures',        'section' => 'agency-tracker',   'type' => 'access',  'module' => 'rentals',          'sort_order' => 9],

        // ── Agency Tracker — Legacy granular ──
        ['key' => 'view_worksheet',          'label' => 'View Worksheet',                  'section' => 'agency-tracker',   'type' => 'access',  'module' => 'agency_tracker',   'sort_order' => 10],
        ['key' => 'edit_worksheet',          'label' => 'Edit Worksheet',                  'section' => 'agency-tracker',   'type' => 'access',  'module' => 'agency_tracker',   'sort_order' => 11],
        ['key' => 'view_deals',              'label' => 'View Deals',                      'section' => 'agency-tracker',   'type' => 'access',  'module' => 'deals',            'sort_order' => 12],
        ['key' => 'create_deals',            'label' => 'Create & Edit Deals',             'section' => 'agency-tracker',   'type' => 'access',  'module' => 'deals',            'sort_order' => 13],
        ['key' => 'settle_deals',            'label' => 'Settle Deals',                    'section' => 'agency-tracker',   'type' => 'access',  'module' => 'deals',            'sort_order' => 14],
        ['key' => 'view_listings',           'label' => 'View Listing Stock',              'section' => 'agency-tracker',   'type' => 'access',  'module' => 'listings',         'sort_order' => 15],
        ['key' => 'import_listings',         'label' => 'Import Listings',                 'section' => 'agency-tracker',   'type' => 'access',  'module' => 'listings',         'sort_order' => 16],
        ['key' => 'view_performance',        'label' => 'View Performance',                'section' => 'agency-tracker',   'type' => 'access',  'module' => 'agency_tracker',   'sort_order' => 17],
        ['key' => 'manage_targets',          'label' => 'Manage Targets',                  'section' => 'agency-tracker',   'type' => 'access',  'module' => 'agency_tracker',   'sort_order' => 18],
        ['key' => 'view_rentals',            'label' => 'View Rentals',                    'section' => 'agency-tracker',   'type' => 'access',  'module' => 'rentals',          'sort_order' => 19],
        ['key' => 'manage_rentals',          'label' => 'Create & Edit Rentals',           'section' => 'agency-tracker',   'type' => 'access',  'module' => 'rentals',          'sort_order' => 20],
        ['key' => 'view_daily_activity',     'label' => 'View Daily Activity',             'section' => 'agency-tracker',   'type' => 'access',  'module' => 'daily_activity',   'sort_order' => 21],
        ['key' => 'manage_tv_messages',      'label' => 'Manage TV Messages',              'section' => 'agency-tracker',   'type' => 'access',  'module' => 'tv_messages',      'sort_order' => 22],

        // ── Deals — Granular Actions ──
        ['key' => 'deals.view',              'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 30],
        ['key' => 'deals.create',            'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 31],
        ['key' => 'deals.edit',              'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 32],
        ['key' => 'deals.archive',           'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 33],

        // ── Listings — Granular Actions ──
        ['key' => 'listings.view',           'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 34],
        ['key' => 'listings.create',         'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 35],
        ['key' => 'listings.edit',           'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 36],
        ['key' => 'listings.archive',        'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 37],

        // ── Rentals — Granular Actions ──
        ['key' => 'rentals.view',            'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 38],
        ['key' => 'rentals.create',          'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 39],
        ['key' => 'rentals.edit',            'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 40],
        ['key' => 'rentals.archive',         'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 41],

        // ── Daily Activity — Granular Actions ──
        ['key' => 'daily_activity.view',     'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 42],
        ['key' => 'daily_activity.create',   'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 43],
        ['key' => 'daily_activity.edit',     'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 44],
        ['key' => 'daily_activity.archive',  'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 45],

        // ── TV Messages — Granular Actions ──
        ['key' => 'tv_messages.view',        'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 46],
        ['key' => 'tv_messages.create',      'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 47],
        ['key' => 'tv_messages.edit',        'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 48],
        ['key' => 'tv_messages.archive',     'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 49],

        // ── Targets — Granular Actions ──
        ['key' => 'targets.view',              'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 50],
        ['key' => 'targets.create',            'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 51],
        ['key' => 'targets.edit',              'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 52],
        ['key' => 'targets.archive',           'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 53],

        // ── Calculators — Granular Actions ──
        ['key' => 'calculators.manage',        'label' => 'Manage Fee Sheets',               'section' => 'calculators',      'type' => 'action',  'module' => 'calculators',      'sort_order' => 10],

        // ── Compliance ──
        ['key' => 'access_compliance',       'label' => 'Access Compliance',               'section' => 'compliance',       'type' => 'access',  'module' => 'compliance',       'sort_order' => 1],
        ['key' => 'manage_compliance',       'label' => 'Manage Compliance Records',       'section' => 'compliance',       'type' => 'access',  'module' => 'compliance',       'sort_order' => 2],
        ['key' => 'view_compliance_reports', 'label' => 'View Compliance Reports',         'section' => 'compliance',       'type' => 'access',  'module' => 'compliance',       'sort_order' => 3],
        ['key' => 'compliance.view',         'label' => 'View',                            'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 10],
        ['key' => 'compliance.manage',       'label' => 'Manage',                          'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 11],
        ['key' => 'compliance.fica.send',    'label' => 'Send FICA Requests',              'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 12],
        ['key' => 'compliance.fica.review',  'label' => 'Review FICA Submissions',         'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 13],
        ['key' => 'compliance.fica.approve', 'label' => 'Approve/Reject FICA',             'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 14],
        ['key' => 'verify_user_documents',   'label' => 'Verify/Reject User Documents',    'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 15],
        ['key' => 'access_compliance_dashboard','label' => 'Access Compliance Dashboard',   'section' => 'compliance',       'type' => 'access',  'module' => 'compliance',       'sort_order' => 16],

        // ── Communication Archive (AT-33) — PRIVACY-SENSITIVE: grants read access to the
        //    full immutable archive of staff email/WhatsApp. Restricted by default. ──
        ['key' => 'access_communication_archive',  'label' => 'View Communication Archive (all staff email/WhatsApp)', 'section' => 'compliance', 'type' => 'access', 'module' => 'communication_archive', 'sort_order' => 17],
        ['key' => 'manage_communication_mailboxes', 'label' => 'Manage Archive Mailboxes (IMAP credentials)',          'section' => 'compliance', 'type' => 'action', 'module' => 'communication_archive', 'sort_order' => 18],
        // Pending triage (AT-36): staff-facing triage of unknown-contact messages (agents).
        ['key' => 'triage_communications',            'label' => 'Triage Pending Communications',                     'section' => 'compliance', 'type' => 'access', 'module' => 'communication_archive', 'sort_order' => 19],
        // BM flag register: audit of who discarded what (privacy-sensitive, no message content).
        ['key' => 'view_communication_flag_register', 'label' => 'View Communication Flag Register (triage audit)',   'section' => 'compliance', 'type' => 'access', 'module' => 'communication_archive', 'sort_order' => 20],
        // PRINCIPAL-ONLY (AT-37): retrieving a stored mailbox password. Every use
        // is itself audited (mailbox_credential_reveals). Owner gets it via the
        // '*' wildcard; explicitly EXCLUDED from admin (admin is exclude-based, so
        // it would otherwise inherit this) and never added to any other role.
        ['key' => 'reveal_mailbox_credential',      'label' => 'Reveal Mailbox Password (audited, principal only)',     'section' => 'compliance', 'type' => 'action', 'module' => 'communication_archive', 'sort_order' => 21],
        // ── AT-118 Communications Access Gate ──
        // `communications.view` is the SCOPED per-contact gate (own/branch/all via
        //   scope_defaults + Role Manager) — same mechanism as deals.view / outreach_queue.view.
        //   Module prefix `communications` → getDataScope($user, 'communications') reads this
        //   key's scope. This is the per-CONTACT comms gate, distinct from the agency-wide
        //   `access_communication_archive` (AT-33) which still governs the compliance archive.
        // `communications.grant_access` is the AUTHORISER capability (who may approve a
        //   non-owner's request to see a contact's threads — the locked model's "role-manager",
        //   implemented as an agency-reconfigurable capability, NOT a hardcoded role).
        //   Default owner/admin only (admin via the all-minus-exclude default; owner via '*').
        ['key' => 'communications.view',         'label' => 'View Contact Communications (owner/scoped)', 'section' => 'compliance', 'type' => 'action', 'module' => 'communications', 'sort_order' => 22],
        ['key' => 'communications.grant_access',  'label' => 'Authorise Communications Access Requests',   'section' => 'compliance', 'type' => 'action', 'module' => 'communications', 'sort_order' => 23],
        // AT-136 — review agent WhatsApp-capture opt-outs (declaration + reason, NEVER
        // message content) + flag a contact for opt-in. Admin/CO; no hardcoded roles.
        ['key' => 'communications.capture_review', 'label' => 'Review WhatsApp Capture Opt-outs (admin/CO)', 'section' => 'compliance', 'type' => 'action', 'module' => 'communications', 'sort_order' => 24],

        // ── RMCP ──
        ['key' => 'access_rmcp',                 'label' => 'View RMCP',                       'section' => 'compliance',       'type' => 'access',  'module' => 'rmcp',             'sort_order' => 20],
        ['key' => 'edit_rmcp',                   'label' => 'Edit RMCP Drafts',                'section' => 'compliance',       'type' => 'action',  'module' => 'rmcp',             'sort_order' => 21],
        ['key' => 'approve_rmcp',                'label' => 'Approve RMCP (Board)',             'section' => 'compliance',       'type' => 'action',  'module' => 'rmcp',             'sort_order' => 22],
        ['key' => 'manage_compliance_officer',   'label' => 'Manage Compliance Officer',       'section' => 'compliance',       'type' => 'action',  'module' => 'rmcp',             'sort_order' => 23],
        ['key' => 'manage_information_officer',  'label' => 'Manage Information Officer (POPIA)','section' => 'compliance',     'type' => 'action',  'module' => 'rmcp',             'sort_order' => 24],
        ['key' => 'manage_activity_mappings',    'label' => 'Manage Activity Points → Calendar Mappings', 'section' => 'compliance', 'type' => 'action', 'module' => 'activity-points', 'sort_order' => 25],

        // ── Policy Acknowledgement Framework (AT-29) ──
        ['key' => 'access_policy',               'label' => 'View & Sign Policies',            'section' => 'compliance',       'type' => 'access',  'module' => 'policy',           'sort_order' => 26],
        ['key' => 'edit_policy',                 'label' => 'Edit Policy Drafts',              'section' => 'compliance',       'type' => 'action',  'module' => 'policy',           'sort_order' => 27],
        ['key' => 'approve_policy',              'label' => 'Approve / Publish Policies',      'section' => 'compliance',       'type' => 'action',  'module' => 'policy',           'sort_order' => 28],

        // ── Employee Screening ──
        ['key' => 'manage_employee_screenings', 'label' => 'Manage Employee Screenings',    'section' => 'compliance',       'type' => 'action',  'module' => 'screening',        'sort_order' => 30],
        ['key' => 'view_own_screening',         'label' => 'View Own Screening Records',    'section' => 'compliance',       'type' => 'access',  'module' => 'screening',        'sort_order' => 31],

        // ── Agency Compliance Provisions ──
        ['key' => 'manage_agency_compliance',   'label' => 'Manage Agency Compliance Provisions', 'section' => 'compliance', 'type' => 'action', 'module' => 'compliance', 'sort_order' => 40],
        ['key' => 'manage_branch_compliance',  'label' => 'Manage Branch Compliance Documents', 'section' => 'compliance', 'type' => 'action', 'module' => 'compliance', 'sort_order' => 41],
        ['key' => 'manage_user_compliance',     'label' => 'Upload on Behalf / Override User Compliance', 'section' => 'compliance', 'type' => 'action', 'module' => 'compliance', 'sort_order' => 42],

        // ── My Portal ──
        ['key' => 'access_my_portal',        'label' => 'Access My Portal',                'section' => 'my-portal',        'type' => 'access',  'module' => 'my_portal',        'sort_order' => 1],
        ['key' => 'upload_own_documents',    'label' => 'Upload Compliance Documents',     'section' => 'my-portal',        'type' => 'action',  'module' => 'my_portal',        'sort_order' => 2],
        ['key' => 'edit_own_profile',        'label' => 'Edit Own Profile',                'section' => 'my-portal',        'type' => 'action',  'module' => 'my_portal',        'sort_order' => 3],
        ['key' => 'view_agency_documents',  'label' => 'View Agency Compliance Documents', 'section' => 'my-portal',        'type' => 'access',  'module' => 'my_portal',        'sort_order' => 4],

        // ── User Management — Granular ──
        ['key' => 'edit_user_designation',   'label' => 'Change User Designation',         'section' => 'franchise-admin',  'type' => 'action',  'module' => 'users',            'sort_order' => 14],
        ['key' => 'assign_user_branch',      'label' => 'Assign User Branch',              'section' => 'franchise-admin',  'type' => 'action',  'module' => 'users',            'sort_order' => 15],
        ['key' => 'edit_user_ppra_status',   'label' => 'Edit User PPRA Status',           'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 42],

        // ── Supervision ──
        ['key' => 'access_supervision',      'label' => 'Access Supervision',              'section' => 'supervision',      'type' => 'access',  'module' => 'supervision',      'sort_order' => 1],
        ['key' => 'manage_supervision',      'label' => 'Manage Supervision Records',      'section' => 'supervision',      'type' => 'access',  'module' => 'supervision',      'sort_order' => 2],
        ['key' => 'supervision.view',        'label' => 'View',                            'section' => 'supervision',      'type' => 'action',  'module' => 'supervision',      'sort_order' => 10],
        ['key' => 'supervision.manage',      'label' => 'Manage',                          'section' => 'supervision',      'type' => 'action',  'module' => 'supervision',      'sort_order' => 11],

        // ── Training ──
        ['key' => 'access_training',         'label' => 'Access Training (LMS)',           'section' => 'training',         'type' => 'access',  'module' => 'training',         'sort_order' => 1],
        ['key' => 'manage_courses',          'label' => 'Manage Courses',                  'section' => 'training',         'type' => 'access',  'module' => 'training',         'sort_order' => 2],
        ['key' => 'assign_training',         'label' => 'Assign Training',                 'section' => 'training',         'type' => 'access',  'module' => 'training',         'sort_order' => 3],
        ['key' => 'training.view',           'label' => 'View',                            'section' => 'training',         'type' => 'action',  'module' => 'training',         'sort_order' => 10],
        ['key' => 'training.manage',         'label' => 'Manage',                          'section' => 'training',         'type' => 'action',  'module' => 'training',         'sort_order' => 11],

        // ── Communication ──
        ['key' => 'access_communication',    'label' => 'Access Communication',            'section' => 'communication',    'type' => 'access',  'module' => 'communication',    'sort_order' => 1],
        ['key' => 'send_messages',           'label' => 'Send Messages',                   'section' => 'communication',    'type' => 'access',  'module' => 'communication',    'sort_order' => 2],
        ['key' => 'manage_announcements',    'label' => 'Manage Announcements',            'section' => 'communication',    'type' => 'access',  'module' => 'communication',    'sort_order' => 3],
        ['key' => 'communication.view',      'label' => 'View',                            'section' => 'communication',    'type' => 'action',  'module' => 'communication',    'sort_order' => 10],
        ['key' => 'communication.send',      'label' => 'Send',                            'section' => 'communication',    'type' => 'action',  'module' => 'communication',    'sort_order' => 11],
        ['key' => 'communication.manage',    'label' => 'Manage',                          'section' => 'communication',    'type' => 'action',  'module' => 'communication',    'sort_order' => 12],

        // ── Client Portal ──
        ['key' => 'access_client_portal',    'label' => 'Access Client Portal',            'section' => 'client-portal',    'type' => 'access',  'module' => 'client_portal',    'sort_order' => 1],
        ['key' => 'manage_clients',          'label' => 'Manage Client Records',           'section' => 'client-portal',    'type' => 'access',  'module' => 'client_portal',    'sort_order' => 2],

        // ── P24 Importer ── REMOVED 2026-05-07: System Owner only (see agency-admin-rule.md).
        // Routes now gated by `owner_only` middleware. No permission keys needed.

        // ── Franchise Admin ──
        ['key' => 'access_franchise_admin',  'label' => 'Access Franchise Admin',          'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 1],
        ['key' => 'manage_branches',         'label' => 'Manage Branches',                 'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 2],
        ['key' => 'manage_users',            'label' => 'Manage Users',                    'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 3],
        ['key' => 'view_financial_reports',  'label' => 'View Financial Reports',          'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 4],
        ['key' => 'impersonate_users',       'label' => 'Impersonate Users',               'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 5],
        ['key' => 'users.view',              'label' => 'View',                            'section' => 'franchise-admin',  'type' => 'action',  'module' => 'users',            'sort_order' => 10],
        ['key' => 'users.create',            'label' => 'Create',                          'section' => 'franchise-admin',  'type' => 'action',  'module' => 'users',            'sort_order' => 11],
        ['key' => 'users.edit',              'label' => 'Edit',                            'section' => 'franchise-admin',  'type' => 'action',  'module' => 'users',            'sort_order' => 12],
        ['key' => 'users.archive',           'label' => 'Archive',                         'section' => 'franchise-admin',  'type' => 'action',  'module' => 'users',            'sort_order' => 13],

        // ── DocuPerfect ──
        ['key' => 'access_docuperfect',          'label' => 'Access DocuPerfect',          'section' => 'docuperfect',      'type' => 'access',  'module' => 'docuperfect',      'sort_order' => 1],
        ['key' => 'create_docuperfect_docs',     'label' => 'Create Documents',            'section' => 'docuperfect',      'type' => 'access',  'module' => 'documents',        'sort_order' => 2],
        ['key' => 'manage_templates',            'label' => 'Manage Templates',            'section' => 'docuperfect',      'type' => 'access',  'module' => 'templates',        'sort_order' => 3],
        ['key' => 'manage_clauses',              'label' => 'Manage Clause Library',       'section' => 'docuperfect',      'type' => 'access',  'module' => 'clauses',          'sort_order' => 4],
        ['key' => 'manage_docuperfect_settings', 'label' => 'Manage DocuPerfect Settings', 'section' => 'docuperfect',      'type' => 'access',  'module' => 'docuperfect',      'sort_order' => 5],
        ['key' => 'access_docuperfect_packs',    'label' => 'Access Document Packs',       'section' => 'docuperfect',      'type' => 'access',  'module' => 'packs',            'sort_order' => 6],
        ['key' => 'access_clause_library',       'label' => 'Access Clause Library',       'section' => 'docuperfect',      'type' => 'access',  'module' => 'clauses',          'sort_order' => 7],
        ['key' => 'documents.view',              'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 10],
        ['key' => 'documents.create',            'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 11],
        ['key' => 'documents.edit',              'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 12],
        ['key' => 'documents.archive',           'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 13],
        ['key' => 'templates.view',              'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 14],
        ['key' => 'templates.create',            'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 15],
        ['key' => 'templates.edit',              'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 16],
        ['key' => 'templates.archive',           'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 17],
        ['key' => 'clauses.view',                'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 18],
        ['key' => 'clauses.create',              'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 19],
        ['key' => 'clauses.edit',                'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 20],
        ['key' => 'clauses.archive',             'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 21],
        ['key' => 'packs.view',                  'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 22],
        ['key' => 'packs.create',                'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 23],
        ['key' => 'packs.edit',                  'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 24],
        ['key' => 'packs.archive',               'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 25],
        // ── E-Sign Compiler (AT-177) — internal Compile Studio; signers need no new perms ──
        ['key' => 'esign.compiler.view',         'label' => 'View Compile Studio',         'section' => 'docuperfect',      'type' => 'access',  'module' => 'esign-compiler',   'sort_order' => 30],
        ['key' => 'esign.compiler.compile',      'label' => 'Compile Templates',           'section' => 'docuperfect',      'type' => 'action',  'module' => 'esign-compiler',   'sort_order' => 31],
        ['key' => 'esign.compiler.publish',      'label' => 'Publish Compiled Versions',   'section' => 'docuperfect',      'type' => 'action',  'module' => 'esign-compiler',   'sort_order' => 32],
        ['key' => 'esign.compiler.dictionary',   'label' => 'Manage Data Dictionary',      'section' => 'docuperfect',      'type' => 'action',  'module' => 'esign-compiler',   'sort_order' => 33],

        // ── Document Library ──
        ['key' => 'access_document_library',     'label' => 'Access Document Library',    'section' => 'document-library', 'type' => 'access',  'module' => 'document_library', 'sort_order' => 1],
        ['key' => 'upload_documents_library',    'label' => 'Upload to Library',           'section' => 'document-library', 'type' => 'access',  'module' => 'document_library', 'sort_order' => 2],
        ['key' => 'manage_document_library',     'label' => 'Manage Document Library',    'section' => 'document-library', 'type' => 'access',  'module' => 'document_library', 'sort_order' => 3],

        // ── Shared Drive ──
        ['key' => 'access_shared_drive',         'label' => 'Access Shared Drive',         'section' => 'shared-drive',     'type' => 'access',  'module' => 'shared_drive',     'sort_order' => 1],
        ['key' => 'shared_drive.view',           'label' => 'View / Browse Files',         'section' => 'shared-drive',     'type' => 'action',  'module' => 'shared_drive',     'sort_order' => 10],
        ['key' => 'shared_drive.upload',         'label' => 'Upload Files',                'section' => 'shared-drive',     'type' => 'action',  'module' => 'shared_drive',     'sort_order' => 11],
        ['key' => 'shared_drive.download',       'label' => 'Download Files',              'section' => 'shared-drive',     'type' => 'action',  'module' => 'shared_drive',     'sort_order' => 12],
        ['key' => 'shared_drive.folders.create', 'label' => 'Create Folders',             'section' => 'shared-drive',     'type' => 'action',  'module' => 'shared_drive',     'sort_order' => 13],
        ['key' => 'shared_drive.folders.delete', 'label' => 'Delete Folders',             'section' => 'shared-drive',     'type' => 'action',  'module' => 'shared_drive',     'sort_order' => 14],
        ['key' => 'shared_drive.files.delete',   'label' => 'Delete Files',               'section' => 'shared-drive',     'type' => 'action',  'module' => 'shared_drive',     'sort_order' => 15],

        // ── Presentations ──
        ['key' => 'access_presentations',        'label' => 'Access Presentations',        'section' => 'presentations',    'type' => 'access',  'module' => 'presentations',    'sort_order' => 1],
        ['key' => 'create_presentations',        'label' => 'Create Presentations',        'section' => 'presentations',    'type' => 'access',  'module' => 'presentations',    'sort_order' => 2],
        ['key' => 'run_analysis',                'label' => 'Run Analysis',                'section' => 'presentations',    'type' => 'access',  'module' => 'presentations',    'sort_order' => 3],
        ['key' => 'compile_pack',                'label' => 'Compile Pack',                'section' => 'presentations',    'type' => 'access',  'module' => 'presentations',    'sort_order' => 4],
        ['key' => 'presentations.view',          'label' => 'View',                        'section' => 'presentations',    'type' => 'action',  'module' => 'presentations',    'sort_order' => 10],
        ['key' => 'presentations.create',        'label' => 'Create',                      'section' => 'presentations',    'type' => 'action',  'module' => 'presentations',    'sort_order' => 11],
        ['key' => 'presentations.edit',          'label' => 'Edit',                        'section' => 'presentations',    'type' => 'action',  'module' => 'presentations',    'sort_order' => 12],
        ['key' => 'presentations.archive',       'label' => 'Archive',                     'section' => 'presentations',    'type' => 'action',  'module' => 'presentations',    'sort_order' => 13],

        // ── Filing Register ──
        ['key' => 'access_filing_register',      'label' => 'Access Filing Register',      'section' => 'filing-register',  'type' => 'access',  'module' => 'filing',           'sort_order' => 1],
        ['key' => 'filing.view',                 'label' => 'View',                        'section' => 'filing-register',  'type' => 'action',  'module' => 'filing',           'sort_order' => 10],
        ['key' => 'filing.create',               'label' => 'Create',                      'section' => 'filing-register',  'type' => 'action',  'module' => 'filing',           'sort_order' => 11],
        ['key' => 'filing.edit',                 'label' => 'Edit',                        'section' => 'filing-register',  'type' => 'action',  'module' => 'filing',           'sort_order' => 12],
        ['key' => 'filing.archive',              'label' => 'Archive',                     'section' => 'filing-register',  'type' => 'action',  'module' => 'filing',           'sort_order' => 13],

        // ── Commercial Evaluations ──
        ['key' => 'access_commercial_evaluations','label' => 'Access Commercial Evaluations','section' => 'commercial-evaluations','type' => 'access', 'module' => 'commercial_evals', 'sort_order' => 1],
        ['key' => 'commercial_evals.view',        'label' => 'View',                       'section' => 'commercial-evaluations','type' => 'action', 'module' => 'commercial_evals', 'sort_order' => 10],
        ['key' => 'commercial_evals.create',      'label' => 'Create',                     'section' => 'commercial-evaluations','type' => 'action', 'module' => 'commercial_evals', 'sort_order' => 11],
        ['key' => 'commercial_evals.edit',        'label' => 'Edit',                       'section' => 'commercial-evaluations','type' => 'action', 'module' => 'commercial_evals', 'sort_order' => 12],
        ['key' => 'commercial_evals.archive',     'label' => 'Archive',                    'section' => 'commercial-evaluations','type' => 'action', 'module' => 'commercial_evals', 'sort_order' => 13],

        // ── Sales Documents ──
        ['key' => 'access_sales_documents',      'label' => 'Access Sales Documents',      'section' => 'sales-documents',  'type' => 'access',  'module' => 'sales_docs',       'sort_order' => 1],
        ['key' => 'sales_docs.view',             'label' => 'View',                        'section' => 'sales-documents',  'type' => 'action',  'module' => 'sales_docs',       'sort_order' => 10],
        ['key' => 'sales_docs.create',           'label' => 'Create',                      'section' => 'sales-documents',  'type' => 'action',  'module' => 'sales_docs',       'sort_order' => 11],
        ['key' => 'sales_docs.edit',             'label' => 'Edit',                        'section' => 'sales-documents',  'type' => 'action',  'module' => 'sales_docs',       'sort_order' => 12],
        ['key' => 'sales_docs.archive',          'label' => 'Archive',                     'section' => 'sales-documents',  'type' => 'action',  'module' => 'sales_docs',       'sort_order' => 13],

        // ── Properties ──
        ['key' => 'access_properties',           'label' => 'Access Properties',           'section' => 'properties',       'type' => 'access',  'module' => 'properties',       'sort_order' => 1],
        ['key' => 'create_properties',           'label' => 'Create & Edit Properties',    'section' => 'properties',       'type' => 'access',  'module' => 'properties',       'sort_order' => 2],
        ['key' => 'publish_properties',          'label' => 'Publish Properties',          'section' => 'properties',       'type' => 'access',  'module' => 'properties',       'sort_order' => 3],
        ['key' => 'delete_properties',           'label' => 'Delete Properties',           'section' => 'properties',       'type' => 'access',  'module' => 'properties',       'sort_order' => 4],
        ['key' => 'properties.view',             'label' => 'View',                        'section' => 'properties',       'type' => 'action',  'module' => 'properties',       'sort_order' => 10],
        ['key' => 'properties.create',           'label' => 'Create',                      'section' => 'properties',       'type' => 'action',  'module' => 'properties',       'sort_order' => 11],
        ['key' => 'properties.edit',             'label' => 'Edit',                        'section' => 'properties',       'type' => 'action',  'module' => 'properties',       'sort_order' => 12],
        ['key' => 'properties.archive',          'label' => 'Archive',                     'section' => 'properties',       'type' => 'action',  'module' => 'properties',       'sort_order' => 13],
        ['key' => 'properties.ad_templates.manage', 'label' => 'Manage Others\' Ad Templates', 'section' => 'properties',    'type' => 'action',  'module' => 'properties',       'sort_order' => 14],
        ['key' => 'properties.share',            'label' => 'Share Listing Link',          'section' => 'properties',       'type' => 'action',  'module' => 'properties',       'sort_order' => 15],

        // ── Contacts ──
        ['key' => 'access_contacts',             'label' => 'Access Contacts',             'section' => 'contacts',         'type' => 'access',  'module' => 'contacts',         'sort_order' => 1],
        ['key' => 'contacts.view',               'label' => 'View',                        'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 10],
        ['key' => 'contacts.create',             'label' => 'Create',                      'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 11],
        ['key' => 'contacts.edit',               'label' => 'Edit',                        'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 12],
        ['key' => 'contacts.archive',            'label' => 'Archive',                     'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 13],
        ['key' => 'contacts.import',             'label' => 'Import',                      'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 14],
        ['key' => 'contacts.export',             'label' => 'Export',                      'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 18],
        ['key' => 'contacts.delete',             'label' => 'Delete',                      'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 15],
        ['key' => 'contacts.whatsapp',           'label' => 'WhatsApp',                    'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 16],
        ['key' => 'contacts.email',              'label' => 'Email',                       'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 17],
        // AT-118 hardening — changing a contact's assigned Primary/Co-Agent is a
        // manager action (it shifts who is accountable for the contact). Default
        // admin + branch_manager; agency-reconfigurable in Role Manager. A plain
        // agent without this cap cannot reassign (enforced server-side).
        ['key' => 'contacts.reassign_agent',     'label' => 'Reassign Contact Agent / Co-Agent', 'section' => 'contacts',   'type' => 'action',  'module' => 'contacts',         'sort_order' => 19],

        // ── Core Matches ──
        ['key' => 'access_core_matches',         'label' => 'Access Core Matches',         'section' => 'core-matches',     'type' => 'access',  'module' => 'core_matches',     'sort_order' => 1],
        ['key' => 'core_matches.view',           'label' => 'View',                        'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 10],
        ['key' => 'core_matches.create',         'label' => 'Create',                      'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 11],
        ['key' => 'core_matches.delete',         'label' => 'Delete',                      'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 12],
        ['key' => 'core_matches.manage',         'label' => 'Manage (edit, archive)',      'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 13],
        ['key' => 'core_matches.convert_to_deal','label' => 'Convert to Deal',             'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 14],
        ['key' => 'core_matches.all_view',       'label' => 'All View (agency/branch oversight)', 'section' => 'core-matches', 'type' => 'action', 'module' => 'core_matches',  'sort_order' => 15],

        // ── Portal Leads (P24 + PP unified) ──
        ['key' => 'access_portal_leads',         'label' => 'Access Portal Leads',         'section' => 'portal-leads',     'type' => 'access',  'module' => 'portal_leads',     'sort_order' => 1],
        ['key' => 'portal_leads.view',           'label' => 'View',                        'section' => 'portal-leads',     'type' => 'action',  'module' => 'portal_leads',     'sort_order' => 10],

        // ── Client App (mobile client portal) ──
        // Spec: .ai/specs/client-auth.md
        ['key' => 'client_app.create_login',     'label' => 'Create Client App Login',     'section' => 'contacts',         'type' => 'action',  'module' => 'client_app',       'sort_order' => 50],
        ['key' => 'client_app.reset_password',   'label' => 'Reset Client App Password',   'section' => 'contacts',         'type' => 'action',  'module' => 'client_app',       'sort_order' => 51],
        ['key' => 'client_app.force_logout',     'label' => 'Force Client Logout',         'section' => 'contacts',         'type' => 'action',  'module' => 'client_app',       'sort_order' => 52],
        ['key' => 'client_app.remove_access',    'label' => 'Remove Client App Access',    'section' => 'contacts',         'type' => 'action',  'module' => 'client_app',       'sort_order' => 53],
        ['key' => 'client_app.view_logs',        'label' => 'View Client App Activity',    'section' => 'admin',            'type' => 'access',  'module' => 'client_app',       'sort_order' => 54],

        // ── Agency Public API (website API) ──
        // Spec: .ai/specs/agency-public-api.md §8
        ['key' => 'agency_api.view',             'label' => 'View Website API Keys',       'section' => 'admin',            'type' => 'access',  'module' => 'agency_api',       'sort_order' => 60],
        ['key' => 'agency_api.manage',           'label' => 'Manage Website API Keys',     'section' => 'admin',            'type' => 'action',  'module' => 'agency_api',       'sort_order' => 61],
        // Testimonials — publish/unpublish to website. Capture is gated by access_contacts.
        // Spec: .ai/specs/testimonials.md §8
        ['key' => 'testimonials.publish',        'label' => 'Publish Testimonials to Website', 'section' => 'admin',        'type' => 'action',  'module' => 'agency_api',       'sort_order' => 62],

        // ── Marketing Suppressions (AT-49) — "one opt-out, suppressed everywhere" register ──
        ['key' => 'marketing_suppressions.view',   'label' => 'View Marketing Suppressions',  'section' => 'admin',            'type' => 'access',  'module' => 'marketing',        'sort_order' => 70],
        ['key' => 'marketing_suppressions.manage', 'label' => 'Lift Marketing Suppressions',  'section' => 'admin',            'type' => 'action',  'module' => 'marketing',        'sort_order' => 71],

        // ── Calculators / Tools ──
        ['key' => 'access_calculators',          'label' => 'Access Calculators & Tools',  'section' => 'calculators',      'type' => 'access',  'module' => 'calculators',      'sort_order' => 1],

        // ── Ad Manager (Tools) ──
        ['key' => 'access_ad_manager',           'label' => 'Access Ad Manager',           'section' => 'calculators',      'type' => 'access',  'module' => 'ad_manager',       'sort_order' => 1],
        // Data-scope view key — drives the None / Own / Branch / All selector in
        // Role Manager (whose properties the user may build ads for).
        ['key' => 'ad_manager.view',             'label' => 'View',                        'section' => 'calculators',      'type' => 'action',  'module' => 'ad_manager',       'sort_order' => 2],

        // ── Flow Map ──
        ['key' => 'access_flow_map',             'label' => 'Access Flow Map',             'section' => 'flow-map',         'type' => 'access',  'module' => 'flow_map',         'sort_order' => 1],

        // ── Ellie AI ──
        ['key' => 'access_ellie',                'label' => 'Access Ellie AI',             'section' => 'ellie',            'type' => 'access',  'module' => 'ellie',            'sort_order' => 1],
        ['key' => 'use_ellie_voice',             'label' => 'Use Ellie Voice Commands',    'section' => 'ellie',            'type' => 'feature', 'module' => 'ellie',            'sort_order' => 2],
        ['key' => 'use_property_image_ai',       'label' => 'Use AI Image Recognition',    'section' => 'ellie',            'type' => 'feature', 'module' => 'ellie',            'sort_order' => 3],

        // ── P24 Market Intelligence ──
        ['key' => 'manage_p24',                  'label' => 'Manage P24 Market Intel',     'section' => 'p24',              'type' => 'access',  'module' => 'p24',              'sort_order' => 1],
        ['key' => 'p24.view',                    'label' => 'View',                        'section' => 'p24',              'type' => 'action',  'module' => 'p24',              'sort_order' => 10],
        ['key' => 'p24.manage',                  'label' => 'Manage',                      'section' => 'p24',              'type' => 'action',  'module' => 'p24',              'sort_order' => 11],

        // ── Prospecting ──
        ['key' => 'access_prospecting',          'label' => 'Access Prospecting',          'section' => 'prospecting',      'type' => 'access',  'module' => 'prospecting',      'sort_order' => 1],

        // ── Market Intelligence Centre (Phase A2) ── per spec §12.2/§12.3
        ['key' => 'mic.edit_address',            'label' => 'Edit / Add Property Address',         'section' => 'prospecting',      'type' => 'action',  'module' => 'mic',              'sort_order' => 50],
        ['key' => 'mic.merge_duplicates',        'label' => 'Merge Duplicate Tracked Properties',  'section' => 'prospecting',      'type' => 'action',  'module' => 'mic',              'sort_order' => 51],
        ['key' => 'mic.upload_reports',          'label' => 'Upload Market / CMA Reports',         'section' => 'prospecting',      'type' => 'action',  'module' => 'mic',              'sort_order' => 52],
        ['key' => 'mic.view_team',               'label' => 'View BM Team Dashboard',              'section' => 'prospecting',      'type' => 'access',  'module' => 'mic',              'sort_order' => 53],
        ['key' => 'mic.regenerate_brief',        'label' => 'Regenerate Strategic Brief (manual)', 'section' => 'prospecting',      'type' => 'action',  'module' => 'mic',              'sort_order' => 54],
        ['key' => 'mic.view_ai_costs',           'label' => 'View AI Token / Cost Dashboard',      'section' => 'prospecting',      'type' => 'access',  'module' => 'mic',              'sort_order' => 55],
        ['key' => 'mic.restore_reports',         'label' => 'Restore Archived Market Reports',     'section' => 'prospecting',      'type' => 'action',  'module' => 'mic',              'sort_order' => 56],

        // ── Evaluation (Property/Suburb/Town Reports) ──
        ['key' => 'access_evaluation',           'label' => 'Access Evaluation Reports',   'section' => 'evaluation',       'type' => 'access',  'module' => 'evaluation',       'sort_order' => 1],

        // ── Deposit Trust Interest ──
        ['key' => 'access_trust_interest',       'label' => 'Access Trust Interest Register', 'section' => 'trust-interest', 'type' => 'access',  'module' => 'trust_interest',   'sort_order' => 1],
        ['key' => 'access_deposit_calculator',   'label' => 'Access Deposit Interest Calculator', 'section' => 'trust-interest', 'type' => 'access',  'module' => 'trust_interest',   'sort_order' => 2],
        ['key' => 'access_deposit_calc_history', 'label' => 'Access Calculation History',        'section' => 'trust-interest', 'type' => 'access',  'module' => 'trust_interest',   'sort_order' => 3],

        // ── PDF Splitter (legacy key — kept for backwards compatibility with existing role assignments) ──
        ['key' => 'access_pdf_splitter',         'label' => 'Access PDF Splitter',         'section' => 'pdf-splitter',     'type' => 'access',  'module' => 'pdf_splitter',     'sort_order' => 1],

        // ── PDF Suite ──
        ['key' => 'access_pdf_suite',            'label' => 'Access PDF Suite',            'section' => 'pdf-suite',        'type' => 'access',  'module' => 'pdf_suite',        'sort_order' => 1],

        // ── Image Converter ──
        ['key' => 'access_image_converter',      'label' => 'Access Image Converter',      'section' => 'image-converter',  'type' => 'access',  'module' => 'image_converter',  'sort_order' => 1],

        // ── Knowledge Base ──
        ['key' => 'access_knowledge_base',       'label' => 'Access Knowledge Base',       'section' => 'knowledge-base',   'type' => 'access',  'module' => 'knowledge',        'sort_order' => 1],
        ['key' => 'manage_knowledge_base',       'label' => 'Manage Knowledge Base',       'section' => 'knowledge-base',   'type' => 'access',  'module' => 'knowledge',        'sort_order' => 2],
        ['key' => 'knowledge.view',              'label' => 'View',                        'section' => 'knowledge-base',   'type' => 'action',  'module' => 'knowledge',        'sort_order' => 10],
        ['key' => 'knowledge.manage',            'label' => 'Manage',                      'section' => 'knowledge-base',   'type' => 'action',  'module' => 'knowledge',        'sort_order' => 11],

        // ── Finance Engine ──
        ['key' => 'access_finance_engine',       'label' => 'Access Finance Engine',       'section' => 'finance-engine',   'type' => 'access',  'module' => 'finance',          'sort_order' => 1],
        ['key' => 'manage_finance_definitions',  'label' => 'Manage Finance Definitions',  'section' => 'finance-engine',   'type' => 'access',  'module' => 'finance',          'sort_order' => 2],
        ['key' => 'run_finance_audit',           'label' => 'Run Finance Audit',           'section' => 'finance-engine',   'type' => 'access',  'module' => 'finance',          'sort_order' => 3],
        ['key' => 'finance.view',                'label' => 'View',                        'section' => 'finance-engine',   'type' => 'action',  'module' => 'finance',          'sort_order' => 10],
        ['key' => 'finance.manage',              'label' => 'Manage',                      'section' => 'finance-engine',   'type' => 'action',  'module' => 'finance',          'sort_order' => 11],

        // ── Deal Register V2 ──
        ['key' => 'access_deal_register_v2',     'label' => 'Access Deal Register V2',     'section' => 'deals-v2',         'type' => 'access',  'module' => 'deals_v2',         'sort_order' => 1],
        ['key' => 'deals_v2.view',               'label' => 'View',                        'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 10],
        ['key' => 'deals_v2.create',             'label' => 'Capture Deals (any in scope)',  'section' => 'deals-v2',       'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 11],
        ['key' => 'deals_v2.capture_own',        'label' => 'Capture Own Deals (agent)',   'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 12],
        ['key' => 'deals_v2.edit',               'label' => 'Edit',                        'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 13],
        ['key' => 'deals_v2.archive',            'label' => 'Archive',                     'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 13],
        ['key' => 'deals_v2.manage_pipeline',    'label' => 'Manage Pipeline Templates',   'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 14],
        ['key' => 'deals_v2.override_dates',     'label' => 'Override Due Dates',          'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 15],
        ['key' => 'deals_v2.manage_suppliers',   'label' => 'Manage Supplier Directory',   'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 16],
        ['key' => 'deals_v2.distribute_documents',   'label' => 'Distribute Documents',        'section' => 'deals-v2',     'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 17],
        ['key' => 'deals_v2.manage_distribution_rules', 'label' => 'Manage Distribution Rules', 'section' => 'deals-v2',   'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 18],
        // WS8 — the pipeline overview / dashboard-board surface. Branch_manager +
        // admin only (managers monitor the whole book; agents keep the register).
        ['key' => 'deals_v2.view_overview',      'label' => 'View Pipeline Overview',      'section' => 'deals-v2',         'type' => 'action',  'module' => 'deals_v2',         'sort_order' => 19],

        // ── Agencies ── REMOVED 2026-05-07: System Owner only (see agency-admin-rule.md).
        // Routes now gated by `owner_only` middleware. No permission keys needed.

        // ── Remote Access (cross-agency consent flow) ──
        // See .ai/specs/agency-access-authorization-spec.md
        ['key' => 'agency.manage_access_authorization', 'label' => 'Manage Remote Access Setting', 'section' => 'remote-access', 'type' => 'access', 'module' => 'remote_access', 'sort_order' => 1],
        ['key' => 'agency.authorize_external_access',   'label' => 'Approve / Deny Remote Access', 'section' => 'remote-access', 'type' => 'action', 'module' => 'remote_access', 'sort_order' => 2],

        // ── Settings ──
        ['key' => 'access_settings',             'label' => 'Access Settings',             'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 1],
        ['key' => 'manage_designations',         'label' => 'Manage Designations',         'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 2],
        ['key' => 'manage_branch_settings',      'label' => 'Manage Branch Settings',      'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 3],
        ['key' => 'prospecting_setup.manage',    'label' => 'Manage Prospecting Setup',    'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 4],
        ['key' => 'manage_performance_settings', 'label' => 'Manage Performance Settings', 'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 4],
        ['key' => 'settings.view',               'label' => 'View',                        'section' => 'settings',         'type' => 'action',  'module' => 'settings',         'sort_order' => 10],
        ['key' => 'settings.edit',               'label' => 'Edit',                        'section' => 'settings',         'type' => 'action',  'module' => 'settings',         'sort_order' => 11],
        ['key' => 'agency.p24.configure',        'label' => 'Configure Property24 API credentials', 'section' => 'settings',  'type' => 'action',  'module' => 'settings',         'sort_order' => 20],
        ['key' => 'agency.p24.sync',             'label' => 'Trigger Property24 location sync',     'section' => 'settings',  'type' => 'action',  'module' => 'settings',         'sort_order' => 21],

        // ── Role Manager ──
        ['key' => 'access_role_manager',         'label' => 'Access Role Manager',         'section' => 'role-manager',     'type' => 'access',  'module' => 'roles',            'sort_order' => 1],
        ['key' => 'edit_permissions',            'label' => 'Edit Permissions',            'section' => 'role-manager',     'type' => 'access',  'module' => 'roles',            'sort_order' => 2],
        ['key' => 'change_user_roles',           'label' => 'Change User Roles',           'section' => 'role-manager',     'type' => 'access',  'module' => 'roles',            'sort_order' => 3],
        ['key' => 'roles.view',                  'label' => 'View',                        'section' => 'role-manager',     'type' => 'action',  'module' => 'roles',            'sort_order' => 10],
        ['key' => 'roles.edit',                  'label' => 'Edit',                        'section' => 'role-manager',     'type' => 'action',  'module' => 'roles',            'sort_order' => 11],

        // ── Data Scope ──
        ['key' => 'view_own_stats',              'label' => 'View Own Performance Stats',  'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 1],
        ['key' => 'view_branch_stats',           'label' => 'View Branch Performance Stats','section' => 'data-scope',      'type' => 'access',  'module' => 'data_scope',       'sort_order' => 2],
        ['key' => 'view_company_stats',          'label' => 'View Company Performance Stats','section' => 'data-scope',     'type' => 'access',  'module' => 'data_scope',       'sort_order' => 3],
        ['key' => 'manage_system',               'label' => 'Manage System',               'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 4],
        ['key' => 'manage_branch',               'label' => 'Manage Branch',               'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 5],
        ['key' => 'manage_agency_switching',     'label' => 'Switch Between Agencies',     'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 6],

        // ── Command Center ──
        ['key' => 'command_center.view',             'label' => 'View Command Center',          'section' => 'command-center',   'type' => 'access',  'module' => 'command_center',   'sort_order' => 1],
        // .view is type 'action' so Role Manager renders the None/Own/Branch/All
        // Data Scope selector; calendar/tasks form their own matrix modules.
        ['key' => 'command_center.calendar.view',    'label' => 'View Calendar',                'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_calendar', 'sort_order' => 2],
        ['key' => 'command_center.calendar.create',  'label' => 'Create Calendar Events',       'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_calendar', 'sort_order' => 3],
        ['key' => 'command_center.calendar.edit',    'label' => 'Edit Calendar Events',         'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_calendar', 'sort_order' => 4],
        ['key' => 'command_center.calendar.delete',  'label' => 'Delete Calendar Events',       'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_calendar', 'sort_order' => 5],
        ['key' => 'command_center.tasks.view',       'label' => 'View Task Board',              'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_tasks',    'sort_order' => 6],
        ['key' => 'command_center.tasks.create',     'label' => 'Create Tasks',                 'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_tasks',    'sort_order' => 7],
        ['key' => 'command_center.tasks.edit',       'label' => 'Edit Tasks',                   'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_tasks',    'sort_order' => 8],
        ['key' => 'command_center.tasks.assign',     'label' => 'Assign Tasks to Others',       'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_tasks',    'sort_order' => 9],
        ['key' => 'command_center.tasks.delete',     'label' => 'Delete Tasks',                 'section' => 'command-center',   'type' => 'action',  'module' => 'command_center_tasks',    'sort_order' => 10],
        ['key' => 'command_center.health.view',      'label' => 'View Property Health',         'section' => 'command-center',   'type' => 'access',  'module' => 'command_center',   'sort_order' => 11],
        ['key' => 'command_center.scorecards.own',   'label' => 'View Own Scorecard',           'section' => 'command-center',   'type' => 'access',  'module' => 'command_center',   'sort_order' => 12],
        ['key' => 'command_center.scorecards.branch','label' => 'View Branch Scorecards',       'section' => 'command-center',   'type' => 'access',  'module' => 'command_center',   'sort_order' => 13],
        ['key' => 'command_center.scorecards.all',   'label' => 'View All Scorecards',          'section' => 'command-center',   'type' => 'access',  'module' => 'command_center',   'sort_order' => 14],
        ['key' => 'command_center.automation.view',  'label' => 'View Automation Rules',        'section' => 'command-center',   'type' => 'access',  'module' => 'command_center',   'sort_order' => 15],
        ['key' => 'command_center.automation.manage','label' => 'Manage Automation Rules',      'section' => 'command-center',   'type' => 'action',  'module' => 'command_center',   'sort_order' => 16],
        ['key' => 'command_center.settings',         'label' => 'Manage Command Center Settings','section' => 'command-center',  'type' => 'access',  'module' => 'command_center',   'sort_order' => 17],
        // AT-164 §15.5 — the Calendar Deck "My Deals" tile. Seeded but granted to NO
        // role (default OFF) — the tile is FLAGGED HIDDEN behind the DR2 hold and
        // lights up when an admin grants this after DR2 ships (no rebuild).
        ['key' => 'calendar.tile.my_deals',          'label' => 'Calendar Deck — My Deals Tile',  'section' => 'command-center',  'type' => 'action',  'module' => 'command_center_calendar', 'sort_order' => 18],

        // ── Contact Governance ──
        ['key' => 'contact_governance.manage',       'label' => 'Manage Contact Governance Settings', 'section' => 'contact-governance', 'type' => 'access', 'module' => 'contact_governance', 'sort_order' => 50],
        ['key' => 'contact_governance.leave_matrix', 'label' => 'Manage Leave Visibility Matrix',    'section' => 'contact-governance', 'type' => 'access', 'module' => 'contact_governance', 'sort_order' => 51],

        // ── Seller Outreach ──
        // See .ai/specs/seller-outreach-spec.md
        ['key' => 'outreach.compose',             'label' => 'Compose Seller Outreach',     'section' => 'outreach', 'type' => 'action', 'module' => 'outreach', 'sort_order' => 1],
        ['key' => 'outreach_templates.manage',    'label' => 'Manage Outreach Templates',   'section' => 'outreach', 'type' => 'action', 'module' => 'outreach', 'sort_order' => 2],
        // AT-91 — WhatsApp Outreach Summary board (agents × outreach states). Oversight surface.
        ['key' => 'outreach.summary.view',        'label' => 'View WhatsApp Outreach Summary', 'section' => 'outreach', 'type' => 'access', 'module' => 'outreach', 'sort_order' => 3],
        // AT-120 — Outreach Queue. `.view` is the SCOPED access key (own/branch/all via
        // scope_defaults + Role Manager) — same mechanism as deals.view / calendar.view.
        // dispatch/cancel are boolean action capabilities (enforced act-own server-side:
        // sending opens the agent's OWN WhatsApp, so you never dispatch another's number).
        ['key' => 'outreach_queue.view',          'label' => 'View Outreach Queue',         'section' => 'outreach', 'type' => 'action', 'module' => 'outreach_queue', 'sort_order' => 4],
        ['key' => 'outreach_queue.dispatch',      'label' => 'Send from Outreach Queue',    'section' => 'outreach', 'type' => 'action', 'module' => 'outreach_queue', 'sort_order' => 5],
        ['key' => 'outreach_queue.cancel',        'label' => 'Remove Outreach Queue items', 'section' => 'outreach', 'type' => 'action', 'module' => 'outreach_queue', 'sort_order' => 6],

        // ── Payroll ──
        ['key' => 'manage_payroll',        'label' => 'Manage Payroll (employees, types)', 'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 120],
        ['key' => 'run_payroll',           'label' => 'Run & Finalise Payroll',            'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 121],
        ['key' => 'view_payroll_reports',  'label' => 'View Payroll Reports',              'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 122],
        ['key' => 'view_own_payslips',     'label' => 'View Own Payslips',                 'section' => 'payroll', 'type' => 'action', 'module' => 'payroll', 'sort_order' => 123],

        // ── Leave ──
        ['key' => 'manage_leave',             'label' => 'Manage Leave (admin/BM)',                  'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 130],
        ['key' => 'approve_leave',            'label' => 'Approve / Reject Leave Applications',     'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 131],
        ['key' => 'apply_for_leave',          'label' => 'Apply for Own Leave',                     'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 132],
        ['key' => 'view_leave_reports',       'label' => 'View Leave Reports',                      'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 133],
        ['key' => 'manage_leave_types',       'label' => 'Manage Leave Types (admin)',               'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 134],
        ['key' => 'manage_staff_take_on',     'label' => 'Manage Staff Take-On Wizard',              'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 135],
        ['key' => 'view_team_leave_calendar', 'label' => 'View Team Leave Calendar',                'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 136],
        ['key' => 'adjust_leave_balances',    'label' => 'Manually Adjust Leave Balances (admin)',   'section' => 'leave', 'type' => 'action', 'module' => 'leave', 'sort_order' => 137],

        // ── Whistleblower Compliance Reporting ──
        ['key' => 'compliance.whistleblow.view',           'label' => 'View Whistleblow Module',               'section' => 'compliance', 'type' => 'action', 'module' => 'compliance_whistleblow', 'sort_order' => 50],
        ['key' => 'compliance.whistleblow.create',         'label' => 'File New Complaints',                   'section' => 'compliance', 'type' => 'action', 'module' => 'compliance_whistleblow', 'sort_order' => 51],
        ['key' => 'compliance.whistleblow.approve',        'label' => 'Approve / Reject Complaints',           'section' => 'compliance', 'type' => 'action', 'module' => 'compliance_whistleblow', 'sort_order' => 52],
        ['key' => 'compliance.whistleblow.view_all_agency','label' => 'View All Agency Complaints',            'section' => 'compliance', 'type' => 'action', 'module' => 'compliance_whistleblow', 'sort_order' => 53],
        ['key' => 'compliance.whistleblow.configure',      'label' => 'Configure Approvers & PPRA Email',      'section' => 'compliance', 'type' => 'action', 'module' => 'compliance_whistleblow', 'sort_order' => 54],

        // ── Branches — Split Branches (Phase 2 branch isolation) ──
        // view_all = bypass BranchScope (see all branches in the agency)
        // switch   = use the "View as Branch" dropdown to impersonate a branch
        // edit_all = write to records in any branch (implies view_all — enforced UI-side)
        ['key' => 'branches.view_all',               'label' => 'View Across All Branches',      'section' => 'branches',        'type' => 'access',  'module' => 'branches',         'sort_order' => 1],
        ['key' => 'branches.switch',                 'label' => 'Switch Branch View',            'section' => 'branches',        'type' => 'access',  'module' => 'branches',         'sort_order' => 2],
        ['key' => 'branches.edit_all',               'label' => 'Edit Across All Branches',      'section' => 'branches',        'type' => 'action',  'module' => 'branches',         'sort_order' => 3],

        // ── Sidebar — section visibility (entire sidebar groups) ──
        // When OFF, the sidebar heading and every item under it (until the
        // next heading) is hidden. Per-feature permissions still gate the
        // underlying routes — this is purely visual grouping.
        ['key' => 'sidebar.section.agents',          'label' => 'Show Agents Section',           'section' => 'sidebar',         'type' => 'access',  'module' => 'sidebar',          'sort_order' => 1],
        ['key' => 'sidebar.section.branch_manager',  'label' => 'Show Branch Manager Section',   'section' => 'sidebar',         'type' => 'access',  'module' => 'sidebar',          'sort_order' => 2],
        ['key' => 'sidebar.section.tools',           'label' => 'Show Tools Section',            'section' => 'sidebar',         'type' => 'access',  'module' => 'sidebar',          'sort_order' => 3],
        ['key' => 'sidebar.section.admin',           'label' => 'Show Admin Section',            'section' => 'sidebar',         'type' => 'access',  'module' => 'sidebar',          'sort_order' => 5],

        // ── Soft Deletes Register ──
        ['key' => 'access_soft_deletes',             'label' => 'Access Soft Deletes Register',  'section' => 'admin',           'type' => 'access',  'module' => 'soft_deletes',     'sort_order' => 1],

        // ── Backups (AT-163) — off-box restic backup status page ──
        ['key' => 'view_backups',                    'label' => 'View Backups (status, health, history)', 'section' => 'admin',   'type' => 'access',  'module' => 'backups',          'sort_order' => 1],
        ['key' => 'reveal_backup_password',          'label' => 'Reveal Backup Encryption Password (audited, principal only)', 'section' => 'admin', 'type' => 'action', 'module' => 'backups', 'sort_order' => 2],

        // ── Server Health Monitor (System Developer) — read-only live vitals ──
        ['key' => 'view_server_health',              'label' => 'View Server Health Monitor (live vitals)', 'section' => 'admin', 'type' => 'access', 'module' => 'monitoring', 'sort_order' => 1],

        // ── Misfiled Documents register (AT-167) ──
        ['key' => 'access_misfiled_documents',       'label' => 'Access Misfiled Documents Register', 'section' => 'admin', 'type' => 'access', 'module' => 'filing', 'sort_order' => 5],
        ['key' => 'misfiled_documents.refile',       'label' => 'Refile Misfiled Documents',          'section' => 'admin', 'type' => 'action', 'module' => 'filing', 'sort_order' => 6],
    ],

    // ──────────────────────────────────────────────────────────
    // Role defaults — ONLY applied on fresh install (--seed-defaults)
    // These do NOT overwrite existing role_permissions.
    // To change live permissions, use the Role Manager UI.
    // ──────────────────────────────────────────────────────────

    'role_defaults' => [
        'super_admin' => '*', // Owner role — gets all permissions

        'admin' => [
            // reveal_mailbox_credential is principal-only — admin must NOT inherit
            // it via the all-minus-exclude default (AT-37).
            // reveal_backup_password is likewise principal-only (AT-163).
            // calendar.tile.my_deals is FLAGGED HIDDEN behind the DR2 hold (AT-164 §15.5)
            // — it must NOT be auto-granted to admin via all-minus-exclude; it lights up
            // only when explicitly granted after DR2 ships.
            'exclude' => ['manage_agency_switching', 'reveal_mailbox_credential', 'reveal_backup_password', 'calendar.tile.my_deals'],
            // Payroll: admin gets full payroll management
            'include' => [
                'manage_payroll', 'run_payroll', 'view_payroll_reports', 'view_own_payslips',
                // Leave: admin gets all leave permissions
                'manage_leave', 'approve_leave', 'apply_for_leave', 'view_leave_reports',
                'manage_leave_types', 'manage_staff_take_on', 'view_team_leave_calendar',
                'adjust_leave_balances',
                // Sidebar sections — admin sees all
                'sidebar.section.agents', 'sidebar.section.branch_manager',
                'sidebar.section.tools', 'sidebar.section.admin',
                // Soft Deletes Register
                'access_soft_deletes',
                // Misfiled Documents register (AT-167)
                'access_misfiled_documents', 'misfiled_documents.refile',
                // Whistleblower
                'compliance.whistleblow.view', 'compliance.whistleblow.create',
                'compliance.whistleblow.approve', 'compliance.whistleblow.view_all_agency',
                'compliance.whistleblow.configure',
                // Seller Outreach
                'outreach.compose', 'outreach_templates.manage', 'outreach.summary.view',
                // MIC (Phase A2) — admin gets every MIC permission
                'mic.edit_address', 'mic.merge_duplicates', 'mic.upload_reports',
                'mic.view_team', 'mic.regenerate_brief', 'mic.view_ai_costs',
                'mic.restore_reports',
                // Agency Public API — admins manage their agency's website keys
                'agency_api.view', 'agency_api.manage',
                // Testimonials — admins curate which testimonials go on the website
                'testimonials.publish',
                // Ad Manager — admins can edit/delete any agency member's ad template
                'properties.ad_templates.manage',
                // Module 6 Activity Points — admin manages the calendar-class →
                // activity-definition mappings that drive auto-credit.
                // (super_admin gets it via the '*' wildcard above; branch_manager
                // already has it in its own include list below.)
                'manage_activity_mappings',
            ],
        ],

        'branch_manager' => [
            'include' => [
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts', 'export_reports',
                'access_agency_tracker', 'access_daily_activity', 'access_deal_register',
                'access_listing_stock', 'access_tv_messages', 'access_worksheet_market',
                'access_rental_signatures',
                'view_worksheet', 'edit_worksheet', 'view_deals', 'create_deals', 'settle_deals',
                'view_listings', 'view_performance', 'manage_targets',
                'view_rentals', 'manage_rentals', 'view_daily_activity', 'manage_tv_messages',
                'deals.view', 'deals.create', 'deals.edit',
                'listings.view', 'listings.create', 'listings.edit',
                'rentals.view', 'rentals.create', 'rentals.edit',
                'daily_activity.view', 'daily_activity.create', 'daily_activity.edit',
                'tv_messages.view', 'tv_messages.create', 'tv_messages.edit',
                'targets.view', 'targets.create', 'targets.edit',
                'calculators.manage',
                'access_compliance', 'manage_compliance', 'view_compliance_reports',
                'compliance.view', 'compliance.manage',
                'verify_user_documents', 'access_compliance_dashboard',
                'access_communication_archive',
                'triage_communications', 'view_communication_flag_register',
                'communications.view', // AT-118 per-contact comms gate — branch scope (via scope_defaults)
                'access_rmcp', 'edit_rmcp', 'manage_compliance_officer', 'manage_information_officer',
                'access_policy', 'edit_policy',
                'manage_activity_mappings',
                'manage_employee_screenings', 'view_own_screening', 'manage_branch_compliance',
                'edit_user_ppra_status',
                'access_my_portal', 'upload_own_documents', 'edit_own_profile', 'view_agency_documents',
                'assign_user_branch',
                'access_supervision', 'manage_supervision',
                'supervision.view', 'supervision.manage',
                'access_training', 'assign_training',
                'training.view', 'training.manage',
                'access_communication', 'send_messages', 'manage_announcements',
                'communication.view', 'communication.send', 'communication.manage',
                'access_client_portal', 'manage_clients',
                'access_docuperfect', 'create_docuperfect_docs', 'manage_templates', 'manage_clauses',
                'access_docuperfect_packs', 'access_clause_library',
                'documents.view', 'documents.create', 'documents.edit',
                'templates.view', 'templates.create', 'templates.edit',
                'clauses.view', 'clauses.create', 'clauses.edit',
                'packs.view', 'packs.create', 'packs.edit',
                'access_document_library', 'upload_documents_library',
                'access_shared_drive', 'shared_drive.view', 'shared_drive.upload',
                'shared_drive.download', 'shared_drive.folders.create',
                'shared_drive.folders.delete', 'shared_drive.files.delete',
                'access_presentations', 'create_presentations', 'run_analysis',
                'presentations.view', 'presentations.create', 'presentations.edit',
                'access_filing_register',
                'filing.view', 'filing.create', 'filing.edit',
                'access_misfiled_documents', 'misfiled_documents.refile',
                'access_commercial_evaluations',
                'commercial_evals.view', 'commercial_evals.create', 'commercial_evals.edit',
                'access_sales_documents',
                'sales_docs.view', 'sales_docs.create', 'sales_docs.edit',
                'access_calculators', 'access_ad_manager', 'ad_manager.view', 'access_ellie', 'use_ellie_voice', 'use_property_image_ai', 'access_flow_map',
                'access_pdf_splitter', 'access_pdf_suite', 'access_image_converter',
                'access_deposit_calculator', 'access_deposit_calc_history',
                'access_prospecting', 'access_evaluation',
                'access_properties', 'create_properties', 'publish_properties', 'delete_properties',
                'properties.view', 'properties.create', 'properties.edit', 'properties.ad_templates.manage', 'properties.share',
                'access_contacts',
                'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.archive',
                'contacts.delete', 'contacts.whatsapp', 'contacts.email', 'contacts.export',
                'contacts.reassign_agent', // AT-118 hardening — managers reassign contact agents
                'access_core_matches',
                'core_matches.view', 'core_matches.create', 'core_matches.delete', 'core_matches.manage', 'core_matches.convert_to_deal',
                'core_matches.all_view',
                'access_portal_leads', 'portal_leads.view',
                'p24.view',
                'access_knowledge_base', 'knowledge.view',
                'settings.view',
                'roles.view',
                'view_branch_stats', 'manage_branch',
                'access_deal_register_v2',
                'deals_v2.view', 'deals_v2.create', 'deals_v2.edit', 'deals_v2.archive',
                'deals_v2.manage_pipeline', 'deals_v2.override_dates', 'deals_v2.manage_suppliers',
                'deals_v2.distribute_documents', 'deals_v2.manage_distribution_rules',
                'deals_v2.view_overview',
                // Branches — can switch between branches of their own agency
                // (testing / training), but does NOT bypass BranchScope by default.
                'branches.switch',
                // Payroll
                'view_own_payslips',
                // Leave
                'manage_leave', 'approve_leave', 'apply_for_leave', 'view_leave_reports',
                'view_team_leave_calendar',
                // Sidebar sections
                'sidebar.section.agents', 'sidebar.section.branch_manager',
                'sidebar.section.tools',
                // Whistleblower
                'compliance.whistleblow.view', 'compliance.whistleblow.create',
                'compliance.whistleblow.approve', 'compliance.whistleblow.view_all_agency',
                // Seller Outreach
                'outreach.compose', 'outreach.summary.view',
                // AT-120 — Outreach Queue: .view scope (branch_manager→branch, agent→own via
                // scope_defaults); dispatch/cancel act-own. Admin/owner get these via '*'.
                'outreach_queue.view', 'outreach_queue.dispatch', 'outreach_queue.cancel',
                // MIC (Phase A2) — branch_manager (= spec "manager"): all
                // EXCEPT regenerate_brief and view_ai_costs (admin+ only).
                'mic.edit_address', 'mic.merge_duplicates', 'mic.upload_reports',
                'mic.view_team',
            ],
        ],

        'agent' => [
            'include' => [
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts',
                'access_agency_tracker', 'access_daily_activity', 'access_rental_signatures',
                'view_worksheet', 'edit_worksheet', 'view_deals',
                'view_listings', 'view_performance',
                'view_rentals', 'manage_rentals', 'view_daily_activity',
                'deals.view', 'deals.create',
                'listings.view',
                'rentals.view', 'rentals.create', 'rentals.edit',
                'daily_activity.view', 'daily_activity.create', 'daily_activity.edit',
                'targets.view',
                'access_my_portal', 'upload_own_documents', 'edit_own_profile', 'view_agency_documents',
                'access_training', 'training.view',
                'access_communication', 'send_messages',
                'communication.view', 'communication.send',
                'triage_communications',
                'communications.view', // AT-118 per-contact comms gate — own scope (via scope_defaults)
                'access_client_portal',
                'access_docuperfect', 'create_docuperfect_docs',
                'access_docuperfect_packs', 'access_clause_library',
                'documents.view', 'documents.create',
                'clauses.view',
                'packs.view',
                'access_document_library',
                'access_shared_drive', 'shared_drive.view', 'shared_drive.upload',
                'shared_drive.download', 'shared_drive.folders.create',
                'access_presentations',
                'presentations.view', 'presentations.create', 'presentations.edit',
                'access_filing_register',
                'filing.view', 'filing.create',
                'access_commercial_evaluations',
                'commercial_evals.view', 'commercial_evals.create',
                'access_sales_documents',
                'sales_docs.view', 'sales_docs.create',
                'access_calculators', 'access_ad_manager', 'ad_manager.view', 'access_ellie', 'use_ellie_voice', 'use_property_image_ai', 'access_flow_map',
                'access_pdf_splitter', 'access_pdf_suite', 'access_image_converter',
                'access_prospecting', 'access_evaluation',
                'access_properties', 'create_properties',
                'properties.view', 'properties.create', 'properties.edit', 'properties.share',
                'access_contacts',
                'contacts.view', 'contacts.create', 'contacts.edit',
                'contacts.whatsapp', 'contacts.email', 'contacts.export',
                'access_core_matches',
                'core_matches.view', 'core_matches.create', 'core_matches.delete', 'core_matches.manage', 'core_matches.convert_to_deal',
                'access_portal_leads', 'portal_leads.view',
                'p24.view',
                'access_knowledge_base', 'knowledge.view',
                'view_own_stats',
                'access_deal_register_v2',
                // AT-158 WS-V3 (Ruling b): capture defaults to BM/admin. Agents get
                // view + edit (to feed back on their own deals); they do NOT get
                // 'deals_v2.create' by default. An agency can additionally grant
                // 'deals_v2.capture_own' via Role Manager to let agents capture the
                // deals they are on (own-membership enforced server-side).
                'deals_v2.view', 'deals_v2.edit',
                'access_rmcp',
                'access_policy',
                'view_own_screening',
                // Payroll
                'view_own_payslips',
                // Leave
                'apply_for_leave', 'view_team_leave_calendar',
                // Sidebar sections
                'sidebar.section.agents', 'sidebar.section.tools',
                // Whistleblower
                'compliance.whistleblow.view', 'compliance.whistleblow.create',
                // Seller Outreach — composer only; template management is admin.
                // Summary board: agent sees their OWN row (ContactScope-enforced).
                'outreach.compose', 'outreach.summary.view',
                // AT-120 — Outreach Queue: .view scope (branch_manager→branch, agent→own via
                // scope_defaults); dispatch/cancel act-own. Admin/owner get these via '*'.
                'outreach_queue.view', 'outreach_queue.dispatch', 'outreach_queue.cancel',
                // MIC (Phase A2) — agent gets edit_address + upload_reports
                // ONLY (per matrix §12.3). No merge, team, brief regen, or
                // AI cost visibility for agents.
                'mic.edit_address', 'mic.upload_reports',
            ],
        ],

        'viewer' => [
            'include' => [
                'access_my_portal',
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts',
                'access_agency_tracker', 'access_daily_activity',
                'view_worksheet', 'view_deals', 'view_listings', 'view_performance',
                'view_rentals', 'view_daily_activity',
                'deals.view', 'listings.view', 'rentals.view', 'daily_activity.view', 'targets.view',
                'access_training', 'training.view',
                'access_communication', 'communication.view',
                'access_client_portal',
                'access_document_library',
                'access_shared_drive', 'shared_drive.view', 'shared_drive.download',
                'documents.view', 'templates.view', 'clauses.view', 'packs.view',
                'access_presentations', 'presentations.view',
                'access_filing_register', 'filing.view',
                'access_commercial_evaluations', 'commercial_evals.view',
                'access_sales_documents', 'sales_docs.view',
                'access_calculators', 'access_ellie', 'use_ellie_voice', 'use_property_image_ai', 'access_flow_map',
                'access_prospecting', 'access_evaluation',
                'access_properties', 'properties.view',
                'access_contacts', 'contacts.view', 'contacts.export',
                'access_core_matches', 'core_matches.view',
                'access_portal_leads', 'portal_leads.view',
                'p24.view',
                'access_knowledge_base', 'knowledge.view',
                'settings.view',
                'view_own_stats',
                'access_rmcp',
                'access_policy',
                'view_own_screening',
                // Sidebar sections
                'sidebar.section.agents', 'sidebar.section.tools',
            ],
        ],

        // AT-118 — office_admin had NO config defaults, so it was skipped by
        // sync and never received communications.view (Angelique saw no comms
        // tab at all). Seed it PRESENT with 'own' scope (via scope_defaults
        // fallback) so the capability is toggleable in Role Manager per agency —
        // an agency can widen it to branch/all or remove it entirely. The default
        // is deliberately minimal (own), not broad.
        'office_admin' => [
            'include' => [
                'communications.view',
            ],
        ],
    ],

    // ──────────────────────────────────────────────────────────
    // Scope defaults for .view permissions (fresh install only)
    // Values: 'own', 'branch', 'all'
    // ──────────────────────────────────────────────────────────

    'scope_defaults' => [
        'super_admin'    => 'all',
        'admin'          => 'all',
        'branch_manager' => 'branch',
        'agent'          => 'own',
        'viewer'         => 'branch',
    ],

    // Modules where scope is always 'all' regardless of role
    'shared_scope_modules' => ['p24', 'knowledge'],

];
