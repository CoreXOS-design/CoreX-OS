<?php

namespace App\Services\Images;

use App\Models\RentalPortalSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Security audit D14 - a picture a tenant or owner sent must never sit at a permanent, guessable public address.
 *
 * Two kinds of stored value are understood everywhere a picture is read:
 *
 *   - `private:<path on the private 'local' disk>` - what NEW portal uploads store. The file is NOT web-reachable at all; it can only
 *     be opened through {@see self::signedUrl()}, which points at `GET /private-image/{token}` (PrivateImageController).
 *   - legacy `/storage/...` URLs (relative or absolute) - the public-disk files every older upload used. They keep working for
 *     every reader (staff screens, PDFs, mail); the portal never emits them any more, only a signed expiring URL to the same file.
 *
 * A signed URL is a capability with three independent limits: the signature (tamper-proof), an expiry (minutes come from the agency's
 * own setting, RentalPortalSetting::portalPhotoLinkMinutesFor - never a constant here), and GUARDS: the database rows the picture
 * belongs to. When any guard row is gone or archived (deleted_at set) the URL stops working at once, before it would have expired -
 * that is how "revoke on archive" works without tracking issued URLs.
 *
 * Built to be shared: the rental inspection photos can resolve their pictures through the same service.
 */
class PrivateImageService
{
    public const PREFIX = 'private:';
    public const DISK = 'local';
    /** Every private picture lives under this directory of the private disk (derived portal copies under DIR/portal-img). */
    public const DIR = 'private-images';
    public const ROUTE = 'private-image.show';
    public const VARIANT_FULL = 'full';
    public const VARIANT_THUMB = 'thumb';
    /** The longest a browser may reuse a streamed picture, however long the link itself lives. */
    public const MAX_BROWSER_CACHE_SECONDS = 300;
    /** What the controller will stream. SVG is deliberately absent (script inside an image would run on our origin). */
    private const SERVABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** @var array<string,bool> */
    private static array $deletedAtColumn = [];

    // ── references ────────────────────────────────────────────────────────────────────────────────────────────────

    public function isPrivate(?string $ref): bool
    {
        return is_string($ref) && str_starts_with(trim($ref), self::PREFIX);
    }

    /** The path on the private disk behind a `private:` ref; null for anything else, a path walk, or a path outside {@see self::DIR}. */
    public function privateRel(?string $ref): ?string
    {
        if (! $this->isPrivate($ref)) {
            return null;
        }
        $rel = ltrim(substr(trim((string) $ref), strlen(self::PREFIX)), '/');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0") || ! str_starts_with($rel, self::DIR . '/')) {
            return null;
        }

