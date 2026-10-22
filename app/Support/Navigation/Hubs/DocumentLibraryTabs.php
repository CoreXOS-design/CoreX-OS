<?php

namespace App\Support\Navigation\Hubs;

use App\Support\Navigation\TabHub;

/**
 * .ai/specs/documents-menu-tabs.md - Documents > Library: Packs | Web Packs | Clause Library | Compile Studio.
 */
final class DocumentLibraryTabs extends TabHub
{
    public static function label(): string
    {
        return 'Library';
    }

    public static function tabs(): array
    {
        $f = 'docuperfect';

        return [
            'packs' => ['label' => 'Packs', 'route' => 'docuperfect.packs.index', 'old' => 'Packs',
                'feature' => $f, 'permissions' => ['access_docuperfect_packs', 'access_docuperfect']],
            'web-packs' => ['label' => 'Web Packs', 'route' => 'docuperfect.web-packs.index', 'old' => 'Web Packs',
                'feature' => $f, 'permissions' => ['access_docuperfect_packs', 'access_docuperfect']],
            'clauses' => ['label' => 'Clause Library', 'route' => 'docuperfect.clauses.index', 'old' => 'Clause Library',
                'feature' => $f, 'permissions' => ['access_clause_library', 'access_docuperfect']],
            'compiler' => ['label' => 'Compile Studio', 'route' => 'docuperfect.compiler.index', 'old' => 'Compile Studio',
                'feature' => $f, 'permissions' => ['esign.compiler.view']],
        ];
    }
}
