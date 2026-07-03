<?php

namespace App\Services\Communications;

use Illuminate\Support\Facades\Storage;

/**
 * Shared content-addressed storage writer for the Communication Archive (AT-32,
 * spec §7). Both adapters (email .eml, WhatsApp .json) and attachments write
 * through this. Identical bytes hash to the same path and are stored once
 * (dedup). MySQL holds only the returned path + content_hash.
 *
 * Destination is swappable by config (`communications.disk`, default 'local' =
 * storage/app/private) without changing callers.
 */
class CommunicationStorageService
{
    public function disk(): string
    {
        return config('communications.disk', 'local');
    }

    /**
     * Store bytes content-addressed. Returns ['path' => ..., 'content_hash' => ...].
     * If the same bytes are already stored, the existing path is returned and
     * nothing is rewritten.
     *
     * @param string $kind logical bucket, e.g. 'email', 'whatsapp', 'attachment'
     */
    public function store(int $agencyId, string $kind, string $bytes): array
    {
        $hash = hash('sha256', $bytes);
        $path = $this->pathFor($agencyId, $kind, $hash);
        $disk = Storage::disk($this->disk());

        if (! $disk->exists($path)) {
            $disk->put($path, $bytes);
        }

        return ['path' => $path, 'content_hash' => $hash];
    }

    public function get(string $path): ?string
    {
        $disk = Storage::disk($this->disk());

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk())->exists($path);
    }

    /**
     * AT-168 Part B — genuinely remove stored bytes (the embargo-purge POPIA
     * contract). Content-addressed dedup means a path may be shared, so callers
     * MUST confirm no other row references it before deleting. Best-effort.
     */
    public function delete(string $path): bool
    {
        $disk = Storage::disk($this->disk());

        return $disk->exists($path) ? $disk->delete($path) : true;
    }

    /**
     * communications/{agencyId}/{kind}/{ab}/{sha256} — the two-char fan-out
     * keeps any single directory from growing unbounded.
     */
    protected function pathFor(int $agencyId, string $kind, string $hash): string
    {
        $kind = preg_replace('/[^a-z0-9_]/i', '', $kind) ?: 'misc';

        return sprintf('communications/%d/%s/%s/%s', $agencyId, $kind, substr($hash, 0, 2), $hash);
    }
}
