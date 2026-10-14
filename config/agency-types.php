<?php

/**
 * What an agency does — sales, rentals, or both — and which Features that decides.
 * Spec: .ai/specs/agency-onboarding-setup.md §5.2 (14 Oct 2026, Johan: a rentals agency must not be handed
 * the sales toolkit, syndication or payroll "just because").
 *
 * Read by App\Services\Features\AgencyTypeFeatureSeeder, which turns these lists into the agency's feature
 * switches when the agency is created (and again, on purpose, when an admin changes the type). The switches
 * stay ordinary switches afterwards: the admin can flip any of them on the Features page.
 *
 * Keys are feature keys from config/corex-features.php. A feature that depends_on a listed one switches off
 * with it (the normal cascade), so only the root of each family is named.
 */
return [
    'default' => 'both',

    'types' => [
        'both'    => ['label' => 'Sales and rentals', 'explain' => 'Everything CoreX does for both sides of the business.'],
        'sales'   => ['label' => 'Sales only',        'explain' => 'Listings, buyers, mandates and deals. The Rentals area is switched off.'],
        'rentals' => ['label' => 'Rentals only',      'explain' => 'Leases, tenants, landlords and maintenance. The sales tools are switched off.'],
    ],

    // Switched OFF for a rentals-only agency: tools that only make sense when you sell property.
    'sales_features' => [
        'core-matches',          // buyer wishlists matched to new listings
        'buyer-pipeline',
        'prospecting',           // seller prospecting (Road to Sold switches off with it)
        'portal-leads',
        'outreach',
        'presentations',         // CMA / seller presentations
        'commercial-evaluations',
        'viewing-packs',
        'deeds-capture',
        'address-review',
        'agency-tracker',        // deal tracking and commission (commission management switches off with it)
        'proforma-invoices',
        'title-deeds',
        'buyers-report',
        'lead-response-report',
        'imported-stock',
    ],

    // Switched OFF for a sales-only agency: the whole Rentals area (every rental module depends on this one).
    'rentals_features' => [
        'rentals',
    ],

    // Switched OFF for every NEW agency whatever its type — the agency opts in when it is ready for them
    // (syndication needs portal credentials; payroll needs staff set up first).
    'opt_in_features' => [
        'syndication-p24',
        'syndication-pp',
        'payroll',
    ],
];
