<?php

namespace App\Support;

use App\Models\Agency;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The agency logo AS THE PORTAL HEADER SHOWS IT. The header draws the logo at most 200 x 38 CSS pixels, but an agency uploads whatever
 * file it has: QA1's own logo is 10629 x 3543 pixels (37.6 megapixels, 1.2 MB), which a browser must fully decode on every portal page
 * and again whenever the page repaints - the renderer froze for seconds, twice, in a real Chrome on the owner's Faults tab and on the
 * first paint of a personal link (8 Oct 2026). So the portal is handed a small copy (at most 640 px wide) made once from the original
 * and kept beside it; the original stays untouched for PDFs, mail and the office screens. Anything that goes wrong (no GD, unreadable
 * file, no memory) falls back to the original URL - the header is never left without a logo because a resize failed.
 */
class PortalLogo
{
    public const MAX_WIDTH = 640;
    public const MAX_HEIGHT = 160;
    /** A logo already this small is served as it is. */
    private const SMALL_BYTES = 150000;

    public static function urlFor(int $agencyId): ?string
    {
        $agency = Agency::withoutGlobalScopes()->find($agencyId);
        if (! $agency || ! $agency->logo_path) {
            return null;
        }
        $original = asset('storage/' . $agency->logo_path);

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($agency->logo_path)) {
                return $original;
            }
            $source = $disk->path($agency->logo_path);
            $info = @getimagesize($source);
            if (! $info) {
                return $original;
            }
            [$w, $h] = $info;
            if ($w <= self::MAX_WIDTH && $h <= self::MAX_HEIGHT * 4 && filesize($source) <= self::SMALL_BYTES) {
                return $original;
            }

            $ext = ($info[2] === IMAGETYPE_PNG) ? 'png' : 'jpg';
            $copy = 'agencies/' . $agency->id . '/portal-logo-' . substr(md5($agency->logo_path . '|' . filemtime($source) . '|' . self::MAX_WIDTH), 0, 12) . '.' . $ext;
            if (! $disk->exists($copy) && ! self::make($source, $info, $disk->path($copy))) {
                return $original;
            }

            return asset('storage/' . $copy);
        } catch (\Throwable $e) {
            Log::warning('Portal logo: could not make the small copy, serving the original', ['agency_id' => $agencyId, 'error' => $e->getMessage()]);

            return $original;
        }
    }

    /** @param array<int, mixed> $info getimagesize() result */
    private static function make(string $source, array $info, string $target): bool
    {
        if (! function_exists('imagecreatetruecolor')) {
            return false;
        }
        [$w, $h, $type] = $info;
        $create = match ($type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null,
        };
        if (! $create || ! function_exists($create)) {
            return false;
        }

        $previous = ini_get('memory_limit');
        @ini_set('memory_limit', '1024M');   // one-off: a 37-megapixel original needs ~150 MB to open
        try {
            $src = @$create($source);
            if (! $src) {
                return false;
            }
            $scale = min(self::MAX_WIDTH / $w, self::MAX_HEIGHT / $h, 1);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            } else {
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            @mkdir(dirname($target), 0775, true);
            $tmp = $target . '.tmp' . getmypid();
            $ok = ($type === IMAGETYPE_PNG) ? imagepng($dst, $tmp, 7) : imagejpeg($dst, $tmp, 88);
            imagedestroy($src);
            imagedestroy($dst);
            if (! $ok) {
                @unlink($tmp);

                return false;
            }
            @chmod($tmp, 0664);

            return rename($tmp, $target);
        } finally {
            @ini_set('memory_limit', (string) $previous);
        }
    }
}
