<?php

namespace App\Services\Documents;

use App\Models\SharedDrive;
use App\Models\SharedDriveFile;
use App\Models\SharedDriveFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared Drive — file/folder storage helpers.
 *
 * Centralises path building, type/size policy, duplicate-name checks and
 * recursive soft-deletion so the controller stays thin. All persistence goes
 * through the tenant-scoped models (BelongsToAgency), so agency isolation is
 * enforced structurally — this service never touches agency_id directly.
 */
class SharedDriveService
{
    /** Max upload size in kilobytes (50 MB). Used by request validation. */
    public const MAX_KILOBYTES = 51200;

    /** Allowed file extensions (lower-case, no dot). */
    public const ALLOWED_EXTENSIONS = [
        'pdf',
        'doc', 'docx',
        'xls', 'xlsx', 'csv',
        'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
    ];

    /** Allowed MIME types (defence-in-depth alongside the extension allow-list). */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain', // some browsers send csv as text/plain
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ];

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local');
    }

    /** Server-side check that an upload's extension AND mime are permitted. */
    public function isAllowed(UploadedFile $file): bool
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getClientMimeType());

        return in_array($ext, self::ALLOWED_EXTENSIONS, true)
            && in_array($mime, self::ALLOWED_MIMES, true);
    }

    /**
     * Find (or lazily create) the agency's default "General" drive. Every
     * agency has exactly one; it is never restricted and never deleted. Fresh
     * agencies with no pre-v2 data get theirs on first visit to the drive list.
     */
    public function ensureDefaultDrive(int $agencyId, int $userId): SharedDrive
    {
        $drive = SharedDrive::where('agency_id', $agencyId)
            ->where('is_default', true)
            ->first();

        if ($drive) {
            return $drive;
        }

        return SharedDrive::create([
            'agency_id'          => $agencyId,
            'name'               => 'General',
            'is_restricted'      => false,
            'is_default'         => true,
            'created_by_user_id' => $userId,
        ]);
    }

    /** Create a new (non-default) drive. */
    public function createDrive(string $name, bool $restricted, int $agencyId, int $userId): SharedDrive
    {
        return SharedDrive::create([
            'agency_id'          => $agencyId,
            'name'               => trim($name),
            'is_restricted'      => $restricted,
            'is_default'         => false,
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * Active, agency-scoped members eligible to be granted drive access. Used
     * both to populate the access-picker and to validate submitted user ids,
     * so the two can never diverge (no cross-agency id can be smuggled in).
     */
    public function agencyMemberQuery(int $agencyId)
    {
        return User::agencyMembers()
            ->where('is_active', 1)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)
                    ->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId));
            })
            ->orderBy('name');
    }

    /**
     * Replace a restricted drive's member list. Submitted ids are intersected
     * with real agency members, so a forged id is silently dropped. Open drives
     * carry no access rows (everyone sees them) — clear any that linger.
     */
    public function syncDriveAccess(SharedDrive $drive, array $userIds): void
    {
        if (!$drive->is_restricted) {
            $drive->accessUsers()->sync([]);
            return;
        }

        $valid = $this->agencyMemberQuery((int) $drive->agency_id)
            ->whereIn('users.id', array_map('intval', $userIds))
            ->pluck('users.id')
            ->all();

        $drive->accessUsers()->sync($valid);
    }

    /**
     * Soft-delete an entire drive: every folder and file carries the drive_id,
     * so we archive them directly (no recursion needed) then the drive itself.
     * Physical files are KEPT for admin recovery (non-negotiable #1).
     */
    public function deleteDrive(SharedDrive $drive): void
    {
        SharedDriveFile::where('drive_id', $drive->id)->get()->each->delete();
        SharedDriveFolder::where('drive_id', $drive->id)->get()->each->delete();
        $drive->accessUsers()->detach();
        $drive->delete();
    }

    /**
     * Store an uploaded file and create its DB record under the given drive and
     * folder (null folder = drive root). Returns the created model.
     */
    public function storeUpload(UploadedFile $file, SharedDrive $drive, ?SharedDriveFolder $folder, int $agencyId, int $userId): SharedDriveFile
    {
        $now = now();
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
        $ext = strtolower($file->getClientOriginalExtension());
        $dir = sprintf('shared_drive/%d/%s', $agencyId, $now->format('Y/m'));
        $filename = $slug . '-' . Str::random(8) . ($ext ? '.' . $ext : '');

        $storedPath = $file->storeAs($dir, $filename, 'local');

        return SharedDriveFile::create([
            'drive_id'            => $drive->id,
            'folder_id'           => $folder?->id,
            'original_name'       => $file->getClientOriginalName(),
            'stored_path'         => $storedPath,
            'mime_type'           => $file->getClientMimeType(),
            'extension'           => $ext,
            'bytes'               => $file->getSize(),
            'uploaded_by_user_id' => $userId,
        ]);
    }

    /**
     * True if a folder with this name already exists (case-insensitive) in the
     * same directory of the same drive. Trashed folders are ignored (the
     * model's soft-delete scope already excludes them).
     */
    public function folderNameTaken(string $name, int $driveId, ?int $parentId): bool
    {
        return SharedDriveFolder::query()
            ->where('drive_id', $driveId)
            ->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->exists();
    }

    /**
     * Recursively soft-delete a folder and all descendant folders + files.
     * Physical files on disk are KEPT so admins can recover via the Soft
     * Deletes Register (non-negotiable #1 — no hard deletes).
     */
    public function deleteFolderRecursive(SharedDriveFolder $folder): void
    {
        foreach ($folder->children()->get() as $child) {
            $this->deleteFolderRecursive($child);
        }

        $folder->files()->get()->each->delete();
        $folder->delete();
    }

    /** Absolute path on disk for a stored file (consistent with the disk used by storeUpload). */
    public function absolutePath(SharedDriveFile $file): string
    {
        return $this->disk()->path($file->stored_path);
    }
}
