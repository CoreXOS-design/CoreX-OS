<?php

namespace App\Support\Navigation\Hubs;

use App\Models\User;
use App\Services\CandidatePractitionerService;
use App\Support\Navigation\TabHub;

/**
 * .ai/specs/documents-menu-tabs.md - Documents > My Documents: My Documents | My E-Sign Documents | Authorise Documents |
 * Agency Documents | Shared Drive. "Authorise Documents" is the My E-Sign Documents screen with ?filter=authorisation (as its old menu
 * item was), so the two tabs share a route and the filter decides which one is lit. The whole Documents panel sits inside
 * @feature('docuperfect'); Shared Drive also has its own switch.
 */
final class MyDocumentsTabs extends TabHub
{
    public static function label(): string
    {
        return 'My Documents';
    }

    public static function tabs(): array
    {
        $f = 'docuperfect';

        return [
            'documents' => ['label' => 'My Documents', 'route' => 'docuperfect.dashboard', 'old' => null,
                'feature' => $f, 'permissions' => ['access_docuperfect']],
            'esign' => ['label' => 'My E-Sign Documents', 'route' => 'docuperfect.esign.myDocuments', 'old' => 'My E-Sign Documents',
                'feature' => $f, 'permissions' => ['create_docuperfect_docs', 'access_docuperfect']],
            'authorise' => ['label' => 'Authorise Documents', 'route' => 'docuperfect.esign.myDocuments', 'query' => ['filter' => 'authorisation'], 'old' => 'Authorise Documents',
                'feature' => $f, 'permissions' => ['create_docuperfect_docs', 'access_docuperfect']],
            'agency-documents' => ['label' => 'Agency Documents', 'route' => 'my-portal.agency-documents', 'old' => 'Agency Documents',
                'feature' => $f, 'permissions' => ['view_agency_documents']],
            'shared-drive' => ['label' => 'Shared Drive', 'route' => 'documents.shared-drive.index', 'old' => 'Shared Drive',
                'feature' => 'shared-drive', 'permissions' => ['access_shared_drive']],
        ];
    }

    protected static function allows(string $key, User $user): bool
    {
        return match ($key) {
            // the old item also asked whether this user may authorise a candidate practitioner's documents
            'authorise' => app(CandidatePractitionerService::class)->canAuthorise($user),
            // Shared Drive sat inside the panel's @feature('docuperfect') as well as its own @feature('shared-drive')
            'shared-drive' => $user->hasFeature('docuperfect'),
            default => true,
        };
    }

    protected static function activeAlso(string $key): bool
    {
        return match ($key) {
            'esign' => request()->query('filter') !== 'authorisation',
            'authorise' => request()->query('filter') === 'authorisation',
            default => true,
        };
    }
}
