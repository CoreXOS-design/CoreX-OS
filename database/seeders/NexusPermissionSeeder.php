<?php

namespace Database\Seeders;

use App\Models\NexusPermission;
use App\Models\RolePermission;
use Illuminate\Database\Seeder;

class NexusPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['key' => 'view_dashboard',          'label' => 'View Dashboard',                  'section' => 'dashboard',        'sort_order' => 1],
            ['key' => 'view_dashboard_kpis',     'label' => 'View KPI Cards',                  'section' => 'dashboard',        'sort_order' => 2],
            ['key' => 'view_dashboard_charts',   'label' => 'View Charts & Analytics',         'section' => 'dashboard',        'sort_order' => 3],
            ['key' => 'export_reports',          'label' => 'Export Reports',                  'section' => 'dashboard',        'sort_order' => 4],

            // Agency Tracker
            ['key' => 'access_agency_tracker',  'label' => 'Access Agency Tracker',           'section' => 'agency-tracker',   'sort_order' => 1],
            ['key' => 'view_worksheet',          'label' => 'View Worksheet',                  'section' => 'agency-tracker',   'sort_order' => 2],
            ['key' => 'edit_worksheet',          'label' => 'Edit Worksheet',                  'section' => 'agency-tracker',   'sort_order' => 3],
            ['key' => 'view_deals',              'label' => 'View Deals',                      'section' => 'agency-tracker',   'sort_order' => 4],
            ['key' => 'create_deals',            'label' => 'Create & Edit Deals',             'section' => 'agency-tracker',   'sort_order' => 5],
            ['key' => 'settle_deals',            'label' => 'Settle Deals',                    'section' => 'agency-tracker',   'sort_order' => 6],
            ['key' => 'view_listings',           'label' => 'View Listing Stock',              'section' => 'agency-tracker',   'sort_order' => 7],
            ['key' => 'import_listings',         'label' => 'Import Listings',                 'section' => 'agency-tracker',   'sort_order' => 8],
            ['key' => 'view_performance',        'label' => 'View Performance',                'section' => 'agency-tracker',   'sort_order' => 9],
            ['key' => 'manage_targets',          'label' => 'Manage Targets',                  'section' => 'agency-tracker',   'sort_order' => 10],
            ['key' => 'view_rentals',            'label' => 'View Rentals',                    'section' => 'agency-tracker',   'sort_order' => 11],
            ['key' => 'manage_rentals',          'label' => 'Create & Edit Rentals',           'section' => 'agency-tracker',   'sort_order' => 12],
            ['key' => 'view_daily_activity',     'label' => 'View Daily Activity',             'section' => 'agency-tracker',   'sort_order' => 13],
            ['key' => 'manage_tv_messages',      'label' => 'Manage TV Messages',              'section' => 'agency-tracker',   'sort_order' => 14],

            // Compliance
            ['key' => 'access_compliance',       'label' => 'Access Compliance',               'section' => 'compliance',       'sort_order' => 1],
            ['key' => 'manage_compliance',       'label' => 'Manage Compliance Records',       'section' => 'compliance',       'sort_order' => 2],
            ['key' => 'view_compliance_reports', 'label' => 'View Compliance Reports',         'section' => 'compliance',       'sort_order' => 3],

            // Supervision
            ['key' => 'access_supervision',      'label' => 'Access Supervision',              'section' => 'supervision',      'sort_order' => 1],
            ['key' => 'manage_supervision',      'label' => 'Manage Supervision Records',      'section' => 'supervision',      'sort_order' => 2],

            // Training
            ['key' => 'access_training',         'label' => 'Access Training (LMS)',           'section' => 'training',         'sort_order' => 1],
            ['key' => 'manage_courses',          'label' => 'Manage Courses',                  'section' => 'training',         'sort_order' => 2],
            ['key' => 'assign_training',         'label' => 'Assign Training',                 'section' => 'training',         'sort_order' => 3],

            // Communication
            ['key' => 'access_communication',    'label' => 'Access Communication',            'section' => 'communication',    'sort_order' => 1],
            ['key' => 'send_messages',           'label' => 'Send Messages',                   'section' => 'communication',    'sort_order' => 2],
            ['key' => 'manage_announcements',    'label' => 'Manage Announcements',            'section' => 'communication',    'sort_order' => 3],

            // Client Portal
            ['key' => 'access_client_portal',    'label' => 'Access Client Portal',            'section' => 'client-portal',    'sort_order' => 1],
            ['key' => 'manage_clients',          'label' => 'Manage Client Records',           'section' => 'client-portal',    'sort_order' => 2],

            // Franchise Admin
            ['key' => 'access_franchise_admin',  'label' => 'Access Franchise Admin',          'section' => 'franchise-admin',  'sort_order' => 1],
            ['key' => 'manage_branches',         'label' => 'Manage Branches',                 'section' => 'franchise-admin',  'sort_order' => 2],
            ['key' => 'manage_users',            'label' => 'Manage Users',                    'section' => 'franchise-admin',  'sort_order' => 3],
            ['key' => 'view_financial_reports',  'label' => 'View Financial Reports',          'section' => 'franchise-admin',  'sort_order' => 4],

            // DocuPerfect
            ['key' => 'access_docuperfect',          'label' => 'Access DocuPerfect',          'section' => 'docuperfect',      'sort_order' => 1],
            ['key' => 'create_docuperfect_docs',     'label' => 'Create Documents',            'section' => 'docuperfect',      'sort_order' => 2],
            ['key' => 'manage_templates',            'label' => 'Manage Templates',            'section' => 'docuperfect',      'sort_order' => 3],
            ['key' => 'manage_clauses',              'label' => 'Manage Clause Library',       'section' => 'docuperfect',      'sort_order' => 4],
            ['key' => 'manage_docuperfect_settings', 'label' => 'Manage DocuPerfect Settings', 'section' => 'docuperfect',      'sort_order' => 5],

            // Document Library
            ['key' => 'access_document_library',     'label' => 'Access Document Library',    'section' => 'document-library', 'sort_order' => 1],
            ['key' => 'upload_documents_library',    'label' => 'Upload to Library',           'section' => 'document-library', 'sort_order' => 2],
            ['key' => 'manage_document_library',     'label' => 'Manage Document Library',    'section' => 'document-library', 'sort_order' => 3],

            // Presentations
            ['key' => 'access_presentations',        'label' => 'Access Presentations',        'section' => 'presentations',    'sort_order' => 1],
            ['key' => 'create_presentations',        'label' => 'Create Presentations',        'section' => 'presentations',    'sort_order' => 2],
            ['key' => 'run_analysis',                'label' => 'Run Analysis',                'section' => 'presentations',    'sort_order' => 3],
            ['key' => 'compile_pack',                'label' => 'Compile Pack',                'section' => 'presentations',    'sort_order' => 4],

            // PDF Splitter
            ['key' => 'access_pdf_splitter',         'label' => 'Access PDF Splitter',         'section' => 'pdf-splitter',     'sort_order' => 1],

            // Knowledge Base
            ['key' => 'access_knowledge_base',       'label' => 'Access Knowledge Base',       'section' => 'knowledge-base',   'sort_order' => 1],
            ['key' => 'manage_knowledge_base',       'label' => 'Manage Knowledge Base',       'section' => 'knowledge-base',   'sort_order' => 2],

            // Finance Engine
            ['key' => 'access_finance_engine',       'label' => 'Access Finance Engine',       'section' => 'finance-engine',   'sort_order' => 1],
            ['key' => 'manage_finance_definitions',  'label' => 'Manage Finance Definitions',  'section' => 'finance-engine',   'sort_order' => 2],
            ['key' => 'run_finance_audit',           'label' => 'Run Finance Audit',           'section' => 'finance-engine',   'sort_order' => 3],

            // Properties
            ['key' => 'access_properties',           'label' => 'Access Properties',           'section' => 'properties',       'sort_order' => 1],
            ['key' => 'create_properties',           'label' => 'Create & Edit Properties',    'section' => 'properties',       'sort_order' => 2],
            ['key' => 'publish_properties',          'label' => 'Publish Properties',          'section' => 'properties',       'sort_order' => 3],
            ['key' => 'delete_properties',           'label' => 'Delete Properties',           'section' => 'properties',       'sort_order' => 4],

            // Agencies (super_admin only in code, seeded here for completeness)
            ['key' => 'access_agencies',             'label' => 'Access Agencies',             'section' => 'agencies',         'sort_order' => 1],
            ['key' => 'manage_agencies',             'label' => 'Manage Agencies',             'section' => 'agencies',         'sort_order' => 2],

            // Settings
            ['key' => 'access_settings',             'label' => 'Access Settings',             'section' => 'settings',         'sort_order' => 1],
            ['key' => 'manage_designations',         'label' => 'Manage Designations',         'section' => 'settings',         'sort_order' => 2],
            ['key' => 'manage_branch_settings',      'label' => 'Manage Branch Settings',      'section' => 'settings',         'sort_order' => 3],
            ['key' => 'manage_performance_settings', 'label' => 'Manage Performance Settings', 'section' => 'settings',         'sort_order' => 4],

            // Role Manager
            ['key' => 'access_role_manager',         'label' => 'Access Role Manager',         'section' => 'role-manager',     'sort_order' => 1],
            ['key' => 'edit_permissions',            'label' => 'Edit Permissions',            'section' => 'role-manager',     'sort_order' => 2],
            ['key' => 'change_user_roles',           'label' => 'Change User Roles',           'section' => 'role-manager',     'sort_order' => 3],
        ];

        foreach ($permissions as $perm) {
            NexusPermission::updateOrCreate(['key' => $perm['key']], $perm);
        }

        $allKeys = array_column($permissions, 'key');

        $defaults = [
            'super_admin' => $allKeys,

            'admin' => array_values(array_filter($allKeys, fn ($k) => !in_array($k, [
                'access_agencies', 'manage_agencies',
            ]))),

            'branch_manager' => [
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts', 'export_reports',
                'access_agency_tracker', 'view_worksheet', 'edit_worksheet', 'view_deals', 'create_deals',
                'settle_deals', 'view_listings', 'view_performance', 'manage_targets', 'view_rentals',
                'manage_rentals', 'view_daily_activity', 'manage_tv_messages',
                'access_compliance', 'manage_compliance', 'view_compliance_reports',
                'access_supervision', 'manage_supervision',
                'access_training', 'assign_training',
                'access_communication', 'send_messages', 'manage_announcements',
                'access_client_portal', 'manage_clients',
                'access_docuperfect', 'create_docuperfect_docs', 'manage_templates', 'manage_clauses',
                'access_document_library', 'upload_documents_library',
                'access_presentations', 'create_presentations', 'run_analysis',
                'access_pdf_splitter',
                'access_properties', 'create_properties', 'publish_properties', 'delete_properties',
            ],

            'agent' => [
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts',
                'access_agency_tracker', 'view_worksheet', 'edit_worksheet', 'view_deals',
                'view_listings', 'view_performance', 'view_rentals', 'manage_rentals', 'view_daily_activity',
                'access_training',
                'access_communication', 'send_messages',
                'access_client_portal',
                'access_docuperfect', 'create_docuperfect_docs',
                'access_document_library',
                'access_presentations',
                'access_pdf_splitter',
                'access_properties', 'create_properties',
            ],

            'viewer' => [
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts',
                'access_agency_tracker', 'view_worksheet', 'view_deals',
                'view_listings', 'view_performance', 'view_rentals', 'view_daily_activity',
                'access_training',
                'access_communication',
                'access_client_portal',
                'access_document_library',
                'access_presentations',
                'access_properties',
            ],
        ];

        $now  = now();
        $rows = [];
        foreach ($defaults as $role => $keys) {
            foreach ($keys as $key) {
                $rows[] = [
                    'role'           => $role,
                    'permission_key' => $key,
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
