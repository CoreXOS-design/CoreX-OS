<?php

namespace App\Support\Navigation;

use App\Support\Navigation\Hubs\BuyersTabs;
use App\Support\Navigation\Hubs\CalendarTabs;
use App\Support\Navigation\Hubs\LeasesTabs;
use App\Support\Navigation\Hubs\LettingTabs;
use App\Support\Navigation\Hubs\MaintenanceTabs;
use App\Support\Navigation\Hubs\MarketIntelligenceTabs;
use App\Support\Navigation\Hubs\OutreachTabs;
use App\Support\Navigation\Hubs\PerformanceTabs;
use App\Support\Navigation\Hubs\PresentationsTabs;
use App\Support\Navigation\Hubs\PropertiesTabs;

/**
 * .ai/specs/real-estate-menu-tabs.md, .ai/specs/dashboard-menu-tabs.md - every tabbed menu hub, by the key in its landing route
 * (corex.hubs.<key>). The landing route, the sidebar guard (SidebarFeatureCoverage) and the tests all read this one list.
 */
final class TabHubs
{
    /** @var array<string, class-string<TabHub>> */
    public const HUBS = [
        'market-intelligence' => MarketIntelligenceTabs::class,
        'properties' => PropertiesTabs::class,
        'buyers' => BuyersTabs::class,
        'outreach' => OutreachTabs::class,
        'presentations' => PresentationsTabs::class,
        // Dashboard
        'calendar' => CalendarTabs::class,
        'performance' => PerformanceTabs::class,
        // Rentals (.ai/specs/rentals-menu-tabs.md)
        'letting' => LettingTabs::class,
        'leases' => LeasesTabs::class,
        'maintenance' => MaintenanceTabs::class,
    ];

    /** @return class-string<TabHub>|null */
    public static function find(string $key): ?string
    {
        return self::HUBS[$key] ?? null;
    }

    /** Every feature key some hub tab is switched by. @return list<string> */
    public static function featureKeys(): array
    {
        $keys = [];
        foreach (self::HUBS as $class) {
            $keys = array_merge($keys, $class::featureKeys());
        }

        return array_values(array_unique($keys));
    }
}
