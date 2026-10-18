<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * THE guard on every agency-logo upload (Company Settings, Settings -> Branding, the platform Agency screens). Platform-wide, not a
 * per-agency setting: a logo is shown at about 200 x 38 CSS pixels, so a huge picture buys nothing and costs every visitor a full decode
 * (8 Oct 2026: QA1's own logo was 10629 x 3543 pixels / 37.6 megapixels and froze a real browser; the portal now also serves a small
 * copy - App\Support\PortalLogo - but the original should never be that big in the first place).
 *
 * Refuses with a plain sentence that says what was wrong and what to do. Limits: JPG / PNG / WebP, file at most 2 MB (unchanged from the
 * old rule), longest side at most 4000 pixels, and a real picture (readable, at least 1 pixel each way).
 */
class AgencyLogoFile implements ValidationRule
{
    public const MAX_KB = 2048;
    public const MAX_SIDE_PX = 4000;
    public const ALLOWED = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;   // nothing uploaded (nullable) - not this rule's business
        }
        if (! $value->isValid()) {
            $fail('The logo could not be uploaded. Please try again.');

            return;
        }

        $kb = (int) ceil(($value->getSize() ?: 0) / 1024);
        if ($kb > self::MAX_KB) {
            $fail('Your logo file is ' . self::sizeText((int) $value->getSize()) . '. Please use one under ' . (self::MAX_KB / 1024) . ' MB (a logo only needs a few hundred kilobytes).');

            return;
        }

        $info = @getimagesize($value->getRealPath());
        if (! $info || ! isset(self::ALLOWED[$info[2] ?? 0]) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            $fail('The logo must be a JPG, PNG or WebP picture.');

            return;
        }

        [$w, $h] = $info;
        if (max($w, $h) > self::MAX_SIDE_PX) {
            $fail("Your logo is {$w} × {$h} pixels. Please use a picture no larger than " . self::MAX_SIDE_PX . ' pixels on its longest side — a logo is shown at about 200 pixels wide, so 600 to 1500 pixels is plenty.');
        }
    }

    private static function sizeText(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, $bytes / 1024)) . ' KB';
    }
}
