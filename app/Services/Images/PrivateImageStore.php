<?php

namespace App\Services\Images;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores evidence photos for the PRIVATE disk (audit 2026-10-19 B21): the WRITE half. Reading - the `private:` reference, the signed
 * expiring URL, the guards that revoke it, the controller that serves it - is the shared {@see PrivateImageService} (security audit D14),
 * so a stored reference here is read exactly like every other private picture.
 *
 * Why not PropertyImageStorer: tenant-home photos used to go to the public disk under properties/{id}/, an unguessable-but-PERMANENT URL that
 * survived a revoked link, an archived inspection or a cancelled one. Here the file is not web-reachable at all.
 *
 * Every stored image is RE-ENCODED (upright, capped at $maxEdge, JPEG), which drops ALL embedded metadata - including the GPS position a
 * phone writes into the EXIF (PropertyImageStorer only re-encodes oversize files, so a normal-sized JPEG kept its GPS). A file GD cannot
 * decode (HEIC) goes through Imagick when this PHP has it; failing that it is stored as it arrived and the gap is logged - evidence is never
 * refused over a format.
 */
class PrivateImageStore
{
    public const DISK = PrivateImageService::DISK;
    public const PREFIX = PrivateImageService::PREFIX;

    public function __construct(
        private int $maxEdge = 2560,
        private int $quality = 85,
    ) {
    }

    public static function isReference(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /** The path on the private disk behind a reference. */
    public static function pathOf(string $reference): string
    {
        return substr($reference, strlen(self::PREFIX));
    }

    /**
     * Store one uploaded image under $directory (inside the shared private-images folder of the private disk) and return its reference.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $directory = PrivateImageService::DIR . '/' . trim($directory, '/');
        $path = $directory . '/' . Str::uuid() . '.jpg';
        $disk = Storage::disk(self::DISK);

        $bytes = $this->cleanJpegBytes($file);
        if ($bytes === null) {
            // not decodable here (HEIC with no Imagick, a corrupt file): keep it as it arrived rather than lose evidence
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $path = $directory . '/' . Str::uuid() . '.' . preg_replace('/[^a-z0-9]/', '', $extension);
            $bytes = (string) file_get_contents($file->getRealPath());
            Log::warning('PrivateImageStore: could not re-encode an upload - stored as received, embedded metadata (EXIF/GPS) NOT stripped', ['path' => $path]);
        }

        $disk->put($path, $bytes);
        if (! $disk->exists($path) || $disk->size($path) < 1) {
            throw new \RuntimeException("PrivateImageStore: \"{$path}\" is not on the private disk after the write - refusing to hand back a reference to nothing.");
        }

        return self::PREFIX . $path;
    }

    /**
     * Re-encode an image that is already stored on the PUBLIC disk (the legacy location) into the private disk and return its reference.
     * Used by the one-off move of older inspection photos; the public original is left for the caller to remove.
     */
    public function importFromPublic(string $publicRelativePath, string $directory): ?string
    {
        $public = Storage::disk('public');
        if (! $public->exists($publicRelativePath)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pis');
        try {
            file_put_contents($tmp, $public->get($publicRelativePath));
            $upload = new UploadedFile($tmp, basename($publicRelativePath), null, null, true);

            return $this->store($upload, $directory);
        } finally {
            @unlink($tmp);
        }
    }

    public function exists(string $reference): bool
    {
        return self::isReference($reference) && Storage::disk(self::DISK)->exists(self::pathOf($reference));
    }

    /**
     * The upright, size-capped, metadata-free JPEG bytes for an upload - or null when no decoder here can read it.
     */
    private function cleanJpegBytes(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (! $path || ! is_file($path)) {
            return null;
        }
        $bytes = (string) file_get_contents($path);

        if (function_exists('imagecreatefromstring') && ($info = @getimagesizefromstring($bytes))) {
            // bake the EXIF orientation into the pixels BEFORE the re-encode drops the tag (a portrait shot would otherwise come out sideways)
            $work = tempnam(sys_get_temp_dir(), 'pio');
            try {
                file_put_contents($work, $bytes);
                app(ImageOrientationNormalizer::class)->normalizeInPlace($work);
                $bytes = (string) file_get_contents($work);
            } finally {
                @unlink($work);
            }

            $src = @imagecreatefromstring($bytes);
            if ($src instanceof \GdImage) {
                [$width, $height] = [imagesx($src), imagesy($src)];
                $scale = max($width, $height) > $this->maxEdge ? $this->maxEdge / max($width, $height) : 1.0;
                $newWidth = max(1, (int) round($width * $scale));
                $newHeight = max(1, (int) round($height * $scale));
                $dst = imagecreatetruecolor($newWidth, $newHeight);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // a transparent PNG must not turn black as a JPEG
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($src);
                ob_start();
                imagejpeg($dst, null, $this->quality);
                $out = (string) ob_get_clean();
                imagedestroy($dst);

                return $out !== '' ? $out : null;
            }
        }

        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick();
                $im->readImageBlob($bytes);
                $im->autoOrient();
                $im->stripImage(); // profiles and EXIF, GPS included
                if (max($im->getImageWidth(), $im->getImageHeight()) > $this->maxEdge) {
                    $im->thumbnailImage($this->maxEdge, $this->maxEdge, true);
                }
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality($this->quality);
                $out = $im->getImageBlob();
                $im->clear();

                return $out !== '' ? $out : null;
            } catch (\Throwable $e) {
                Log::info('PrivateImageStore: Imagick could not read the upload', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }
}
