<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * .ai/specs/real-estate-menu-tabs.md - one menu item that opens a screen with tabs across the top. Each tab is an EXISTING screen on its
 * EXISTING route; a tab is visible exactly when the menu item it replaced was visible (same feature switch, permission(s) and conditions,
 * copied from the sidebar), and the hub's menu item shows when at least one tab is visible. Same model as AT-466's Rentals NoticesTabs.
 *
 * A hub subclass lists its tabs in tabs(), in order:
 *   'key' => [
 *     'label'       => tab label,
 *     'route'       => the screen's own route (the tab's link),
 *     'query'       => optional query parameters for the link,
 *     'match'       => routeIs() patterns that make this tab the active one (default: [route]); a tab bar only renders on a route one
 *                      of its tabs matches, so a screen shared with Rentals / Auctions (another route name) never shows it,
 *     'feature'     => the features-registry key that switches the old menu item, or null for a core screen,
 *     'permissions' => every permission the old menu item checked (all required),
 *     'config'      => optional config() flag the old menu item checked,
 *     'old'         => the name(s) the menu used before the tabs (kept for menu search; a string or a list), or null when unchanged,
 *   ]
 * and may override allows() for a condition that is not a feature / permission / config flag, and badge() for a count on a tab.
 */
abstract class TabHub
{
    /** @return array<string, array<string, mixed>> */
    abstract public static function tabs(): array;

    /** The name of the hub's menu item and tab bar. */
    abstract public static function label(): string;

    /** Any extra condition the old menu item checked. */
    protected static function allows(string $key, User $user): bool
    {
        return true;
    }

    /** A count shown on the tab (null = none). */
    public static function badge(string $key, User $user): ?int
    {
        return null;
    }

    /** The colour of the tab / menu-item count (default: the amber the Refresh Requests count always used). */
    public static function badgeColor(): string
    {
        return 'var(--ds-amber, #f59e0b)';
    }

    /**
     * The tabs this user may open, in order.
     *
     * @return array<string, array{label: string, url: string, old: string|list<string>|null, badge: ?int}>
     */
    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }
        $out = [];
        foreach (static::tabs() as $key => $t) {
            if (! Route::has($t['route'])
                || (! empty($t['config']) && ! config($t['config']))
                || (! empty($t['feature']) && ! $user->hasFeature($t['feature']))) {
                continue;
            }
            foreach ($t['permissions'] ?? [] as $permission) {
                if (! $user->hasPermission($permission)) {
                    continue 2;
                }
            }
            if (! static::allows($key, $user)) {
                continue;
            }
            $out[$key] = [
                'label' => $t['label'],
                'url' => route($t['route'], $t['query'] ?? []),
                'old' => $t['old'] ?? null,
                'badge' => static::badge($key, $user),
            ];
        }

        return $out;
    }

    /** Where the menu item lands: the asked-for tab if the user may open it, else the first tab they may open. */
    public static function landingUrl(?User $user, ?string $tab = null): ?string
    {
        $tabs = static::forUser($user);

        return ($tab !== null && isset($tabs[$tab])) ? $tabs[$tab]['url'] : (array_values($tabs)[0]['url'] ?? null);
    }

    /** The tab the current request is on, or null when this route is not one of the hub's tabs (e.g. the Rentals entry of a shared screen). */
    public static function activeKey(): ?string
    {
        foreach (static::tabs() as $key => $t) {
            if (request()->routeIs(...($t['match'] ?? [$t['route']])) && static::activeAlso($key)) {
                return $key;
            }
        }

        return null;
    }

    /** A further request check for the active tab (e.g. the ?tab= of a screen that holds two tabs). */
    protected static function activeAlso(string $key): bool
    {
        return true;
    }

    /**
     * Menu-search entries for the old item names, pointing at their tabs (the sidebar's data-nav-extra, as AT-466's Notices item).
     *
     * @param  array<string, array{label: string, url: string, old: ?string, badge: ?int}>  $tabs  forUser()'s result
     * @return list<array{l: string, h: string}>
     */
    public static function searchEntries(array $tabs): array
    {
        return collect($tabs)
            ->filter(fn ($t) => $t['old'] !== null)
            // 'old' may list several names (a Rentals item's label AND its earlier "Rental ..." alias)
            ->flatMap(fn ($t) => array_map(fn ($name) => ['l' => $name, 'h' => $t['url']], (array) $t['old']))
            ->values()->all();
    }

    /** Every feature key a tab of this hub is switched by. @return list<string> */
    public static function featureKeys(): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($t) => $t['feature'] ?? null, static::tabs()))));
    }

    /**
     * Total of the tab badges, for the menu item.
     *
     * @param  array<string, array{label: string, url: string, old: ?string, badge: ?int}>  $tabs  forUser()'s result
     */
    public static function badgeTotal(array $tabs): int
    {
        return (int) array_sum(array_map(fn ($t) => $t['badge'] ?? 0, $tabs));
    }
}
