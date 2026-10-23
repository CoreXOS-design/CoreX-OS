<?php

namespace App\Support\Navigation\Hubs;

use App\Models\User;
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
        ];
    }

    protected static function allows(string $key, User $user): bool
    {
        return $key !== 'packages' || $user->isOwnerRole();
    }
}
