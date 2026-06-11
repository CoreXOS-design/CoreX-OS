<?php

declare(strict_types=1);

namespace App\Services\Presentations\Pdf;

use App\Support\MarketAnalytics\HaversineDistance;

/**
 * AT-22 item 3 — render a decluttered, legible "spatial view" SVG for
 * PresentationPdfService.
 *
 * The subject sits at the centre of the canvas; each comp is placed
 * relative to the subject by computed bearing + distance (Haversine).
 * The canvas radius represents the maximum comp distance (or 1km
 * minimum), so all comps fit. A subtle compass + scale bar + ring grid
 * give context.
 *
 * REDESIGN (AT-22 §3): the old renderer painted a full two-line address
 * label next to EVERY dot, then ran a collision loop that gave up after
 * 8 steps and accepted overlapping labels — with ~13 clustered comps the
 * labels totally overprinted and the map was unreadable at PDF scale.
 *
 * The new design draws PINS ONLY on the map face: each comp is a
 * colour-coded circle (colour by layer) with its LEGEND INDEX NUMBER
 * (1, 2, 3…) centred inside it. Numbers are 1–2 chars and never
 * overprint the way addresses did. The subject is a visually distinct
 * teal marker with a heavier ring and an "S" glyph. The full address /
 * price / date detail lives in a keyed legend rendered by the caller
 * (PresentationPdfService) — render() returns an ordered point list so
 * the pin numbers and the legend share ONE source of truth.
 *
 * The PDF engine is Chromium/Puppeteer (scripts/html-to-pdf.mjs), so
 * full SVG + HTML/CSS + web fonts are available.
 *
 * Pure PHP, no external deps.
 */
final class SpatialViewSvgRenderer
{
    /** Layer → pin colour. Mirrors the web/review map palette. */
    private const COLOURS = [
        'sold_comps'       => '#3b82f6',
        'active_listings'  => '#f59e0b',
        'competitor_stock' => '#f59e0b',
        'mic_subjects'     => '#64748b',
        'scheme_owners'    => '#8b5cf6',
        'hfc_listings'     => '#00d4aa',
    ];

    private const DEFAULT_COLOUR = '#64748b';

    /** Subject marker colour (teal, distinct from all comp colours). */
    private const SUBJECT_COLOUR = '#0d9488';

