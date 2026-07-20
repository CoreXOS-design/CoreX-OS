<?php

namespace App\Console\Commands;

use App\Models\FicaDocument;
use App\Services\Compliance\FicaDocumentStorage;
use App\Services\Security\MediaCipher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-173 — retroactively encrypt existing plaintext media, safely.
 *
 * Per item: read → skip if already encrypted at its final home (idempotent) →
 * encrypt → VERIFY decrypt(ciphertext) === plaintext in memory → write ciphertext →
 * re-read + verify → only THEN remove the old copy. A plaintext file is never
 * removed until its ciphertext has been proven to round-trip back to it byte-for-byte.
 * No hard deletes of DB rows; --dry-run reports without writing a single byte.
 *
 *   php artisan media:encrypt-backfill --scope=comms [--dry-run] [--limit=N]
 *   php artisan media:encrypt-backfill --scope=fica  [--dry-run] [--limit=N]
 */
class EncryptMediaBackfill extends Command
{
    protected $signature = 'media:encrypt-backfill
        {--scope=comms : comms (communication media) | fica (FICA documents)}
        {--dry-run : Report what would happen; write nothing}
        {--limit=0 : Max files to process (0 = all)}';

    protected $description = 'AT-173 — encrypt existing plaintext media in place, round-trip verified';

    private MediaCipher $cipher;
    private bool $dry;
    private int $limit;

    public function handle(MediaCipher $cipher): int
    {
        $this->cipher = $cipher;
        if (! $cipher->enabled()) {
            $this->error('Media encryption is not enabled (MEDIA_ENCRYPTION_KEY missing or disabled). Aborting.');

            return self::FAILURE;
        }
        $this->dry = (bool) $this->option('dry-run');
        $this->limit = (int) $this->option('limit');
        $scope = (string) $this->option('scope');

        $stats = match ($scope) {
            'comms' => $this->backfillComms(),
            'fica' => $this->backfillFica(),
            default => null,
        };
        if ($stats === null) {
            $this->error("Unknown scope '{$scope}'. Supported: comms, fica.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            '[%s] scope=%s — seen=%d, already-encrypted=%d, %s=%d, empty-skipped=%d, failed=%d',
            $this->dry ? 'DRY-RUN' : 'APPLIED', $scope,
            $stats['seen'], $stats['already'], $this->dry ? 'WOULD encrypt' : 'encrypted',
            $stats['encrypted'], $stats['skipped_empty'], $stats['failed']
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Communication media: files written through CommunicationStorageService, in place on one disk. */
    private function backfillComms(): array
    {
        $disk = config('communications.disk', 'local');
        $fs = Storage::disk($disk);
        $stats = $this->freshStats();

        foreach ($fs->allFiles('communications') as $path) {
            if ($this->hitLimit($stats)) {
                break;
            }
            $stats['seen']++;
            $bytes = $fs->get($path);
            if ($bytes === null || $bytes === '') {
                $stats['skipped_empty']++;
                continue;
            }
            if ($this->cipher->isEncrypted($bytes)) {
                $stats['already']++;
                continue;
            }
            if (! $this->provenEncrypt($bytes, $env)) {
                $stats['failed']++;
                $this->warn("round-trip FAILED (untouched): {$disk}:{$path}");
                continue;
            }
            if ($this->dry) {
                $stats['encrypted']++;
                continue;
            }
            $tmp = $path . '.cxe-tmp';
            $fs->put($tmp, $env);
            if ($this->cipher->decrypt($fs->get($tmp)) !== $bytes) {
                $fs->delete($tmp);
                $stats['failed']++;
                $this->warn("temp verify FAILED (original untouched): {$path}");
                continue;
            }
            $fs->delete($path);
            $fs->move($tmp, $path);
            if ($this->cipher->decrypt($fs->get($path)) !== $bytes) {
                $stats['failed']++;
                $this->error("POST-REPLACE verify FAILED: {$path}");
                continue;
            }
            $stats['encrypted']++;
        }

        return $stats;
    }

    /** FICA documents: DB rows, possibly on the legacy public disk → encrypt + relocate to private. */
    private function backfillFica(): array
    {
        $priv = Storage::disk(FicaDocumentStorage::DISK);
        $pub = Storage::disk(FicaDocumentStorage::LEGACY_DISK);
        $stats = $this->freshStats();

        FicaDocument::withoutGlobalScopes()->orderBy('id')->chunkById(200, function ($docs) use ($priv, $pub, &$stats) {
            foreach ($docs as $doc) {
                if ($this->hitLimit($stats)) {
                    return false;
                }
                $stats['seen']++;
                $path = $doc->file_path;
                $onPrivate = $priv->exists($path);
                $onPublic = $pub->exists($path);
                if (! $onPrivate && ! $onPublic) {
                    $stats['skipped_empty']++; // missing file (already removed / never stored)
                    continue;
                }
                $raw = $onPrivate ? $priv->get($path) : $pub->get($path);
                // Final home is: encrypted, on private.
                if ($onPrivate && ! $onPublic && $this->cipher->isEncrypted($raw)) {
                    $stats['already']++;
                    continue;
                }
                $plain = $this->cipher->decrypt($raw); // plaintext, or passthrough if already-enc
                if (! $this->provenEncrypt($plain, $env)) {
                    $stats['failed']++;
                    $this->warn("round-trip FAILED (untouched): fica doc #{$doc->id} {$path}");
                    continue;
                }
                if ($this->dry) {
                    $stats['encrypted']++;
                    continue;
                }
                $priv->put($path, $env);
                if ($this->cipher->decrypt($priv->get($path)) !== $plain) {
                    $priv->delete($path);
                    $stats['failed']++;
                    $this->error("private write verify FAILED (public copy kept): fica #{$doc->id}");
                    continue;
                }
                // Only after the private ciphertext is proven do we drop the legacy public copy.
                if ($onPublic) {
                    $pub->delete($path);
                }
                $stats['encrypted']++;
            }
        });

        return $stats;
    }

    /** Encrypt + prove the round-trip in memory before any write. */
    private function provenEncrypt(string $plain, ?string &$env): bool
    {
        try {
            $env = $this->cipher->encrypt($plain);

            return $this->cipher->decrypt($env) === $plain;
        } catch (\Throwable) {
            return false;
        }
    }

    private function freshStats(): array
    {
        return ['seen' => 0, 'already' => 0, 'encrypted' => 0, 'failed' => 0, 'skipped_empty' => 0];
    }

    private function hitLimit(array $stats): bool
    {
        return $this->limit > 0 && $stats['encrypted'] >= $this->limit;
    }
}
