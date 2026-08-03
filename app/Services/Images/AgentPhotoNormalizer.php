<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Forces every agent photo to a uniform 1200×1200 square WebP, so the image
 * renders identically everywhere (admin grid, agent portal, presentation /
 * signature footers, property-detail sidebar, and the public website agent
 * cards via AgentResource.photo_url).
 *
 * This is the "absorb" half of the agent-photo feature (Robustness Charter):
 * the client cropper PREVENTS bad input; this normalizer ABSORBS anything that
 * bypasses it (legacy imports, API, direct posts) so uniformity holds regardless.
 *
 * Uses GD (loaded locally AND on prod, unlike imagick).
 *
 * Spec: .ai/specs/agent-photo.md §3
 */
class AgentPhotoNormalizer
{
    /** Output edge length — pixel-perfect fit in every display surface. */
    public const SIZE = 1200;

    /** Minimum source short-edge; smaller would upscale to mush. */
    public const MIN_SOURCE = 800;

    /** Target max encoded size; quality steps down until met. */
    public const MAX_BYTES = 500 * 1024;

    /**
     * Normalize + store. Returns the public-disk relative path
     * (e.g. "agents/42/photo.webp"). Deletes $existingPath if it differs.
     *
     * Also writes a JPEG rendition at photoJpegPath($userId) from the same
     * square canvas — Private Property's UpdateAgentImage rejects every
     * non-JPG upload ("only jpg images are supported"), so the portal-facing
     * copy can never be the WebP CoreX uses everywhere else. See
     * PrivatePropertySyndicationService::submitAgentImages().
     *
     * @throws ValidationException when the source is unreadable or too small.
     */
    public function store(UploadedFile $file, int $userId, ?string $existingPath = null): string
    {
        $square = $this->toSquareCanvas($file);

        $relPath = "agents/{$userId}/photo.webp";
        Storage::disk('public')->put($relPath, $this->encodeWebp($square));
        Storage::disk('public')->put($this->photoJpegPath($userId), $this->encodeJpeg($square));
        imagedestroy($square);

        if ($existingPath && $existingPath !== $relPath) {
            Storage::disk('public')->delete($existingPath);
        }

        return $relPath;
    }

    /**
     * Public-disk relative path of the JPEG rendition for a given user.
     */
    public function photoJpegPath(int $userId): string
    {
        return "agents/{$userId}/photo.jpg";
    }

    /**
     * Absorb (Robustness Charter, prevent-or-absorb): agents whose photo was
     * uploaded before the JPEG rendition existed have no photo.jpg on disk.
     * Regenerate it on demand from the current WebP rather than requiring a
     * separate backfill pass. Returns the relative path, or null when there
     * is no source photo to regenerate from.
     */
    public function ensureJpeg(int $userId): ?string
    {
        $jpegPath = $this->photoJpegPath($userId);
        $disk = Storage::disk('public');

        if ($disk->exists($jpegPath)) {
            return $jpegPath;
        }

        $webpPath = "agents/{$userId}/photo.webp";
        if (!$disk->exists($webpPath)) {
            return null;
        }

        $bytes = $disk->get($webpPath);
        $src = $bytes ? @imagecreatefromstring($bytes) : false;
        if (! $src instanceof \GdImage) {
            return null;
        }

        $disk->put($jpegPath, $this->encodeJpeg($src));
        imagedestroy($src);

        return $jpegPath;
    }

    /**
     * Decode → EXIF-orient → center-crop to square → resample to SIZE×SIZE.
     *
     * @return \GdImage
     */
    private function toSquareCanvas(UploadedFile $file): \GdImage
    {
        $bytes = @file_get_contents($file->getRealPath());
        $src = $bytes ? @imagecreatefromstring($bytes) : false;

        if (! $src instanceof \GdImage) {
            throw ValidationException::withMessages([
                'agent_photo' => 'That image could not be read. Upload a JPG, PNG, or WebP.',
            ]);
        }

        $src = $this->applyExifOrientation($src, $file);

        $w = imagesx($src);
        $h = imagesy($src);
        $short = min($w, $h);

        if ($short < self::MIN_SOURCE) {
            imagedestroy($src);
            throw ValidationException::withMessages([
                'agent_photo' => 'Photo is too small — use at least '
                    .self::MIN_SOURCE.'×'.self::MIN_SOURCE.' px (square '.self::SIZE.'×'.self::SIZE.' is ideal).',
            ]);
        }

        // Center-crop square region of the source.
        $sx = (int) (($w - $short) / 2);
        $sy = (int) (($h - $short) / 2);

        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        // Preserve transparency through to WebP (PNG sources with alpha).
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));

        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $short, $short);
        imagedestroy($src);

        return $dst;
    }

    /**
     * Honour JPEG EXIF orientation (phone photos) before cropping. No-op for
     * formats GD's imagecreatefromstring already orients (PNG/WebP carry none).
     *
     * @return \GdImage
     */
    private function applyExifOrientation(\GdImage $img, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }
        $mime = (string) $file->getMimeType();
        if ($mime !== 'image/jpeg' && $mime !== 'image/jpg') {
            return $img;
        }

        $exif = @exif_read_data($file->getRealPath());
        $orientation = (int) ($exif['Orientation'] ?? 0);

        switch ($orientation) {
            case 3:
                $rotated = imagerotate($img, 180, 0);
                break;
            case 6:
                $rotated = imagerotate($img, -90, 0);
                break;
            case 8:
                $rotated = imagerotate($img, 90, 0);
                break;
            default:
                return $img;
        }

        if ($rotated instanceof \GdImage) {
            imagedestroy($img);
            return $rotated;
        }

        return $img;
    }

    /**
     * Encode WebP, stepping quality down until under MAX_BYTES (or floor 60).
     */
    private function encodeWebp(\GdImage $img): string
    {
        for ($quality = 82; $quality >= 60; $quality -= 8) {
            ob_start();
            imagewebp($img, null, $quality);
            $bytes = (string) ob_get_clean();

            if (strlen($bytes) <= self::MAX_BYTES || $quality === 60) {
                return $bytes;
            }
        }

        return $bytes; // unreachable; satisfies static analysis
    }

    /**
     * Encode JPEG, stepping quality down until under MAX_BYTES (or floor 60).
     * JPEG has no alpha channel, so the square canvas (which carries a
     * semi-transparent-white fill for non-opaque sources) is first flattened
     * onto a solid white background — otherwise transparent regions render
     * as black/garbage in consumers that don't understand the source's alpha.
     */
    private function encodeJpeg(\GdImage $img): string
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);

        $bytes = '';
        for ($quality = 85; $quality >= 60; $quality -= 8) {
            ob_start();
            imagejpeg($flat, null, $quality);
            $bytes = (string) ob_get_clean();

            if (strlen($bytes) <= self::MAX_BYTES || $quality === 60) {
                break;
            }
        }

        imagedestroy($flat);

        return $bytes;
    }
}
