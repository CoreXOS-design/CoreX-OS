<?php

namespace App\Services\Documents;

use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;

/**
 * The ONE answer to "may this user open this document file?".
 *
 * Agency isolation is already structural: Document carries BelongsToAgency, so route-model binding
 * 404s another agency's document before this runs. This adds the within-agency rules, own / branch /
 * agency, resolved through the same scopes the screens use (Property::visibleTo, ContactScope,
 * DealV2::visibleTo, RentalApplication::visibleTo) — never a parallel implementation.
 *
 * Spec: .ai/specs/private-document-storage.md §3.
 */
class DocumentAccessService
{
    /** Permissions that unlock identity / FICA documents (any one is enough). */
    private const IDENTITY_PERMISSIONS = ['fica.view', 'compliance.view'];

    /** 403 unless the user may open the document. */
    public function authorise(?User $user, Document $document): void
    {
        abort_unless($user !== null && $this->allows($user, $document), 403);
    }

    public function allows(User $user, Document $document): bool
    {
        // A template's source Word file belongs to the template, not to a property / contact / deal:
        // only someone who may manage templates AND may open that template. Checked first so an
        // agency-wide `documents` scope on its own never opens it.
        if ($document->source_type === \App\Services\Docuperfect\CdsReimportService::SOURCE_TYPE) {
            return $this->allowsTemplateSource($user, $document);
        }

        if ($this->isIdentityDocument($document) && ! $this->hasAnyPermission($user, self::IDENTITY_PERMISSIONS)) {
            return false;
        }

        // OWN
        if ($document->uploaded_by !== null && (int) $document->uploaded_by === (int) $user->id) {
            return true;
        }

        // AGENCY-wide documents scope
        if (PermissionService::getDataScope($user, 'documents') === 'all') {
            return true;
        }

        // OWN / BRANCH through whatever the document is filed against.
        if ($document->properties()->getQuery()->exists()
            && Property::query()
                ->visibleTo($user)
                ->whereIn('properties.id', $document->properties()->select('properties.id'))
                ->exists()) {
            return true;
        }

        // Contacts: the global ContactScope already narrows this relation to what the user may see.
        if ($document->contacts()->exists()) {
            return true;
        }

        if ($document->deal_id
            && DealV2::query()->visibleTo($user)->whereKey($document->deal_id)->exists()) {
            return true;
        }

        if ($document->rentalApplications()->getQuery()->exists()
            && \App\Models\RentalApplication::query()
                ->visibleTo($user)
                ->whereIn('rental_applications.id', $document->rentalApplications()->select('rental_applications.id'))
                ->exists()) {
            return true;
        }

        return false;
    }

    private function allowsTemplateSource(User $user, Document $document): bool
    {
        if (! $user->hasPermission('manage_templates') || $document->source_id === null) {
            return false;
        }
        $template = \App\Models\Docuperfect\Template::query()->find($document->source_id);
        if (! $template) {
            return false;
        }
        try {
            $template->assertAccessibleBy($user);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return false;
        }

        return true;
    }

    /** FICA / identity paperwork needs the compliance permission on top of record access. */
    private function isIdentityDocument(Document $document): bool
    {
        if ($document->source_type === 'fica') {
            return true;
        }

        return $document->documentType !== null && ! empty($document->documentType->fica_slot);
    }

    /** @param string[] $keys */
    private function hasAnyPermission(User $user, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($user->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }
}
