<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Holds an uploaded package between "preview" and "confirm". The file is bound to
 * the uploading user, lives outside the web root, is deleted on confirm / cancel /
 * expiry, and a token is the only way to reach it. Spec: esign-template-transfer.md §4, §8.
 */
final class TemplateTransferStaging
{
    private function dir(): string
    {
        $dir = storage_path('app/template-transfer/staged');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /** @throws TemplateTransferException */
    public function stage(UploadedFile $file, User $user): string
    {
        $this->prune();

        if (! $file->isValid()) {
            throw new TemplateTransferException('The file could not be uploaded. Please try again.');
        }
        if ((int) $file->getSize() > TemplateTransferSettings::maxPackageBytes()) {
            throw new TemplateTransferException('The file is larger than the allowed ' . TemplateTransferSettings::int('max_package_mb') . ' MB.');
        }

        $token = Str::random(40);
        $file->move($this->dir(), $token . '.upload');
        file_put_contents($this->dir() . '/' . $token . '.json', json_encode([
            'user_id'       => $user->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'created_at'    => now()->timestamp,
        ]));

        return $token;
    }

    /**
     * @return array{path:string, original_name:string}
     * @throws TemplateTransferException when the token is unknown, expired, used, or someone else's
     */
    public function resolve(string $token, User $user): array
    {
        $gone = new TemplateTransferException('This upload has expired or was already used. Upload the package again.');
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            throw $gone;
        }
        $meta = @json_decode((string) @file_get_contents($this->dir() . '/' . $token . '.json'), true);
        $path = $this->dir() . '/' . $token . '.upload';
        if (! is_array($meta) || ! is_file($path) || (int) ($meta['user_id'] ?? 0) !== (int) $user->id) {
            throw $gone;
        }

        return ['path' => $path, 'original_name' => (string) ($meta['original_name'] ?? 'package')];
    }

    public function discard(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            @unlink($this->dir() . '/' . $token . '.upload');
            @unlink($this->dir() . '/' . $token . '.json');
        }
    }

    /** Delete uploads nobody confirmed within the configured window. */
    public function prune(): void
    {
        $cutoff = now()->subHours(TemplateTransferSettings::int('staged_upload_hours'))->timestamp;
        foreach (glob($this->dir() . '/*.json') ?: [] as $metaFile) {
            $meta = @json_decode((string) @file_get_contents($metaFile), true);
            if (! is_array($meta) || (int) ($meta['created_at'] ?? 0) < $cutoff) {
                $token = basename($metaFile, '.json');
                @unlink($this->dir() . '/' . $token . '.upload');
                @unlink($metaFile);
            }
        }
    }
}
