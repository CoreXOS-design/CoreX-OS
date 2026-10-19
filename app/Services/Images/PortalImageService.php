<?php

namespace App\Services\Images;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Every picture the tenant/owner portal shows goes through here (.ai/specs/rental-portal-access.md §23; security audit D14).
 *
 * The portal used to hand the browser the STORED file: a 72 x 72 px square showed a 2560 px photo (or whatever a crew or agent upload
 * left on disk, which only the tenant wizard shrinks). So the portal now gets two server-made copies of whatever file is stored,
 * whichever path it came in by (tenant wizard, owner wizard, agent, crew, contractor, completion round, fault-type guide):
 *
 *   - `thumb_url` — at most THUMB_EDGE px on the longest side, for every list / grid;
 *   - `url`       — at most FULL_EDGE px, for "open the picture".
 *
 * D14: neither is ever a permanent public address. Both are SIGNED, EXPIRING URLs ({@see PrivateImageService::signedUrl()}, expiry from the
 * agency's portal setting) that stream the copy through `GET /private-image/{token}` and stop working the moment a guard row (the photo,
 * its fault / work order / job card) is archived. The copies are made once, on first open, on the PRIVATE disk (never web-reachable), keyed
 * on the original's modification time so a replaced original gets fresh copies. The stored original is never touched (PDFs, mail and the
 * office screens still use it). GD only (the CLI/queue PHP has no Imagick). Whatever goes wrong while making a copy (no GD, unreadable
 * file, a picture too large to open safely) streams the original instead, so a picture is never lost because a resize failed.
 */
class PortalImageService
{
    public const THUMB_EDGE = 480;
    public const FULL_EDGE = 1600;
    private const THUMB_QUALITY = 78;
    private const FULL_QUALITY = 82;
    /** A file already inside the size limit AND this small is served as it is - a re-encode would only make it worse. */
    private const THUMB_KEEP_BYTES = 60000;
    private const FULL_KEEP_BYTES = 600000;
    /** Above this many pixels opening the picture is not safe; the original URL is returned unchanged. */
    private const MAX_PIXELS = 120_000_000;

    public const DIR = 'portal-img';

    /**
     * The two signed, expiring URLs for one stored picture. Anything that is not a picture we hold (an external link, a missing file, a
     * path walk) comes back unchanged for both, exactly as before; a picture we hold NEVER comes back as its own permanent address.
     *
     * @param  array<int,array{0:string,1:int|string}>  $guards  [table, id] rows the picture belongs to; archived / removed = the URL stops working
     * @return array{url: ?string, thumb_url: ?string}
     */
    public function urls(?string $stored, array $guards = [], ?int $agencyId = null): array
    {
        $stored = is_string($stored) ? trim($stored) : null;
        if ($stored === null || $stored === '') {
            return ['url' => $stored, 'thumb_url' => $stored];
        }

        $private = app(PrivateImageService::class);
        if (! $this->isPictureWeHold($stored) || ! $private->exists($stored)) {
            return ['url' => $stored, 'thumb_url' => $stored];
        }

        return [
            'url' => $private->signedUrl($stored, $agencyId, $guards, null, PrivateImageService::VARIANT_FULL),
            'thumb_url' => $private->signedUrl($stored, $agencyId, $guards, null, PrivateImageService::VARIANT_THUMB),
        ];
    }

    /**
     * The file the controller streams for a stored picture: the smaller copy of the asked variant (made now on the private disk if not
     * there yet), or the original when it already fits or no copy could be made. Null when the original is not there.
     */
    public function servePath(string $stored, string $variant): ?string
    {
        $private = app(PrivateImageService::class);
        $src = $this->isPictureWeHold($stored) ? $private->absolutePath($stored) : null;
        if ($src === null) {
            return null;
        }
        $info = @getimagesize($src);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return $src;
        }

        [$edge, $quality, $keep] = $variant === PrivateImageService::VARIANT_THUMB
            ? [self::THUMB_EDGE, self::THUMB_QUALITY, self::THUMB_KEEP_BYTES]
            : [self::FULL_EDGE, self::FULL_QUALITY, self::FULL_KEEP_BYTES];

