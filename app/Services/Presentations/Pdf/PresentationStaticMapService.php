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
     * AT-22 item 3: comp markers now carry `label:N` numbered pins keyed to
     * the same ordered legend the SVG path emits, so the static-image path
     * gets an identical HTML legend. Subject stays `label:S`.
     *
     * @param array{lat:float,lng:float,title?:string|null} $subject
     * @param array<int, array{lat:float,lng:float,title?:string|null,title_type?:string|null,price?:?int,sale_date?:?string,layer?:?string}> $soldComps
     * @param array<int, array{latitude?:?float,longitude?:?float,address?:?string|null,title?:string|null,price?:?int}> $competition
     *
     * @return array{data_uri: ?string, legend: array<int, array{index:int, title:string, price:?int, sale_date:?string, distance_m:?int, layer:string, colour:string}>}
     *         `data_uri` is the base64 PNG ("data:image/png;base64,...") or
     *         null on failure / no key (caller falls back to the SVG path).
     *         `legend` is the ordered point list — index N ↔ the marker
     *         labelled N on the map — for the caller's HTML legend. The
     *         legend is built even when the fetch fails so the caller can
     *         decide; it is only empty when there is no subject GPS.
     */
    public function renderBase64(
        array $subject,
        array $soldComps = [],
        array $competition = [],
        int $width  = self::DEFAULT_WIDTH,
        int $height = self::DEFAULT_HEIGHT,
    ): array {
        $key = (string) config('services.google.static_maps_api_key', '');
        if ($key === '') {
            return ['data_uri' => null, 'legend' => []];
        }

        if (!isset($subject['lat'], $subject['lng'])) {
            return ['data_uri' => null, 'legend' => []];
        }
        $subjectLat = (float) $subject['lat'];
        $subjectLng = (float) $subject['lng'];

        [$url, $legend] = $this->buildUrl($subjectLat, $subjectLng, $soldComps, $competition, $width, $height, $key);

        try {
            $resp = Http::timeout(self::HTTP_TIMEOUT_SEC)->get($url);
            if (!$resp->ok()) {
                Log::warning('PresentationStaticMapService: HTTP non-OK', [
                    'status' => $resp->status(),
                    'body'   => mb_substr((string) $resp->body(), 0, 200),
                ]);
                return ['data_uri' => null, 'legend' => $legend];
            }
            $body = $resp->body();
            if ($body === '' || $body === false) {
                return ['data_uri' => null, 'legend' => $legend];
            }
            // Sanity check — must look like a PNG (8-byte magic).
            if (substr($body, 0, 4) !== "\x89PNG") {
                Log::warning('PresentationStaticMapService: response not a PNG');
                return ['data_uri' => null, 'legend' => $legend];
            }
            return ['data_uri' => 'data:image/png;base64,' . base64_encode($body), 'legend' => $legend];
        } catch (\Throwable $e) {
            Log::warning('PresentationStaticMapService: fetch failed', ['err' => $e->getMessage()]);
            return ['data_uri' => null, 'legend' => $legend];
        }
    }

    /** Layer → legend swatch colour (CSS hex, mirrors the SVG palette). */
    private const LEGEND_COLOURS = [
        'sold_comps'       => '#3b82f6',
        'active_listings'  => '#f59e0b',
        'competitor_stock' => '#f59e0b',
    ];

    /**
     * Build the Google Static Maps URL with numbered comp markers + an
     * ordered legend keyed to the same indices.
     *
     * AT-22 item 3: each comp gets its own `markers=` block with
     * `label:N` so the pin number and the HTML legend share ONE source
     * of truth. The subject stays `label:S`. Sold comps are numbered
     * first, then competition — the SAME order the SVG path uses (sold +
     * active first, competition last) so both paths key identically.
     *
     * Google Static Maps `label:` accepts a single alphanumeric glyph
     * only. We label markers 1–9 with the digit and 10–35 with A–Z; the
     * legend carries both the numeric `index` and the rendered
     * `label_glyph` so the HTML legend shows exactly what the pin shows.
     *
     * Cap total markers at MAX_MARKERS so the URL stays under the length
     * limit. Subject always present (1 marker); soldComps + competition
     * split the remainder proportionally when over the cap.
     *
     * @return array{0: string, 1: array<int, array{index:int, label_glyph:string, title:string, price:?int, sale_date:?string, distance_m:?int, layer:string, colour:string}>}
     */
    private function buildUrl(
        float $subjectLat,
        float $subjectLng,
        array $soldComps,
        array $competition,
        int $width,
        int $height,
        string $key,
    ): array {
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
        $legend      = [];

        // Subject — teal stand-in, distinct large pin with label "S".
        $markerLines[] = sprintf(
            'color:0x00d4aa|label:S|size:mid|%s,%s',
            number_format($subjectLat, 6, '.', ''),
            number_format($subjectLng, 6, '.', ''),
        );

        // Ordered comp list: sold/active first, competition last (mirrors
        // the SVG path ordering). Each marker numbered with its index.
        $index = 0;

        foreach ($soldPlottable as $c) {
            $index++;
            $glyph  = $this->labelGlyph($index);
            $tt     = $c['title_type'] ?? 'full_title';
            $colour = match ($tt) {
                'sectional_title' => '0x7c3aed',
                'vacant_land'     => '0x06b6d4',
                default           => '0x0b2a4a',  // full_title + other
            };
            $markerLines[] = sprintf(
                'color:%s|label:%s|size:small|%s,%s',
                $colour,
                $glyph,
                number_format((float) $c['lat'], 6, '.', ''),
                number_format((float) $c['lng'], 6, '.', ''),
            );
            $layer = $c['layer'] ?? 'sold_comps';
            $legend[] = [
                'index'       => $index,
                'label_glyph' => $glyph,
                'title'       => (string) ($c['title'] ?? ('Comp ' . $index)),
                'price'       => isset($c['price']) && $c['price'] !== null ? (int) $c['price'] : null,
                'sale_date'   => $c['sale_date'] ?? null,
                'distance_m'  => isset($c['distance_m']) && $c['distance_m'] !== null ? (int) $c['distance_m'] : null,
                'layer'       => $layer,
                'colour'      => self::LEGEND_COLOURS[$layer] ?? '#64748b',
            ];
        }

        // Competition stock — amber, numbered, read as the different
        // stratum the review-map orange diamond conveys.
        foreach ($compPlottable as $c) {
            $index++;
            $glyph = $this->labelGlyph($index);
            $markerLines[] = sprintf(
                'color:0xf59e0b|label:%s|size:small|%s,%s',
                $glyph,
                number_format((float) $c['latitude'],  6, '.', ''),
                number_format((float) $c['longitude'], 6, '.', ''),
            );
            $legend[] = [
                'index'       => $index,
                'label_glyph' => $glyph,
                'title'       => (string) ($c['title'] ?? ($c['address'] ?? ('Listing ' . $index))),
                'price'       => isset($c['price']) && $c['price'] !== null ? (int) $c['price'] : null,
                'sale_date'   => null,
                'distance_m'  => isset($c['distance_m']) && $c['distance_m'] !== null ? (int) $c['distance_m'] : null,
                'layer'       => 'competitor_stock',
                'colour'      => self::LEGEND_COLOURS['competitor_stock'],
            ];
        }

        // Build the URL. `markers=` is repeated once per marker spec,
        // not joined. URLs over 8192 chars hit Google's hard limit;
        // MAX_MARKERS=80 with ~50 chars each stays well below.
        $query = http_build_query($params);
        foreach ($markerLines as $line) {
            $query .= '&markers=' . rawurlencode($line);
        }

        return ['https://maps.googleapis.com/maps/api/staticmap?' . $query, $legend];
    }

    /**
     * Map a 1-based index to a single alphanumeric glyph Google Static
     * Maps `label:` accepts: 1–9 → "1".."9", 10–35 → "A".."Z". Beyond 35
     * we wrap to "Z" (35 numbered markers already exceeds any real comp
     * set; the legend still carries the true numeric index).
     */
    private function labelGlyph(int $index): string
    {
        if ($index >= 1 && $index <= 9) {
            return (string) $index;
        }
        if ($index >= 10 && $index <= 35) {
            return chr(ord('A') + ($index - 10));
        }
        return 'Z';
    }
}
