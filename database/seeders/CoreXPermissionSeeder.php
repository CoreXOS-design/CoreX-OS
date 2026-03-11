<?php

namespace Database\Seeders;

use App\Models\CoreXPermission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

class CoreXPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // ──────────────────────────────────────────────────────────
        // type = 'access'  → sidebar / menu visibility (existing)
        // type = 'action'  → granular CRUD (new)
        // module groups permissions together in the matrix UI
        // ──────────────────────────────────────────────────────────

        $permissions = [

            // ── Dashboard ──
            ['key' => 'view_dashboard',          'label' => 'View Dashboard',                  'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 1],
            ['key' => 'view_dashboard_kpis',     'label' => 'View KPI Cards',                  'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 2],
            ['key' => 'view_dashboard_charts',   'label' => 'View Charts & Analytics',         'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 3],
            ['key' => 'export_reports',          'label' => 'Export Reports',                  'section' => 'dashboard',        'type' => 'access',  'module' => 'dashboard',        'sort_order' => 4],

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

            // ── Agency Tracker — Legacy granular (keep for backwards compat) ──
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

            // ── NEW: Deals — Granular Actions ──
            ['key' => 'deals.view',              'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 30],
            ['key' => 'deals.create',            'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 31],
            ['key' => 'deals.edit',              'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 32],
            ['key' => 'deals.archive',           'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'deals',            'sort_order' => 33],

            // ── NEW: Listings — Granular Actions ──
            ['key' => 'listings.view',           'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 34],
            ['key' => 'listings.create',         'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 35],
            ['key' => 'listings.edit',           'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 36],
            ['key' => 'listings.archive',        'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'listings',         'sort_order' => 37],

            // ── NEW: Rentals — Granular Actions ──
            ['key' => 'rentals.view',            'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 38],
            ['key' => 'rentals.create',          'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 39],
            ['key' => 'rentals.edit',            'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 40],
            ['key' => 'rentals.archive',         'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'rentals',          'sort_order' => 41],

            // ── NEW: Daily Activity — Granular Actions ──
            ['key' => 'daily_activity.view',     'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 42],
            ['key' => 'daily_activity.create',   'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 43],
            ['key' => 'daily_activity.edit',     'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 44],
            ['key' => 'daily_activity.archive',  'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'daily_activity',   'sort_order' => 45],

            // ── NEW: TV Messages — Granular Actions ──
            ['key' => 'tv_messages.view',        'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 46],
            ['key' => 'tv_messages.create',      'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 47],
            ['key' => 'tv_messages.edit',        'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 48],
            ['key' => 'tv_messages.archive',     'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'tv_messages',      'sort_order' => 49],

            // ── NEW: Targets — Granular Actions ──
            ['key' => 'targets.view',              'label' => 'View',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 50],
            ['key' => 'targets.create',            'label' => 'Create',                          'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 51],
            ['key' => 'targets.edit',              'label' => 'Edit',                            'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 52],
            ['key' => 'targets.archive',           'label' => 'Archive',                         'section' => 'agency-tracker',   'type' => 'action',  'module' => 'targets',          'sort_order' => 53],

            // ── NEW: Calculators — Granular Actions ──
            ['key' => 'calculators.manage',        'label' => 'Manage Fee Sheets',               'section' => 'calculators',      'type' => 'action',  'module' => 'calculators',      'sort_order' => 10],

            // ── Compliance ──
            ['key' => 'access_compliance',       'label' => 'Access Compliance',               'section' => 'compliance',       'type' => 'access',  'module' => 'compliance',       'sort_order' => 1],
            ['key' => 'manage_compliance',       'label' => 'Manage Compliance Records',       'section' => 'compliance',       'type' => 'access',  'module' => 'compliance',       'sort_order' => 2],
            ['key' => 'view_compliance_reports', 'label' => 'View Compliance Reports',         'section' => 'compliance',       'type' => 'access',  'module' => 'compliance',       'sort_order' => 3],
            ['key' => 'compliance.view',         'label' => 'View',                            'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 10],
            ['key' => 'compliance.manage',       'label' => 'Manage',                          'section' => 'compliance',       'type' => 'action',  'module' => 'compliance',       'sort_order' => 11],

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

            // ── Franchise Admin ──
            ['key' => 'access_franchise_admin',  'label' => 'Access Franchise Admin',          'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 1],
            ['key' => 'manage_branches',         'label' => 'Manage Branches',                 'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 2],
            ['key' => 'manage_users',            'label' => 'Manage Users',                    'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 3],
            ['key' => 'view_financial_reports',  'label' => 'View Financial Reports',          'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 4],
            ['key' => 'impersonate_users',       'label' => 'Impersonate Users',               'section' => 'franchise-admin',  'type' => 'access',  'module' => 'franchise_admin',  'sort_order' => 5],
            // Granular user management
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
            // Granular: Documents
            ['key' => 'documents.view',              'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 10],
            ['key' => 'documents.create',            'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 11],
            ['key' => 'documents.edit',              'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 12],
            ['key' => 'documents.archive',           'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'documents',        'sort_order' => 13],
            // Granular: Templates
            ['key' => 'templates.view',              'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 14],
            ['key' => 'templates.create',            'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 15],
            ['key' => 'templates.edit',              'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 16],
            ['key' => 'templates.archive',           'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'templates',        'sort_order' => 17],
            // Granular: Clauses
            ['key' => 'clauses.view',                'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 18],
            ['key' => 'clauses.create',              'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 19],
            ['key' => 'clauses.edit',                'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 20],
            ['key' => 'clauses.archive',             'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'clauses',          'sort_order' => 21],
            // Granular: Packs
            ['key' => 'packs.view',                  'label' => 'View',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 22],
            ['key' => 'packs.create',                'label' => 'Create',                      'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 23],
            ['key' => 'packs.edit',                  'label' => 'Edit',                        'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 24],
            ['key' => 'packs.archive',               'label' => 'Archive',                     'section' => 'docuperfect',      'type' => 'action',  'module' => 'packs',            'sort_order' => 25],

            // ── Document Library ──
            ['key' => 'access_document_library',     'label' => 'Access Document Library',    'section' => 'document-library', 'type' => 'access',  'module' => 'document_library', 'sort_order' => 1],
            ['key' => 'upload_documents_library',    'label' => 'Upload to Library',           'section' => 'document-library', 'type' => 'access',  'module' => 'document_library', 'sort_order' => 2],
            ['key' => 'manage_document_library',     'label' => 'Manage Document Library',    'section' => 'document-library', 'type' => 'access',  'module' => 'document_library', 'sort_order' => 3],

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

            // ── Contacts ──
            ['key' => 'access_contacts',             'label' => 'Access Contacts',             'section' => 'contacts',         'type' => 'access',  'module' => 'contacts',         'sort_order' => 1],
            ['key' => 'contacts.view',               'label' => 'View',                        'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 10],
            ['key' => 'contacts.create',             'label' => 'Create',                      'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 11],
            ['key' => 'contacts.edit',               'label' => 'Edit',                        'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 12],
            ['key' => 'contacts.archive',            'label' => 'Archive',                     'section' => 'contacts',         'type' => 'action',  'module' => 'contacts',         'sort_order' => 13],

            // ── Core Matches ──
            ['key' => 'access_core_matches',         'label' => 'Access Core Matches',         'section' => 'core-matches',     'type' => 'access',  'module' => 'core_matches',     'sort_order' => 1],
            ['key' => 'core_matches.view',           'label' => 'View',                        'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 10],
            ['key' => 'core_matches.create',         'label' => 'Create',                      'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 11],
            ['key' => 'core_matches.delete',         'label' => 'Delete',                      'section' => 'core-matches',     'type' => 'action',  'module' => 'core_matches',     'sort_order' => 12],

            // ── Calculators / Tools ──
            ['key' => 'access_calculators',          'label' => 'Access Calculators & Tools',  'section' => 'calculators',      'type' => 'access',  'module' => 'calculators',      'sort_order' => 1],

            // ── Ellie AI ──
            ['key' => 'access_ellie',                'label' => 'Access Ellie AI',             'section' => 'ellie',            'type' => 'access',  'module' => 'ellie',            'sort_order' => 1],

            // ── P24 Market Intelligence ──
            ['key' => 'manage_p24',                  'label' => 'Manage P24 Market Intel',     'section' => 'p24',              'type' => 'access',  'module' => 'p24',              'sort_order' => 1],
            ['key' => 'p24.view',                    'label' => 'View',                        'section' => 'p24',              'type' => 'action',  'module' => 'p24',              'sort_order' => 10],
            ['key' => 'p24.manage',                  'label' => 'Manage',                      'section' => 'p24',              'type' => 'action',  'module' => 'p24',              'sort_order' => 11],

            // ── PDF Splitter ──
            ['key' => 'access_pdf_splitter',         'label' => 'Access PDF Splitter',         'section' => 'pdf-splitter',     'type' => 'access',  'module' => 'pdf_splitter',     'sort_order' => 1],

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

            // ── Agencies ──
            ['key' => 'access_agencies',             'label' => 'Access Agencies',             'section' => 'agencies',         'type' => 'access',  'module' => 'agencies',         'sort_order' => 1],
            ['key' => 'manage_agencies',             'label' => 'Manage Agencies',             'section' => 'agencies',         'type' => 'access',  'module' => 'agencies',         'sort_order' => 2],

            // ── Settings ──
            ['key' => 'access_settings',             'label' => 'Access Settings',             'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 1],
            ['key' => 'manage_designations',         'label' => 'Manage Designations',         'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 2],
            ['key' => 'manage_branch_settings',      'label' => 'Manage Branch Settings',      'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 3],
            ['key' => 'manage_performance_settings', 'label' => 'Manage Performance Settings', 'section' => 'settings',         'type' => 'access',  'module' => 'settings',         'sort_order' => 4],
            ['key' => 'settings.view',               'label' => 'View',                        'section' => 'settings',         'type' => 'action',  'module' => 'settings',         'sort_order' => 10],
            ['key' => 'settings.edit',               'label' => 'Edit',                        'section' => 'settings',         'type' => 'action',  'module' => 'settings',         'sort_order' => 11],

            // ── Role Manager ──
            ['key' => 'access_role_manager',         'label' => 'Access Role Manager',         'section' => 'role-manager',     'type' => 'access',  'module' => 'roles',            'sort_order' => 1],
            ['key' => 'edit_permissions',            'label' => 'Edit Permissions',            'section' => 'role-manager',     'type' => 'access',  'module' => 'roles',            'sort_order' => 2],
            ['key' => 'change_user_roles',           'label' => 'Change User Roles',           'section' => 'role-manager',     'type' => 'access',  'module' => 'roles',            'sort_order' => 3],
            ['key' => 'roles.view',                  'label' => 'View',                        'section' => 'role-manager',     'type' => 'action',  'module' => 'roles',            'sort_order' => 10],
            ['key' => 'roles.edit',                  'label' => 'Edit',                        'section' => 'role-manager',     'type' => 'action',  'module' => 'roles',            'sort_order' => 11],

            // ── Data Scope (controls sidebar section visibility) ──
            ['key' => 'view_own_stats',              'label' => 'View Own Performance Stats',  'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 1],
            ['key' => 'view_branch_stats',           'label' => 'View Branch Performance Stats','section' => 'data-scope',      'type' => 'access',  'module' => 'data_scope',       'sort_order' => 2],
            ['key' => 'view_company_stats',          'label' => 'View Company Performance Stats','section' => 'data-scope',     'type' => 'access',  'module' => 'data_scope',       'sort_order' => 3],
            ['key' => 'manage_system',               'label' => 'Manage System',               'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 4],
            ['key' => 'manage_branch',               'label' => 'Manage Branch',               'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 5],
            ['key' => 'manage_agency_switching',     'label' => 'Switch Between Agencies',     'section' => 'data-scope',       'type' => 'access',  'module' => 'data_scope',       'sort_order' => 6],
        ];

        foreach ($permissions as $perm) {
            CoreXPermission::updateOrCreate(['key' => $perm['key']], $perm);
        }

        $allKeys = array_column($permissions, 'key');

        // ──────────────────────────────────────────────────────────
        // Role defaults
        // ──────────────────────────────────────────────────────────

        $defaults = [
            'super_admin' => $allKeys, // Owner role also bypasses via is_owner

            'admin' => array_values(array_filter($allKeys, fn ($k) => !in_array($k, [
                'access_agencies', 'manage_agencies', 'manage_agency_switching',
            ]))),

            'branch_manager' => [
                // Dashboard
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts', 'export_reports',
                // Agency Tracker menu access
                'access_agency_tracker', 'access_daily_activity', 'access_deal_register',
                'access_listing_stock', 'access_tv_messages', 'access_worksheet_market',
                'access_rental_signatures',
                // Agency Tracker legacy
                'view_worksheet', 'edit_worksheet', 'view_deals', 'create_deals', 'settle_deals',
                'view_listings', 'view_performance', 'manage_targets',
                'view_rentals', 'manage_rentals', 'view_daily_activity', 'manage_tv_messages',
                // Granular: Deals
                'deals.view', 'deals.create', 'deals.edit',
                // Granular: Listings
                'listings.view', 'listings.create', 'listings.edit',
                // Granular: Rentals
                'rentals.view', 'rentals.create', 'rentals.edit',
                // Granular: Daily Activity
                'daily_activity.view', 'daily_activity.create', 'daily_activity.edit',
                // Granular: TV Messages
                'tv_messages.view', 'tv_messages.create', 'tv_messages.edit',
                // Granular: Targets
                'targets.view', 'targets.create', 'targets.edit',
                // Calculators
                'calculators.manage',
                // Compliance
                'access_compliance', 'manage_compliance', 'view_compliance_reports',
                'compliance.view', 'compliance.manage',
                // Supervision
                'access_supervision', 'manage_supervision',
                'supervision.view', 'supervision.manage',
                // Training
                'access_training', 'assign_training',
                'training.view', 'training.manage',
                // Communication
                'access_communication', 'send_messages', 'manage_announcements',
                'communication.view', 'communication.send', 'communication.manage',
                // Client Portal
                'access_client_portal', 'manage_clients',
                // DocuPerfect
                'access_docuperfect', 'create_docuperfect_docs', 'manage_templates', 'manage_clauses',
                'access_docuperfect_packs', 'access_clause_library',
                'documents.view', 'documents.create', 'documents.edit',
                'templates.view', 'templates.create', 'templates.edit',
                'clauses.view', 'clauses.create', 'clauses.edit',
                'packs.view', 'packs.create', 'packs.edit',
                // Document Library
                'access_document_library', 'upload_documents_library',
                // Presentations
                'access_presentations', 'create_presentations', 'run_analysis',
                'presentations.view', 'presentations.create', 'presentations.edit',
                // Filing
                'access_filing_register',
                'filing.view', 'filing.create', 'filing.edit',
                // Commercial Evals
                'access_commercial_evaluations',
                'commercial_evals.view', 'commercial_evals.create', 'commercial_evals.edit',
                // Sales Docs
                'access_sales_documents',
                'sales_docs.view', 'sales_docs.create', 'sales_docs.edit',
                // Calculators / Ellie
                'access_calculators', 'access_ellie',
                // PDF Splitter
                'access_pdf_splitter',
                // Properties
                'access_properties', 'create_properties', 'publish_properties', 'delete_properties',
                'properties.view', 'properties.create', 'properties.edit',
                // Contacts
                'access_contacts',
                'contacts.view', 'contacts.create', 'contacts.edit', 'contacts.archive',
                // Core Matches
                'access_core_matches',
                'core_matches.view', 'core_matches.create', 'core_matches.delete',
                // P24
                'p24.view',
                // Knowledge
                'access_knowledge_base', 'knowledge.view',
                // Settings
                'settings.view',
                // Roles
                'roles.view',
                // Data scope
                'view_branch_stats', 'manage_branch',
            ],

            'agent' => [
                // Dashboard
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts',
                // Agency Tracker menu access
                'access_agency_tracker', 'access_daily_activity', 'access_rental_signatures',
                // Agency Tracker legacy
                'view_worksheet', 'edit_worksheet', 'view_deals',
                'view_listings', 'view_performance',
                'view_rentals', 'manage_rentals', 'view_daily_activity',
                // Granular: Deals — view + create own
                'deals.view', 'deals.create',
                // Granular: Listings
                'listings.view',
                // Granular: Rentals
                'rentals.view', 'rentals.create', 'rentals.edit',
                // Granular: Daily Activity
                'daily_activity.view', 'daily_activity.create', 'daily_activity.edit',
                // Granular: Targets — agents can view own targets
                'targets.view',
                // Training
                'access_training', 'training.view',
                // Communication
                'access_communication', 'send_messages',
                'communication.view', 'communication.send',
                // Client Portal
                'access_client_portal',
                // DocuPerfect
                'access_docuperfect', 'create_docuperfect_docs',
                'access_docuperfect_packs', 'access_clause_library',
                'documents.view', 'documents.create',
                'clauses.view',
                'packs.view',
                // Document Library
                'access_document_library',
                // Presentations
                'access_presentations',
                'presentations.view', 'presentations.create', 'presentations.edit',
                // Filing
                'access_filing_register',
                'filing.view', 'filing.create',
                // Commercial Evals
                'access_commercial_evaluations',
                'commercial_evals.view', 'commercial_evals.create',
                // Sales Docs
                'access_sales_documents',
                'sales_docs.view', 'sales_docs.create',
                // Calculators / Ellie
                'access_calculators', 'access_ellie',
                // PDF Splitter
                'access_pdf_splitter',
                // Properties
                'access_properties', 'create_properties',
                'properties.view', 'properties.create', 'properties.edit',
                // Contacts
                'access_contacts',
                'contacts.view', 'contacts.create', 'contacts.edit',
                // Core Matches
                'access_core_matches',
                'core_matches.view', 'core_matches.create', 'core_matches.delete',
                // P24
                'p24.view',
                // Knowledge
                'access_knowledge_base', 'knowledge.view',
                // Data scope
                'view_own_stats',
            ],

            'viewer' => [
                // Dashboard
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts',
                // Agency Tracker menu access
                'access_agency_tracker', 'access_daily_activity',
                // Agency Tracker legacy
                'view_worksheet', 'view_deals', 'view_listings', 'view_performance',
                'view_rentals', 'view_daily_activity',
                // Granular: view only
                'deals.view', 'listings.view', 'rentals.view', 'daily_activity.view', 'targets.view',
                // Training
                'access_training', 'training.view',
                // Communication
                'access_communication', 'communication.view',
                // Client Portal
                'access_client_portal',
                // Document Library
                'access_document_library',
                'documents.view', 'templates.view', 'clauses.view', 'packs.view',
                // Presentations
                'access_presentations', 'presentations.view',
                // Filing
                'access_filing_register', 'filing.view',
                // Commercial Evals
                'access_commercial_evaluations', 'commercial_evals.view',
                // Sales Docs
                'access_sales_documents', 'sales_docs.view',
                // Calculators / Ellie
                'access_calculators', 'access_ellie',
                // Properties
                'access_properties', 'properties.view',
                // Contacts
                'access_contacts', 'contacts.view',
                // Core Matches
                'access_core_matches', 'core_matches.view',
                // P24
                'p24.view',
                // Knowledge
                'access_knowledge_base', 'knowledge.view',
                // Settings
                'settings.view',
                // Data scope
                'view_own_stats',
            ],
        ];

        // ──────────────────────────────────────────────────────────
        // Data scope defaults for {module}.view permissions
        // Values: 'own', 'branch', 'all'
        // Only applied to .view keys — everything else stays null
        // ──────────────────────────────────────────────────────────
        $viewKeys = array_filter($allKeys, fn ($k) => str_ends_with($k, '.view'));

        $scopeDefaults = [
            'super_admin' => array_fill_keys($viewKeys, 'all'),

            'admin' => array_fill_keys($viewKeys, 'all'),

            'branch_manager' => array_merge(
                array_fill_keys($viewKeys, 'branch'), // default: branch
                // Override: shared modules get 'all'
                ['p24.view' => 'all', 'knowledge.view' => 'all']
            ),

            'agent' => array_merge(
                array_fill_keys($viewKeys, 'own'), // default: own
                // Override: shared modules get 'all'
                ['p24.view' => 'all', 'knowledge.view' => 'all']
            ),

            'viewer' => array_merge(
                array_fill_keys($viewKeys, 'branch'), // viewers see branch data
                // Override: shared modules get 'all'
                ['p24.view' => 'all', 'knowledge.view' => 'all']
            ),
        ];

        $now  = now();
        $rows = [];

        // Read roles from DB if available, fall back to defaults map keys
        $roleNames = [];
        try {
            $roleNames = Role::pluck('name')->all();
        } catch (\Throwable $e) {
            $roleNames = array_keys($defaults);
        }

        foreach ($roleNames as $roleName) {
            $ownerRole = null;
            try {
                $ownerRole = Role::where('name', $roleName)->where('is_owner', true)->first();
            } catch (\Throwable $e) {
                // roles table may not exist
            }

            if ($ownerRole) {
                $keys = $allKeys;
            } elseif (isset($defaults[$roleName])) {
                $keys = $defaults[$roleName];
            } else {
                $keys = [];
            }

            $roleScopes = $scopeDefaults[$roleName] ?? [];

            foreach ($keys as $key) {
                $rows[] = [
                    'role'           => $roleName,
                    'permission_key' => $key,
                    'scope'          => $roleScopes[$key] ?? null,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        // Wipe and re-seed (idempotent)
        RolePermission::truncate();
        if (count($rows)) {
            RolePermission::insert($rows);
        }
    }
}