    /**
     * Render the spatial view.
     *
     * @param array{lat: float, lng: float, title: string|null} $subject
     * @param array<int, array{lat: float, lng: float, title: string|null, subtitle?: string|null, layer: string, price: int|null, sale_date: string|null}> $comps
     *
     * @return array{svg: string, legend: array<int, array{index:int, title:string, price:?int, sale_date:?string, distance_m:int, layer:string, colour:string}>}
     *         `svg` is the inline <svg> markup (or a fallback <div> when the
     *         subject has no GPS, in which case `legend` is empty). `legend`
     *         is the ordered point list — index N corresponds to the pin
     *         labelled N on the map face — for the caller's HTML legend.
     */
    public function render(array $subject, array $comps, int $widthPx = 540, int $heightPx = 360): array
    {
        if (!isset($subject['lat'], $subject['lng'])) {
            return [
                'svg'    => '<div style="padding:12px;font-size:11px;color:#64748b;">Subject GPS not available — spatial view not rendered.</div>',
                'legend' => [],
            ];
        }

        $subjectLat = (float) $subject['lat'];
        $subjectLng = (float) $subject['lng'];

        // Compute polar coords (distance metres + bearing radians) per comp,
        // in input order — this order IS the legend index order.
        $points = [];
        $maxDistance = 0;
        foreach ($comps as $comp) {
            if (!isset($comp['lat'], $comp['lng'])) {
                continue;
            }
            $cLat = (float) $comp['lat'];
            $cLng = (float) $comp['lng'];
            $d = HaversineDistance::distanceMetres($subjectLat, $subjectLng, $cLat, $cLng);
            $bearing = $this->bearingRad($subjectLat, $subjectLng, $cLat, $cLng);
            $layer = $comp['layer'] ?? 'sold_comps';
            $points[] = [
                'distance'  => $d,
                'bearing'   => $bearing,
                'layer'     => $layer,
                'title'     => $comp['title'] ?? null,
                'price'     => $comp['price'] ?? null,
                'sale_date' => $comp['sale_date'] ?? null,
                'colour'    => self::COLOURS[$layer] ?? self::DEFAULT_COLOUR,
            ];
            if ($d > $maxDistance) {
                $maxDistance = $d;
            }
        }

        // Scale: canvas radius = max(1km, maxDistance × 1.1) so even one
        // far-out comp doesn't crowd the centre.
        $radiusMetres = max(1000, (int) ceil($maxDistance * 1.1));
        $padding = 36;
        $cx = $widthPx / 2;
        $cy = $heightPx / 2;
        $r  = (min($widthPx, $heightPx) / 2) - $padding;

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $widthPx . ' ' . $heightPx
            . '" style="width:100%;max-width:' . $widthPx . 'px;height:auto;font-family:Helvetica,Arial,sans-serif;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">';

        // Background ring grid (distance bands).
        $svg .= $this->renderGrid($cx, $cy, $r, $radiusMetres);

        // Comp connector lines + pins. Detect exact-coincidence pins (same
        // x,y after projection) and apply a deterministic spiral jitter so
        // overlapping numbers stay readable. Connectors drawn first so pins
        // sit on top.
        $pinRadius = 8.5;
        $numFontSize = 9.5;

        // First pass: project to pixel coords.
        $projected = [];
        foreach ($points as $i => $p) {
            $rPx = ($p['distance'] / $radiusMetres) * $r;
            $px = $cx + $rPx * sin($p['bearing']);
            $py = $cy - $rPx * cos($p['bearing']);
            $projected[$i] = ['x' => $px, 'y' => $py];
        }

        // Deterministic coincidence jitter: group pins by rounded coords;
        // within a group, fan members out on a small index-derived spiral.
        $coincidence = [];
        foreach ($projected as $i => $pos) {
            $bucket = round($pos['x'], 0) . ',' . round($pos['y'], 0);
            $coincidence[$bucket][] = $i;
        }
        foreach ($coincidence as $members) {
            if (count($members) < 2) {
                continue;
            }
            // Spiral: each subsequent member steps out by ~10px at a fixed
            // golden-angle turn — fully deterministic (index-derived).
            foreach ($members as $rank => $i) {
                if ($rank === 0) {
                    continue;
                }
                $angle = $rank * 2.39996; // golden angle (radians)
                $step  = 10 + 3 * $rank;
                $projected[$i]['x'] += $step * cos($angle);
                $projected[$i]['y'] += $step * sin($angle);
            }
        }

        // Connectors from centre to each pin.
        foreach ($points as $i => $p) {
            $px = round($projected[$i]['x'], 1);
            $py = round($projected[$i]['y'], 1);
            $svg .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . $px . '" y2="' . $py
                . '" stroke="#cbd5e1" stroke-width="0.6" opacity="0.5"/>';
        }

        // Pins on top of connectors.
        foreach ($points as $i => $p) {
            $px = round($projected[$i]['x'], 1);
            $py = round($projected[$i]['y'], 1);
            $index = $i + 1;
            $svg .= '<circle cx="' . $px . '" cy="' . $py . '" r="' . $pinRadius . '" fill="' . $p['colour']
                . '" stroke="#fff" stroke-width="1.6"/>';
            $svg .= '<text x="' . $px . '" y="' . round($py + ($numFontSize * 0.35), 1)
                . '" text-anchor="middle" font-size="' . $numFontSize
                . '" font-weight="700" fill="#fff">' . $index . '</text>';
        }

        // Subject marker — drawn last so it sits above any nearby comp pin.
        // Distinct: heavier double ring + "S" glyph, teal.
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="16" fill="none" stroke="' . self::SUBJECT_COLOUR . '" stroke-width="1.4" opacity="0.4"/>';
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="11" fill="' . self::SUBJECT_COLOUR . '" stroke="#fff" stroke-width="3"/>';
        $svg .= '<text x="' . $cx . '" y="' . round($cy + 4, 1)
            . '" text-anchor="middle" font-size="11" font-weight="800" fill="#fff">S</text>';

        // Compass + scale bar.
        $svg .= $this->renderCompass($widthPx, $heightPx);
        $svg .= $this->renderScaleBar($widthPx, $heightPx, $cx, $r, $radiusMetres);

        $svg .= '</svg>';

        // Ordered legend list — index N ↔ pin N. ONE source of truth shared
        // with the pin numbers above and the caller's HTML legend.
        $legend = [];
        foreach ($points as $i => $p) {
            $legend[] = [
                'index'      => $i + 1,
                'title'      => (string) ($p['title'] ?? ('Comp ' . ($i + 1))),
                'price'      => $p['price'] !== null ? (int) $p['price'] : null,
                'sale_date'  => $p['sale_date'] ?? null,
                'distance_m' => (int) round($p['distance']),
                'layer'      => $p['layer'],
                'colour'     => $p['colour'],
            ];
        }

        return ['svg' => $svg, 'legend' => $legend];
    }

