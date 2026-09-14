<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-focus-fix (2026-09-18) then AT-flow-fix (2026-09-19) — Johan first
 * reported the commission check "off by R17,800" against nothing visible
 * on screen, while his own numbers (10,000 + 10,000 against a 20,000 deal
 * commission) genuinely balanced. That was fixed (stale hidden-field
 * listener). Re-verifying it, Johan then ruled the WHOLE reconciliation
 * design wrong: the master selling price/commission should never be a
 * second, independently-typed figure to check against the parts — it
 * should BE the sum, derived and displayed. That removed the entire class
 * of problem: there is no longer a competing number that could disagree.
 *
 * This file now proves the SERVER-SIDE derivation
 * (DealPropertyPricingService::recalculateTotals(), called unconditionally
 * from applyCreateTimeMultiProperty()) across the same matrix originally
 * asked for — equal splits, unequal splits, one property carrying the
 * whole commission, zero on one, decimal splits — but the assertion
 * changed shape: instead of "does the save get rejected on mismatch", it's
 * "is the STORED total_commission always the true sum, regardless of
 * (and even despite) whatever was submitted at the top level". The
 * required/numeric shape validation on each row (missing field, empty
 * string) is unchanged and still proven here. The CLIENT-SIDE fix (the
 * stale hidden-field bug, the VAT-basis-aware conversion, and the
 * additive-master flow itself) is proven separately by a real browser
 * session — see the build reports — since none of it is reachable through
 * an HTTP feature test.
 */
final class CreateTimeMultiPropertyCommissionCheckTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $bm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'CTMP Comm Co', 'slug' => 'ctmp-comm-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'create_deals', 'deals.view', 'deals.create', 'deals.edit'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();
        $this->bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function makeProperty(string $address): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'CTMPC-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ]));
    }

    private function makeContact(string $first): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'first_name' => $first, 'last_name' => 'Owner', 'phone' => '0' . random_int(600000000, 699999999),
        ]);
    }

    private function linkOwner(Property $property, Contact $contact): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function basePayload(): array
    {
        return [
            'period' => '2026-09', 'deal_date' => '2026-09-14', 'branch_id' => $this->branchId,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_agents' => [$this->bm->id], 'selling_agents' => [$this->bm->id],
        ];
    }

    /** @return array{0: Property, 1: Property} */
    private function twoOwnedProperties(): array
    {
        $propA = $this->makeProperty('Comm Check A Rd');
        $propB = $this->makeProperty('Comm Check B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);

        return [$propA, $propB];
    }

    /**
     * The top-level property_value/total_commission submitted here are
     * DELIBERATELY the correct sums (matching what the real create-mode JS
     * would compute and submit as the read-only, derived master) — this
     * file is about proving the row-level sum lands correctly in storage,
     * not about the override behaviour, which has its own dedicated test.
     */
    private function submitAndGetDeal(Property $propA, Property $propB, float $priceA, float $commA, float $priceB, float $commB): Deal
    {
        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => $priceA + $priceB, 'total_commission' => $commA + $commB,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => $priceA, 'allocated_commission' => $commA],
                ['property_id' => $propB->id, 'allocated_price' => $priceB, 'allocated_commission' => $commB],
            ],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $deal = Deal::where('property_id', $propA->id)->latest('id')->first();
        $this->assertNotNull($deal, 'the deal must have saved');

        return $deal;
    }

    public function test_equal_commission_split_sums_correctly(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $deal = $this->submitAndGetDeal($propA, $propB, 100_000, 10_000, 100_000, 10_000);

        $this->assertSame('20000.00', $deal->total_commission);
    }

    public function test_unequal_commission_split_sums_correctly(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        // 7,000 + 13,000 — an uneven split.
        $deal = $this->submitAndGetDeal($propA, $propB, 100_000, 7_000, 100_000, 13_000);

        $this->assertSame('20000.00', $deal->total_commission);
    }

    public function test_one_property_carrying_the_entire_commission_sums_correctly(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        // Property A carries all 20,000; property B carries none.
        $deal = $this->submitAndGetDeal($propA, $propB, 100_000, 20_000, 100_000, 0);

        $this->assertSame('20000.00', $deal->total_commission);
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id, 'allocated_commission' => 0]);
    }

    public function test_zero_commission_on_one_property_sums_correctly(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $deal = $this->submitAndGetDeal($propA, $propB, 100_000, 0, 100_000, 15_000);

        $this->assertSame('15000.00', $deal->total_commission);
    }

    public function test_a_missing_commission_field_is_rejected_outright_not_silently_treated_as_zero(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 200_000, 'total_commission' => 10_000,
            'properties' => [
                // allocated_commission omitted entirely on the first row.
                ['property_id' => $propA->id, 'allocated_price' => 100_000],
                ['property_id' => $propB->id, 'allocated_price' => 100_000, 'allocated_commission' => 10_000],
            ],
        ]));

        $response->assertSessionHasErrors('properties.0.allocated_commission');
        $this->assertSame($countBefore, Deal::count(), 'a missing figure must be rejected outright, never coerced to zero and silently summed');
    }

    public function test_an_empty_string_commission_field_is_rejected_outright(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 200_000, 'total_commission' => 10_000,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 100_000, 'allocated_commission' => ''],
                ['property_id' => $propB->id, 'allocated_price' => 100_000, 'allocated_commission' => 10_000],
            ],
        ]));

        $response->assertSessionHasErrors('properties.0.allocated_commission');
        $this->assertSame($countBefore, Deal::count());
    }

    public function test_decimal_commission_split_sums_to_the_cent(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        // 6,666.67 + 13,333.33 = 20,000.00 exactly.
        $deal = $this->submitAndGetDeal($propA, $propB, 100_000, 6_666.67, 100_000, 13_333.33);

        $this->assertSame('20000.00', $deal->total_commission);
    }

    /**
     * AT-flow-fix, 2026-09-19 — SUPERSEDES the earlier "a mismatch is
     * rejected" design entirely. There is no client-typed total to
     * disagree with anymore; a submitted top-level total_commission that
     * doesn't match the row sum (however it got there — a stale request,
     * a crafted one) is simply overridden by the true sum. This is the
     * server-side integrity guarantee Johan asked to confirm still exists
     * — it does, unconditionally, and always has (DealPropertyPricingService::
     * recalculateTotals(), unchanged by this build).
     */
    public function test_a_submitted_commission_total_that_disagrees_with_the_row_sum_is_overridden_not_rejected(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            // Rows sum to 20,000; top-level total_commission deliberately
            // submitted as something else entirely.
            'property_value' => 200_000, 'total_commission' => 1,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 100_000, 'allocated_commission' => 6_666.66],
                ['property_id' => $propB->id, 'allocated_price' => 100_000, 'allocated_commission' => 13_333.33],
            ],
        ]));

        $response->assertSessionDoesntHaveErrors();
        $deal = Deal::where('property_id', $propA->id)->latest('id')->first();
        $this->assertNotNull($deal);
        $this->assertSame('19999.99', $deal->total_commission, 'stored figure must be the true row sum (19,999.99), never the submitted 1');
    }
}
