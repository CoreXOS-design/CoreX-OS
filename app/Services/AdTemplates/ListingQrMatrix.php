<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\DebugWriter;

/**
 * AT-456 §B.9-1 — the QR code of an ad, as data. A QR is a square grid of dark/light modules; this returns
 * that grid for a link, and the render kernel draws it as an inline SVG in whatever colours the element
 * carries. Nothing is fetched from anywhere when an ad is drawn or exported (no CDN, no QR image service),
 * and the picture is exactly the same on the canvas, in a Template Manager preview and in the exported image.
 *
 * Error correction level M (about 15% of the code can be damaged and it still scans) — the usual balance for
 * a code printed small on a social image. A link too long to fit returns [] and the kernel simply leaves the
 * element off the ad.
 */
final class ListingQrMatrix
{
    /** Longest link we will encode; a longer one makes a code too dense to scan at 120 px. */
    public const MAX_URL_LENGTH = 200;

    /**
     * @return array<int, string> one string per row, '1' = dark module, '0' = light; [] when the link cannot be encoded.
     */
    public static function forUrl(?string $url): array
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('#^https?://#i', $url) !== 1) {
            return [];
        }

        try {
            $qr = new QrCode(data: $url, errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 300, margin: 0);
            $m  = (new DebugWriter())->write($qr)->getMatrix();
            $n  = $m->getBlockCount();
            $rows = [];
            for ($r = 0; $r < $n; $r++) {
                $row = '';
                for ($c = 0; $c < $n; $c++) {
                    $row .= $m->getBlockValue($r, $c) === 1 ? '1' : '0';
                }
                $rows[] = $row;
            }

            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
