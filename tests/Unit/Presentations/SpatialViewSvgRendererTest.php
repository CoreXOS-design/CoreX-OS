<?php

declare(strict_types=1);

namespace Tests\Unit\Presentations;

use App\Services\Presentations\Pdf\SpatialViewSvgRenderer;
use PHPUnit\Framework\TestCase;

/**
 * AT-22 item 3 — the spatial view redesign.
 *
 * Asserts the decluttered map contract: numbered pins (one per comp,
 * NO per-dot address labels), a visually-distinct subject marker, and a
 * returned ordered legend whose row count == the pin count (one source
 * of truth for pin numbers and the HTML legend).
 */
final class SpatialViewSvgRendererTest extends TestCase
{
    private function subject(): array
    {
        // Margate-ish coordinates, KZN South Coast.
        return ['lat' => -30.8633, 'lng' => 30.3700, 'title' => '36 Grindewald'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function comps(int $n): array
    {
        $comps = [];
        for ($i = 0; $i < $n; $i++) {
            // Spread the comps around the subject so they project to
            // distinct pixels (the coincidence-jitter path is exercised
            // separately).
            $comps[] = [
                'lat'       => -30.8633 + 0.0008 * cos($i),
                'lng'       => 30.3700 + 0.0008 * sin($i),
                'title'     => 'Comp address ' . ($i + 1),
                'layer'     => $i % 3 === 0 ? 'competitor_stock' : 'sold_comps',
                'price'     => 2_000_000 + $i * 50_000,
                'sale_date' => '2026-0' . (($i % 9) + 1) . '-15',
            ];
        }
        return $comps;
    }

    public function test_renders_n_numbered_pins_and_legend_has_n_rows(): void
    {
        $n = 13; // PRES 87 curated 13 comps — the clustered worst case.
        $renderer = new SpatialViewSvgRenderer();

        $out = $renderer->render($this->subject(), $this->comps($n), 540, 360);

        $this->assertArrayHasKey('svg', $out);
        $this->assertArrayHasKey('legend', $out);

        $svg = $out['svg'];
        $legend = $out['legend'];

        // Legend has exactly N rows, indexed 1..N in order.
        $this->assertCount($n, $legend, 'legend row count must equal comp count');
        foreach ($legend as $row => $entry) {
            $this->assertSame($row + 1, $entry['index']);
            $this->assertArrayHasKey('title', $entry);
            $this->assertArrayHasKey('price', $entry);
            $this->assertArrayHasKey('sale_date', $entry);
            $this->assertArrayHasKey('distance_m', $entry);
            $this->assertArrayHasKey('layer', $entry);
            $this->assertArrayHasKey('colour', $entry);
            $this->assertIsInt($entry['distance_m']);
        }

        // Each legend index N appears as a numbered pin glyph in the SVG.
        // The pin number is rendered as a <text ...>N</text> glyph.
        for ($i = 1; $i <= $n; $i++) {
            $this->assertMatchesRegularExpression(
                '/<text[^>]*>' . $i . '<\/text>/',
                $svg,
                "pin number {$i} must be drawn on the map face"
            );
        }

        // PIN count == legend rows: count the comp pin circles. Each comp
        // pin is a <circle ... r="8.5" .../>; the subject uses different
        // radii (11 / 16). This guards against the old per-dot-label /
        // overprint design ever returning.
        $pinCircles = preg_match_all('/<circle[^>]*r="8\.5"/', $svg);
        $this->assertSame($n, $pinCircles, 'comp pin count must equal legend rows');
    }

    public function test_subject_marker_is_distinct(): void
    {
        $renderer = new SpatialViewSvgRenderer();
        $out = $renderer->render($this->subject(), $this->comps(5), 540, 360);
        $svg = $out['svg'];

        // Subject is a distinct teal marker with an "S" glyph and a
        // heavier ring — clearly different from the numbered comp circles.
        $this->assertStringContainsString('>S</text>', $svg, 'subject must carry an "S" glyph');
        $this->assertStringContainsString('#0d9488', $svg, 'subject teal colour must be present');
        // Subject ring radii (11 / 16) differ from comp pin radius (8.5).
        $this->assertMatchesRegularExpression('/<circle[^>]*r="11"/', $svg);
        $this->assertMatchesRegularExpression('/<circle[^>]*r="16"/', $svg);
    }

    public function test_no_per_dot_address_text_on_map_face(): void
    {
        // The whole point of the redesign: NO address strings painted on
        // the map. Addresses live only in the returned legend.
        $renderer = new SpatialViewSvgRenderer();
        $out = $renderer->render($this->subject(), $this->comps(8), 540, 360);

        $this->assertStringNotContainsString('Comp address', $out['svg'], 'no address labels may be painted on the map');
        // But the address IS carried in the legend.
        $this->assertStringContainsString('Comp address 1', $out['legend'][0]['title']);
    }

    public function test_coincident_pins_get_deterministic_jitter(): void
    {
        // Two comps at the exact same coordinates must still produce two
        // readable, separated pins (deterministic spiral, not random).
        $sameLat = -30.8650;
        $sameLng = 30.3720;
        $comps = [
            ['lat' => $sameLat, 'lng' => $sameLng, 'title' => 'A', 'layer' => 'sold_comps', 'price' => 2_100_000, 'sale_date' => '2026-01-10'],
            ['lat' => $sameLat, 'lng' => $sameLng, 'title' => 'B', 'layer' => 'sold_comps', 'price' => 2_200_000, 'sale_date' => '2026-02-10'],
        ];

        $renderer = new SpatialViewSvgRenderer();
        $out1 = $renderer->render($this->subject(), $comps, 540, 360);
        $out2 = $renderer->render($this->subject(), $comps, 540, 360);

        // Both pins present.
        $this->assertCount(2, $out1['legend']);
        $this->assertMatchesRegularExpression('/<text[^>]*>1<\/text>/', $out1['svg']);
        $this->assertMatchesRegularExpression('/<text[^>]*>2<\/text>/', $out1['svg']);

        // Deterministic: identical inputs → identical SVG (no rand()).
        $this->assertSame($out1['svg'], $out2['svg'], 'render must be deterministic for coincident pins');
    }

    public function test_missing_subject_gps_returns_fallback_and_empty_legend(): void
    {
        $renderer = new SpatialViewSvgRenderer();
        $out = $renderer->render(['title' => 'No GPS'], $this->comps(3), 540, 360);

        $this->assertSame([], $out['legend']);
        $this->assertStringContainsString('Subject GPS not available', $out['svg']);
    }
}
