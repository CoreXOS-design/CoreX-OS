<?php

namespace App\Support\Navigation\Hubs;

use App\Models\PresentationRefreshRequest;
use App\Models\User;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/real-estate-menu-tabs.md - Real Estate > Presentations: Presentations | Analytics | Outcomes | Refresh requests |
 * Commercial evaluations. The open refresh-request count that sat on its own menu item sits on its tab and on the Presentations item.
 */
final class PresentationsTabs extends TabHub
{
    public static function label(): string
    {
        return 'Presentations';
    }

    public static function tabs(): array
    {
        return [
            'presentations' => ['label' => 'Presentations', 'route' => 'presentations.index', 'old' => null,
                'feature' => 'presentations', 'permissions' => ['access_presentations'], 'config' => 'features.presentations'],
            'analytics' => ['label' => 'Analytics', 'route' => 'corex.presentations.analytics.index', 'match' => ['corex.presentations.analytics.index'], 'old' => 'Analytics',
                'feature' => 'presentations', 'permissions' => ['access_presentations']],
            'outcomes' => ['label' => 'Outcomes', 'route' => 'corex.presentations.outcomes.index', 'old' => 'Outcomes',
                'feature' => 'presentations', 'permissions' => ['access_presentations']],
            'refresh-requests' => ['label' => 'Refresh requests', 'route' => 'corex.presentations.refresh-requests.index', 'old' => 'Refresh Requests',
                'feature' => 'presentations', 'permissions' => ['access_presentations']],
            'commercial-evaluations' => ['label' => 'Commercial evaluations', 'route' => 'commercial-evaluations.index', 'old' => 'Commercial Evaluations',
                'feature' => 'commercial-evaluations', 'permissions' => ['access_commercial_evaluations']],
        ];
    }

    /** Open (pending + acknowledged) refresh requests for the user's agency - the count the old menu item carried. */
    public static function badge(string $key, User $user): ?int
    {
        if ($key !== 'refresh-requests') {
            return null;
        }
        try {
            $agencyId = $user->effectiveAgencyId();

            return $agencyId ? PresentationRefreshRequest::where('agency_id', $agencyId)->whereIn('status', ['pending', 'acknowledged'])->count() : 0;
        } catch (\Throwable $e) {
            return 0; // the menu must never blow up
        }
    }
}
