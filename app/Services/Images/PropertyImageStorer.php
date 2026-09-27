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
        $disk = Storage::disk('public');
        $path = $file->store("properties/{$propertyId}", 'public');
        $this->assertStored($disk, $path, 'immediately after the initial write');

        // Bake EXIF orientation into the original before downscaling. downscale()
        // re-encodes large captures with GD, which strips the EXIF orientation
        // tag without rotating the pixels — a portrait phone photo would otherwise
        // be saved sideways. Doing it first means downscale() (and every derived
        // thumbnail) works from upright pixels. No-op for upright/non-JPEG.
        app(ImageOrientationNormalizer::class)
            ->normalizeInPlace($disk->path($path));

        $this->downscale($path);
        $this->assertStored($disk, $path, 'after downscale()');

        $url = Storage::url($path);

        // Generate the small list-view thumbnail up front so the Properties
        // grid/table never serves the full-resolution original. Best-effort:
        // if it fails, list views fall back to the original (nothing breaks).
        app(PropertyThumbnailService::class)->generateForUrl($url);

        return $url;
    }

    /**
     * AT-436 incident, 2026-09-27 — property 5792, cc5: a photo record whose
     * file does not exist on disk. Traced: storePhotos() (RentalInspection
     * RecordingController) already calls store() BEFORE RentalInspectionPhoto
     * ::create() on both the pre- and post-AT-433 code paths — the row was
     * never written ahead of the file. The actual cause of the one real
     * ghost row found was a QA1 dev-server process running from a different
     * filesystem root than the one the site is actually served from: the
     * write genuinely succeeded, on a disk this app wasn't reading back
     * from — Flysystem's own write-failure exception (which already exists
     * for a literal failed write) can't catch that, because nothing failed.
     * This is the class fix Johan asked for regardless: this service is the
     * ONE place every caller (rental inspections, the marketing gallery, the
     * mobile API — see this class's own docblock) stores an image, so a
     * verified-on-THIS-disk check here closes the "row committed, file not
     * there" failure mode everywhere at once, for whatever future reason
     * might produce it, not just this one. Throwing here means the caller's
     * RentalInspectionPhoto::create() (or any other model write built on
     * this URL) is never reached — no row, no rollback needed, because
     * nothing was written yet.
     */
    private function assertStored(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path, string $when): void
    {
        if (! $disk->exists($path) || $disk->size($path) < 1) {
            throw new \RuntimeException("PropertyImageStorer: \"{$path}\" does not exist (or is empty) on disk {$when} — refusing to hand back a URL for a photo record to point at nothing.");
        }
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
