<?php

namespace App\Console\Commands;

use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reconcile each agent's profile-photo RECORDS with the file actually on disk.
 *
 * Root cause this repairs: the photo records and the stored file desync. The
 * normalizer writes agents/{id}/photo.webp, but historically:
 *   - the 2026_04_22 backfill seeded user_documents.file_path from the then-current
 *     agent_photo_path (a .jpg/.jpeg), and
 *   - admin-side uploads (UserManagementController) update only agent_photo_path,
 *     never the user_documents row,
 * so both can keep pointing at a long-deleted .jpg while the real file is .webp.
 * Because User::profilePhotoUrl() (and now the P24 sync) prefer user_documents,
 * the photo then resolves to a missing file and shows nowhere — including P24.
 *
 * For every user with a real file under agents/{id}/ this command repoints BOTH
 * the user_documents 'profile_photo' row AND the legacy agent_photo_path column
 * at that file. Idempotent; --dry-run previews without writing.
 */
class AgentsReconcilePhotos extends Command
{
    protected $signature = 'agents:reconcile-photos
        {--agency=0 : Limit to one CoreX agency ID (0 = all)}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Repoint agent profile-photo records (user_documents + agent_photo_path) at the file actually on disk';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $agencyOpt = (int) $this->option('agency');

        $query = User::withoutGlobalScope(AgencyScope::class)->orderBy('id');
        if ($agencyOpt > 0) {
            $query->where('agency_id', $agencyOpt);
        }
        $users = $query->get();

        $disk = Storage::disk('public');
        $fixedDoc = 0;
        $fixedCol = 0;
        $created = 0;
        $noFile = 0;
        $alreadyOk = 0;

        foreach ($users as $user) {
            $real = $this->resolveRealFile($disk, $user->id);
            if ($real === null) {
                $noFile++;
                continue;
            }

            $mime = $disk->mimeType($real) ?: 'image/webp';
            $size = $disk->size($real) ?: null;
            $changes = [];

            // 1. Legacy column.
            if ($user->agent_photo_path !== $real) {
                $changes[] = "col: " . ($user->agent_photo_path ?: 'NULL') . " -> {$real}";
                if (!$dry) {
                    $user->agent_photo_path = $real;
                    $user->save();
                }
                $fixedCol++;
            }

            // 2. Canonical user_documents profile_photo row.
            $doc = $user->documents()
                ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
                ->latest()
                ->first();

            if ($doc) {
                if ($doc->file_path !== $real) {
                    $changes[] = "doc: " . ($doc->file_path ?: 'NULL') . " -> {$real}";
                    if (!$dry) {
                        $doc->update([
                            'file_path' => $real,
                            'file_name' => basename($real),
                            'mime_type' => $mime,
                            'file_size' => $size,
                        ]);
                    }
                    $fixedDoc++;
                }
            } else {
                $changes[] = "doc: (none) -> create {$real}";
                if (!$dry) {
                    UserDocument::create([
                        'user_id'       => $user->id,
                        'agency_id'     => $user->agency_id,
                        'document_type' => UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO,
                        'file_path'     => $real,
                        'file_name'     => basename($real),
                        'file_size'     => $size,
                        'mime_type'     => $mime,
                        'status'        => 'verified',
                        'verified_at'   => now(),
                        'verified_by'   => $user->id,
                        'uploaded_by'   => $user->id,
                    ]);
                }
                $created++;
            }

            if (empty($changes)) {
                $alreadyOk++;
            } else {
                $this->line("#{$user->id} {$user->name}: " . implode(' | ', $changes));
            }
        }

        $this->newLine();
        $prefix = $dry ? '[DRY RUN] would ' : '';
        $this->info("Reconcile complete ({$users->count()} users).");
        $this->line("  {$prefix}repoint documents: {$fixedDoc}");
        $this->line("  {$prefix}repoint columns:   {$fixedCol}");
        $this->line("  {$prefix}create documents:  {$created}");
        $this->line("  already correct:    {$alreadyOk}");
        $this->line("  no file on disk (need re-upload): <fg=yellow>{$noFile}</>");

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Find the real photo file for a user under agents/{id}/. Prefers the
     * normalizer's canonical photo.webp; otherwise the first image file present.
     */
    private function resolveRealFile($disk, int $userId): ?string
    {
        $preferred = "agents/{$userId}/photo.webp";
        if ($disk->exists($preferred)) {
            return $preferred;
        }
        foreach ($disk->files("agents/{$userId}") as $f) {
            if (preg_match('/\.(webp|jpe?g|png)$/i', $f)) {
                return $f;
            }
        }
        return null;
    }
}