        return $this->copy($src, $info, $edge, $quality, $keep) ?? $src;
    }

    /** A private ref, or a legacy public picture (never a copy of a copy, never a path walk). */
    private function isPictureWeHold(string $stored): bool
    {
        $private = app(PrivateImageService::class);
        if ($private->isPrivate($stored)) {
            $rel = $private->privateRel($stored);

            return $rel !== null && (bool) preg_match('#\.(?:jpe?g|png|webp)$#i', $rel) && ! str_starts_with($rel, PrivateImageService::DIR . '/' . self::DIR . '/');
        }

        return $this->relativePath($stored) !== null;
    }

    /** The path of a stored picture on the public disk, from a `/storage/...` URL (relative or absolute); null for anything else. */
    public function relativePath(string $stored): ?string
    {
        $path = parse_url($stored, PHP_URL_PATH) ?: $stored;
        if (! preg_match('#/storage/(.+\.(?:jpe?g|png|webp))$#i', $path, $m)) {
            return null;
        }
        $rel = $m[1];
        if (str_contains($rel, '..') || str_starts_with($rel, self::DIR . '/')) {
            return null;   // never a path walk, never a copy of a copy
        }

        return $rel;
    }

    /**
     * The absolute path of the copy (made now if not there yet), the original's own path when it already fits, or null when no copy
     * could be made (the caller then streams the original).
     */
    private function copy(string $src, array $info, int $edge, int $quality, int $keepBytes): ?string
    {
        [$w, $h] = $info;
        $disk = Storage::disk(PrivateImageService::DISK);
        $bytes = (int) @filesize($src);

        if (max($w, $h) <= $edge && $bytes <= $keepBytes && $info[2] === IMAGETYPE_JPEG) {
            return $src;   // already small enough: the original is streamed as it is
        }

        $copyRel = PrivateImageService::DIR . '/' . self::DIR . '/' . $edge . '/' . substr(sha1($src . '|' . (int) @filemtime($src) . '|' . $edge), 0, 24) . '.jpg';
        if ($disk->exists($copyRel)) {
            return $disk->path($copyRel);
        }

        if (! function_exists('imagecreatefromstring') || ($w * $h) > self::MAX_PIXELS) {
            return null;
        }

        try {
            return $this->render($src, $disk->path($copyRel), $w, $h, $edge, $quality) ? $disk->path($copyRel) : null;
        } catch (\Throwable $e) {
            Log::warning('Portal image: could not make the smaller copy, serving the original', ['file' => basename($src), 'edge' => $edge, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function render(string $src, string $dst, int $w, int $h, int $edge, int $quality): bool
    {
        $previous = ini_get('memory_limit');
        @ini_set('memory_limit', '1024M');   // a 30 megapixel original needs ~250 MB to open and resample
        $work = null;
        try {
            // Bake the EXIF orientation in first (GD drops the tag without turning the pixels); done on a private copy so the original stays untouched.
            $open = $src;
            if (function_exists('exif_read_data')) {
                $work = tempnam(sys_get_temp_dir(), 'pimg');
                if ($work !== false && @copy($src, $work)) {
                    app(ImageOrientationNormalizer::class)->normalizeInPlace($work);
                    $open = $work;
                    $dims = @getimagesize($work);
                    if ($dims) {
                        [$w, $h] = $dims;
                    }
                }
            }

            $data = @file_get_contents($open);
            $img = $data === false ? false : @imagecreatefromstring($data);
            unset($data);
            if (! $img) {
                return false;
            }

            $scale = max($w, $h) > $edge ? $edge / max($w, $h) : 1.0;
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $out = imagecreatetruecolor($nw, $nh);
            imagefilledrectangle($out, 0, 0, $nw, $nh, imagecolorallocate($out, 255, 255, 255));   // PNG/WebP transparency must not go black
            imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);

            $dir = dirname($dst);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $tmp = $dst . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $ok = @imagejpeg($out, $tmp, $quality);
            imagedestroy($out);
            if (! $ok) {
                @unlink($tmp);

                return false;
            }

            return @rename($tmp, $dst);   // atomic: two requests making the same copy cannot leave a half file behind
        } finally {
            if ($work) {
                @unlink($work);
            }
            if ($previous !== false) {
                @ini_set('memory_limit', (string) $previous);
            }
        }
    }
}
