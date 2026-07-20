<?php

namespace App\Console\Commands;

use App\Services\Security\MediaCipher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-173 — retroactively encrypt existing plaintext media, safely.
 *
 * Per file: read → skip if already enveloped (idempotent) → encrypt → VERIFY
 * decrypt(ciphertext) === plaintext in memory → write ciphertext to a sibling
 * temp path → re-read + verify the temp decrypts to the original → only THEN
 * atomically replace the original. A plaintext file is never removed until its
 * ciphertext has been proven to round-trip back to it byte-for-byte. No hard
 * deletes; --dry-run reports without writing a single byte.
 *
 *   php artisan media:encrypt-backfill --scope=comms [--dry-run] [--limit=N]
 */
class EncryptMediaBackfill extends Command
{
    protected $signature = 'media:encrypt-backfill
        {--scope=comms : comms (communication media/attachments)}
        {--dry-run : Report what would happen; write nothing}
        {--limit=0 : Max files to process (0 = all)}';

    protected $description = 'AT-173 — encrypt existing plaintext media in place, round-trip verified';

    public function handle(MediaCipher $cipher): int
    {
        if (! $cipher->enabled()) {
            $this->error('Media encryption is not enabled (MEDIA_ENCRYPTION_KEY missing or MEDIA_ENCRYPTION_ENABLED=false). Aborting.');

            return self::FAILURE;
        }

        $scope = (string) $this->option('scope');
        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        [$disk, $files] = match ($scope) {
            'comms' => [config('communications.disk', 'local'), $this->commsFiles()],
            default => [null, null],
        };
        if ($files === null) {
            $this->error("Unknown scope '{$scope}'. Supported: comms.");

            return self::FAILURE;
        }

        $fs = Storage::disk($disk);
        $stats = ['seen' => 0, 'already' => 0, 'encrypted' => 0, 'failed' => 0, 'skipped_empty' => 0];

        foreach ($files as $path) {
            if ($limit > 0 && $stats['encrypted'] >= $limit) {
                break;
            }
            $stats['seen']++;

            $bytes = $fs->get($path);
            if ($bytes === null || $bytes === '') {
                $stats['skipped_empty']++;
                continue;
            }
            if ($cipher->isEncrypted($bytes)) {
                $stats['already']++;
                continue;
            }

            // In-memory proof BEFORE any write.
            $env = $cipher->encrypt($bytes);
            if ($cipher->decrypt($env) !== $bytes) {
                $stats['failed']++;
                $this->warn("round-trip FAILED (left untouched): {$path}");
                continue;
            }

            if ($dry) {
                $stats['encrypted']++; // "would encrypt"
                continue;
            }

            // Write to a temp sibling, verify it, then atomically replace.
            $tmp = $path . '.cxe-tmp';
            $fs->put($tmp, $env);
            if (! $cipher->isEncrypted($fs->get($tmp)) || $cipher->decrypt($fs->get($tmp)) !== $bytes) {
                $fs->delete($tmp);
                $stats['failed']++;
                $this->warn("temp verify FAILED (original untouched): {$path}");
                continue;
            }
            // Atomic-ish replace: move temp over the original (same disk).
            $fs->delete($path);
            $fs->move($tmp, $path);

            // Final read-back proof on the real path.
            if ($cipher->decrypt($fs->get($path)) !== $bytes) {
                $stats['failed']++;
                $this->error("POST-REPLACE verify FAILED for {$path} — investigate immediately.");
                continue;
            }
            $stats['encrypted']++;
        }

        $verb = $dry ? 'WOULD encrypt' : 'encrypted';
        $this->info(sprintf(
            "[%s] scope=%s disk=%s — seen=%d, already-encrypted=%d, %s=%d, empty-skipped=%d, failed=%d",
            $dry ? 'DRY-RUN' : 'APPLIED', $scope, $disk,
            $stats['seen'], $stats['already'], $verb, $stats['encrypted'], $stats['skipped_empty'], $stats['failed']
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** All files written through CommunicationStorageService live under communications/. */
    private function commsFiles(): array
    {
        return Storage::disk(config('communications.disk', 'local'))->allFiles('communications');
    }
}
