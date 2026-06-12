<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

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
 * Two layers of defence, because a brand can arrive in TWO ways:
 *
 *   1. URL / PATH layer (isGenuinePhoto) — substring denylist over the
 *      URL or stored path. Catches a logo whose URL advertises the brand
 *      (cdn.remax.co.za/...) or whose filename says "logo/branding". This
 *      was the original AT-22 item-2 implementation.
 *
 *   2. IMAGE-CONTENT layer (inspectImageBytes / inspectImageFile) — OCR
 *      brand-text match + flat-graphic colour signal over the PIXELS.
 *      Catches a brand that the URL layer is STRUCTURALLY BLIND to: a
 *      competitor uploads a RE/MAX card as the listing's primary photo, the
 *      portal serves it from a neutral CDN URL (images.pp.co.za/listing/...),
 *      and our download job stores it under a system filename
 *      (pp_PP-T5391969.jpg). No substring of the URL or path contains the
 *      brand — it lives only in the rendered pixels. This is exactly how
 *      PRES 87 / v175 leaked the "12 Bairn Street" RE/MAX Coast and Country
 *      card to a live seller report. URL matching can never catch this; only
 *      content inspection can.
 *
 * The content layer runs ONCE at ingress (DownloadListingThumbnail) and on
 * the rescan backfill — never per render — and persists its verdict to
 * prospecting_listings.thumbnail_blocked_reason. The render gate reads that
 * one column.
 *
 * Design bias (locked by the spec): CONSERVATIVE. Prefer false-negatives
 * (let a real photo through) over false-positives (never blank a genuine
 * property photo). The denylist tokens and the flat-graphic thresholds are
 * chosen with a wide margin below any real photograph (measured: genuine PP
 * listing photos sit at ~195-283 quantised colours / ~7.3 luminance entropy;
 * a flat brand card sits at ~26 / ~2.0 — the gate fires only well inside the
 * graphic band).
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
     * Brand signatures matched against OCR-extracted IMAGE TEXT. Normalised to
     * [a-z0-9] only — both the OCR output and these tokens are stripped of all
     * punctuation/whitespace before comparison, so 'RE/MAX', 'RE-MAX' and
     * 'RE MAX' all collapse to 'remax'. Tokens are deliberately long/distinctive
     * (a competitor's full marque) to keep OCR false-positives near zero — a
     * genuine property photo essentially never contains these strings.
     */
    private const BRAND_TEXT_TOKENS = [
        'remax',
        'pamgolding',
        'seeff',
        'tysonproperties',
        'harcourts',
        'rawson',
        'engelvolkers',
        'chaseveritt',
        'justproperty',
        'onlyrealty',
        'leapfrog',
        'coastandcountry', // RE/MAX "Coast and Country" franchise wordmark
    ];

    /**
     * Flat-graphic thresholds (item 2 catch-all). An asset whose pixels carry
     * very few distinct colours AND very low luminance entropy is a graphic
     * (logo card, "coming soon" banner, watermark plate) — not a continuous-
     * tone photograph. Both conditions must hold; the band is set far below the
     * real-photo floor (see class docblock) so a genuine photo is never blanked.
     */
    private const GRAPHIC_MAX_UNIQUE_COLORS = 64;   // real photos measured >=195
    private const GRAPHIC_MAX_ENTROPY       = 4.0;  // real photos measured >=7.25

    /** Cached tesseract-availability probe (null = not yet probed). */
    private static ?bool $tesseractAvailable = null;

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

    // ---------------------------------------------------------------------
    // IMAGE-CONTENT layer (AT-22 item 2) — inspect the PIXELS, not the URL.
    // ---------------------------------------------------------------------

    /**
     * Inspect a stored image file's CONTENT for a competitor brand.
     *
     * @param  string|null  $absolutePath  Resolved absolute file path.
     * @return array{genuine: bool, reason: ?string, ocr_ran: bool, signals: array{unique_colors: int, entropy: float}}
     *         reason is null when genuine; otherwise a provenance string such
     *         as 'brand:remax' or 'graphic' (persist verbatim to
     *         thumbnail_blocked_reason).
     */
    public function inspectImageFile(?string $absolutePath): array
    {
        if ($absolutePath === null || trim($absolutePath) === '' || ! is_file($absolutePath)) {
            return $this->genuineVerdict();
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return $this->genuineVerdict();
        }

        return $this->inspectImageBytes($bytes);
    }

    /**
     * Inspect raw image bytes for a competitor brand. Two signals:
     *
     *   1. OCR brand-text match — tesseract reads the rendered text; if it
     *      contains a known competitor marque, reason = 'brand:<token>'.
     *   2. Flat-graphic colour signal — too few colours AND too little
     *      luminance entropy to be a photograph, reason = 'graphic'.
     *
     * CONSERVATIVE on every failure path: undecodable bytes, a missing
     * tesseract binary, or an OCR error all degrade to "genuine" (reason null)
     * so a real photo is never blanked on the strength of a tooling gap. The
     * URL/path layer and the flat-graphic signal still apply where they can.
     *
     * @return array{genuine: bool, reason: ?string, ocr_ran: bool, signals: array{unique_colors: int, entropy: float}}
     */
    public function inspectImageBytes(?string $bytes): array
    {
        if ($bytes === null || $bytes === '') {
            return $this->genuineVerdict();
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            // Not decodable as an image — leave the verdict to the caller's
            // existing path checks; do not assert a brand we cannot read.
            return $this->genuineVerdict();
        }

        try {
            $signals = $this->colorSignals($image);

            // 1. OCR brand-text match — the precise, primary signal.
            $ocrText = $this->ocrImage($image);
            $ocrRan  = $ocrText !== null;
            if ($ocrRan) {
                $token = $this->matchBrandToken($ocrText);
                if ($token !== null) {
                    return [
                        'genuine' => false,
                        'reason'  => 'brand:' . $token,
                        'ocr_ran' => true,
                        'signals' => $signals,
                    ];
                }
            }

            // 2. Flat-graphic catch-all — a logo card the OCR could not read
            //    (stylised wordmark, missing binary) is still not a photo.
            if ($signals['unique_colors'] <= self::GRAPHIC_MAX_UNIQUE_COLORS
                && $signals['entropy'] < self::GRAPHIC_MAX_ENTROPY) {
                return [
                    'genuine' => false,
                    'reason'  => 'graphic',
                    'ocr_ran' => $ocrRan,
                    'signals' => $signals,
                ];
            }

            return [
                'genuine' => true,
                'reason'  => null,
                'ocr_ran' => $ocrRan,
                'signals' => $signals,
            ];
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * True when OCR is available on this host. Surfaced so the rescan command
     * can warn an operator that brand-text detection is inert (the flat-graphic
     * signal still runs). Result is process-cached.
     */
    public function ocrAvailable(): bool
    {
        if (self::$tesseractAvailable !== null) {
            return self::$tesseractAvailable;
        }

        try {
            $probe = new Process(['tesseract', '--version']);
            $probe->setTimeout(10);
            $probe->run();
            self::$tesseractAvailable = $probe->isSuccessful();
        } catch (\Throwable) {
            self::$tesseractAvailable = false;
        }

        if (self::$tesseractAvailable === false) {
            Log::warning('ListingImageValidator: tesseract OCR binary unavailable — competitor brand-TEXT detection is inert; only the flat-graphic + URL/path signals apply. Install tesseract-ocr on this host for full coverage (AT-22 item 2).');
        }

        return self::$tesseractAvailable;
    }

    /**
     * Run tesseract over a GD image, returning the extracted text, or null if
     * OCR is unavailable or failed. The image is re-encoded to PNG first so the
     * input format (jpg/png/webp) is irrelevant to the OCR step.
     */
    private function ocrImage(\GdImage $image): ?string
    {
        if (! $this->ocrAvailable()) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'liv_ocr_');
        if ($tmp === false) {
            return null;
        }
        $pngPath = $tmp . '.png';

        try {
            if (! imagepng($image, $pngPath)) {
                return null;
            }

            // --psm 6: treat the card as a single uniform block of text — the
            // right mode for a logo/banner plate. Output to stdout.
            $process = new Process(['tesseract', $pngPath, 'stdout', '--psm', '6']);
            $process->setTimeout(20);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            return $process->getOutput();
        } catch (\Throwable $e) {
            Log::warning('ListingImageValidator: OCR failed — ' . $e->getMessage());
            return null;
        } finally {
            @unlink($tmp);
            @unlink($pngPath);
        }
    }

    /**
     * Match OCR text against the brand denylist. Returns the matched token
     * (e.g. 'remax') or null. Both sides are normalised to [a-z0-9] so
     * 'RE/MAX', 'RE-MAX', 'RE MAX' all collapse to 'remax'.
     */
    private function matchBrandToken(string $ocrText): ?string
    {
        $normalised = preg_replace('/[^a-z0-9]/', '', strtolower($ocrText)) ?? '';
        if ($normalised === '') {
            return null;
        }

        foreach (self::BRAND_TEXT_TOKENS as $token) {
            if (str_contains($normalised, $token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * Colour-diversity signals over a 100px-wide downsample: the count of
     * distinct 12-bit (4 bits/channel) quantised colours and the Shannon
     * entropy of the luminance histogram. A photograph is rich in both; a flat
     * graphic is poor in both.
     *
     * @return array{unique_colors: int, entropy: float}
     */
    private function colorSignals(\GdImage $image): array
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if ($w < 1 || $h < 1) {
            return ['unique_colors' => 0, 'entropy' => 0.0];
        }

        $nw = min(100, $w);
        $nh = max(1, (int) round($h * ($nw / $w)));
        $sample = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $colors = [];
        $lum    = array_fill(0, 256, 0);
        $total  = $nw * $nh;

        for ($y = 0; $y < $nh; $y++) {
            for ($x = 0; $x < $nw; $x++) {
                $rgb = imagecolorat($sample, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $colors[(($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4)] = true;
                $lum[(int) round(0.299 * $r + 0.587 * $g + 0.114 * $b)]++;
            }
        }
        imagedestroy($sample);

        $entropy = 0.0;
        foreach ($lum as $count) {
            if ($count > 0) {
                $p = $count / $total;
                $entropy -= $p * log($p, 2);
            }
        }

        return [
            'unique_colors' => count($colors),
            'entropy'       => round($entropy, 2),
        ];
    }

    /** The conservative "show it" verdict used on every undecidable path. */
    private function genuineVerdict(): array
    {
        return [
            'genuine' => true,
            'reason'  => null,
            'ocr_ran' => false,
            'signals' => ['unique_colors' => 0, 'entropy' => 0.0],
        ];
    }
}
