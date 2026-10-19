<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;

/**
 * Audit fixes 19 Oct 2026 (C-I1 / B19, spec rentals-faults-work-orders.md section 29): ONE place that turns an iPhone HEIC / HEIF photo into an upright, downscaled
 * JPEG. GD cannot read HEIC, so the old PropertyImageStorer stored it as-is (sideways, full size, unreadable on most desktops). Uses the Imagick extension
 * (HEIC delegate present on this stack). Absorb, never break: when conversion is impossible the caller keeps the original file.
 */
class HeicConverter
{
    public const MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];
    public const EXTENSIONS = ['heic', 'heif'];
    /** The `mimes:` list for every photo upload (section 29, C-I1): one list, so no endpoint can quietly reject what another accepts. */
    public const UPLOAD_MIMES = 'jpg,jpeg,png,webp,heic,heif';

    /** HEIC / HEIF by mime, by extension, or by the "ftyp" brand in the first bytes (a phone often sends application/octet-stream). */
    public static function isHeic(UploadedFile|string $file): bool
    {
        if ($file instanceof UploadedFile) {
            $mime = strtolower((string) $file->getMimeType());
            $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
            if (in_array($mime, self::MIMES, true) || in_array($ext, self::EXTENSIONS, true)) {
                return true;
            }
            $path = $file->getRealPath();
        } else {
            $path = $file;
        }
        if (! $path || ! is_file($path)) {
            return false;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 16);

        return strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true);
    }

    public static function available(): bool
    {
        if (! class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return in_array('HEIC', \Imagick::queryFormats('HEIC'), true) || in_array('HEIF', \Imagick::queryFormats('HEIF'), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Write an upright JPEG (longest edge at most $maxEdge) of the HEIC at $source to $destination. The EXIF block (capture time) is kept; the orientation tag is reset
     * because the pixels are rotated. Returns false when it could not (the caller then keeps the original).
     */
    public static function convert(string $source, string $destination, int $maxEdge = 2560, int $quality = 85): bool
    {
        if (! self::available()) {
            return false;
        }
        try {
            $im = new \Imagick();
            $im->readImage($source . '[0]');
            $im->autoOrient();
            if (max($im->getImageWidth(), $im->getImageHeight()) > $maxEdge) {
                $im->thumbnailImage($maxEdge, $maxEdge, true);
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);
            $im->setImageBackgroundColor('white');
            $flat = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $ok = $flat->writeImage($destination);
            $flat->clear();
            $im->clear();

            return $ok && is_file($destination) && filesize($destination) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The EXIF DateTimeOriginal of a HEIC as "Y:m:d H:i:s" (+ offset when stored), or null - read BEFORE conversion so the capture time is never lost. */
    public static function exifDateTime(string $source): ?array
    {
        if (! self::available()) {
            return null;
        }
        try {
            $im = new \Imagick();
            $im->pingImage($source . '[0]');
            $raw = $im->getImageProperty('exif:DateTimeOriginal') ?: $im->getImageProperty('exif:DateTimeDigitized');
            $offset = $im->getImageProperty('exif:OffsetTimeOriginal') ?: $im->getImageProperty('exif:OffsetTimeDigitized');
            $im->clear();

            return is_string($raw) && $raw !== '' ? [trim($raw), is_string($offset) && $offset !== '' ? trim($offset) : null] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
