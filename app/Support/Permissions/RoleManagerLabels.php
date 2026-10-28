<?php

namespace App\Support\Permissions;

/**
 * The wording the Role Manager uses for a permission module, in one place so the gradual-rollout access review
 * (spec agency-feature-rollout.md §21) shows exactly what the Role Manager shows.
 */
final class RoleManagerLabels
{
    /** Module headings shown in the Role Manager matrix. */
    public const MODULE_LABELS = [
        'dashboard'        => 'Dashboard',
        'command_center'          => 'Command Center',
        'command_center_calendar' => 'Calendar',
        'command_center_tasks'    => 'Tasks',
        'agency_tracker'   => 'Agency Tracker',
        'deals'            => 'Deals',
        'deals_v2'         => 'Deal Register V2',
        'listings'         => 'Listings',
        'rentals'          => 'Rentals',
        'leases'                  => 'Leases',
        'rental_command_centre'   => 'Rentals Command Centre',
        'rental_applications'     => 'Rental Applications',
        'rental_details'          => 'Rental Details',
        'rental_inspections'      => 'Rental Inspections',
        'rental_inventories'      => 'Rental Inventories',
        'rental_fault_reports'    => 'Rental Fault Reports',
        'rental_fault_types'      => 'Rental Fault Types',
        'rental_work_orders'      => 'Rental Work Orders',
        'rental_job_cards'        => 'Rental Job Cards',
        'rental_catalogue'        => 'Rental Catalogue & Crews',
        'rental_notices'          => 'Rental Notices',
        'rental_portal'           => 'Rental Portal Access',
        'rental_reports'          => 'Rental Reports',
        'rentals_take_on_import'  => 'Rental Take-On Import',
        'ppra_employment_letters' => 'PPRA Employment Letters',
        'ppra_inspection_pack'    => 'PPRA Inspection Pack',
        'daily_activity'   => 'Daily Activity',
        'tv_messages'      => 'TV Messages',
        'compliance'       => 'Compliance',
        'supervision'      => 'Supervision',
        'training'         => 'Training',
        'communication'    => 'Communication',
        'client_portal'    => 'Client Portal',
        'franchise_admin'  => 'Franchise Admin',
        'users'            => 'Users',
        'docuperfect'      => 'DocuPerfect',
        'documents'        => 'Documents',
        'templates'        => 'Templates',
        'clauses'          => 'Clauses',
        'packs'            => 'Document Packs',
        'document_library' => 'Document Library',
        'presentations'    => 'Presentations',
        'filing'           => 'Filing Register',
        'title_deeds'      => 'Title Deeds',
        'commercial_evals' => 'Commercial Evaluations',
        'sales_docs'       => 'Sales Documents',
        'properties'       => 'Properties',
        'contacts'         => 'Contacts',
        'contact_rental_history' => 'Contact Rental History',
        'buyer_pipeline'   => 'Buyer Pipeline',
        'core_matches'     => 'Core Matches',
        'calculators'      => 'Calculators & Tools',
        'ad_manager'       => 'Ad Manager',
        'ellie'            => 'Ellie AI',
        'p24'              => 'P24 Market Intel',
        'prospecting'      => 'Prospecting',
        'deeds_capture'    => 'Deeds Capture',
        'evaluation'       => 'Evaluation',
        'pdf_splitter'     => 'PDF Splitter',
        'knowledge'        => 'Knowledge Base',
        'finance'          => 'Finance Engine',
        'agencies'         => 'Agencies',
        'settings'         => 'Settings',
        'billing'          => 'Billing',
        'roles'            => 'Role Manager',
        'data_scope'       => 'Data Scope',
        'sidebar'          => 'Sidebar',
        'assistants'       => 'Assistants',
    ];

    /** Modules every user can see — no scope picker. */
    public const SHARED_MODULES = ['p24', 'knowledge', 'calculators', 'ellie', 'pdf_splitter', 'prospecting', 'evaluation'];
}
