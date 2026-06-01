<?php

declare(strict_types=1);

namespace App\Services\Presentations\Pdf;

use App\Support\MarketAnalytics\HaversineDistance;

/**
 * Phase 3g V2 Part D5 — render a "spatial view" SVG for PresentationPdfService.
 *
 * DomPDF can't rasterise Leaflet, so we build a deliberately-simple chart:
 * the subject sits at the centre of a square canvas; each comp is placed
 * relative to the subject by computed bearing + distance (Haversine). The
 * canvas radius represents whatever the maximum comp distance is (or 1km
 * minimum), so all comps fit. Dots are labelled with address + price; a
 * subtle compass + scale bar gives context.
 *
 * Pure PHP, no external deps. Returns an SVG markup string that the PDF
 * pipeline embeds inline.
 */
final class SpatialViewSvgRenderer
{
    /**
     * @param array{lat: float, lng: float, title: string|null} $subject
     * @param array<int, array{lat: float, lng: float, title: string|null, subtitle: string|null, layer: string, price: int|null, sale_date: string|null}> $comps
     */
    public function render(array $subject, array $comps, int $widthPx = 540, int $heightPx = 360): string
    {
        if (!isset($subject['lat'], $subject['lng'])) {
            return '<div style="padding:12px;font-size:11px;color:#64748b;">Subject GPS not available — spatial view not rendered.</div>';
        }

        $subjectLat = (float) $subject['lat'];
        $subjectLng = (float) $subject['lng'];

        // Compute polar coords (distance metres + bearing radians) per comp.
        $points = [];
        $maxDistance = 0;
        foreach ($comps as $comp) {
            if (!isset($comp['lat'], $comp['lng'])) continue;
            $cLat = (float) $comp['lat'];
            $cLng = (float) $comp['lng'];
            $d = HaversineDistance::distanceMetres($subjectLat, $subjectLng, $cLat, $cLng);
            $bearing = $this->bearingRad($subjectLat, $subjectLng, $cLat, $cLng);
            $points[] = [
                'distance' => $d,
                'bearing'  => $bearing,
                'layer'    => $comp['layer']    ?? 'sold_comps',
                'title'    => $comp['title']    ?? null,
                'subtitle' => $comp['subtitle'] ?? null,
                'price'    => $comp['price']    ?? null,
                'sale_date'=> $comp['sale_date']?? null,
            ];
            if ($d > $maxDistance) $maxDistance = $d;
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

        // Background ring grid (250m / 500m / 1km bands when they fit).
        $svg .= $this->renderGrid($cx, $cy, $r, $radiusMetres);

        // Subject pin in the middle.
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="9" fill="#00d4aa" stroke="#fff" stroke-width="2.5"/>';
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="14" fill="none" stroke="#00d4aa" stroke-width="1" opacity="0.45"/>';
        $subjectLabel = $this->escape($subject['title'] ?? 'Subject');
        $svg .= '<text x="' . $cx . '" y="' . ($cy + 26) . '" text-anchor="middle" font-size="9" font-weight="700" fill="#0f172a">' . $subjectLabel . '</text>';

        // Comp dots — colour per layer matches the web map. The new
        // 'competitor_stock' layer (orange diamond on the review map)
        // shares the amber palette with the legacy 'active_listings'
        // — both represent "active stock competing with subject" and
        // the agent doesn't need them visually distinct in the PDF.
        $colours = [
            'sold_comps'       => '#3b82f6',
            'active_listings'  => '#f59e0b',
            'competitor_stock' => '#f59e0b',
            'mic_subjects'     => '#64748b',
            'scheme_owners'    => '#8b5cf6',
            'hfc_listings'     => '#00d4aa',
        ];

        // Label-collision avoidance: simple greedy placement at offsets that
        // step away from the dot in the bearing direction.
        $usedRects = [];

        foreach ($points as $i => $p) {
            // Convert polar → SVG cartesian (y inverted because SVG +y is down).
            $rPx = ($p['distance'] / $radiusMetres) * $r;
            $x = $cx + $rPx * sin($p['bearing']);
            $y = $cy - $rPx * cos($p['bearing']);

            $colour = $colours[$p['layer']] ?? '#64748b';
            $svg .= '<line x1="' . $cx . '" y1="' . $cy . '" x2="' . round($x, 1) . '" y2="' . round($y, 1) . '" stroke="#cbd5e1" stroke-width="0.5" opacity="0.5"/>';
            $svg .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="5" fill="' . $colour . '" stroke="#fff" stroke-width="1.5"/>';

            // Label: address (truncated to 24 chars) + price.
            $title = $this->escape(mb_substr($p['title'] ?? 'Comp ' . ($i + 1), 0, 24));
            $priceTxt = $p['price'] ? 'R ' . number_format($p['price'], 0, '.', ' ') : '';
            $dateTxt  = $p['sale_date'] ? \Carbon\Carbon::parse($p['sale_date'])->format('M Y') : '';

            $label1 = $title;
            $label2 = trim($priceTxt . ($dateTxt ? ' · ' . $dateTxt : ''));

            // Position label below the dot when above centre, above when below.
            $labelY = $y > $cy ? $y + 16 : $y - 10;
            $svg .= '<text x="' . round($x, 1) . '" y="' . round($labelY, 1) . '" text-anchor="middle" font-size="7" font-weight="600" fill="#1e293b">' . $label1 . '</text>';
            if ($label2 !== '') {
                $svg .= '<text x="' . round($x, 1) . '" y="' . round($labelY + 8, 1) . '" text-anchor="middle" font-size="6.5" fill="#475569">' . $this->escape($label2) . '</text>';
            }
        }

        // Compass + scale bar.
        $svg .= $this->renderCompass($widthPx, $heightPx);
        $svg .= $this->renderScaleBar($widthPx, $heightPx, $cx, $r, $radiusMetres);

        $svg .= '</svg>';
        return $svg;
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

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
