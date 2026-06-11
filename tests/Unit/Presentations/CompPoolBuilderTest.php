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

    private function cand(int $price, string $type = 'House', ?int $size = null, ?float $lat = null, ?float $lng = null, bool $exempt = false, $key = null, ?string $titleType = null): array
    {
        return [
            'key'           => $key ?? $price,
            'price'         => $price,
            'size_m2'       => $size,
            'property_type' => $type,
            'title_type'    => $titleType,
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
        $this->assertSame(10, $c['min_count']);
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

    public function test_shortlist_caps_exempt_comps_to_max_count(): void
    {
        // AT-22 round-1 (Johan, 11 Jun): exemption waives the PRICE band only;
        // exempt comps NO LONGER bypass the shortlist cap. This is the PRES 87
        // failure mode — 94 exempt comps must collapse to max_count (15), not
        // persist wholesale.
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        $candidates = [];
        for ($i = 0; $i < 30; $i++) {
            // All exempt, spread across a wide price range (mimics the leak).
            $candidates[] = $this->cand(300_000 + $i * 100_000, 'House', exempt: true, key: "ex$i");
        }
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertCount(15, $res['selected_keys'], 'Exempt comps must still be capped at max_count');
    }

    public function test_exempt_waives_price_only_not_the_type_gate(): void
    {
        // An analyst-vetted (exempt) SECTIONAL comp must still be excluded from
        // a freehold subject's pool — exemption does not wave it through type.
        $subject = ['title_type' => \App\Services\TitleTypeClassifier::TITLE_FULL, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        $candidates = [
            $this->cand(2_400_000, 'House', key: 'h1'),
            $this->cand(2_500_000, 'House', key: 'h2'),
            $this->cand(2_300_000, 'House', key: 'h3'),
            $this->cand(2_400_000, 'Residence', exempt: true, key: 'exempt_sectional', titleType: \App\Services\TitleTypeClassifier::TITLE_SECTIONAL),
        ];
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertNotContains('exempt_sectional', $res['selected_keys'], 'Exempt sectional comp must still fail the freehold type gate');
    }

    public function test_exempt_obeys_radius(): void
    {
        // Exempt comp far outside the radius must be dropped when near comps
        // already satisfy min_count (exemption no longer bypasses radius).
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => 0.0, 'lng' => 0.0, 'erf_m2' => null];
        $candidates = [];
        for ($i = 0; $i < 6; $i++) {
            $candidates[] = $this->cand(2_400_000 + $i * 10_000, 'House', lat: 0.0, lng: 0.0005, key: "near$i"); // ~55m
        }
        // ~0.05 deg ≈ 5.5km — well outside the 3000m ceiling.
        $candidates[] = $this->cand(2_400_000, 'House', lat: 0.0, lng: 0.05, exempt: true, key: 'exempt_far');
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $this->assertNotContains('exempt_far', $res['selected_keys'], 'Exempt comp beyond the radius ceiling must be dropped');
    }

    public function test_subject_anchor_drives_the_band_not_the_pool_median(): void
    {
        // The §1.5 R1.1M trap: a pool dominated by sub-R1M sales. Without a
        // subject anchor the band would centre ~R900k and KEEP them. With the
        // subject asking (R2.9M) as anchor, the sub-R1M sales fall outside the
        // ±25% band (R2.175M–R3.625M) and are excluded.
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null, 'anchor_price' => 2_900_000];
        $candidates = [
            $this->cand(2_600_000, 'House', key: 'on_profile_1'),
            $this->cand(2_900_000, 'House', key: 'on_profile_2'),
            $this->cand(3_100_000, 'House', key: 'on_profile_3'),
            $this->cand(620_000,  'House', key: 'cheap_1'),
            $this->cand(800_000,  'House', key: 'cheap_2'),
            $this->cand(900_000,  'House', key: 'cheap_3'),
            $this->cand(960_000,  'House', key: 'cheap_4'),
        ];
        $res = $this->builder()->select($subject, $candidates, $this->config());
        foreach (['cheap_1', 'cheap_2', 'cheap_3', 'cheap_4'] as $k) {
            $this->assertNotContains($k, $res['selected_keys'], "Sub-R1M sale $k must fall outside the asking-anchored band");
        }
        $this->assertContains('on_profile_2', $res['selected_keys']);
        $this->assertSame(2_900_000, $res['diagnostics']['anchor_used'], 'Band must anchor on the subject value');
    }

    public function test_premium_comps_surface_when_cheap_nearby_do_not_halt_the_ladder(): void
    {
        // AT-22 round-1 (Johan, 11 Jun): PRES 87 shape — a premium R2.9M
        // subject with cheap EXEMPT sales very close, and the genuine premium
        // comps a little further out. The cheap exempt sales must NOT satisfy
        // the widen count (they're out of the price tier); the ladder must
        // reach the premium comps and ranking must surface them.
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => 0.0, 'lng' => 0.0, 'erf_m2' => 1375, 'anchor_price' => 2_900_000];
        $candidates = [];
        for ($i = 0; $i < 8; $i++) { // ~220m, R1.1M, exempt → waive band but out of tier
            $candidates[] = $this->cand(1_100_000 + $i * 10_000, 'House', size: 900, lat: 0.0, lng: 0.002, exempt: true, key: "cheap$i");
        }
        for ($i = 0; $i < 6; $i++) { // ~780m, R2.5M, in-band → the real comparables
            $candidates[] = $this->cand(2_500_000 + $i * 20_000, 'House', size: 1300, lat: 0.0, lng: 0.007, key: "premium$i");
        }
        $res = $this->builder()->select($subject, $candidates, $this->config());
        $keys = $res['selected_keys'];
        foreach (['premium0', 'premium3', 'premium5'] as $k) {
            $this->assertContains($k, $keys, "Premium comp $k must surface despite cheaper, closer sales");
        }
        $this->assertGreaterThan(300, $res['radius_used'], 'Ladder must widen past 300m — cheap nearby exempt comps must not halt it');
    }

    public function test_empty_when_no_usable_candidates(): void
    {
        $subject = ['title_type' => null, 'property_type' => 'House', 'lat' => null, 'lng' => null, 'erf_m2' => null];
        $res = $this->builder()->select($subject, [], $this->config());
        $this->assertSame([], $res['selected_keys']);
        $this->assertNull($res['anchor']);
    }
}
