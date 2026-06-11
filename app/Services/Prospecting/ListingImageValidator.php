<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * ListingImageValidator — the single source of truth for "is this a genuine
 * property photo, or is it a logo / icon / tracking pixel / agency brand?"
 *
 * AT-22 items 2 + 7. A competitor logo on a Home Finders seller report is
 * unshippable (item 2); a missing or wrong image is the inverse failure
 * (item 7). Both converge here: the seller surfaces emit a thumbnail ONLY
 * when this validator says it is a genuine photo — otherwise the neutral
 * "No photo" placeholder fires. A logo is NEVER shown.
 *
 * Heuristic origin: this promotes/centralises the heuristic that previously
 * lived inline in
 *   PortalCaptureController::isValidListingImageUrl()
 * (reject icon_, /logo, tracking, .svg, 1x1, pixel) and EXTENDS it with an
 * agency-logo denylist (remax, pamgolding, seeff, tyson) plus generic
 * branding tokens (logo / branding / watermark). PortalCaptureController is
 * deliberately left UNCHANGED in this pass — the ~12-line duplication there
 * is acceptable for now; a follow-up can repoint it at this validator.
 *
 * Design bias (locked by the spec): CONSERVATIVE. Prefer false-negatives
 * (let a real photo through) over false-positives (never blank a genuine
 * property photo). The denylist tokens are chosen to be unambiguous markers
 * of a non-photo asset.
 */
class ListingImageValidator
{
    /**
     * Tokens that, when present anywhere in the URL/path, mark the asset as
     * a NON-photo (icon, logo, tracker, vector, pixel) — the original
     * PortalCaptureController heuristic.
     */
    private const NON_PHOTO_TOKENS = [
        'icon_',
        '/logo',
        'logo_',
        '-logo',
        '_logo',
        'tracking',
        '.svg',
        '1x1',
        'pixel',
    ];

    /**
     * Generic branding tokens — an asset whose path advertises itself as
     * branding/watermark is not a property photo.
     */
    private const BRANDING_TOKENS = [
        'logo',
        'branding',
        'watermark',
    ];

    /**
     * Known agency-brand host/path tokens. A captured "image" that is in
     * fact one of these agencies' marque is a branding leak — blank it and
     * fall back to the placeholder. Conservative, lower-cased, matched as a
     * substring of the URL/path.
     */
    private const AGENCY_LOGO_TOKENS = [
        'remax',
        're-max',
        'pamgolding',
        'pam-golding',
        'seeff',
        'tyson',
    ];

    /**
     * Is the given URL OR stored file path a genuine property photo (i.e.
     * safe to render on a seller-facing surface)?
     *
     * Returns false for empty/null, for any non-photo token, for branding
     * tokens, and for known agency-logo tokens. Returns true otherwise —
     * the conservative default so real photos are never blanked.
     */
    public function isGenuinePhoto(?string $urlOrPath): bool
    {
        if ($urlOrPath === null) {
            return false;
        }

        $value = trim($urlOrPath);
        if ($value === '') {
            return false;
        }

        $lower = strtolower($value);

        // Original PortalCaptureController heuristic — non-photo assets.
        foreach (self::NON_PHOTO_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return false;
            }
        }

        // Generic branding / watermark markers.
        foreach (self::BRANDING_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return false;
            }
        }

        // Known third-party agency brands (item 2 — competitor logo leak).
        foreach (self::AGENCY_LOGO_TOKENS as $token) {
            if (str_contains($lower, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convenience for a stored file path: the file must EXIST on disk AND
     * pass isGenuinePhoto(). Used at render-time in scoreAndMapRow so a
     * thumbnail is emitted only when both the bits are present and the
     * source is a real photo (items 2 + 7 share this rule).
     *
     * @param  string|null  $absolutePath  Resolved absolute file path.
     */
    public function isGenuineStoredPhoto(?string $absolutePath): bool
    {
        if ($absolutePath === null || trim($absolutePath) === '') {
            return false;
        }

        if (! is_file($absolutePath)) {
            return false;
        }

        return $this->isGenuinePhoto($absolutePath);
    }
}
