<?php

namespace App\Support\Navigation\Hubs;

use App\Models\Docuperfect\TemplateRelease;
use App\Models\User;
use App\Services\Docuperfect\Release\AgencyDocumentAuthorisers;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/documents-menu-tabs.md - Documents > Templates: Template Management | Field Groups | Named Fields | Document Types |
 * Import Document | Template Packages. Template Packages stays System-Owner-only, exactly as its old menu item was.
 */
final class DocumentTemplatesTabs extends TabHub
{
    public static function label(): string
    {
        return 'Templates';
    }

    public static function tabs(): array
    {
        $f = 'docuperfect';
        $manage = ['manage_templates', 'access_docuperfect'];

        return [
            'templates' => ['label' => 'Template Management', 'route' => 'docuperfect.templates.index', 'old' => 'Template Management',
                'feature' => $f, 'permissions' => $manage],
            'field-groups' => ['label' => 'Field Groups', 'route' => 'docuperfect.field-groups.index', 'old' => 'Field Groups',
                'feature' => $f, 'permissions' => $manage],
            'named-fields' => ['label' => 'Named Fields', 'route' => 'docuperfect.settings.namedFields', 'old' => 'Named Fields',
                'feature' => $f, 'permissions' => $manage],
            'types' => ['label' => 'Document Types', 'route' => 'docuperfect.settings.types', 'old' => 'Document Types',
                'feature' => $f, 'permissions' => $manage],
            'import' => ['label' => 'Import Document', 'route' => 'docuperfect.import.index', 'old' => 'Import Document',
                'feature' => $f, 'permissions' => $manage],
            'packages' => ['label' => 'Template Packages', 'route' => 'docuperfect.template-transfer.index', 'old' => 'Template Packages',
                'feature' => $f, 'permissions' => ['templates.transfer']],
            // E-sign document workshop (.ai/specs/esign-template-transfer.md §13.4).
            'releases' => ['label' => 'Releases', 'route' => 'docuperfect.template-releases.index', 'old' => 'Releases', 'match' => ['docuperfect.template-releases.*'],
                'feature' => $f, 'permissions' => ['templates.release']],
            'approvals' => ['label' => 'Approvals', 'route' => 'docuperfect.template-approvals.index', 'old' => 'Approvals', 'match' => ['docuperfect.template-approvals.*'],
                'feature' => $f, 'permissions' => ['templates.approve_release']],
        ];
    }

    protected static function allows(string $key, User $user): bool
    {
        return match ($key) {
            'packages', 'releases' => $user->isOwnerRole(),
            // The agency's authorisers, and the owner (who sees it read-only).
            'approvals' => $user->isOwnerRole() || AgencyDocumentAuthorisers::canAuthorise($user, (int) $user->effectiveAgencyId()),
            default => true,
        };
    }

    /** Documents waiting for approval at the viewer's agency (authorisers only). */
    public static function badge(string $key, User $user): ?int
    {
        if ($key !== 'approvals' || $user->isOwnerRole()) {
            return null;
        }
        $n = TemplateRelease::query()->where('agency_id', (int) $user->effectiveAgencyId())->where('state', TemplateRelease::STATE_AWAITING)->count();

        return $n > 0 ? $n : null;
    }
}