    /**
     * Bearing in radians from (lat1, lng1) to (lat2, lng2).
     * 0 = north, π/2 = east, π = south, 3π/2 = west.
     */
    private function bearingRad(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dLam = deg2rad($lng2 - $lng1);
        $y = sin($dLam) * cos($phi2);
        $x = cos($phi1) * sin($phi2) - sin($phi1) * cos($phi2) * cos($dLam);
        return atan2($y, $x);
    }

    private function renderGrid(float $cx, float $cy, float $r, int $radiusMetres): string
    {
        $svg = '';
        // 3 concentric rings at 1/3, 2/3, full.
        for ($i = 1; $i <= 3; $i++) {
            $rr = ($i / 3) * $r;
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($rr, 1)
                . '" fill="none" stroke="#e2e8f0" stroke-width="1" stroke-dasharray="2,2"/>';
            // Ring label (top-right of the ring).
            $labelDist = round(($i / 3) * $radiusMetres);
            $labelText = $labelDist >= 1000
                ? number_format($labelDist / 1000, 1) . ' km'
                : $labelDist . ' m';
            $svg .= '<text x="' . ($cx + $rr - 4) . '" y="' . ($cy - 4)
                . '" text-anchor="end" font-size="6.5" fill="#94a3b8">' . $labelText . '</text>';
        }
        return $svg;
    }

    private function renderCompass(int $w, int $h): string
    {
        $x = $w - 30;
        $y = 30;
        return '<g transform="translate(' . $x . ',' . $y . ')">'
            . '<circle cx="0" cy="0" r="14" fill="#fff" stroke="#cbd5e1" stroke-width="1"/>'
            . '<polygon points="0,-10 -4,4 0,1 4,4" fill="#0f172a"/>'
            . '<text x="0" y="-14" text-anchor="middle" font-size="7" font-weight="700" fill="#0f172a">N</text>'
            . '</g>';
    }

    private function renderScaleBar(int $w, int $h, float $cx, float $r, int $radiusMetres): string
    {
        // 500m bar (or whatever fits at the ring scale).
        $barMetres = $radiusMetres >= 1000 ? 500 : (int) ($radiusMetres / 2);
        $barPx = ($barMetres / $radiusMetres) * $r;
        $bx = 20;
        $by = $h - 18;
        $label = $barMetres >= 1000 ? ($barMetres / 1000) . ' km' : $barMetres . ' m';
        return '<line x1="' . $bx . '" y1="' . $by . '" x2="' . ($bx + $barPx) . '" y2="' . $by . '" stroke="#0f172a" stroke-width="1.5"/>'
            . '<line x1="' . $bx . '" y1="' . ($by - 3) . '" x2="' . $bx . '" y2="' . ($by + 3) . '" stroke="#0f172a" stroke-width="1.5"/>'
            . '<line x1="' . ($bx + $barPx) . '" y1="' . ($by - 3) . '" x2="' . ($bx + $barPx) . '" y2="' . ($by + 3) . '" stroke="#0f172a" stroke-width="1.5"/>'
            . '<text x="' . ($bx + $barPx / 2) . '" y="' . ($by - 5) . '" text-anchor="middle" font-size="7" fill="#0f172a">' . $label . '</text>';
    }
}
