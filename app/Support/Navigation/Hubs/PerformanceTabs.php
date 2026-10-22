<?php

namespace App\Support\Navigation\Hubs;

use App\Models\User;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/dashboard-menu-tabs.md - Dashboard > Performance: Performance | My Performance | Branch Report | Agency Report | Oversight |
 * Lost Deals. All six sat inside the sidebar's @feature('performance-dashboards') block; the role checks on Agency Report and Lost Deals
 * are copied from those menu items as they were.
 */
final class PerformanceTabs extends TabHub
{
    private const AGENCY_ROLES = ['admin', 'super_admin', 'owner'];

    public static function label(): string
    {
        return 'Performance';
    }

    public static function tabs(): array
    {
        $f = 'performance-dashboards';

        return [
            'performance' => ['label' => 'Performance', 'route' => 'command-center.performance', 'match' => ['command-center.performance*'], 'old' => null,
                'feature' => $f, 'permissions' => []],
            'my-performance' => ['label' => 'My Performance', 'route' => 'command-center.reporting.agent', 'old' => 'My Performance',
                'feature' => $f, 'permissions' => []],
            'branch-report' => ['label' => 'Branch Report', 'route' => 'command-center.reporting.branch', 'old' => 'Branch Report',
                'feature' => $f, 'permissions' => ['dashboard.oversight.view']],
            'agency-report' => ['label' => 'Agency Report', 'route' => 'command-center.reporting.agency', 'old' => 'Agency Report',
                'feature' => $f, 'permissions' => []],
            'oversight' => ['label' => 'Oversight', 'route' => 'corex.dashboard.oversight', 'old' => 'Oversight',
                'feature' => $f, 'permissions' => ['dashboard.oversight.view']],
            'lost-deals' => ['label' => 'Lost Deals', 'route' => 'command-center.lost-deals', 'old' => 'Lost Deals',
                'feature' => $f, 'permissions' => ['command_center.settings']],
        ];
    }

    protected static function allows(string $key, User $user): bool
    {
        return ! in_array($key, ['agency-report', 'lost-deals'], true) || in_array($user->role, self::AGENCY_ROLES, true);
    }
}
