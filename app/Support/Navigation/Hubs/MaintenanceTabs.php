<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/rentals-menu-tabs.md - Rentals > Maintenance (was the "Maintenance" heading): Fault Reports | Job Cards | Crews |
 * Parts & Labour Catalogue | Rental Work Orders | Suppliers & contractors | Fault Types (Fault Types was under "Set-up").
 * Suppliers is the Deals supplier directory opened with ?nav=rentals - only that entry shows this bar.
 */
final class MaintenanceTabs extends TabHub
{
    public static function label(): string
    {
        return 'Maintenance';
    }

    public static function tabs(): array
    {
        return [
            'fault-reports' => ['label' => 'Fault Reports', 'route' => 'corex.rental-fault-reports.index', 'old' => ['Fault Reports', 'Rental Fault Reports'],
                'feature' => 'rental-faults', 'permissions' => ['rental_fault_reports.view']],
            'job-cards' => ['label' => 'Job Cards', 'route' => 'corex.rental-job-cards.index', 'old' => 'Job Cards',
                'feature' => 'rental-job-cards', 'permissions' => ['rental_job_cards.view']],
            'crews' => ['label' => 'Crews', 'route' => 'corex.rental-crews.index', 'old' => ['Crews', 'Rental Crews'],
                'feature' => 'rental-crews', 'permissions' => ['rental_catalogue.view']],
            'catalogue' => ['label' => 'Parts & Labour Catalogue', 'route' => 'corex.rental-catalogue-items.index', 'old' => 'Parts & Labour Catalogue',
                'feature' => 'rental-catalogue', 'permissions' => ['rental_catalogue.view']],
            'work-orders' => ['label' => 'Rental Work Orders', 'route' => 'corex.rental-work-orders.index', 'old' => 'Rental Work Orders',
                'feature' => 'rental-work-orders', 'permissions' => ['rental_work_orders.view']],
            // the old item had no feature switch (the supplier directory is a core Deals screen) - copied as-is
            'suppliers' => ['label' => 'Suppliers & contractors', 'route' => 'deals-v2.suppliers.index', 'query' => ['nav' => 'rentals'], 'old' => 'Suppliers & contractors',
                'feature' => null, 'permissions' => ['deals_v2.manage_suppliers']],
            'fault-types' => ['label' => 'Fault Types', 'route' => 'corex.rental-fault-types.index', 'old' => ['Fault Types', 'Rental Fault Types'],
                'feature' => 'rental-faults', 'permissions' => ['rental_fault_types.view']],
        ];
    }

    protected static function activeAlso(string $key): bool
    {
        return $key !== 'suppliers' || request()->query('nav') === 'rentals';
    }
}
