<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/real-estate-menu-tabs.md - Real Estate > Outreach: Activity Feed | Consent Funnel | Queue. Activity and Consent are the two tabs the
 * Outreach & Canvassing screen already had (?tab=activity|consent); Consent is the WhatsApp Outreach board in full, so its old route
 * (corex.outreach-summary.index, still reachable by tour and URL) lights the same tab.
 */
final class OutreachTabs extends TabHub
{
    public static function label(): string
    {
        return 'Outreach';
    }

    public static function tabs(): array
    {
        return [
            'activity' => ['label' => 'Activity Feed', 'route' => 'corex.outreach-canvassing.index', 'query' => ['tab' => 'activity'], 'old' => 'Outreach & Canvassing',
                'match' => ['corex.outreach-canvassing.index'], 'feature' => 'outreach', 'permissions' => ['outreach.summary.view']],
            'consent' => ['label' => 'Consent Funnel', 'route' => 'corex.outreach-canvassing.index', 'query' => ['tab' => 'consent'], 'old' => 'WhatsApp Outreach',
                'match' => ['corex.outreach-canvassing.index', 'corex.outreach-summary.index'], 'feature' => 'outreach', 'permissions' => ['outreach.summary.view']],
            'queue' => ['label' => 'Queue', 'route' => 'corex.outreach-queue.index', 'old' => 'Outreach Queue',
                'feature' => 'outreach', 'permissions' => ['outreach_queue.view']],
        ];
    }

    protected static function activeAlso(string $key): bool
    {
        if (! request()->routeIs('corex.outreach-canvassing.index')) {
            return true;
        }

        return $key === (request()->query('tab') === 'consent' ? 'consent' : 'activity');
    }
}
