<?php

namespace App\Services\Images;

use App\Jobs\RemoveAgentPhotoBackgroundJob;
use App\Models\Agency;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for an agent's profile photo. Every write goes through
 * here so the three records that represent a photo NEVER desync:
 *   1. the normalised file on the public disk (agents/{id}/photo.webp),
 *   2. the canonical user_documents 'profile_photo' row, and
 *   3. the legacy users.agent_photo_path column.
 *
 * Before this existed, admin uploads (UserManagementController) updated only the
 * column while agent self-service (AgentPortalController) updated the document —
 * so the two routinely pointed at different (often deleted) files, and
 * User::profilePhotoUrl() / the P24 sync resolved a missing image. That is the
 * "agent photos don't reach P24" bug. See agents:reconcile-photos for the
 * one-off repair of rows written before this service existed.
 */
class AgentProfilePhotoService
{
    public function __construct(private AgentPhotoNormalizer $normalizer) {}

    /**
     * Normalise + store the uploaded photo and bring BOTH records into lockstep
     * with the file actually written. Returns the public-disk relative path.
     *
     * Also dispatches the AI background-removal job (§15.2) exactly ONCE per
     * upload/replace — never per ad render, never per property. A fresh
     * upload's bg_removal_* fields are reset to null/'processing' here (not
     * left carrying the PREVIOUS photo's cutout state/error), since the
     * queued job hasn't run yet for this new file.
     */
    public function set(User $user, UploadedFile $file): string
    {
        $path = $this->normalizer->store($file, $user->id, $user->agent_photo_path);
        $size = Storage::disk('public')->size($path) ?: null;

        $attrs = [
            'file_path'   => $path,
            'file_name'   => basename($path),
            'file_size'   => $size,
            'mime_type'   => 'image/webp',
            'status'      => 'verified',
            'verified_at' => now(),
            'verified_by' => $user->id,
            // A new photo file — any cutout/status/error recorded belongs to
            // the PREVIOUS file and must not be shown against this one.
            'bg_removal_status'       => null,
            'bg_removal_cutout_path'  => null,
            'bg_removal_driver'       => null,
            'bg_removal_processed_at' => null,
            'bg_removal_error'        => null,
        ];

        $doc = $user->documents()
            ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->latest()
            ->first();

        if ($doc) {
            $doc->update($attrs);
        } else {
            UserDocument::create(array_merge($attrs, [
                'user_id'       => $user->id,
                'agency_id'     => $user->agency_id,
                'document_type' => UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO,
                'uploaded_by'   => $user->id,
            ]));
        }

        if ($user->agent_photo_path !== $path) {
            $user->update(['agent_photo_path' => $path]);
        }

        $this->dispatchBackgroundRemoval($user, $path);

        return $path;
    }

    /**
     * Queue the AI background-removal job (§15.2), unless the agency has
     * switched it off — checked here too (not just inside the job) so a
     * disabled agency never even enqueues a wasted API call.
     *
     * The file's content hash is captured HERE, at upload time, and carried
     * on the job — the stored path is always "agents/{id}/photo.webp" for a
     * given user (AgentPhotoNormalizer overwrites the same path in place), so
     * the path alone can never tell a still-in-flight job for an OLD photo
     * apart from a photo that has since been replaced at that same path. See
     * RemoveAgentPhotoBackgroundJob's docblock.
     */
    private function dispatchBackgroundRemoval(User $user, string $path): void
    {
        $agency = $user->agency_id ? Agency::withoutGlobalScopes()->find($user->agency_id) : null;
        if ($agency && ! $agency->ad_bg_removal_api_enabled) {
            return;
        }

        $hash = md5(Storage::disk('public')->get($path));
        RemoveAgentPhotoBackgroundJob::dispatch($user->id, $path, $hash);
    }

    /**
     * Remove the agent's profile photo across all three records together. The
     * document row is soft-deleted (no hard deletes — CLAUDE.md #1); the file is
     * removed since it is regenerated on the next upload.
     */
    public function clear(User $user): void
    {
        if ($user->agent_photo_path) {
            Storage::disk('public')->delete($user->agent_photo_path);
            Storage::disk('public')->delete($this->normalizer->photoJpegPath($user->id));
        }

        // §15.2 — the cutout is regenerated media, not a record; delete it
        // alongside the original rather than leaving it orphaned on disk.
        $cutoutPath = $user->documents()
            ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->latest()
            ->value('bg_removal_cutout_path');
        if ($cutoutPath) {
            Storage::disk('public')->delete($cutoutPath);
        }

        $user->documents()
            ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->get()
            ->each
            ->delete();

        if ($user->agent_photo_path !== null) {
            $user->update(['agent_photo_path' => null]);
        }
    }
}
