<?php

declare(strict_types=1);

namespace Tests\Feature\Commission;

use App\Models\Deal;
use App\Models\DealSettlement;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use App\Services\Finance\CommissionCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression coverage for the "our_share_percent applied to an internal side"
 * defect (real deal #169 on Staging/QA1 — R58,650 gross should net R25,500,
 * was printing R12,750, exactly half). our_share_percent only ever means
 * something for a side handed to an EXTERNAL agency — it must never reduce
 * our own internal side's pool. Covers all three call sites that used to
 * duplicate this formula: Deal/DealV2::calculateInternalPool(),
 * CommissionCalculator, and DealMoneyLineRebuilder.
 */
final class CommissionInternalPoolShareDefectTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Pool Co', 'slug' => 'pool-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agent(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent',
        ]);
    }

    private function makeV1Deal(array $over = []): Deal
    {
        return Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(6000, 9999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_value' => 1_500_000, 'total_commission' => 58_650,
        ], $over));
    }

    /** Reproduces deal #169's real shape exactly: listing external (dead our_share),
     *  selling internal with our_share wrongly set to 50 instead of the default 100. */
    private function makeDeal169Shape(): Deal
    {
        return $this->makeV1Deal([
            'listing_external' => 1, 'listing_split_percent' => 50, 'listing_our_share_percent' => 0,
            'selling_external' => 0, 'selling_split_percent' => 50, 'selling_our_share_percent' => 50,
        ]);
    }

    private function makeDealV2(array $over = []): DealV2
    {
        $agent = $this->agent();

        return DealV2::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'reference' => 'DR2-' . Str::random(6), 'deal_type' => 'bond',
            'listing_agent_id' => $agent->id, 'created_by_id' => $agent->id,
            'purchase_price' => 1_500_000, 'offer_date' => '2026-06-10',
            'commission_amount' => 51_000, 'commission_vat' => 7_650, // 58,650 inc VAT @15%
            'listing_split_percent' => 50, 'listing_external' => 0, 'listing_our_share_percent' => 100,
            'selling_split_percent' => 50, 'selling_external' => 0, 'selling_our_share_percent' => 100,
        ], $over));
    }

    public function test_v1_deal_169_shape_now_computes_the_correct_total(): void
    {
        $deal = $this->makeDeal169Shape();

        // 58,650 inc VAT / 1.15 = 51,000 ex VAT. Listing (external) = 0.
        // Selling (internal, 50% split) = 25,500 — our_share_percent=50 must be ignored.
        $this->assertEqualsWithDelta(0.0, $deal->listingPool(), 0.01);
        $this->assertEqualsWithDelta(25_500.0, $deal->sellingPool(), 0.01);
        $this->assertEqualsWithDelta(25_500.0, $deal->totalOurCommission(), 0.01);
    }

    public function test_our_share_percent_on_internal_side_is_ignored_v1(): void
    {
        $deal = $this->makeV1Deal([
            'total_commission' => 115_000, // 100,000 ex VAT
            'listing_external' => 0, 'listing_split_percent' => 100, 'listing_our_share_percent' => 1,
            'selling_external' => 0, 'selling_split_percent' => 0,
        ]);

        $this->assertEqualsWithDelta(100_000.0, $deal->listingPool(), 0.01, 'internal side must ignore our_share_percent entirely');
    }

    public function test_our_share_percent_on_internal_side_is_ignored_dealv2(): void
    {
        $deal = $this->makeDealV2([
            'listing_split_percent' => 100, 'listing_our_share_percent' => 1,
            'selling_split_percent' => 0,
        ]);

        $this->assertEqualsWithDelta(51_000.0, $deal->listingPool(), 0.01, 'internal side must ignore our_share_percent entirely');
    }

    public function test_external_side_still_produces_zero_internal_pool_v1_and_dealv2(): void
    {
        $deal = $this->makeDeal169Shape();
        $this->assertSame(0.0, $deal->listingPool());

        $v2 = $this->makeDealV2(['listing_external' => 1, 'listing_our_share_percent' => 40]);
        $this->assertSame(0.0, $v2->listingPool());
    }

    public function test_external_payable_calculation_is_completely_unchanged_by_this_fix(): void
    {
        // Not part of the defect — DealMoneyLineRebuilder's externalPayable figure is a
        // separate concept (what we owe OUT to an external agency) that must be untouched,
        // in both its branches, exactly as it behaved before this fix.
        $externalDeal = $this->makeV1Deal([
            'total_commission' => 115_000,
            'listing_external' => 1, 'listing_split_percent' => 100, 'listing_our_share_percent' => 60,
            'selling_external' => 0, 'selling_split_percent' => 0,
        ]);
        $pools = DealMoneyLineRebuilder::computeDealPools($externalDeal);
        // Original behaviour: when the side IS external, externalPayable is the full side
        // amount — our_share_percent does not reduce it in this branch, unchanged.
        $this->assertEqualsWithDelta(115_000.0, $pools['listingExternalPayable'], 0.01);
        $this->assertEqualsWithDelta(0.0, $pools['listingPool'], 0.01);

        $internalDeal = $this->makeV1Deal([
            'total_commission' => 115_000,
            'listing_external' => 0, 'listing_split_percent' => 100, 'listing_our_share_percent' => 60,
            'selling_external' => 0, 'selling_split_percent' => 0,
        ]);
        $pools2 = DealMoneyLineRebuilder::computeDealPools($internalDeal);
        // Original behaviour: when the side is NOT external, externalPayable = side * (1 - our/100),
        // unchanged by this fix — 115,000 * (1 - 0.6) = 46,000.
        $this->assertEqualsWithDelta(46_000.0, $pools2['listingExternalPayable'], 0.01);
        // But the internal POOL itself is the actual defect — it must now ignore
        // our_share_percent entirely and keep the full ex-VAT side amount.
        $this->assertEqualsWithDelta(100_000.0, $pools2['listingPool'], 0.01);
    }

    public function test_vat_exclusive_and_inclusive_fields_agree_across_v1_and_dealv2(): void
    {
        // V1 stores a single VAT-inclusive total_commission.
        $v1 = $this->makeV1Deal([
            'total_commission' => 115_000,
            'listing_external' => 0, 'listing_split_percent' => 100, 'listing_our_share_percent' => 30,
            'selling_external' => 0, 'selling_split_percent' => 0,
        ]);

        // DealV2 stores commission_amount (ex VAT) + commission_vat separately.
        $v2 = $this->makeDealV2([
            'commission_amount' => 100_000, 'commission_vat' => 15_000,
            'listing_split_percent' => 100, 'listing_our_share_percent' => 30,
            'selling_split_percent' => 0,
        ]);

        $this->assertEqualsWithDelta($v1->listingPool(), $v2->listingPool(), 0.01);
        $this->assertEqualsWithDelta(100_000.0, $v1->listingPool(), 0.01);
    }

    public function test_multiple_agents_one_side_with_and_without_settlement_rows(): void
    {
        $deal = $this->makeDeal169Shape(); // sellingPool = 25,500 once fixed
        $agentA = $this->agent();
        $agentB = $this->agent();
        $deal->agents()->attach($agentA->id, ['side' => 'selling', 'agent_split_percent' => 50]);
        $deal->agents()->attach($agentB->id, ['side' => 'selling', 'agent_split_percent' => 50]);

        // No settlement rows yet — pool itself must already be correct.
        $pools = DealMoneyLineRebuilder::computeDealPools($deal);
        $this->assertEqualsWithDelta(25_500.0, $pools['sellingPool'], 0.01);

        // Settlement rows change how the pool is divided between agents, never its size.
        DealSettlement::create([
            'agency_id' => $this->agencyId, 'deal_id' => $deal->id, 'user_id' => $agentA->id,
            'side' => 'selling', 'share_percent' => 70, 'agent_cut_percent' => 50,
            'paye_method' => 'percentage', 'paye_value' => 0, 'deductions' => 0,
        ]);

        $poolsAfter = DealMoneyLineRebuilder::computeDealPools($deal->fresh());
        $this->assertEqualsWithDelta(25_500.0, $poolsAfter['sellingPool'], 0.01, 'settlement rows must not change the pool size, only its split');
    }

    public function test_commission_calculator_deal_money_line_rebuilder_and_model_methods_all_agree(): void
    {
        // Proves there is exactly one calculation left: three call sites, one number,
        // across a spread of shapes — external listing only, external selling only,
        // both internal with a broken share value on both sides, and deal #169 itself.
        $shapes = [
            ['listing_external' => 1, 'listing_split_percent' => 40, 'listing_our_share_percent' => 0,
                'selling_external' => 0, 'selling_split_percent' => 60, 'selling_our_share_percent' => 1],
            ['listing_external' => 0, 'listing_split_percent' => 70, 'listing_our_share_percent' => 1,
                'selling_external' => 1, 'selling_split_percent' => 30, 'selling_our_share_percent' => 0],
            ['listing_external' => 0, 'listing_split_percent' => 50, 'listing_our_share_percent' => 50,
                'selling_external' => 0, 'selling_split_percent' => 50, 'selling_our_share_percent' => 50],
            ['listing_external' => 1, 'listing_split_percent' => 50, 'listing_our_share_percent' => 0,
                'selling_external' => 0, 'selling_split_percent' => 50, 'selling_our_share_percent' => 50],
        ];

        foreach ($shapes as $i => $shape) {
            $deal = $this->makeV1Deal($shape);

            $modelTotal = round($deal->listingPool() + $deal->sellingPool(), 2);
            $calcTotal = round(CommissionCalculator::companyIncomeExVat($deal), 2);
            $pools = DealMoneyLineRebuilder::computeDealPools($deal);
            $rebuilderTotal = round($pools['listingPool'] + $pools['sellingPool'], 2);

            $this->assertEqualsWithDelta($modelTotal, $calcTotal, 0.01, "shape #{$i}: CommissionCalculator disagrees with the model");
            $this->assertEqualsWithDelta($modelTotal, $rebuilderTotal, 0.01, "shape #{$i}: DealMoneyLineRebuilder disagrees with the model");
        }
    }
}
