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
            ['key' => 'view_dashboard',          'label' => 'View Dashboard',              'section' => 'dashboard',       'sort_order' => 1],
            ['key' => 'view_dashboard_kpis',     'label' => 'View KPI Cards',              'section' => 'dashboard',       'sort_order' => 2],
            ['key' => 'view_dashboard_charts',   'label' => 'View Charts & Analytics',     'section' => 'dashboard',       'sort_order' => 3],
            ['key' => 'export_reports',          'label' => 'Export Reports',               'section' => 'dashboard',       'sort_order' => 4],

            // Agency Tracker
            ['key' => 'access_agency_tracker',   'label' => 'Access Agency Tracker',       'section' => 'agency-tracker',  'sort_order' => 1],
            ['key' => 'view_worksheet',          'label' => 'View Worksheet',              'section' => 'agency-tracker',  'sort_order' => 2],
            ['key' => 'edit_worksheet',          'label' => 'Edit Worksheet',              'section' => 'agency-tracker',  'sort_order' => 3],
            ['key' => 'view_deals',              'label' => 'View Deals',                  'section' => 'agency-tracker',  'sort_order' => 4],
            ['key' => 'create_deals',            'label' => 'Create & Edit Deals',         'section' => 'agency-tracker',  'sort_order' => 5],
            ['key' => 'settle_deals',            'label' => 'Settle Deals',                'section' => 'agency-tracker',  'sort_order' => 6],
            ['key' => 'view_listings',           'label' => 'View Listing Stock',          'section' => 'agency-tracker',  'sort_order' => 7],
            ['key' => 'import_listings',         'label' => 'Import Listings',             'section' => 'agency-tracker',  'sort_order' => 8],
            ['key' => 'view_performance',        'label' => 'View Performance',            'section' => 'agency-tracker',  'sort_order' => 9],
            ['key' => 'manage_targets',          'label' => 'Manage Targets',              'section' => 'agency-tracker',  'sort_order' => 10],
            ['key' => 'view_rentals',            'label' => 'View Rentals',                'section' => 'agency-tracker',  'sort_order' => 11],
            ['key' => 'manage_rentals',          'label' => 'Create & Edit Rentals',       'section' => 'agency-tracker',  'sort_order' => 12],
            ['key' => 'view_daily_activity',     'label' => 'View Daily Activity',         'section' => 'agency-tracker',  'sort_order' => 13],
            ['key' => 'manage_tv_messages',      'label' => 'Manage TV Messages',          'section' => 'agency-tracker',  'sort_order' => 14],

            // Documents
            ['key' => 'access_documents',        'label' => 'Access Documents',            'section' => 'documents',       'sort_order' => 1],
            ['key' => 'upload_documents',        'label' => 'Upload Documents',            'section' => 'documents',       'sort_order' => 2],
            ['key' => 'delete_documents',        'label' => 'Delete Documents',            'section' => 'documents',       'sort_order' => 3],

            // Compliance
            ['key' => 'access_compliance',       'label' => 'Access Compliance',           'section' => 'compliance',      'sort_order' => 1],
            ['key' => 'manage_compliance',       'label' => 'Manage Compliance Records',   'section' => 'compliance',      'sort_order' => 2],
            ['key' => 'view_compliance_reports', 'label' => 'View Compliance Reports',     'section' => 'compliance',      'sort_order' => 3],

            // Supervision
            ['key' => 'access_supervision',      'label' => 'Access Supervision',          'section' => 'supervision',     'sort_order' => 1],
            ['key' => 'manage_supervision',      'label' => 'Manage Supervision Records',  'section' => 'supervision',     'sort_order' => 2],

            // Training
            ['key' => 'access_training',         'label' => 'Access Training (LMS)',       'section' => 'training',        'sort_order' => 1],
            ['key' => 'manage_courses',          'label' => 'Manage Courses',              'section' => 'training',        'sort_order' => 2],
            ['key' => 'assign_training',         'label' => 'Assign Training',             'section' => 'training',        'sort_order' => 3],

            // Communication
            ['key' => 'access_communication',    'label' => 'Access Communication',        'section' => 'communication',   'sort_order' => 1],
            ['key' => 'send_messages',           'label' => 'Send Messages',               'section' => 'communication',   'sort_order' => 2],
            ['key' => 'manage_announcements',    'label' => 'Manage Announcements',        'section' => 'communication',   'sort_order' => 3],

            // Client Portal
            ['key' => 'access_client_portal',    'label' => 'Access Client Portal',        'section' => 'client-portal',   'sort_order' => 1],
            ['key' => 'manage_clients',          'label' => 'Manage Client Records',       'section' => 'client-portal',   'sort_order' => 2],

            // Franchise Admin
            ['key' => 'access_franchise_admin',  'label' => 'Access Franchise Admin',      'section' => 'franchise-admin', 'sort_order' => 1],
            ['key' => 'manage_branches',         'label' => 'Manage Branches',             'section' => 'franchise-admin', 'sort_order' => 2],
            ['key' => 'manage_users',            'label' => 'Manage Users',                'section' => 'franchise-admin', 'sort_order' => 3],
            ['key' => 'view_financial_reports',  'label' => 'View Financial Reports',      'section' => 'franchise-admin', 'sort_order' => 4],

            // Settings
            ['key' => 'access_settings',         'label' => 'Access Settings',             'section' => 'settings',        'sort_order' => 1],
            ['key' => 'manage_designations',     'label' => 'Manage Designations',         'section' => 'settings',        'sort_order' => 2],
            ['key' => 'manage_branch_settings',  'label' => 'Manage Branch Settings',      'section' => 'settings',        'sort_order' => 3],
            ['key' => 'manage_performance_settings', 'label' => 'Manage Performance Settings', 'section' => 'settings',    'sort_order' => 4],

            // Role Manager
            ['key' => 'access_role_manager',     'label' => 'Access Role Manager',         'section' => 'role-manager',    'sort_order' => 1],
            ['key' => 'edit_permissions',        'label' => 'Edit Permissions',            'section' => 'role-manager',    'sort_order' => 2],
            ['key' => 'change_user_roles',       'label' => 'Change User Roles',           'section' => 'role-manager',    'sort_order' => 3],
        ];

        foreach ($permissions as $perm) {
            NexusPermission::updateOrCreate(['key' => $perm['key']], $perm);
        }

        // Seed default role-permission assignments matching the existing canAccessNexusSection logic
        $defaults = [
            'admin' => array_column($permissions, 'key'), // admin gets everything
            'branch_manager' => [
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts', 'export_reports',
                'access_agency_tracker', 'view_worksheet', 'edit_worksheet', 'view_deals', 'create_deals',
                'settle_deals', 'view_listings', 'view_performance', 'manage_targets', 'view_rentals',
                'manage_rentals', 'view_daily_activity', 'manage_tv_messages',
                'access_documents', 'upload_documents',
                'access_compliance', 'manage_compliance', 'view_compliance_reports',
                'access_supervision', 'manage_supervision',
                'access_training', 'assign_training',
                'access_communication', 'send_messages', 'manage_announcements',
                'access_client_portal', 'manage_clients',
            ],
            'agent' => [
                'view_dashboard', 'view_dashboard_kpis', 'view_dashboard_charts',
                'access_agency_tracker', 'view_worksheet', 'edit_worksheet', 'view_deals',
                'view_listings', 'view_performance', 'view_rentals', 'manage_rentals', 'view_daily_activity',
                'access_documents',
                'access_training',
                'access_communication', 'send_messages',
                'access_client_portal',
            ],
        ];

        $now = now();
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

        // Only seed if table is empty
        if (RolePermission::count() === 0) {
            RolePermission::insert($rows);
        }
    }
}
