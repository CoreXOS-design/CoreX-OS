<?php

namespace App\Console\Commands;

use App\Models\Scopes\AgencyScope;
use App\Models\UserDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off migration for the UserDocument public-disk exposure fix.
 *
 * Moves every sensitive UserDocument file (everything except 'profile_photo',
 * which is intentionally public — see AgentProfilePhotoService docblock and
 * User::profilePhotoUrl()) from the 'public' disk to the private 'local' disk,
 * at the same relative path. There is no 'disk' column on user_documents, so
 * UserDocumentDownloadController resolves this transparently: it checks
 * 'local' first, falling back to 'public' for any row this command could not
 * safely migrate.
 *
 * Safety: copies to 'local', verifies the copy (existence + byte-for-byte
 * size match) BEFORE deleting the 'public' original. A row is left untouched
 * on any failure — never data loss, only a slower cutover. Idempotent: rows
 * already present on 'local' are skipped and counted separately.
 */
class MigrateUserDocumentsToPrivateDisk extends Command
{
    protected $signature = 'user-documents:migrate-to-private-disk
        {--dry-run : Show what would change without writing or deleting anything}';

    protected $description = 'Copy sensitive UserDocument files from the public disk to the private local disk, verify, then delete the public originals';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $documents = UserDocument::withoutGlobalScope(AgencyScope::class)
            ->withTrashed()
            ->where('document_type', '!=', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->orderBy('id')
            ->get();

        $public = Storage::disk('public');
        $local = Storage::disk('local');

        $migrated = 0;
        $alreadyLocal = 0;
        $missingBoth = 0;
        $failed = [];

        foreach ($documents as $doc) {
            $path = $doc->file_path;

            if (! $path) {
                $missingBoth++;
                $this->warn("#{$doc->id} ({$doc->document_type}): no file_path on row — skipped.");
                continue;
            }

            if ($local->exists($path)) {
                $alreadyLocal++;
                continue;
            }

            if (! $public->exists($path)) {
                $missingBoth++;
                $this->warn("#{$doc->id} ({$doc->document_type}): file missing on BOTH disks at '{$path}' — skipped, needs re-upload.");
                continue;
            }

            $expectedSize = $public->size($path);

            if ($dry) {
                $this->line("[DRY RUN] would migrate #{$doc->id} ({$doc->document_type}): {$path} ({$expectedSize} bytes)");
                $migrated++;
                continue;
            }

            try {
                $stream = $public->readStream($path);
                if ($stream === false || $stream === null) {
                    throw new \RuntimeException('could not open read stream on public disk');
                }
                $local->put($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (\Throwable $e) {
                $failed[] = "#{$doc->id} ({$doc->document_type}) {$path}: copy failed — {$e->getMessage()}";
                continue;
            }

            // Verify before touching the original: file must exist on 'local' with the
            // exact same byte size as the 'public' source.
            if (! $local->exists($path) || $local->size($path) !== $expectedSize) {
                $failed[] = "#{$doc->id} ({$doc->document_type}) {$path}: verification failed (local size "
                    . ($local->exists($path) ? $local->size($path) : 'MISSING') . " vs expected {$expectedSize}) — public original left untouched.";
                continue;
            }

            $public->delete($path);
            $migrated++;
            $this->line("Migrated #{$doc->id} ({$doc->document_type}): {$path} ({$expectedSize} bytes)");
        }

        $this->newLine();
        $prefix = $dry ? '[DRY RUN] would migrate: ' : 'migrated: ';
        $this->info('UserDocument public -> local migration complete.');
        $this->line("  {$prefix}{$migrated}");
        $this->line("  already on local: {$alreadyLocal}");
        $this->line("  missing on both disks (skipped): {$missingBoth}");
        $this->line('  failed (public original left untouched): ' . count($failed));

        foreach ($failed as $line) {
            $this->error('  ' . $line);
        }

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing written or deleted. Re-run without --dry-run to apply.');
        }

        return empty($failed) ? self::SUCCESS : self::FAILURE;
    }
}
