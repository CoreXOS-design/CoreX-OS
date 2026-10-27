<?php

namespace App\Support\Navigation\Hubs;

use App\Models\Compliance\WhistleblowComplaint;
use App\Models\User;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/compliance-menu-tabs.md - Compliance > Reports & Logs: Compliance Reporting | Communications Log. Every tab also carries the
 * Compliance panel's own gate. The pending-approval count that sat on Compliance Reporting moves onto its tab (and the menu item).
 */
final class ComplianceReportsTabs extends TabHub
{
    public static function label(): string
    {
        return 'Reports & Logs';
    }

    public static function tabs(): array
    {
        $f = 'compliance';

        return [
            'reporting' => ['label' => 'Compliance Reporting', 'route' => 'compliance.whistleblow.index', 'old' => 'Compliance Reporting',
                'feature' => $f, 'permissions' => ['access_compliance', 'compliance.whistleblow.view']],
            'communications-log' => ['label' => 'Communications Log', 'route' => 'compliance.communications.index', 'old' => 'Communications Log',
                'feature' => $f, 'permissions' => ['access_compliance', 'access_communication_archive']],
        ];
    }

    /** Pending-approval complaints - the whole agency's for someone who may see them all, else only the ones this user reported (as before). */
    public static function badge(string $key, User $user): ?int
    {
        if ($key !== 'reporting') {
            return null;
        }
        $q = WhistleblowComplaint::where('status', 'pending_approval');
        if (! $user->hasPermission('compliance.whistleblow.view_all_agency')) {
            $q->where('reported_by_user_id', $user->id);
        }

        return $q->count();
    }
}
