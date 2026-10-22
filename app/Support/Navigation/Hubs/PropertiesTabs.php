<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/real-estate-menu-tabs.md - Real Estate > Properties: Properties | Imported stock | Map. The tabs match only the sales route
 * names, so the same screens opened from Rentals or Auctions (their own routes) show no Real Estate tab bar.
 */
final class PropertiesTabs extends TabHub
{
    public static function label(): string
    {
        return 'Properties';
    }

    public static function tabs(): array
    {
        return [
            'properties' => ['label' => 'Properties', 'route' => 'corex.properties.index', 'old' => null,
                'feature' => null, 'permissions' => ['access_properties'], 'config' => 'features.properties'],
            'imported-stock' => ['label' => 'Imported stock', 'route' => 'corex.properties.imported-stock', 'old' => 'Imported Stock',
                'feature' => 'imported-stock', 'permissions' => ['access_imported_stock']],
            'map' => ['label' => 'Map', 'route' => 'corex.map.index', 'old' => 'Map',
                'feature' => null, 'permissions' => ['access_properties']],
        ];
    }
}
