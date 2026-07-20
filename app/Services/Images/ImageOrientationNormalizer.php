<?php

namespace App\Services\Images;

/**
 * Bakes a JPEG's EXIF orientation into its pixels, in place, and rewrites the
 * file upright with the orientation tag reset to normal.
 *
 * WHY THIS EXISTS
 * ---------------
 * Phone cameras capture in the sensor's native landscape orientation and record
 * an EXIF `Orientation` tag telling viewers how to rotate the pixels on display.
 * A photo shot in portrait is therefore stored as landscape pixels + "rotate me"
 * metadata. CoreX then re-encodes those files with GD downstream —
 * PropertyThumbnailService::renderThumb() and PropertyImageStorer::downscale() —
 * and GD DROPS the EXIF tag without rotating the pixels. The tag is gone, the
 * pixels were never turned, so the photo renders sideways everywhere the tag was
 * lost (thumbnails, portal feeds, brochures). This is exactly how property 6118's
 * mobile-captured gallery came out rotated 90°.
 *
 * Rather than trust every downstream surface AND every client to honour EXIF, we
 * ABSORB the problem once, at ingest: rotate the pixels to match the tag and strip
 * the tag. Every surface then shows the photo upright regardless of whether it
 * reads EXIF — including the current mobile app build. (Robustness Charter:
 * prevent-or-absorb; fix the class, not the instance.)
 *
 * GD only (present locally AND on prod, unlike imagick). No-op for anything GD
 * can't decode from EXIF (HEIC/PNG/WebP carry no actionable JPEG orientation) or
 * that is already upright — so it is safe to call on every stored image.
 */
class ImageOrientationNormalizer
{
    /**
     * Re-encode quality when a photo is rewritten upright. High, because this is
     * the master original — every downstream size derives from it. Only photos
     * that actually need rotation are ever re-encoded; upright ones are untouched.
     */
    private const QUALITY = 92;

    /**
     * Normalise the orientation of the JPEG at $absPath IN PLACE.
     *
     * Returns true when the file was rewritten upright; false when nothing needed
     * doing (already upright, not a decodable JPEG, unreadable, or the platform
     * lacks the required functions).
     */
    public function normalizeInPlace(string $absPath): bool
    {
        if (! function_exists('exif_read_data') || ! function_exists('imagecreatefromstring')) {
            return false;
        }
        if (! is_file($absPath)) {
            return false;
        }

        // EXIF orientation only lives in JPEG/TIFF. Trust the decoded type, not
        // the file extension — a mis-named file must not be mis-handled.
        $info = @getimagesize($absPath);
        if (! $info || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
            return false;
        }

        $exif = @exif_read_data($absPath);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        // 1 = already upright; anything outside 2..8 is absent/invalid — no work.
        if ($orientation < 2 || $orientation > 8) {
            return false;
        }

        $bytes = @file_get_contents($absPath);
        $img = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        unset($bytes);
        if (! $img instanceof \GdImage) {
            return false;
        }

        $img = $this->applyOrientation($img, $orientation);

        $ok = @imagejpeg($img, $absPath, self::QUALITY);
        imagedestroy($img);

        return (bool) $ok;
    }

    /**
     * Return the GD image transformed so its pixels are upright for the given
     * EXIF orientation. Handles all eight values, including the four mirrored
     * ones (2/4/5/7) that front-camera captures and some editors emit.
     *
     * imagerotate() rotates COUNTER-clockwise for positive angles, so a stored
     * image that is 90° CW of upright (orientation 6) is corrected with -90.
     */
    private function applyOrientation(\GdImage $img, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2: // mirrored horizontally
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $img;
            case 3: // rotated 180
                return $this->rotate($img, 180);
            case 4: // mirrored vertically
                imageflip($img, IMG_FLIP_VERTICAL);
                return $img;
            case 5: // mirrored vertically + rotated 90 CW
                imageflip($img, IMG_FLIP_VERTICAL);
                return $this->rotate($img, -90);
            case 6: // rotated 90 CW
                return $this->rotate($img, -90);
            case 7: // mirrored horizontally + rotated 90 CW
                imageflip($img, IMG_FLIP_HORIZONTAL);
                return $this->rotate($img, -90);
            case 8: // rotated 90 CCW
                return $this->rotate($img, 90);
            default:
                return $img;
        }
    }

    /**
     * Rotate $img by $degrees (imagerotate convention: positive = CCW), swapping
     * in the rotated handle and freeing the original. Falls back to the original
     * if GD returns false so the caller always has a valid image.
     */
    private function rotate(\GdImage $img, int $degrees): \GdImage
    {
        $rotated = imagerotate($img, $degrees, 0);
        if ($rotated instanceof \GdImage) {
            imagedestroy($img);
            return $rotated;
        }

        return $img;
    }
}
