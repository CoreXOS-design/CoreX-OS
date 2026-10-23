<?php

namespace App\Support\Navigation\Hubs;

use App\Models\User;
use App\Models\UserDocument;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/compliance-menu-tabs.md - Compliance > Agents & Staff: Verification Queue | Agent Compliance | Staff Screening |
 * Document Types | Agency Documents. Every tab also carries the Compliance panel's own gate. The counts that sat on the old items move
 * onto their tabs: the pending-verification count (a number) and the Agent Compliance dot.
 */
final class ComplianceAgentsTabs extends TabHub
{
    public static function label(): string
    {
        return 'Agents & Staff';
    }

    public static function tabs(): array
    {
        $f = 'compliance';

        return [
            'verification' => ['label' => 'Verification Queue', 'route' => 'compliance.verification.index', 'old' => 'Verification Queue',
                'feature' => $f, 'permissions' => ['access_compliance', 'verify_user_documents']],
            // System Owner (or someone viewing as super_admin) only - copied from the old item
            'agents' => ['label' => 'Agent Compliance', 'route' => 'compliance.agents', 'old' => 'Agent Compliance',
                'feature' => $f, 'permissions' => ['access_compliance']],
            'screening' => ['label' => 'Staff Screening', 'route' => 'compliance.screening.dashboard.index', 'old' => 'Staff Screening',
                'feature' => $f, 'permissions' => ['access_compliance', 'manage_employee_screenings', 'access_compliance_dashboard']],
            'document-types' => ['label' => 'Document Types', 'route' => 'compliance.document-types.index', 'old' => 'Document Types',
                'feature' => $f, 'permissions' => ['access_compliance', 'manage_agency_compliance']],
            'agency-documents' => ['label' => 'Agency Documents', 'route' => 'compliance.agency-settings.index', 'old' => 'Agency Documents',
                'feature' => $f, 'permissions' => ['access_compliance', 'manage_agency_compliance']],
        ];
    }

    protected static function allows(string $key, User $user): bool
    {
        return $key !== 'agents' || $user->isOwnerRole() || $user->effectiveRole() === 'super_admin';
    }

    public static function badge(string $key, User $user): ?int
    {
        return match ($key) {
            'verification' => UserDocument::pending()->count(),
            // the old item's dot: active users with no FFC number (counted as it always was)
            'agents' => User::where('is_active', true)->whereNull('deleted_at')->whereNull('ffc_number')->count(),
            default => null,
        };
    }

    public static function dotOnly(string $key): bool
    {
        return $key === 'agents';
    }
}
