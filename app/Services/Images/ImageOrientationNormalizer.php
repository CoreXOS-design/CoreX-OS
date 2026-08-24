<?php

namespace App\Services\Images;

use Illuminate\Support\Facades\Log;

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
 *
 * INVALID/ABSENT ORIENTATION — THE ORIENTATION-0 GAP (property 6142, 2026-08-03)
 * -------------------------------------------------------------------------------
 * A valid EXIF Orientation tag is 1–8. Anything else was previously treated as
 * "no work needed" — the same bucket as a genuinely upright photo. That is false
 * for some HUAWEI/HONOR devices — confirmed on two, same day:
 *   - HUAWEI Mate X6, software ICL-L29 15.0.0.209: writes `Orientation => 0`
 *     (not a valid value). 33/33 sampled photos needed correction.
 *   - HONOR BRP-NX1: writes no Orientation tag at all.
 * Both store the pixel buffer in the sensor's non-upright native orientation
 * regardless — a firmware/camera-stack quirk (HUAWEI and HONOR share camera
 * pipeline lineage pre-2020 split), not a genuinely-upright missing tag. Every
 * sampled photo from both devices needed the identical 90° CCW correction (the
 * same transform as EXIF value 8).
 *
 * We cannot guess a rotation direction for an unknown/invalid tag in general —
 * there is no signal to guess from. This is a narrow, evidence-backed exception
 * for the device/value combinations we have verified (self::HEURISTIC_MAKES),
 * gated on the defect's own signature (Make matches, invalid/absent
 * Orientation, portrait-shaped canvas — a landscape scene could never
 * legitimately fill a portrait canvas without rotation). Every other
 * invalid/absent case is left untouched, exactly as before, but now logged
 * instead of silently swallowed — so the next unknown device/value shows up in
 * the logs instead of shipping sideways for months. This is a client-side
 * capture defect; the real fix belongs in the mobile app (bake orientation
 * before upload). This absorbs it server-side in the meantime — prevent AND
 * absorb, not one or the other.
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
     * Device makes with a verified orientation-0/absent firmware quirk (see
     * class docblock). Evidence-first list — extend only after visually
     * confirming the rotation direction across multiple real samples, never
     * speculatively.
     */
    private const HEURISTIC_MAKES = ['HUAWEI', 'HONOR'];

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

        $exif = @exif_read_data($absPath) ?: [];
        $rawOrientation = $exif['Orientation'] ?? null;

        // A valid tag (1-8): 1 is confirmed upright (no-op); 2-8 is a known
        // transform we can apply with certainty.
        if (is_numeric($rawOrientation) && (int) $rawOrientation >= 1 && (int) $rawOrientation <= 8) {
            $orientation = (int) $rawOrientation;

            return $orientation === 1 ? false : $this->rewriteUpright($absPath, $orientation);
        }

        // Absent or invalid (e.g. 0) — the tag gives no actionable direction.
        // Verified exception: the HUAWEI/HONOR orientation-0/absent firmware
        // quirk (see class docblock). Gated on the defect's own signature so
        // this never fires for a device/value combination we have no evidence about.
        $make = trim((string) ($exif['Make'] ?? ''));
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);

        $matchedMake = null;
        foreach (self::HEURISTIC_MAKES as $known) {
            if (strcasecmp($make, $known) === 0) {
                $matchedMake = $known;
                break;
            }
        }

        if ($width > 0 && $width < $height && $matchedMake !== null) {
            $this->logBestEffort('info', "Image orientation: applied {$matchedMake} orientation heuristic (90 CCW)", [
                'path' => $absPath,
                'make' => $make,
                'model' => $exif['Model'] ?? null,
                'software' => $exif['Software'] ?? null,
                'raw_orientation' => $rawOrientation,
            ]);

            return $this->rewriteUpright($absPath, 8);
        }

        $this->logBestEffort('warning', 'Image orientation: EXIF orientation missing or invalid, no verified correction available — left as-is', [
            'path' => $absPath,
            'make' => $make !== '' ? $make : null,
            'model' => $exif['Model'] ?? null,
            'raw_orientation' => $rawOrientation,
            'width' => $width,
            'height' => $height,
        ]);

        return false;
    }

    /** Load, rotate to the given EXIF orientation, and re-save in place. */
    private function rewriteUpright(string $absPath, int $orientation): bool
    {
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
     * Log without ever letting logging break normalization — this class is
     * unit-tested via plain PHPUnit with no application container, and
     * observability must always be best-effort, never load-bearing.
     */
    private function logBestEffort(string $level, string $message, array $context): void
    {
        try {
            Log::{$level}($message, $context);
        } catch (\Throwable) {
            // no-op — logging is best-effort only
        }
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
