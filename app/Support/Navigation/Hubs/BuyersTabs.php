<?php

namespace App\Support\Navigation\Hubs;

use App\Models\PerformanceSetting;
use App\Models\User;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/real-estate-menu-tabs.md - Real Estate > Buyers: Buyer pipeline | Core Matches | Portal leads | Viewing packs. The Rentals
 * twins of the pipeline and Core Matches have their own route names, so they show no Real Estate tab bar.
 */
final class BuyersTabs extends TabHub
{
    public static function label(): string
    {
        return 'Buyers';
    }

    public static function tabs(): array
    {
        return [
            // The sales pipeline route checks no permission today, only the feature (spec §7, reported) - copied as-is.
            'pipeline' => ['label' => 'Buyer pipeline', 'route' => 'command-center.buyers.pipeline', 'old' => 'Buyer Pipeline',
                'feature' => 'buyer-pipeline', 'permissions' => []],
            'core-matches' => ['label' => 'Core Matches', 'route' => 'corex.core-matches.index', 'match' => ['corex.core-matches.index', 'corex.core-matches.all'], 'old' => 'Core Matches',
                'feature' => 'core-matches', 'permissions' => ['access_core_matches', 'access_contacts']],
            'portal-leads' => ['label' => 'Portal leads', 'route' => 'corex.portal-leads.index', 'old' => 'Portal Leads',
                'feature' => 'portal-leads', 'permissions' => ['access_portal_leads']],
            'viewing-packs' => ['label' => 'Viewing packs', 'route' => 'corex.viewing-packs.index', 'old' => 'Viewing Packs',
                'feature' => 'viewing-packs', 'permissions' => ['access_viewing_packs']],
        ];
    }

    protected static function allows(string $key, User $user): bool
    {
        return $key !== 'core-matches' || (bool) PerformanceSetting::get('matches_enabled', 1);
    }
}
