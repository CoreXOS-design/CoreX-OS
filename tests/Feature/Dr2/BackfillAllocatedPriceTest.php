<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealProperty;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-398 — defect cc1's browser pass found: the original migration backfilled
 * every pre-existing deal's `is_primary` deal_properties row but never set
 * its allocated_price/allocated_commission, so an old single-property deal
 * showed R0.00 on the new per-property price card even though the deal's
 * own (correct, unaffected) property_value/total_commission was fine all
 * along. This proves the FOLLOW-UP migration
 * (2026_09_10_120000_backfill_allocated_price_for_existing_single_property_deals)
 * corrects that stale state, is idempotent, and touches ONLY what it says —
 * never a non-primary row, never a row that already has a real price.
 */
final class BackfillAllocatedPriceTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Backfill Co', 'slug' => 'bf-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeProperty(string $address): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'BF-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ]));
    }

    private function makeDeal(Property $property): Deal
    {
        return Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(4000, 4999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $property->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 875000, 'total_commission' => 50312.50,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);
    }

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_09_10_120000_backfill_allocated_price_for_existing_single_property_deals.php');
        $migration->up();
    }

    public function test_backfill_corrects_a_stale_primary_row_from_the_deals_own_totals(): void
    {
        $property = $this->makeProperty('1 Backfill Rd');
        $deal = $this->makeDeal($property);
        // Force the row back to the stale pre-backfill state — Deal::syncPrimaryPropertyPivot()
        // already mirrored it live on create, so this simulates the ORIGINAL bug directly.
        DealProperty::where('deal_id', $deal->id)->update(['allocated_price' => null, 'allocated_commission' => null]);

        $this->runBackfill();

        $row = DealProperty::where('deal_id', $deal->id)->first();
        $this->assertSame('875000.00', (string) $row->allocated_price);
        $this->assertSame('50312.50', (string) $row->allocated_commission);
    }

    public function test_backfill_is_idempotent(): void
    {
        $property = $this->makeProperty('2 Backfill Idempotent Rd');
        $deal = $this->makeDeal($property);
        DealProperty::where('deal_id', $deal->id)->update(['allocated_price' => null, 'allocated_commission' => null]);

        $this->runBackfill();
        $firstPass = DealProperty::where('deal_id', $deal->id)->first()->allocated_price;
        $this->runBackfill(); // second pass — nothing left to correct

        $this->assertSame($firstPass, DealProperty::where('deal_id', $deal->id)->first()->allocated_price);
    }

    public function test_backfill_never_overwrites_a_row_that_already_has_a_real_price(): void
    {
        $property = $this->makeProperty('3 Backfill Real Rd');
        $deal = $this->makeDeal($property);
        // A row that already has its own, correctly-entered allocation — must survive untouched
        // even though the deal's own totals differ (proves this only fills NULLs, never overwrites).
        DealProperty::where('deal_id', $deal->id)->update(['allocated_price' => 123456.78, 'allocated_commission' => 9999.99]);

        $this->runBackfill();

        $row = DealProperty::where('deal_id', $deal->id)->first();
        $this->assertSame('123456.78', (string) $row->allocated_price);
        $this->assertSame('9999.99', (string) $row->allocated_commission);
    }

    public function test_backfill_never_touches_a_non_primary_row(): void
    {
        $propA = $this->makeProperty('4 Backfill Primary Rd');
        $propB = $this->makeProperty('4 Backfill Secondary Rd');
        $deal = $this->makeDeal($propA);
        $secondRow = DealProperty::create(['deal_id' => $deal->id, 'property_id' => $propB->id, 'is_primary' => false]);
        DealProperty::where('id', $secondRow->id)->update(['allocated_price' => null, 'allocated_commission' => null]);

        $this->runBackfill();

        $this->assertNull(DealProperty::find($secondRow->id)->allocated_price, 'a non-primary NULL is not this migration\'s concern — it has no deal-level total to mirror from');
    }
}
