<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRES-CMA-SELLER-VOICE (Johan, 2026-06-15) — the seller-facing report must
 * let the MARKET deliver the message: anchor everything on the evaluated value
 * (the comp-median), never the upper band, and never reassure an over-market
 * asking as "ok / right in the band".
 *
 * Covers:
 *  - Bug 3: the Q3-anchored upper IQR fence drops a lone high outlier so the
 *    recommended-band ceiling reflects what comparable homes actually sold for.
 *  - Bug 2: asking-vs-value is measured against the MIDDLE regardless of the
 *    agent-selected range.
 *  - Bug 1: the recommendation bullet states an over-market asking plainly in
 *    the market's voice (no self-contradiction, no false reassurance).
 */
final class SellerVoiceAnchorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── Bug 3 — upper IQR fence drops the lone high outlier ───────────────

    public function test_upper_iqr_fence_drops_lone_high_outlier_from_band(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createProperty($agencyId, $user->id, ['price' => 2_500_000]);
        $version  = $this->seedVersion($agencyId, $user->id, $property);

        // 6 tight genuine comps + 1 lone high outlier (R3.1M). All same size,
        // so R/m² ∝ price. Tukey upper fence = Q3 + 1.5×IQR ≈ R2.85M → the
        // R3.1M sale is dropped; the genuine P75 survives.
        $this->seedComps($version->presentation_id, $agencyId, [
            2_400_000, 2_450_000, 2_500_000, 2_550_000, 2_600_000, 2_650_000,
            3_100_000, // outlier
        ]);

        $data = (new AnalysisDataService())->compile(
            $version->presentation()->with('property')->first(),
            $version,
        );
        $ps  = $data['cma_computed']['pool_stats'];
        $cma = $data['cma_valuation'];

        // The outlier is gone from the cleaned pool: max is the genuine top,
        // not R3,100,000 (which it would be without the upper fence).
        $this->assertSame(2_650_000, $ps['max'], 'lone high outlier must be fenced out');
        $this->assertSame(6, $ps['n_cleaned'], 'cleaned pool keeps the 6 genuine comps');
        // Recommended-band ceiling (p75) reflects genuine comps, well under R3M.
        $this->assertSame(2_587_500, $cma['cma_upper']);
        $this->assertLessThan(3_000_000, $cma['cma_upper']);
    }

    // ── Bug 2 — asking-vs-value anchors on the MIDDLE, not selected range ─

    public function test_asking_vs_value_uses_middle_even_when_selected_range_is_upper(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createProperty($agencyId, $user->id, ['price' => 2_900_000]);
        $version  = $this->seedVersion($agencyId, $user->id, $property);
        // Agent picked 'upper' as the headline range — the pre-fix bug measured
        // asking against upper and read an over-market asking as "Ok".
        $version->presentation()->update([
            'asking_price_inc'   => 2_900_000,
            'cma_selected_range' => 'upper',
        ]);
        $this->seedComps($version->presentation_id, $agencyId, [
            2_400_000, 2_450_000, 2_500_000, 2_550_000, 2_600_000, 2_650_000,
        ]);

        $cma = (new AnalysisDataService())->compile(
            $version->presentation()->with('property')->first(),
            $version,
        )['cma_valuation'];

        // Evaluated value (middle) = R2,525,000; upper = R2,587,500.
        $this->assertSame(2_525_000, $cma['cma_middle']);
        $this->assertSame(2_587_500, $cma['cma_upper']);
        $this->assertSame('upper', $cma['selected_range']);
        // Asking R2.9M vs the MIDDLE R2,525,000 = +14.9% OVER — NOT the
        // +12.1% it would read vs upper, and NOT a negative "Ok" figure.
        $this->assertEqualsWithDelta(14.9, $cma['asking_vs_cma_pct'], 0.1);
        $this->assertTrue($cma['is_overpriced']);
    }

    // ── Bug 1 — recommendation bullet, market's voice, anchored on middle ─

    public function test_recommendation_bullet_states_above_market_in_market_voice(): void
    {
        [$payload] = $this->buildPayloadForAsking(2_900_000);
        $bullet = $this->recommendationBullet($payload);

        $this->assertStringContainsString('asking more than homes like yours have actually sold for', $bullet);
        $this->assertStringContainsString('Pricing closer to that is what brings buyers', $bullet);
        // Never reassure an over-market asking.
        $this->assertStringNotContainsString('priced right in the band', $bullet);
        $this->assertFalse($payload['well_priced']);
    }

    public function test_recommendation_bullet_is_fair_when_asking_at_or_below_evaluated_value(): void
    {
        // Asking R2.4M is below the evaluated value (R2,525,000) — genuinely
        // well-placed, so a fair (non-pressuring) line is correct here.
        [$payload] = $this->buildPayloadForAsking(2_400_000);
        $bullet = $this->recommendationBullet($payload);

        $this->assertStringContainsString('at or below what homes like yours have sold for', $bullet);
        $this->assertStringNotContainsString('asking more than', $bullet);
        $this->assertTrue($payload['well_priced']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0: array} payload from buildSummaryPayload */
    private function buildPayloadForAsking(int $asking): array
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $property = $this->createProperty($agencyId, $user->id, ['price' => $asking]);
        $version  = $this->seedVersion($agencyId, $user->id, $property);
        $version->presentation()->update(['asking_price_inc' => $asking]);
        $this->seedComps($version->presentation_id, $agencyId, [
            2_400_000, 2_450_000, 2_500_000, 2_550_000, 2_600_000, 2_650_000,
        ]);
        $presentation = $version->presentation()->with('property')->first();
        $data = (new AnalysisDataService())->compile($presentation, $version);
        $payload = (new PresentationPdfService())->buildSummaryPayload($presentation, $version, $data);
        return [$payload];
    }

    private function recommendationBullet(array $payload): string
    {
        foreach ($payload['bullets'] as $b) {
            if (($b['key'] ?? null) === 'recommendation') {
                return (string) $b['html'];
            }
        }
        return '';
    }

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    private function createProperty(int $agencyId, int $userId, array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'agent_id'  => $userId,
            'title'     => 'Test Property',
        ], $overrides));
    }

    private function seedVersion(int $agencyId, int $userId, Property $property): PresentationVersion
    {
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'property_id'        => $property->id,
            'created_by_user_id' => $userId,
            'title'              => 'Seller Voice Test',
            'property_address'   => '1 Test Avenue',
            'suburb'             => 'Testville',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
        return PresentationVersion::create([
            'agency_id'          => $agencyId,
            'presentation_id'    => $presentation->id,
            'compiled_by'        => $userId,
            'blueprint_version'  => 'v1',
            'data_snapshot_json' => json_encode(['sections' => []]),
            'compiled_at'        => now(),
            'review_status'      => PresentationVersion::REVIEW_AWAITING,
            'awaiting_review_at' => now(),
        ]);
    }

    private function seedComps(int $presentationId, int $agencyId, array $prices): void
    {
        foreach ($prices as $i => $price) {
            PresentationSoldComp::create([
                'agency_id'       => $agencyId,
                'presentation_id' => $presentationId,
                'sold_date'       => now()->subMonths(2)->subDays($i)->toDateString(),
                'sold_price_inc'  => $price,
                'suburb'          => 'Testville',
                'property_type'   => 'house',
                'beds'            => 3,
                'size_m2'         => 150,
                'raw_row_json'    => json_encode(['address' => 'Comp ' . ($i + 1)]),
                'parser_version'  => 'test',
            ]);
        }
    }
}
