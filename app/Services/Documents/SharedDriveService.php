<?php

namespace App\Services\Documents;

use App\Models\SharedDriveFile;
use App\Models\SharedDriveFolder;
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
     * Store an uploaded file and create its DB record under the given folder
     * (null folder = drive root). Returns the created model.
     */
    public function storeUpload(UploadedFile $file, ?SharedDriveFolder $folder, int $agencyId, int $userId): SharedDriveFile
    {
        $now = now();
        $slug = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
        $ext = strtolower($file->getClientOriginalExtension());
        $dir = sprintf('shared_drive/%d/%s', $agencyId, $now->format('Y/m'));
        $filename = $slug . '-' . Str::random(8) . ($ext ? '.' . $ext : '');

        $storedPath = $file->storeAs($dir, $filename, 'local');

        return SharedDriveFile::create([
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
     * same directory. Trashed folders are ignored (the model's soft-delete
     * scope already excludes them).
     */
    public function folderNameTaken(string $name, ?int $parentId): bool
    {
        return SharedDriveFolder::query()
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
