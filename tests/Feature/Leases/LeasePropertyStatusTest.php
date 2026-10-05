<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\User;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-444 item 7 (GATE 2, approved 2026-10-04 WITH Johan's no-new-status
 * change) — property status follows the lease through the EXISTING
 * status mechanism only. Rows 1 (built in AT-440) and 5 (deliberately
 * never automatic) are not re-tested here; this covers rows 2/3/4/6/7,
 * their settings toggles, reversal, and the portal (isOnMarket())
 * consequence of each.
 */
final class LeasePropertyStatusTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Status Agency', 'slug' => 'status-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Status test property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function activeLease(array $overrides = []): Lease
    {
        $lease = Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000, 'start_date' => now()->subMonths(2)->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ], $overrides));

        return app(LeaseActivationService::class)->activate($lease);
    }

    public function test_row1_lease_activation_captures_status_before_letting_and_flips_to_let_out(): void
    {
        self::assertSame('active', $this->property->status);

        $lease = $this->activeLease();

        $property = $this->property->fresh();
        self::assertSame('let_out', $property->status);
        self::assertSame('active', $property->status_before_letting);
        self::assertFalse($property->isOnMarket(), 'let_out must be off-market.');
    }

    public function test_row2_notice_with_readvertise_ticked_puts_property_back_on_market(): void
    {
        $lease = $this->activeLease();
        $moveOut = now()->addDays(30)->toDateString();

        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, $moveOut, null, $this->agent, Lease::NOTICE_OUTCOME_READVERTISE);

        $property = $this->property->fresh();
        self::assertSame(LeaseSetting::DEFAULT_PRE_LET_STATUS, $property->status, "Row 2 ALWAYS uses the agency's on-market rental status — never status_before_letting directly.");
        self::assertTrue($property->isOnMarket(), 'Row 2 ticked must put the listing back on-market.');
        self::assertSame(now()->addDays(31)->toDateString(), $property->lease_start_date->toDateString(), 'Availability date = day after move-out.');
    }

    /**
     * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (3): the
     * notice dialog's own "Show available-from date on portals" tick
     * persists onto the property's own setting in the SAME write.
     */
    public function test_row2_readvertise_persists_the_show_available_from_on_portals_tick(): void
    {
        $lease = $this->activeLease();

        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_READVERTISE, false);

        self::assertFalse((bool) $this->property->fresh()->show_available_from_on_portals);
    }

    /**
     * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (1): the
     * "Withdraw" arm uses the EXISTING `withdrawn` status, applied
     * IMMEDIATELY (same timing as readvertise — ruling #2, no new timing
     * rule invented). No availability date is written for a withdrawn
     * listing.
     */
    public function test_row2_withdraw_sets_the_existing_withdrawn_status_immediately(): void
    {
        $lease = $this->activeLease();
        $beforeLeaseStart = $this->property->fresh()->lease_start_date;

        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_LANDLORD, now()->addDays(30)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_WITHDRAW);

        $property = $this->property->fresh();
        self::assertSame('withdrawn', $property->status);
        self::assertFalse($property->isOnMarket());
        self::assertEquals($beforeLeaseStart, $property->lease_start_date, 'Withdraw does not touch the availability date.');
    }

    public function test_row2_withdraw_is_reversed_by_reverse_notice(): void
    {
        $lease = $this->activeLease();
        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_LANDLORD, now()->addDays(30)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_WITHDRAW);
        self::assertSame('withdrawn', $this->property->fresh()->status);

        app(LeaseRenewalService::class)->reverseNotice($lease->fresh(), $this->agent);

        self::assertSame('let_out', $this->property->fresh()->status);
    }

    /**
     * .ai/specs/rental-renewals.md §19 — "let the agent change the choice
     * later" (Johan's ruling): changing from readvertise to withdraw
     * reverses the back-on-market effect and applies withdrawn instead,
     * in one call, without touching move_out_date/notice_given_by.
     */
    public function test_change_notice_outcome_reverses_the_old_effect_and_applies_the_new_one(): void
    {
        $lease = $this->activeLease();
        $moveOut = now()->addDays(30)->toDateString();
        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, $moveOut, null, $this->agent, Lease::NOTICE_OUTCOME_READVERTISE);
        self::assertTrue($this->property->fresh()->isOnMarket());

        $updated = app(LeaseRenewalService::class)->changeNoticeOutcome($lease->fresh(), Lease::NOTICE_OUTCOME_WITHDRAW, $this->agent);

        $property = $this->property->fresh();
        self::assertSame('withdrawn', $property->status);
        self::assertFalse($property->isOnMarket());
        self::assertSame(Lease::NOTICE_OUTCOME_WITHDRAW, $updated->notice_outcome);
        self::assertSame($moveOut, $updated->move_out_date->toDateString(), 'Changing the outcome must not touch the recorded move-out date.');
        self::assertSame('tenant', $updated->notice_given_by, 'Changing the outcome must not touch who gave notice.');
    }

    public function test_change_notice_outcome_requires_an_active_notice(): void
    {
        $lease = $this->activeLease();

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(LeaseRenewalService::class)->changeNoticeOutcome($lease, Lease::NOTICE_OUTCOME_WITHDRAW, $this->agent);
    }

    public function test_change_notice_outcome_rejects_an_invalid_value(): void
    {
        $lease = $this->activeLease();
        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_LEAVE);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(LeaseRenewalService::class)->changeNoticeOutcome($lease->fresh(), 'not-a-real-outcome', $this->agent);
    }

    /**
     * Proves row 2 and rows 6/7 are genuinely DIFFERENT mechanisms: row 2
     * always uses the on-market setting, even when status_before_letting
     * holds something else. Deliberately sets the two to different values
     * so a regression that conflates them (as an earlier build of this
     * file did) fails loudly instead of coincidentally passing.
     */
    public function test_row2_uses_the_on_market_setting_even_when_it_differs_from_status_before_letting(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'default_pre_let_status' => 'for_sale']);
        $lease = $this->activeLease();
        self::assertSame('active', $this->property->fresh()->status_before_letting);

        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_READVERTISE);

        self::assertSame('for_sale', $this->property->fresh()->status, "Row 2 must use the agency's configured setting, not the captured status_before_letting.");
    }

    public function test_row2_notice_left_as_is_leaves_property_let_out(): void
    {
        $lease = $this->activeLease();
        $moveOut = now()->addDays(30)->toDateString();

        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, $moveOut, null, $this->agent, Lease::NOTICE_OUTCOME_LEAVE);

        $property = $this->property->fresh();
        self::assertSame('let_out', $property->status);
        self::assertFalse($property->isOnMarket());
    }

    public function test_row2_reversing_notice_restores_let_out(): void
    {
        $lease = $this->activeLease();
        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $this->agent, Lease::NOTICE_OUTCOME_READVERTISE);
        self::assertTrue($this->property->fresh()->isOnMarket());

        app(LeaseRenewalService::class)->reverseNotice($lease->fresh(), $this->agent);

        $property = $this->property->fresh();
        self::assertSame('let_out', $property->status);
        self::assertFalse($property->isOnMarket());
    }

    /**
     * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (1)
     * removed this setting's only consumer (it used to default the notice
     * dialog's pre-ticked checkbox; the dialog no longer pre-selects
     * anything, ever). The setting/column/getter are left exactly as they
     * were — removing them is a separate, bigger call than this ticket's
     * explicit scope — so this is now a plain getter test, not a behaviour
     * test. Flagged to Johan in this build's own report.
     */
    public function test_row2_respects_the_agency_toggle_default(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'auto_readvertise_on_notice' => false]);
        self::assertFalse(LeaseSetting::autoReadvertiseOnNoticeFor($this->agency->id));
        self::assertTrue(LeaseSetting::autoReadvertiseOnNoticeFor(null), 'No-agency default stays ON.');
    }

    public function test_row3_renewal_signed_leaves_property_let_out(): void
    {
        $previous = $this->activeLease();
        self::assertSame('let_out', $this->property->fresh()->status);

        $renewal = app(LeaseRenewalService::class)->createRenewalTerm($previous->fresh(), [
            'start_date' => now()->addDay()->toDateString(), 'rental_amount' => 9500,
        ], $this->agent);
        app(LeaseRenewalService::class)->activateRenewalTerm($renewal, $this->agent);

        $property = $this->property->fresh();
        self::assertSame('let_out', $property->status, 'Row 3 — renewal signed must not change property status.');
        self::assertFalse($property->isOnMarket());
    }

    public function test_row4_month_to_month_leaves_property_let_out(): void
    {
        $lease = $this->activeLease();

        app(LeaseRenewalService::class)->recordMonthToMonth($lease, null, $this->agent);

        $property = $this->property->fresh();
        self::assertSame('let_out', $property->status, 'Row 4 — month-to-month must not change property status.');
        self::assertFalse($property->isOnMarket());
    }

    public function test_row5_end_date_passing_with_no_outcome_leaves_property_untouched(): void
    {
        $lease = $this->activeLease(['end_date' => now()->subDay()->toDateString()]);

        $property = $this->property->fresh();
        self::assertSame('let_out', $property->status, 'Row 5 — never automatic; property stays exactly as row 1 left it.');
    }

    public function test_row6_out_inspection_completion_restores_pre_let_status(): void
    {
        $lease = $this->activeLease();
        self::assertTrue((bool) $this->property->fresh()->status_before_letting);

        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'status' => 'scheduled',
        ]);
        $inspection->update(['status' => RentalInspection::STATUS_COMPLETED]);

        $property = $this->property->fresh();
        self::assertSame('active', $property->status);
        self::assertTrue($property->isOnMarket(), 'Row 6 — ended + confirmed vacant must re-list.');
        self::assertSame(now()->toDateString(), $property->lease_start_date->toDateString());
        self::assertNull($property->status_before_letting, 'Cleared so the NEXT lease cycle captures fresh.');
        self::assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status);
    }

    public function test_row6_respects_the_agency_toggle(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'auto_restore_status_on_lease_ended' => false]);
        $lease = $this->activeLease();

        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_OUT, 'status' => 'scheduled',
        ]);
        $inspection->update(['status' => RentalInspection::STATUS_COMPLETED]);

        self::assertSame('let_out', $this->property->fresh()->status, 'Toggle OFF must skip the automatic write.');
    }

    public function test_row6_in_type_inspection_completion_is_ignored(): void
    {
        $lease = $this->activeLease();

        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_IN, 'status' => 'scheduled',
        ]);
        $inspection->update(['status' => RentalInspection::STATUS_COMPLETED]);

        self::assertSame('let_out', $this->property->fresh()->status, 'Only an OUT inspection triggers row 6.');
    }

    public function test_row7_lease_cancellation_restores_pre_let_status(): void
    {
        $lease = $this->activeLease();

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Tenant breach'])
            ->assertRedirect();

        $property = $this->property->fresh();
        self::assertSame('active', $property->status);
        self::assertTrue($property->isOnMarket());
        self::assertSame(now()->toDateString(), $property->lease_start_date->toDateString());
        self::assertSame(Lease::STATUS_CANCELLED, $lease->fresh()->status);
    }

    public function test_row7_cancelling_a_never_activated_draft_does_not_touch_the_property(): void
    {
        $draft = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $draft), ['cancel_reason' => 'Changed mind'])
            ->assertRedirect();

        self::assertSame('active', $this->property->fresh()->status, 'A draft never activated never flipped the property — nothing to restore.');
        self::assertNull($this->property->fresh()->status_before_letting);
    }

    public function test_row7_respects_the_agency_toggle(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'auto_restore_status_on_lease_cancelled' => false]);
        $lease = $this->activeLease();

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Tenant breach'])
            ->assertRedirect();

        self::assertSame('let_out', $this->property->fresh()->status, 'Toggle OFF must skip the automatic write.');
    }

    public function test_fallback_default_pre_let_status_is_used_when_nothing_was_ever_captured(): void
    {
        // A lease active before this feature shipped — status_before_letting
        // was never captured for it.
        $this->property->status = 'let_out';
        $this->property->save();
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subYear()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        self::assertNull($this->property->fresh()->status_before_letting);

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Tenant breach'])
            ->assertRedirect();

        self::assertSame(LeaseSetting::DEFAULT_PRE_LET_STATUS, $this->property->fresh()->status);
    }
}
