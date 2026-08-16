<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Gated read path for UserDocument files (agent identity/compliance docs — ID
 * copies, FFC certificates, tax clearance, police clearance, etc.). These are
 * sensitive PII and must never be served as a static asset('storage/...') URL.
 *
 * Access is limited to the document's own owner, or an admin/compliance-type
 * user (manage_users / manage_user_compliance / verify_user_documents) whose
 * effectiveAgencyId() matches the document owner's agency — mirrors the
 * multi-tenant gate in AgencyDocumentsViewerController::download().
 */
class UserDocumentDownloadController extends Controller
{
    public function download(UserDocument $document, Request $request)
    {
        $viewer = $request->user();

        $this->authorizeAccess($viewer, (int) $document->user_id, $document->agency_id);

        return $this->streamPath($document->file_path, $document->file_name);
    }

    /**
     * Legacy fallback for users.ffc_certificate_path — a column written/deleted
     * directly by UserManagementController alongside (but not always in lockstep
     * with) a user_documents 'ffc_certificate' row. Prefer the linked
     * UserDocument row when one exists (single gated code path); fall back to
     * the raw column for rows where it doesn't.
     */
    public function downloadFfcCertificate(User $user, Request $request)
    {
        $viewer = $request->user();

        $this->authorizeAccess($viewer, $user->id, $user->agency_id);

        $document = UserDocument::where('user_id', $user->id)
            ->where('document_type', UserDocument::DOCUMENT_TYPE_FFC_CERTIFICATE)
            ->latest('created_at')
            ->first();

        $path = $document->file_path ?? $user->ffc_certificate_path;
        $name = $document->file_name ?? ($path ? basename($path) : null);

        return $this->streamPath($path, $name);
    }

    private function authorizeAccess(?User $viewer, int $ownerUserId, ?int $ownerAgencyId): void
    {
        abort_unless($viewer, 403);

        if ($viewer->id === $ownerUserId) {
            return;
        }

        $hasQualifyingPermission = $viewer->hasPermission('manage_users')
            || $viewer->hasPermission('manage_user_compliance')
            || $viewer->hasPermission('verify_user_documents');

        $sameAgency = $ownerAgencyId !== null && $viewer->effectiveAgencyId() === $ownerAgencyId;

        abort_unless($hasQualifyingPermission && $sameAgency, 403);
    }

    private function streamPath(?string $path, ?string $downloadName)
    {
        abort_unless($path, 404, 'No file attached to this document.');

        // New uploads are written to the private 'local' disk (see
        // AgentPortalController, UserDocumentAdminController, UserManagementController).
        // Older rows may still point at a file on the 'public' disk from before that
        // change — there is no 'disk' column on user_documents to record which disk a
        // row used, so probe 'local' first and fall back to 'public' for legacy files
        // (mirrors AgencyDocumentsViewerController::download()).
        $disk = Storage::disk('local')->exists($path) ? 'local' : 'public';

        abort_unless(Storage::disk($disk)->exists($path), 404, 'Document file is missing from storage.');

        return Storage::disk($disk)->download($path, $downloadName);
    }
}
