<?php

namespace App\Support\Navigation\Hubs;

use App\Models\PerformanceSetting;
use App\Models\User;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/rentals-menu-tabs.md - Rentals > Letting (was the "Letting" heading): Applications | Application Authorisation |
 * Core Matches | Rental Pipeline. Core Matches and the pipeline are the shared sales screens opened through their Rentals routes,
 * so the tabs match only the Rentals route names (the sales routes show the Real Estate > Buyers bar instead).
 */
final class LettingTabs extends TabHub
{
    public static function label(): string
    {
        return 'Letting';
    }

    public static function tabs(): array
    {
        return [
            'applications' => ['label' => 'Applications', 'route' => 'corex.rental-applications.index', 'old' => ['Applications', 'Rental Applications'],
                'feature' => 'rental-applications', 'permissions' => ['rental_applications.view']],
            'authorisation' => ['label' => 'Application Authorisation', 'route' => 'corex.rental-applications.authorisation.index', 'old' => ['Application Authorisation', 'Rental Application Authorisation'],
                'feature' => 'rental-applications', 'permissions' => ['rental_applications.authorise']],
            'core-matches' => ['label' => 'Core Matches', 'route' => 'corex.rentals.core-matches.index', 'match' => ['corex.rentals.core-matches.index', 'corex.rentals.core-matches.all'], 'old' => 'Core Matches',
                'feature' => 'core-matches', 'permissions' => ['core_matches.view']],
            'pipeline' => ['label' => 'Rental Pipeline', 'route' => 'corex.rentals.pipeline.index', 'old' => 'Rental Pipeline',
                'feature' => 'buyer-pipeline', 'permissions' => ['buyer_pipeline.view']],
        ];
    }

    protected static function allows(string $key, User $user): bool
    {
        return match ($key) {
            // the old item also asked whether this user sees the authorisation queue at all
            'authorisation' => $user->seesRentalAuthorisationQueue(),
            'core-matches' => (bool) PerformanceSetting::get('matches_enabled', 1),
            default => true,
        };
    }
}
