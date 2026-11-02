<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * AT-534 — System Developer → Campaigns: Campaigns | Lists | Suppressed | Settings (.ai/specs/campaigns.md §2). Owner-only by the
 * route group and the sidebar's owner section; no feature switch and DELIBERATELY no permission key.
 */
final class CampaignsTabs extends TabHub
{
    public static function label(): string
    {
        return 'Campaigns';
    }

    public static function tabs(): array
    {
        return [
            'campaigns' => ['label' => 'Campaigns', 'route' => 'admin.campaigns.index',
                'match' => ['admin.campaigns.index', 'admin.campaigns.create', 'admin.campaigns.show', 'admin.campaigns.edit'], 'feature' => null, 'permissions' => []],
            'lists' => ['label' => 'Lists', 'route' => 'admin.campaigns.lists.index', 'match' => ['admin.campaigns.lists.*'], 'feature' => null, 'permissions' => []],
            'suppressed' => ['label' => 'Suppressed', 'route' => 'admin.campaigns.suppressions.index', 'match' => ['admin.campaigns.suppressions.*'], 'feature' => null, 'permissions' => []],
            'settings' => ['label' => 'Settings', 'route' => 'admin.campaigns.settings', 'feature' => null, 'permissions' => []],
        ];
    }
}