        return $rel;
    }

    /**
     * The path on the PUBLIC disk behind a legacy `/storage/...` value (relative or absolute URL, or a bare `storage/..` / `public/..`
     * path); null for anything else (an external link, a path walk).
     */
    public function legacyPublicRel(?string $stored): ?string
    {
        $stored = is_string($stored) ? trim($stored) : '';
        if ($stored === '' || $this->isPrivate($stored)) {
            return null;
        }
        $path = (string) (parse_url($stored, PHP_URL_PATH) ?: $stored);
        if (! preg_match('#^/?(?:storage|public)/(.+)$#i', $path, $m) && ! preg_match('#/storage/(.+)$#', $path, $m)) {
            return null;
        }
        $rel = ltrim($m[1], '/');
        if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
            return null;
        }

        return $rel;
    }

    /** The file on disk behind any stored value we hold (private ref or legacy public file); null when it is not ours or not there. */
    public function absolutePath(?string $ref): ?string
    {
        $private = $this->privateRel($ref);
        if ($private !== null) {
            $disk = Storage::disk(self::DISK);

            return $disk->exists($private) ? $disk->path($private) : null;
        }

        $public = $this->legacyPublicRel($ref);
        if ($public !== null) {
            $disk = Storage::disk('public');

            return $disk->exists($public) ? $disk->path($public) : null;
        }

        return null;
    }

    public function exists(?string $ref): bool
    {
        return $this->absolutePath($ref) !== null;
    }

    // ── storing ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** Store one upload on the private disk (same pipeline as PropertyImageStorer) and return its `private:` ref. */
    public function storeUpload(UploadedFile $file, int $propertyId): string
    {
        $rel = app(PropertyImageStorer::class)->storeOnDisk($file, self::DIR . '/properties/' . $propertyId, self::DISK);

        return self::PREFIX . $rel;
    }

    // ── reading ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * A signed, expiring URL for one stored picture.
     *
     * @param  array<int,array{0:string,1:int|string}>  $guards  [table, id] rows the picture belongs to; the URL dies when any is missing or archived
     */
    public function signedUrl(string $ref, ?int $agencyId, array $guards = [], ?int $minutes = null, string $variant = self::VARIANT_FULL): string
    {
        $minutes ??= RentalPortalSetting::portalPhotoLinkMinutesFor($agencyId);
        $expires = now()->addMinutes(max(1, $minutes));

        $payload = json_encode([
            'r' => $ref,
            'v' => $variant === self::VARIANT_THUMB ? self::VARIANT_THUMB : self::VARIANT_FULL,
            'g' => array_values(array_map(fn ($g) => [(string) $g[0], (int) $g[1]], $guards)),
            'e' => $expires->getTimestamp(),
        ]);

        return URL::temporarySignedRoute(self::ROUTE, $expires, ['token' => $this->encode($payload)]);
    }

    /**
     * What a staff screen / view puts in an `<img src>`: a signed URL for a `private:` ref, the stored value untouched for a legacy one
     * (legacy staff behaviour is unchanged), null for an empty value.
     */
    public function displayUrl(?string $ref, ?int $agencyId, array $guards = [], string $variant = self::VARIANT_FULL): ?string
    {
        $ref = is_string($ref) ? trim($ref) : null;
        if ($ref === null || $ref === '') {
            return $ref;
        }

        return $this->isPrivate($ref) ? $this->signedUrl($ref, $agencyId, $guards, null, $variant) : $ref;
    }

    /**
     * True when any guard row is missing or archived. A guard on a table without `deleted_at` only needs the row to exist.
     *
     * @param  array<int,array{0:string,1:int|string}>  $guards
     */
    public function revoked(array $guards): bool
    {
        foreach ($guards as $guard) {
            $table = (string) ($guard[0] ?? '');
            $id = (int) ($guard[1] ?? 0);
            if ($id < 1 || ! preg_match('/^[a-z0-9_]+$/', $table)) {
                return true;
            }
            $q = DB::table($table)->where('id', $id);
            if ($this->hasDeletedAt($table)) {
                $q->whereNull('deleted_at');
            }
            if (! $q->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything the controller needs to stream a token's picture, or null for ANY reason it must not (tampered, expired, revoked,
     * file gone, not an image) - the controller answers all of them with the same 404.
     *
     * @return array{path:string,mime:string,seconds:int}|null
     */
    public function resolve(string $token): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($this->decode($token)), true);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || ! isset($data['r'], $data['e']) || ! is_string($data['r'])) {
            return null;
        }
        $remaining = (int) $data['e'] - now()->getTimestamp();
        if ($remaining < 1) {
            return null;
        }
        $guards = is_array($data['g'] ?? null) ? $data['g'] : [];
        if ($this->revoked($guards)) {
            return null;
        }

        $variant = ($data['v'] ?? self::VARIANT_FULL) === self::VARIANT_THUMB ? self::VARIANT_THUMB : self::VARIANT_FULL;
        $path = app(PortalImageService::class)->servePath($data['r'], $variant);
        if ($path === null || ! is_file($path)) {
            return null;
        }
        $mime = (string) (@mime_content_type($path) ?: '');
        if (! in_array($mime, self::SERVABLE_MIMES, true)) {
            return null;
        }

        return ['path' => $path, 'mime' => $mime, 'seconds' => max(1, min($remaining, self::MAX_BROWSER_CACHE_SECONDS))];
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────────

    private function hasDeletedAt(string $table): bool
    {
        return self::$deletedAtColumn[$table] ??= Schema::hasColumn($table, 'deleted_at');
    }

    /** Encrypted, then made safe for a URL path segment (the encrypted text is already base64). */
    private function encode(string $plain): string
    {
        return rtrim(strtr(Crypt::encryptString($plain), '+/', '-_'), '=');
    }

    private function decode(string $token): string
    {
        if ($token === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            throw new \InvalidArgumentException('bad token');
        }
        $b64 = strtr($token, '-_', '+/');

        return $b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4);
    }
}
