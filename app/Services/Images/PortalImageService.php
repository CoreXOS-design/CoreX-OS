<?php

namespace App\Services\Images;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Every picture the tenant/owner portal shows goes through here (.ai/specs/rental-portal-access.md §23).
 *
 * The portal used to hand the browser the STORED file: a 72 x 72 px square showed a 2560 px photo (or whatever a crew or agent upload
 * left on disk, which only the tenant wizard shrinks). So the portal now gets two server-made copies of whatever file is stored,
 * whichever path it came in by (tenant wizard, owner wizard, agent, crew, contractor, completion round, fault-type guide):
 *
 *   - `thumb_url` — at most THUMB_EDGE px on the longest side, for every list / grid;
 *   - `url`       — at most FULL_EDGE px, for "open the picture".
 *
 * The stored original is never touched (PDFs, mail and the office screens still use it) and is never what the portal serves while a
 * smaller copy can be made. Copies are made once, on first use, beside the public disk, and keyed on the file's modification time so a
 * replaced original gets fresh copies. GD only (the CLI/queue PHP has no Imagick). Anything that goes wrong (no GD, unreadable file,
 * a picture too large to open safely) falls back to the original URL, so a picture is never lost because a resize failed.
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
     * The two URLs for one stored picture. Anything that is not a public-disk picture we hold (an external link, a missing file) comes
     * back unchanged for both, exactly as before.
     *
     * @return array{url: ?string, thumb_url: ?string}
     */
    public function urls(?string $stored): array
    {
        $stored = is_string($stored) ? trim($stored) : null;
        if ($stored === null || $stored === '') {
            return ['url' => $stored, 'thumb_url' => $stored];
        }

        $rel = $this->relativePath($stored);
        if ($rel === null) {
            return ['url' => $stored, 'thumb_url' => $stored];
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($rel)) {
            return ['url' => $stored, 'thumb_url' => $stored];
        }

        $src = $disk->path($rel);
        $info = @getimagesize($src);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return ['url' => $stored, 'thumb_url' => $stored];
        }

        return [
            'url' => $this->copy($stored, $rel, $src, $info, self::FULL_EDGE, self::FULL_QUALITY, self::FULL_KEEP_BYTES) ?? $stored,
            'thumb_url' => $this->copy($stored, $rel, $src, $info, self::THUMB_EDGE, self::THUMB_QUALITY, self::THUMB_KEEP_BYTES) ?? $stored,
        ];
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
     * The URL of the copy (made now if not there yet), the original's own URL when the original already fits, or null when no copy
     * could be made (the caller then falls back to the original).
     */
    private function copy(string $stored, string $rel, string $src, array $info, int $edge, int $quality, int $keepBytes): ?string
    {
        [$w, $h] = $info;
        $disk = Storage::disk('public');
        $bytes = (int) @filesize($src);

        if (max($w, $h) <= $edge && $bytes <= $keepBytes && $info[2] === IMAGETYPE_JPEG) {
            return $stored;   // already small enough: the stored value is returned exactly as it was (relative or absolute)
        }

        $copyRel = self::DIR . '/' . $edge . '/' . substr(sha1($rel . '|' . (int) @filemtime($src) . '|' . $edge), 0, 24) . '.jpg';
        if ($disk->exists($copyRel)) {
            return asset('storage/' . $copyRel);
        }

        if (! function_exists('imagecreatefromstring') || ($w * $h) > self::MAX_PIXELS) {
            return null;
        }

        try {
            return $this->render($src, $disk->path($copyRel), $w, $h, $edge, $quality) ? asset('storage/' . $copyRel) : null;
        } catch (\Throwable $e) {
            Log::warning('Portal image: could not make the smaller copy, serving the original', ['file' => $rel, 'edge' => $edge, 'error' => $e->getMessage()]);

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
