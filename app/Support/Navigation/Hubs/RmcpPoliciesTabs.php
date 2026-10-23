<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/compliance-menu-tabs.md - Compliance > RMCP & Policies: RMCP | RMCP Dashboard | Policies | Policy Register. Every tab also
 * carries the Compliance panel's own gate (@permission('access_compliance') + @feature('compliance')).
 */
final class RmcpPoliciesTabs extends TabHub
{
    public static function label(): string
    {
        return 'RMCP & Policies';
    }

    public static function tabs(): array
    {
        $f = 'compliance';

        return [
            'rmcp' => ['label' => 'RMCP', 'route' => 'compliance.rmcp.index', 'old' => 'RMCP',
                'feature' => $f, 'permissions' => ['access_compliance', 'access_rmcp']],
            'rmcp-dashboard' => ['label' => 'RMCP Dashboard', 'route' => 'compliance.rmcp.dashboard.index', 'old' => 'RMCP Dashboard',
                'feature' => $f, 'permissions' => ['access_compliance', 'access_compliance_dashboard']],
            'policies' => ['label' => 'Policies', 'route' => 'compliance.policy.index', 'old' => 'Policies',
                'feature' => $f, 'permissions' => ['access_compliance', 'access_policy']],
            'policy-register' => ['label' => 'Policy Register', 'route' => 'compliance.policy.dashboard.index', 'old' => 'Policy Register',
                'feature' => $f, 'permissions' => ['access_compliance', 'access_compliance_dashboard']],
        ];
    }
}
