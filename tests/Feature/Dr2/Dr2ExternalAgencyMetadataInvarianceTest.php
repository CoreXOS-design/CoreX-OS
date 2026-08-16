<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 external-agency-as-input — the per-side external-agency picker persists a FIRM + working
 * contact per side (listing_external_agency_provider_id / listing_external_agency_contact_id and
 * the selling_* equivalents). The migration that added them states the contract outright:
 *
 *   "NO effect on the commission / pool / our-share / split math, which reads only
 *    {side}_external / {side}_split_percent / {side}_our_share_percent — never the agency link."
 *   (2026_08_02_120001_add_per_side_external_agency_links_to_deals.php)
 *
 * The split/pool engine lives in TWO places, both of which this test pins:
 *   - App\Models\Deal::calculateInternalPool() → listingPool()/sellingPool()/totalOurCommission()
 *   - App\Services\DealMoneyLineRebuilder::computeDealPools()
 *
 * Regression guarded: the agency LINK is metadata. Changing which external agency (or none) is
 * selected on either side must leave every computed money figure BYTE-IDENTICAL. The guard-the-
 * guard tests prove the same engine DOES move when the real numeric inputs (_external /
 * _split_percent / _our_share_percent) change — so the invariance above is a real signal, not a
 * math path that ignores all of its inputs.
 */
final class Dr2ExternalAgencyMetadataInvarianceTest extends TestCase
{
    use RefreshDatabase;

    /** Build a granted DR2 deal with explicit, non-default split inputs. */
    private function makeDeal(array $overrides = []): Deal
    {
        $agency = Agency::create([
            'name' => 'HFC', 'slug' => 'hfc-' . uniqid(),
            'trading_name' => 'Home Finders Coastal',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create(array_merge([
            'period' => '2026-03', 'deal_date' => '2026-03-01',
            'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'listing_agent_id' => $agent->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'accepted_status' => 'G',
            // explicit non-default numeric inputs so the math is exercised, not defaulted
            'listing_split_percent' => 60, 'selling_split_percent' => 40,
            'listing_our_share_percent' => 100, 'selling_our_share_percent' => 100,
            'listing_external' => false, 'selling_external' => false,
        ], $overrides)));
    }

    /** All money figures produced by computeDealPools(), as a comparable snapshot. */
    private function poolSnapshot(Deal $deal): array
    {
        return DealMoneyLineRebuilder::computeDealPools($deal->fresh());
    }

    /** The in-model pool triplet, as a comparable snapshot. */
    private function modelSnapshot(Deal $deal): array
    {
        $deal = $deal->fresh();

        return [
            'listingPool'         => $deal->listingPool(),
            'sellingPool'         => $deal->sellingPool(),
            'totalOurCommission'  => $deal->totalOurCommission(),
        ];
    }

    /**
     * computeDealPools() is byte-identical no matter which external agency is linked on each side.
     * We flip the four link columns across three configurations — firm A, firm B, and none —
     * while every numeric split input is held constant.
     */
    public function test_compute_pools_ignores_external_agency_link_columns(): void
    {
        $deal = $this->makeDeal([
            'listing_external_agency_provider_id' => 111, 'listing_external_agency_contact_id' => 222,
            'selling_external_agency_provider_id' => 333, 'selling_external_agency_contact_id' => 444,
        ]);
        $baseline = $this->poolSnapshot($deal);

        // A different external agency on each side — pure metadata swap.
        $deal->forceFill([
            'listing_external_agency_provider_id' => 555, 'listing_external_agency_contact_id' => 666,
            'selling_external_agency_provider_id' => 777, 'selling_external_agency_contact_id' => 888,
        ])->saveQuietly();
        $this->assertSame($baseline, $this->poolSnapshot($deal), 'swapping the linked external agency must not move any pool figure');

        // No external agency linked at all — still metadata, still identical.
        $deal->forceFill([
            'listing_external_agency_provider_id' => null, 'listing_external_agency_contact_id' => null,
            'selling_external_agency_provider_id' => null, 'selling_external_agency_contact_id' => null,
        ])->saveQuietly();
        $this->assertSame($baseline, $this->poolSnapshot($deal), 'clearing the external agency link must not move any pool figure');
    }

    /** The in-model listing/selling pools are equally blind to the link columns. */
    public function test_model_pools_ignore_external_agency_link_columns(): void
    {
        $deal = $this->makeDeal([
            'listing_external_agency_provider_id' => 111, 'listing_external_agency_contact_id' => 222,
        ]);
        $baseline = $this->modelSnapshot($deal);

        $deal->forceFill([
            'listing_external_agency_provider_id' => 999, 'listing_external_agency_contact_id' => 1001,
            'selling_external_agency_provider_id' => 1002, 'selling_external_agency_contact_id' => 1003,
        ])->saveQuietly();

        $this->assertSame($baseline, $this->modelSnapshot($deal));
        // sanity: with both sides internal and 100% our-share, our commission is the full ex-VAT figure
        $this->assertEqualsWithDelta(112_125 / 1.15, $baseline['totalOurCommission'], 0.01);
    }

    /**
     * Guard-the-guard: the SAME engine that ignored the link columns above DOES respond to the
     * real numeric inputs. If any of these stopped moving the math, the invariance tests would be
     * asserting over a dead code path.
     */
    public function test_split_percent_moves_the_pool(): void
    {
        $a = $this->makeDeal(['listing_split_percent' => 60]);
        $b = $this->makeDeal(['listing_split_percent' => 30]);

        $this->assertNotEqualsWithDelta(
            $a->listingPool(), $b->listingPool(), 0.01,
            'changing listing_split_percent MUST change the listing pool'
        );
    }

    public function test_our_share_percent_moves_the_pool(): void
    {
        $full  = $this->makeDeal(['listing_our_share_percent' => 100]);
        $half  = $this->makeDeal(['listing_our_share_percent' => 50]);

        $this->assertEqualsWithDelta(
            $full->listingPool() / 2.0, $half->listingPool(), 0.01,
            'halving listing_our_share_percent MUST halve the listing pool'
        );
    }

    public function test_external_flag_zeroes_the_side_pool(): void
    {
        $internal = $this->makeDeal(['listing_external' => false]);
        $external = $this->makeDeal(['listing_external' => true]);

        $this->assertGreaterThan(0.0, $internal->listingPool());
        $this->assertSame(0.0, $external->listingPool(), 'listing_external = true MUST zero the listing pool');
        // and computeDealPools routes the side commission to the external payable instead
        $pools = DealMoneyLineRebuilder::computeDealPools($external->fresh());
        $this->assertSame(0.0, $pools['listingPool']);
        $this->assertGreaterThan(0.0, $pools['listingExternalPayable']);
    }
}
