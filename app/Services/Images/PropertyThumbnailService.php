<?php

namespace App\Services\Images;

use App\Models\Property;
use Illuminate\Support\Facades\Storage;

/**
 * Web thumbnails for property list surfaces.
 *
 * Property galleries store full-resolution photos (avg ~290 KB, up to ~1.5 MB).
 * List views (the Properties grid/table, match cards, contact property lists)
 * render the FIRST photo tiny — so shipping the original there means a 100-row
 * page pulls ~30 MB of images. This service produces a small (~600px, JPEG q80)
 * thumbnail of each photo, stored ALONGSIDE the original in a `thumbs/`
 * subdirectory — the original file is never touched (full-res still serves the
 * property page, brochures and portal feeds).
 *
 * Design guarantees:
 *  - Originals immutable. Thumbs live under properties/{id}/thumbs/.
 *  - Graceful fallback: displayUrl() returns the original whenever the thumb
 *    isn't on disk yet, so list views never break before/during a backfill.
 *  - GD only (no Imagick) — the queue/CLI PHP has GD but not Imagick, and the
 *    whole codebase resizes with GD (see PropertyImageStorer::downscale).
 *  - Idempotent: generateForUrl() skips an existing thumb unless forced.
 */
class PropertyThumbnailService
{
    /** Sibling subdirectory that holds the thumbnails. */
    public const THUMB_DIR = 'thumbs';

    public function __construct(
        // 500px longest edge covers the list-card display size (~176px tall,
        // up to ~450px wide) sharply even on retina, while keeping a 20-row
        // page well under 1 MB. Quality 80 is visually lossless at card size.
        private int $maxEdge = 500,
        private int $quality = 80,
    ) {
    }

    /**
     * The URL a list view should use for a given original image: the thumbnail
     * if it exists on disk, otherwise the original (graceful fallback). Any URL
     * that isn't a recognised property-image /storage path is returned as-is.
     */
    public function displayUrl(?string $publicUrl): ?string
    {
        if (!is_string($publicUrl) || $publicUrl === '') {
            return $publicUrl;
        }
        $rel = $this->relativePathFromUrl($publicUrl);
        if ($rel === null) {
            return $publicUrl;
        }
        $thumbRel = $this->thumbRelPath($rel);

        return Storage::disk('public')->exists($thumbRel)
            ? Storage::url($thumbRel)
            : $publicUrl;
    }

    /**
     * Generate the thumbnail for one original image URL. Idempotent (skips an
     * existing thumb unless $force). Returns true when a thumb exists afterwards.
     */
    public function generateForUrl(?string $publicUrl, bool $force = false): bool
    {
        $rel = $this->relativePathFromUrl($publicUrl);
        if ($rel === null) {
            return false;
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($rel)) {
            return false; // original missing — nothing to shrink
        }

        $thumbRel = $this->thumbRelPath($rel);
        if (!$force && $disk->exists($thumbRel)) {
            return true; // already done
        }

        return $this->renderThumb($disk->path($rel), $disk->path($thumbRel));
    }

    /**
     * Delete the thumbnail for one original image URL, if present. Best-effort —
     * used when an upload is discarded (e.g. a concurrent idempotent duplicate)
     * so a freshly-generated thumb for the binned original isn't left orphaned.
     * Returns true when no thumb remains afterwards.
     */
    public function deleteForUrl(?string $publicUrl): bool
    {
        $rel = $this->relativePathFromUrl($publicUrl);
        if ($rel === null) {
            return false;
        }
        $thumbRel = $this->thumbRelPath($rel);
        $disk = Storage::disk('public');

        return $disk->exists($thumbRel) ? $disk->delete($thumbRel) : true;
    }

    /**
     * Generate thumbnails for every image on a property. Returns the number of
     * thumbnails produced (or already present when not forcing).
     */
    public function generateForProperty(Property $property, bool $force = false): int
    {
        $count = 0;
        foreach ($property->allImages() as $url) {
            if ($this->generateForUrl($url, $force)) {
                $count++;
            }
        }

        return $count;
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * Normalise a stored image URL to its path relative to the public disk,
     * e.g. "https://host/storage/properties/6058/abc.jpg" → "properties/6058/abc.jpg".
     * Returns null for anything that isn't a property-image file, or is already
     * a thumbnail.
     */
    private function relativePathFromUrl(?string $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        if (!preg_match('#/storage/(properties/\d+/[^/]+\.(?:jpe?g|png|webp))$#i', $path, $m)) {
            return null;
        }
        $rel = $m[1];
        if (str_contains($rel, '/' . self::THUMB_DIR . '/')) {
            return null; // never thumbnail a thumbnail
        }

        return $rel;
    }

    /** properties/{id}/abc.png → properties/{id}/thumbs/abc.jpg (always JPEG). */
    private function thumbRelPath(string $rel): string
    {
        $dir  = dirname($rel);
        $stem = pathinfo($rel, PATHINFO_FILENAME);

        return $dir . '/' . self::THUMB_DIR . '/' . $stem . '.jpg';
    }

    /** GD downscale → JPEG. Mirrors PropertyImageStorer::downscale. */
    private function renderThumb(string $srcAbs, string $dstAbs): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $info = @getimagesize($srcAbs);
        if (!$info) {
            return false;
        }
        [$width, $height] = $info;

        $bytes = @file_get_contents($srcAbs);
        if ($bytes === false) {
            return false;
        }
        $src = @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$src) {
            return false;
        }

        $maxSide = max($width, $height);
        $scale   = $maxSide > $this->maxEdge ? $this->maxEdge / $maxSide : 1.0;
        $newW    = max(1, (int) round($width * $scale));
        $newH    = max(1, (int) round($height * $scale));

        $dst   = imagecreatetruecolor($newW, $newH);
        // Flatten any alpha onto white so PNG/WebP transparency doesn't go black.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);

        $dir = dirname($dstAbs);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ok = @imagejpeg($dst, $dstAbs, $this->quality);
        imagedestroy($dst);

        return (bool) $ok;
    }
}
