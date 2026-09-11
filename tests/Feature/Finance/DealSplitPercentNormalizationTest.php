<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Deal;
use App\Models\PerformanceSetting;
use App\Services\DealMoneyLineRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * DR2 Financial Audit F8 (AT-414) — computeDealPools() (the settlement
 * SCREEN's calculation) only clamped each split to [0,100] independently;
 * rebuildSingleDeal() (what actually gets STORED and feeds
 * printAgentPayslip) additionally normalized the pair to sum to 100 when
 * they didn't. A deal with mismatched splits would show genuinely different
 * numbers on screen than what was actually paid. Confirmed zero real QA1
 * deals currently trigger this (re-verified fresh, not just trusting the
 * audit's original count) — no historical correction applies.
 *
 * Fixed by extracting one shared DealMoneyLineRebuilder::resolveSplitPercents()
 * both call sites now use, so they can never again disagree. The tolerance
 * for "close enough to 100" is an agency-configurable PerformanceSetting
 * (split_sum_tolerance_percent, default 0.01 — identical to the prior
 * hardcoded value, so this is a no-op for every already-correct deal).
 */
final class DealSplitPercentNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Split Co', 'slug' => 'sp-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeDeal(float $listingSplit, float $sellingSplit): Deal
    {
        return Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(800000, 899999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 100_000,
            'listing_split_percent' => $listingSplit, 'selling_split_percent' => $sellingSplit,
        ]);
    }

    public function test_mismatched_splits_are_normalized_proportionally_not_left_as_is(): void
    {
        // 60 + 60 = 120 — a real bad-data shape, proportionally rescaled to 50/50
        // (each was equal weight, so each keeps equal weight, just summing to 100).
        $deal = $this->makeDeal(60, 60);

        [$listing, $selling] = DealMoneyLineRebuilder::resolveSplitPercents($deal);

        $this->assertSame(50.0, $listing);
        $this->assertSame(50.0, $selling);
    }

    public function test_mismatched_splits_preserve_relative_weight_not_forced_to_even(): void
    {
        // 90 + 30 = 120 — listing had 3x selling's weight; after normalizing
        // to sum to 100, that 3:1 ratio must survive (75/25), not become 50/50.
        $deal = $this->makeDeal(90, 30);

        [$listing, $selling] = DealMoneyLineRebuilder::resolveSplitPercents($deal);

        $this->assertEqualsWithDelta(75.0, $listing, 0.01);
        $this->assertEqualsWithDelta(25.0, $selling, 0.01);
    }

    public function test_settlement_screen_and_stored_payslip_calculation_can_no_longer_disagree(): void
    {
        $deal = $this->makeDeal(60, 60);

        $screenSplits = DealMoneyLineRebuilder::resolveSplitPercents($deal);
        $pools = DealMoneyLineRebuilder::computeDealPools($deal);

        // Before this fix, computeDealPools() (the screen) would have used
        // 60/60 as-is (each independently clamped, never normalized) while
        // rebuildSingleDeal() (storage/payslip) would have used 50/50 —
        // genuinely different pool amounts for the same deal.
        $this->assertSame([50.0, 50.0], $screenSplits);
        $this->assertGreaterThan(0, $pools['listingPool']);
    }

    public function test_a_mismatch_is_logged_as_a_warning_not_silently_absorbed(): void
    {
        Log::spy();

        $deal = $this->makeDeal(60, 60);
        DealMoneyLineRebuilder::resolveSplitPercents($deal);

        Log::shouldHaveReceived('warning')
            ->atLeast()->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'DEAL SPLIT PERCENT MISMATCH') && $context['deal_id'] === $deal->id);
    }

    public function test_a_correctly_summing_deal_is_never_logged_or_altered(): void
    {
        Log::spy();

        $deal = $this->makeDeal(70, 30);
        [$listing, $selling] = DealMoneyLineRebuilder::resolveSplitPercents($deal);

        $this->assertSame(70.0, $listing);
        $this->assertSame(30.0, $selling);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_mismatch_tolerance_is_an_agency_configurable_setting_not_hardcoded(): void
    {
        // A deal 0.5 off 100 (60.5/40) — outside the default 0.01 tolerance,
        // so it normalizes by default...
        $deal = $this->makeDeal(60.5, 40);
        [$listing] = DealMoneyLineRebuilder::resolveSplitPercents($deal);
        $this->assertNotEqualsWithDelta(60.5, $listing, 0.001);

        // ...but an agency that widens its own tolerance past that gap keeps
        // the value exactly as entered.
        PerformanceSetting::set('split_sum_tolerance_percent', 1.0, $this->agencyId);
        [$listingWithWiderTolerance] = DealMoneyLineRebuilder::resolveSplitPercents($deal->fresh());
        $this->assertSame(60.5, $listingWithWiderTolerance);
    }
}
