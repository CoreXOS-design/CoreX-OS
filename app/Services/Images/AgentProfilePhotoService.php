<?php

namespace App\Services\Images;

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

        return $path;
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
