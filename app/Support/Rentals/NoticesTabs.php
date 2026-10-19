<?php

namespace App\Support\Rentals;

use App\Models\User;
use App\Services\Features\AgencyFeatureService;

/**
 * AT-466 (Johan, 16 Oct 2026) - the ONE "Notices" menu item under Rentals > Leases. It opens a screen with tabs: Breaches (the breach
 * register), Notices received (a notice the tenant / landlord gave us), Notices sent (the notices the agency sent) and Templates (the agency's
 * notice templates). Each tab is the existing screen on its existing route with its existing permission - a user sees only the tabs they may
 * open, and the item itself only when at least one tab is open to them. This class is the single list of the tabs, used by the tab bar, the
 * hub route and the sidebar's search entries.
 */
final class NoticesTabs
{
    /** @var array<string, array{label: string, route: string, permission: string, old: string}> in tab order; `old` = the name the menu used before the tabs */
    public const TABS = [
        'breaches' => ['label' => 'Breaches', 'route' => 'corex.breaches.index', 'permission' => 'rental_breaches.view', 'old' => 'Breach Register'],
        'received' => ['label' => 'Notices received', 'route' => 'corex.lease-notices.index', 'permission' => 'rental_notices.review', 'old' => 'Notices Received'],
        'sent' => ['label' => 'Notices sent', 'route' => 'corex.rental-notices.index', 'permission' => 'rental_notices.create', 'old' => 'Rental Notices'],
        'templates' => ['label' => 'Templates', 'route' => 'corex.rental-notice-templates.index', 'permission' => 'rental_notice_templates.manage_settings', 'old' => 'Notice Templates'],
    ];

    /** @return list<string> every permission that opens one of the tabs (the hub and the menu item need any one of them) */
    public static function permissions(): array
    {
        return array_values(array_map(fn (array $t) => $t['permission'], self::TABS));
    }

    /**
     * The tabs this user may open, in order.
     *
     * @return array<string, array{label: string, url: string, old: string, permission: string}>
     */
    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }
        $features = app(AgencyFeatureService::class);
        $out = [];
        foreach (self::TABS as $key => $t) {
            if ($features->permissionBlockedByFeature($t['permission']) || ! $user->hasPermission($t['permission'])) {
                continue;
            }
            $out[$key] = ['label' => $t['label'], 'url' => route($t['route']), 'old' => $t['old'], 'permission' => $t['permission']];
        }

        return $out;
    }

    /** Where the Notices item lands: the asked-for tab if the user may open it, else the first tab they may open. */
    public static function landingUrl(?User $user, ?string $tab = null): ?string
    {
        $tabs = self::forUser($user);

        return ($tab !== null && isset($tabs[$tab])) ? $tabs[$tab]['url'] : (array_values($tabs)[0]['url'] ?? null);
    }
}
