<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Docuperfect\Document;
use App\Models\User;
use App\Services\PermissionService;

/**
 * Per-record data-scope authorization for DocuPerfect documents — the single-record sibling of
 * Document::scopeVisibleTo().
 *
 * AT-267 H5 (audit 2026-07-21): the document mutators (DocumentController, SalesDocumentController,
 * AmendmentController) bound a document/amendment/send by id and authorized on the permission KEY
 * OR a bare owner_id === $user->id check — neither runs a scoped per-record guard, and the related
 * models (DocumentAmendment, SalesDocumentSend) have no global scope. So a documents.edit holder
 * could edit/rename/archive/destroy/approve ANY agent's document by id, and an assistant was not
 * pinned to the assigned agent's OWN documents.
 *
 * A write path pins an assistant to the assigned agent's OWN documents (mutationScope clamps
 * assistants to 'own', keyed on owner_id ∈ dataIdentityIds); a pure read views at the agent's
 * breadth. Mirrors Document::scopeVisibleTo() EXACTLY so LIST and OPEN can never disagree.
 */
trait AuthorizesDocumentAccess
{
    protected function guardDocument(Document $document, bool $forEdit = true): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $scope = $forEdit
            ? PermissionService::mutationScope($user, 'documents')
            : PermissionService::getDataScope($user, 'documents');

        if ($scope === 'all') {
            return;
        }
        if ($scope === 'branch' && (int) $document->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }
        if ($scope === 'own' && in_array((int) $document->owner_id, $user->dataIdentityIds(), true)) {
            return;
        }

        abort(403);
    }
}
