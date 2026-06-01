<?php

declare(strict_types=1);

namespace App\Services\Presentations\Pdf;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CMA-map static-image renderer for the presentation PDF.
 *
 * Constructs a Google Static Maps URL with subject + sold-comp +
 * competition markers, fetches the PNG server-side at build time,
 * and returns it as a base64 data URI ready to embed in the
 * presentation HTML. Self-contained when the PDF prints — no
 * network requests during Puppeteer's `waitUntil: 'load'` window
 * (see scripts/html-to-pdf.mjs comment about avoiding tile races).
 *
 * Configuration:
 *   services.google.static_maps_api_key — defaults to the
 *   GOOGLE_GEOCODING_API_KEY (same Google Cloud project; the agency
 *   just enables the Maps Static API on it).
 *   agencies.presentations_map_provider — 'static_image' selects
 *   this service; 'svg_radial' falls back to the polar SVG.
 *
 * Gracefully returns null when:
 *   - no API key configured (agency hasn't enabled Maps Static)
 *   - subject has no GPS (no centre to compute the viewport from)
 *   - HTTP fetch fails (network down, quota exceeded)
 * → caller falls back to SpatialViewSvgRenderer.
 *
 * Marker palette mirrors the review map:
 *   Subject       teal hex      color:0x00d4aa label:S
 *   Sold comp     navy circle   color:0x0b2a4a (full_title default)
 *                 purple        color:0x7c3aed (sectional_title)
 *                 cyan          color:0x06b6d4 (vacant_land)
 *   Competition   amber         color:0xf59e0b label:C
 *
 * Google Static Maps supports up to ~512 chars per URL on free tier
 * (longer with paid). We cap markers at 80 total to stay under the
 * URL length limit on the worst case.
 */
final class PresentationStaticMapService
{
    public const MAX_MARKERS = 80;
    public const DEFAULT_WIDTH  = 640;
    public const DEFAULT_HEIGHT = 480;
    public const DEFAULT_ZOOM   = 14;
    public const HTTP_TIMEOUT_SEC = 10;

    /**
     * @param array{lat:float,lng:float,title?:string|null} $subject
     * @param array<int, array{lat:float,lng:float,title_type?:string|null}> $soldComps
     * @param array<int, array{latitude?:?float,longitude?:?float,address?:?string|null}> $competition
     * @return ?string Base64 PNG data URI ("data:image/png;base64,...") or null on failure / no key.
     */
    public function renderBase64(
        array $subject,
        array $soldComps = [],
        array $competition = [],
        int $width  = self::DEFAULT_WIDTH,
        int $height = self::DEFAULT_HEIGHT,
    ): ?string {
        $key = (string) config('services.google.static_maps_api_key', '');
        if ($key === '') return null;

        if (!isset($subject['lat'], $subject['lng'])) return null;
        $subjectLat = (float) $subject['lat'];
        $subjectLng = (float) $subject['lng'];

        $url = $this->buildUrl($subjectLat, $subjectLng, $soldComps, $competition, $width, $height, $key);

        try {
            $resp = Http::timeout(self::HTTP_TIMEOUT_SEC)->get($url);
            if (!$resp->ok()) {
                Log::warning('PresentationStaticMapService: HTTP non-OK', [
                    'status' => $resp->status(),
                    'body'   => mb_substr((string) $resp->body(), 0, 200),
                ]);
                return null;
            }
            $body = $resp->body();
            if ($body === '' || $body === false) return null;
            // Sanity check — must look like a PNG (8-byte magic).
            if (substr($body, 0, 4) !== "\x89PNG") {
                Log::warning('PresentationStaticMapService: response not a PNG');
                return null;
            }
            return 'data:image/png;base64,' . base64_encode($body);
        } catch (\Throwable $e) {
            Log::warning('PresentationStaticMapService: fetch failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build the Google Static Maps URL with the three marker layers.
     * Cap total markers at MAX_MARKERS so the URL stays under the
     * length limit. Subject always present (1 marker); soldComps +
     * competition split the remainder evenly when over the cap.
     */
    private function buildUrl(
        float $subjectLat,
        float $subjectLng,
        array $soldComps,
        array $competition,
        int $width,
        int $height,
        string $key,
    ): string {
        $params = [
            'size'    => $width . 'x' . $height,
            'scale'   => 2,                                  // retina-quality
            'maptype' => 'roadmap',
            'key'     => $key,
        ];

        // Filter to those with real coords. The map plots only honest
        // pins — never a fallback. Caller's caption already surfaces
        // the unplotted count.
        $soldPlottable = array_values(array_filter($soldComps, fn ($c) =>
            isset($c['lat'], $c['lng']) && is_numeric($c['lat']) && is_numeric($c['lng'])
        ));
        $compPlottable = array_values(array_filter($competition, fn ($c) =>
            !empty($c['latitude']) && !empty($c['longitude'])
        ));

        // Budget: 1 for subject, split remainder.
        $remaining = max(0, self::MAX_MARKERS - 1);
        $soldCount = count($soldPlottable);
        $compCount = count($compPlottable);
        $total = $soldCount + $compCount;
        if ($total > $remaining) {
            // Proportional cut — keep at least 1 per layer if any.
            $soldShare = (int) floor($remaining * ($soldCount / max(1, $total)));
            $compShare = $remaining - $soldShare;
            $soldPlottable = array_slice($soldPlottable, 0, $soldShare);
            $compPlottable = array_slice($compPlottable, 0, $compShare);
        }

        // Marker spec lines — Google Static Maps `markers=` repeated.
        $markerLines = [];

        // Subject — teal hex stand-in (Google has no hex shape; use
        // a distinct large green pin with label "S").
        $markerLines[] = sprintf(
            'color:0x00d4aa|label:S|size:mid|%s,%s',
            number_format($subjectLat, 6, '.', ''),
            number_format($subjectLng, 6, '.', ''),
        );

        // Sold comps — colour by title_type (mirrors the review map
        // palette as close as Google's predefined colours allow; Google
        // Static Maps accepts hex via 0xRRGGBB).
        foreach ($soldPlottable as $c) {
            $tt = $c['title_type'] ?? 'full_title';
            $colour = match ($tt) {
                'sectional_title' => '0x7c3aed',
                'vacant_land'     => '0x06b6d4',
                default           => '0x0b2a4a',  // full_title + other
            };
            $markerLines[] = sprintf(
                'color:%s|size:small|%s,%s',
                $colour,
                number_format((float) $c['lat'], 6, '.', ''),
                number_format((float) $c['lng'], 6, '.', ''),
            );
        }

        // Competition stock — amber, distinct label "C" to read as the
        // different stratum the review-map orange diamond conveys.
        foreach ($compPlottable as $c) {
            $markerLines[] = sprintf(
                'color:0xf59e0b|label:C|size:small|%s,%s',
                number_format((float) $c['latitude'],  6, '.', ''),
                number_format((float) $c['longitude'], 6, '.', ''),
            );
        }

        // Build the URL. `markers=` is repeated once per marker spec,
        // not joined. URLs over 8192 chars hit Google's hard limit;
        // MAX_MARKERS=80 with ~50 chars each stays well below.
        $query = http_build_query($params);
        foreach ($markerLines as $line) {
            $query .= '&markers=' . rawurlencode($line);
        }

        return 'https://maps.googleapis.com/maps/api/staticmap?' . $query;
    }
}
