<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\CompPoolBuilder;
use Tests\TestCase;

/**
 * AT-22 §1 / §1.5 — CompPoolBuilder gate-then-rank + anchor robustness.
 *
 * Pure-logic tests over candidate arrays (no DB). Uses the Laravel
 * container only for the TitleTypeClassifier resolution inside select().
 */
class CompPoolBuilderTest extends TestCase
{
    private function builder(): CompPoolBuilder
    {
        return new CompPoolBuilder();
    }

    /** Default config (null agency → service-constant defaults). */
    private function config(): array
    {
        return CompPoolBuilder::configForAgency(null);
    }

    private function cand(int $price, string $type = 'House', ?int $size = null, ?float $lat = null, ?float $lng = null, bool $exempt = false, $key = null): array
    {
        return [
            'key'           => $key ?? $price,
            'price'         => $price,
            'size_m2'       => $size,
            'property_type' => $type,
            'lat'           => $lat,
            'lng'           => $lng,
            'exempt'        => $exempt,
        ];
    }

    public function test_config_defaults_match_locked_values(): void
    {
        $c = $this->config();
        $this->assertSame(25.0, $c['price_band_pct']);
        $this->assertSame(30.0, $c['erf_band_pct']);
        $this->assertSame(300, $c['radius_m']);
        $this->assertSame(3000, $c['radius_max_m']);
        $this->assertSame(5, $c['min_count']);
        $this->assertSame(15, $c['max_count']);
        $this->assertSame(25.0, $c['divergence_pct']);
        $this->assertSame(25, $c['range_lower_pct']);
        $this->assertSame(75, $c['range_upper_pct']);
        $this->assertSame([300, 600, 1000, 1500, 3000], $c['radius_steps']);
    }

    public function test_type_hard_gate_never_crosses_freehold_and_sectional(): void
    {
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        $candidates = [
            $this->cand(2_400_000, 'House', key: 'h1'),
            $this->cand(2_500_000, 'House', key: 'h2'),
            $this->cand(2_300_000, 'House', key: 'h3'),
            $this->cand(2_400_000, 'Apartment', key: 'sectional'), // must be excluded
        ];
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertNotContains('sectional', $res['selected_keys'], 'Sectional comp must never enter a freehold pool');
        $this->assertContains('h1', $res['selected_keys']);
    }

    public function test_price_band_excludes_out_of_band_sales(): void
    {
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        // Median of type-gated = 2.4M; ±25% band = 1.8M–3.0M.
        $candidates = [
            $this->cand(2_000_000, 'House', key: 'in_low'),
            $this->cand(2_200_000, 'House', key: 'in_2'),
            $this->cand(2_400_000, 'House', key: 'mid'),
            $this->cand(2_600_000, 'House', key: 'in_4'),
            $this->cand(2_800_000, 'House', key: 'in_high'),
            $this->cand(900_000,   'House', key: 'too_low'),   // below band → drop
            $this->cand(5_000_000, 'House', key: 'too_high'),  // above band → drop
        ];
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertNotContains('too_low', $res['selected_keys']);
        $this->assertNotContains('too_high', $res['selected_keys']);
        $this->assertContains('mid', $res['selected_keys']);
        // Anchor is the median of the gated set — defensible, ~2.4M, NOT R1.1M.
        $this->assertGreaterThanOrEqual(2_200_000, $res['anchor']);
        $this->assertLessThanOrEqual(2_600_000, $res['anchor']);
    }

    public function test_exempt_comp_bypasses_price_gate_and_is_retained(): void
    {
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        $candidates = [
            $this->cand(2_400_000, 'House', key: 'a'),
            $this->cand(2_300_000, 'House', key: 'b'),
            $this->cand(2_500_000, 'House', key: 'c'),
            // Way out of band, but analyst-vetted / trusted-internal → kept.
            $this->cand(800_000, 'House', exempt: true, key: 'exempt_low'),
        ];
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertContains('exempt_low', $res['selected_keys'], 'Exempt comp must bypass the price gate');
    }

    public function test_radius_ladder_widens_when_thin(): void
    {
        // Subject at origin. Five comps ~2km away (outside 300m, inside 3000m).
        // With min_count 5 the ladder must widen past 300m to resolve them.
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => 0.0, 'lng' => 0.0, 'erf_m2' => null];
        $candidates = [];
        for ($i = 0; $i < 5; $i++) {
            // ~0.018 deg longitude ≈ 2km at the equator.
            $candidates[] = $this->cand(2_400_000 + $i * 10_000, 'House', lat: 0.0, lng: 0.018, key: "far$i");
        }
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertGreaterThan(300, $res['radius_used'], 'Radius must widen past the 300m default to resolve a thin pool');
        $this->assertCount(5, $res['selected_keys']);
    }

    public function test_shortlist_caps_at_max_count_but_keeps_exempt(): void
    {
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        $candidates = [];
        for ($i = 0; $i < 25; $i++) {
            $candidates[] = $this->cand(2_400_000 + $i * 1_000, 'House', key: "c$i");
        }
        $candidates[] = $this->cand(800_000, 'House', exempt: true, key: 'exempt');
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertLessThanOrEqual(15, count($res['selected_keys']));
        $this->assertContains('exempt', $res['selected_keys'], 'Exempt comp survives the shortlist cap');
    }

    public function test_empty_when_no_usable_candidates(): void
    {
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        $res = $this->builder()->select($subject, [], $this->config());
        $this->assertSame([], $res['selected_keys']);
        $this->assertNull($res['anchor']);
    }
}
