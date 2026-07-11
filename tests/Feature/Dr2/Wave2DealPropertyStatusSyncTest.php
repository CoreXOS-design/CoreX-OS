<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\AgencyDealSyncSettings;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 Wave 2 — Deal → Property → Portal status sync (Johan's design). Event-driven
 * (DealCreated / DealStageAdvanced / DealClosed → property status), agency-configurable,
 * OFF by default, prior status captured for the decline-revert companion.
 */
final class Wave2DealPropertyStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'W2 Co', 'slug' => 'w2-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'W2-' . Str::random(8), 'title' => '9 Sync Rd', 'address' => '9 Sync Rd',
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ]));
    }

    private function settings(array $over): void
    {
        AgencyDealSyncSettings::forAgency($this->agencyId)->update($over);
    }

    private function makeDeal(array $over = []): Deal
    {
        return Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(6000, 9999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $this->property->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ], $over));
    }

    public function test_off_by_default_deal_created_does_not_touch_property(): void
    {
        $this->makeDeal();
        $this->assertSame('for_sale', $this->property->fresh()->status, 'default OFF — no status change');
    }

    public function test_flag_on_marks_under_offer_and_captures_prior(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $this->makeDeal();
        $p = $this->property->fresh();
        $this->assertSame('under_offer', $p->status);
        $this->assertSame('for_sale', $p->pre_deal_offer_status);
    }

    public function test_no_linked_property_is_a_noop(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $this->makeDeal(['property_id' => null]);
        $this->assertSame('for_sale', $this->property->fresh()->status);
    }

    public function test_off_market_property_is_not_flagged(): void
    {
        Property::withoutEvents(fn () => $this->property->update(['status' => 'sold']));
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $this->makeDeal();
        $this->assertSame('sold', $this->property->fresh()->status, 'off-market never moved to under_offer');
    }

    public function test_sold_at_granted_milestone(): void
    {
        $this->settings(['sold_milestone' => 'granted']);
        $deal = $this->makeDeal();
        $deal->update(['accepted_status' => 'G']);
        $this->assertSame('sold', $this->property->fresh()->status);
    }

    public function test_registered_milestone_waits_for_R(): void
    {
        $this->settings(['sold_milestone' => 'registered']);
        $deal = $this->makeDeal();
        $deal->update(['accepted_status' => 'G']);
        $this->assertNotSame('sold', $this->property->fresh()->status, 'Granted does not sell when milestone=registered');
        $deal->update(['accepted_status' => 'R']);
        $this->assertSame('sold', $this->property->fresh()->status);
    }

    public function test_decline_reverts_to_prior_on_market_status(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]); // revert defaults ON
        $deal = $this->makeDeal();
        $this->assertSame('under_offer', $this->property->fresh()->status);
        $deal->update(['accepted_status' => 'D']); // Declined → DealClosed(lost) → revert
        $p = $this->property->fresh();
        $this->assertSame('for_sale', $p->status);
        $this->assertNull($p->pre_deal_offer_status);
    }

    public function test_revert_off_leaves_under_offer(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true, 'revert_property_on_deal_declined' => false]);
        $deal = $this->makeDeal();
        $deal->update(['accepted_status' => 'D']);
        $this->assertSame('under_offer', $this->property->fresh()->status);
    }
}
