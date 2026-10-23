<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/real-estate-menu-tabs.md - Real Estate > Market intelligence: MIC's own tabs (Work .. Importer, unchanged) plus Deeds capture,
 * Address review and Stale claims, which were their own menu items. Deeds capture and Address review keep their own routes OUTSIDE MIC's
 * access_prospecting route group, so a user who may open only those still reaches them (they see only the tabs they may open).
 */
final class MarketIntelligenceTabs extends TabHub
{
    public static function label(): string
    {
        return 'Market intelligence';
    }

    public static function tabs(): array
    {
        $mic = ['feature' => 'prospecting', 'permissions' => ['access_prospecting']];

        return [
            'work' => ['label' => 'Work', 'route' => 'market-intelligence.work', 'old' => null] + $mic,
            'opportunities' => ['label' => 'Opportunities', 'route' => 'market-intelligence.opportunities', 'old' => null] + $mic,
            'analyse' => ['label' => 'Analyse', 'route' => 'market-intelligence.analyse', 'old' => null] + $mic,
            'market-pulse' => ['label' => 'Market Pulse', 'route' => 'market-intelligence.market-pulse', 'old' => null] + $mic,
            'portal-alerts' => ['label' => 'Portal Alerts', 'route' => 'market-intelligence.portal-alerts', 'old' => null] + $mic,
            'team' => ['label' => 'Team', 'route' => 'market-intelligence.team', 'old' => null,
                'feature' => 'prospecting', 'permissions' => ['access_prospecting', 'mic.view_team']],
            'importer' => ['label' => 'Importer', 'route' => 'market-intelligence.reports.bulk-import', 'match' => ['market-intelligence.reports.*'], 'old' => null,
                'feature' => 'prospecting', 'permissions' => ['access_prospecting', 'mic.upload_reports']],
            'deeds-capture' => ['label' => 'Deeds capture', 'route' => 'corex.deeds-capture.index', 'match' => ['corex.deeds-capture.index'], 'old' => 'Deeds Capture',
                'feature' => 'deeds-capture', 'permissions' => ['deeds_capture.access']],
            'address-review' => ['label' => 'Address review', 'route' => 'corex.address-review.index', 'match' => ['corex.address-review.index'], 'old' => 'Address Review',
                'feature' => 'address-review', 'permissions' => ['address_review.manage']],
            'stale-claims' => ['label' => 'Stale claims', 'route' => 'market-intelligence.stale-review', 'match' => ['market-intelligence.stale-review*'], 'old' => 'Stale claims review',
                'feature' => 'prospecting', 'permissions' => ['access_prospecting', 'prospecting_setup.manage']],
        ];
    }
}
