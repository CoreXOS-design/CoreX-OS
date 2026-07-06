<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Canonical store-and-downscale for property images.
 *
 * Single source of truth for the "put an uploaded image on the public disk
 * under properties/{id}/, downscale it to a sane web size, return its
 * /storage URL" operation. Used by the web PropertyController (marketing
 * gallery + rental inspection galleries) and the mobile API
 * (MobileRentalImagesController) so the two channels can never drift on
 * storage location, sizing or encoding.
 *
 * Uses GD (always present in this stack) so no extra dependency is needed.
 * Spec: .ai/specs/rental-images.md
 */
class PropertyImageStorer
{
    public function __construct(
        private int $maxEdge = 2560,
        private int $quality = 85,
    ) {
    }

    /**
     * Store one uploaded image and return its public /storage URL.
     */
    public function store(UploadedFile $file, int $propertyId): string
    {
        $path = $file->store("properties/{$propertyId}", 'public');
        $this->downscale($path);

        $url = Storage::url($path);

        // Generate the small list-view thumbnail up front so the Properties
        // grid/table never serves the full-resolution original. Best-effort:
        // if it fails, list views fall back to the original (nothing breaks).
        app(PropertyThumbnailService::class)->generateForUrl($url);

        return $url;
    }

    /**
     * Store every UploadedFile in the given list, in order.
     *
     * @param  array<int, mixed>  $files
     * @return array<int, string>  public /storage URLs, upload order preserved
     */
    public function storeMany(array $files, int $propertyId): array
    {
        $urls = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $urls[] = $this->store($file, $propertyId);
            }
        }

        return $urls;
    }

    /**
     * Resize a stored image down to a sensible web size (max dimension on the
     * longest edge, re-encoded as JPEG at the configured quality). Keeps the
     * file path/extension intact, overwriting in place. Failures are swallowed
     * so the upload still succeeds — the source file simply isn't resized.
     */
    public function downscale(string $relativePath): void
    {
        if (!function_exists('imagecreatefromstring')) {
            return;
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($relativePath)) {
            return;
        }

        $absolute = $disk->path($relativePath);

        $info = @getimagesize($absolute);
        if (!$info) {
            return;
        }
        [$width, $height] = $info;
        $maxSide = max($width, $height);

        if ($maxSide <= $this->maxEdge && $info[2] === IMAGETYPE_JPEG) {
            return;
        }

        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            return;
        }
        $src = @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$src) {
            return;
        }

        if ($maxSide > $this->maxEdge) {
            $scale     = $this->maxEdge / $maxSide;
            $newWidth  = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $dst       = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($src);
            $src = $dst;
        }

        @imagejpeg($src, $absolute, $this->quality);
        imagedestroy($src);
    }
}
