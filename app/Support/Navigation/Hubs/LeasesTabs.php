<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/rentals-menu-tabs.md - Rentals > Leases: Leases | Inspections. Notices stays its own menu item (it is itself a tabbed screen,
 * AT-466 - a tab never holds tabs).
 */
final class LeasesTabs extends TabHub
{
    public static function label(): string
    {
        return 'Leases';
    }

    public static function tabs(): array
    {
        return [
            'leases' => ['label' => 'Leases', 'route' => 'corex.leases.index', 'old' => null,
                'feature' => 'rental-leases', 'permissions' => ['leases.view']],
            'inspections' => ['label' => 'Inspections', 'route' => 'corex.rental-inspections.index', 'old' => ['Inspections', 'Rental Inspections'],
                'feature' => 'rental-inspections', 'permissions' => ['rental_inspections.view']],
        ];
    }
}
