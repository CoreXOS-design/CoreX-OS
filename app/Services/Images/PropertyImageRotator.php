<?php

namespace App\Services\Images;

use App\Models\Property;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Rotates a stored property gallery image 90°/180° and writes the result as a
 * NEW file in the same property directory, returning the new public URL.
 *
 * Why a new filename (not in-place overwrite): the gallery, public website,
 * Property24 / PrivateProperty syndication, presentations and PDF exports all
 * read the same stored file by URL. Overwriting in place leaves every browser
 * and CDN serving the stale orientation from cache. A new URL is uncacheable-
 * stale by construction — the corrected orientation shows everywhere at once.
 * The caller is responsible for swapping the old URL → new URL across the
 * property's image JSON fields (see PropertyController::swapImageUrl).
 *
 * Uses GD (loaded locally AND on prod, unlike imagick) — same dependency the
 * upload downscaler and AgentPhotoNormalizer already rely on.
 *
 * Spec: .ai/specs/gallery-image-rotation.md
 */
class PropertyImageRotator
{
    /**
     * @param  int  $degrees  Passed straight to GD imagerotate() (counter-
     *                        clockwise positive): 90 = rotate left,
     *                        -90 = rotate right, 180 = flip.
     * @return string The new public URL of the rotated file.
     *
     * @throws \InvalidArgumentException  url outside this property's dir, or unreadable
     * @throws \RuntimeException          GD unavailable / encode failed
     */
    public function rotate(Property $property, string $imageUrl, int $degrees): string
    {
        if (! function_exists('imagerotate')) {
            throw new \RuntimeException('Image rotation is unavailable on this server (GD missing).');
        }

        $disk = Storage::disk('public');
        $rel  = $this->relativePathFromUrl($imageUrl);

        // Hard boundary: the file MUST live in this property's own directory.
        // Blocks path traversal and cross-property / cross-agency file access.
        $expectedPrefix = "properties/{$property->id}/";
        if (str_contains($rel, '..') || ! str_starts_with($rel, $expectedPrefix)) {
            // Common real cause: the image is hosted externally (syndicated /
            // portal-pulled), so there is no CoreX-owned file to rotate.
            throw new \InvalidArgumentException('This image can’t be rotated because it isn’t a CoreX-stored gallery image.');
        }
        if (! $disk->exists($rel)) {
            throw new \InvalidArgumentException('The image file could not be found on the server.');
        }

        $absolute = $disk->path($rel);
        $info     = @getimagesize($absolute);
        $bytes    = @file_get_contents($absolute);
        $src      = $bytes ? @imagecreatefromstring($bytes) : false;
        if (! $info || ! $src instanceof \GdImage) {
            throw new \InvalidArgumentException('Image could not be read.');
        }

        $rotated = imagerotate($src, $degrees, 0);
        imagedestroy($src);
        if (! $rotated instanceof \GdImage) {
            throw new \RuntimeException('Rotation failed.');
        }

        $ext    = strtolower(pathinfo($rel, PATHINFO_EXTENSION)) ?: 'jpg';
        $newRel = $expectedPrefix . Str::random(40) . '.' . $ext;

        $this->encode($rotated, $disk->path($newRel), (int) $info[2]);
        imagedestroy($rotated);

        // Remove the original now the rotated copy is written.
        $disk->delete($rel);

        return $disk->url($newRel);
    }

    /**
     * Encode preserving the source format. For right-angle rotations the whole
     * canvas is covered, so no background fill is needed; alpha is preserved for
     * PNG/WebP so transparent sources stay transparent.
     */
    private function encode(\GdImage $img, string $path, int $type): void
    {
        switch ($type) {
            case IMAGETYPE_PNG:
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $ok = imagepng($img, $path);
                break;
            case IMAGETYPE_WEBP:
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $ok = imagewebp($img, $path, 90);
                break;
            case IMAGETYPE_GIF:
                $ok = imagegif($img, $path);
                break;
            default:
                $ok = imagejpeg($img, $path, 90);
        }

        if (! $ok) {
            throw new \RuntimeException('Could not write the rotated image.');
        }
    }

    /** Public URL (relative or absolute) → public-disk relative path. */
    private function relativePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return $path;
    }
}
