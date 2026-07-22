<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Agency;
use App\Models\Billing\AgencySubscription;
use App\Models\Branch;
use App\Models\User;
use App\Services\Billing\BillingQuote;
use App\Services\Billing\SubscriptionPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The pricing engine — what an agency owes CoreX.
 *
 * Spec: .ai/specs/agency-billing.md §4, §11  (AT-11)
 *
 * The graduated-tier arithmetic is the single easiest thing in this feature to
 * get quietly wrong (charging 25 × R195 instead of walking the bands is a
 * R2 500/month error that nobody would notice until an agency queried their
 * invoice), so Johan's worked example is asserted verbatim.
 */
class SubscriptionPricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = app(SubscriptionPricingService::class);
    }

    private function makeAgency(string $name = 'Home Finders Coastal'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
    }

    /** Seats are ANY active, non-archived user — role is irrelevant (spec D1). */
    private function seatUsers(Agency $agency, int $count, array $overrides = []): void
    {
        User::factory()->count($count)->create(array_merge([
            'agency_id' => $agency->id,
            'is_active' => 1,
        ], $overrides));
    }

    private function total(Agency $agency): float
    {
        return $this->pricing->quoteFor($agency->fresh())->payableZar;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The reference case — Johan's worked example, asserted to the rand.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_25_seats_bills_6425_in_seats_plus_1495_base_equals_7920(): void
    {
        $lines = $this->pricing->lines('agency', seats: 25, branches: 1);

        // 10 × R295 = R2 950  |  10 × R250 = R2 500  |  5 × R195 = R975
        $this->assertSame(1495.00, $this->lineAmount($lines, 'Platform base fee'));
        $this->assertSame(2950.00, $this->lineAmount($lines, 'Your first 10 users'));
        $this->assertSame(2500.00, $this->lineAmount($lines, 'Users 11 to 20'));
        $this->assertSame(975.00,  $this->lineAmount($lines, 'Users 21 and up'));

        // Asserted by GROUP as well as by label: the billing page sections the
        // receipt on `group`, so a label rename must not be able to move money
        // out of the seat section without a test noticing.
        $this->assertSame(6425.00, $this->groupAmount($lines, 'seats'), 'The seat component must be R6 425.');
        $this->assertSame(1495.00, $this->groupAmount($lines, 'base'));

        $this->assertSame(7920.00, round(array_sum(array_column($lines, 'amount')), 2));
    }

    /** Every line must declare which section of the receipt it belongs to. */
    public function test_every_line_carries_a_group_the_page_can_section_on(): void
    {
        $lines = $this->pricing->lines('agency', seats: 25, branches: 3);

        foreach ($lines as $line) {
            $this->assertContains($line['group'], ['base', 'seats', 'branches'], "Line '{$line['label']}' has no valid group.");
            $this->assertNotSame('', $line['note'], "Line '{$line['label']}' has no plain-English explanation.");
        }

        $this->assertSame(1500.00, $this->groupAmount($lines, 'branches'));   // 2 extra × R750
    }

    /** Graduated, NOT a flat rate that switches: 25 seats is never 25 × R195. */
    public function test_seat_tiers_are_graduated_not_flat_switched(): void
    {
        $lines = $this->pricing->lines('agency', seats: 25, branches: 1);
        $flatAtLowestTier = 1495.00 + (25 * 195.00);   // = R6 370 — the wrong answer

        $this->assertNotSame($flatAtLowestTier, round(array_sum(array_column($lines, 'amount')), 2));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Team plan — flat headcount × R450
    // ─────────────────────────────────────────────────────────────────────────

    /** @dataProvider teamSeatProvider */
    public function test_team_plan_is_flat_headcount_times_450(int $seats, float $expected): void
    {
        $lines = $this->pricing->lines('team', $seats, branches: 1);

        $this->assertSame($expected, round(array_sum(array_column($lines, 'amount')), 2));
    }

    public static function teamSeatProvider(): array
    {
        return [
            'solo agent'      => [1, 450.00],
            'small shop'      => [7, 3150.00],
            'at the Team cap' => [10, 4500.00],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tier boundaries — where an off-by-one costs real money
    // ─────────────────────────────────────────────────────────────────────────

    /** @dataProvider agencyBoundaryProvider */
    public function test_agency_plan_tier_boundaries(int $seats, float $expectedSeatCost): void
    {
        $lines = $this->pricing->lines('agency', $seats, branches: 1);
        $seatCost = round(array_sum(array_column($lines, 'amount')), 2) - 1495.00;

        $this->assertSame($expectedSeatCost, round($seatCost, 2));
    }

    public static function agencyBoundaryProvider(): array
    {
        return [
            '10 seats — first band only'      => [10, 2950.00],                     // 10×295
            '11 seats — spills into band 2'   => [11, 2950.00 + 250.00],            // +1×250
            '20 seats — band 2 exactly full'  => [20, 2950.00 + 2500.00],           // 10×295 + 10×250
            '21 seats — spills into band 3'   => [21, 2950.00 + 2500.00 + 195.00],  // +1×195
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plan derivation — the 11th seat moves them
    // ─────────────────────────────────────────────────────────────────────────

    public function test_ten_seats_is_team_and_eleven_is_agency(): void
    {
        $this->assertSame(AgencySubscription::PLAN_TEAM,   $this->pricing->derivePlan(10));
        $this->assertSame(AgencySubscription::PLAN_AGENCY, $this->pricing->derivePlan(11));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Seat definition (spec D1) — the empty / lazy paths
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_agency_with_no_active_users_bills_zero_and_does_not_crash(): void
    {
        $agency = $this->makeAgency('Brand New Agency');
        $quote  = $this->pricing->quoteFor($agency);

        $this->assertSame(0, $quote->seats);
        $this->assertSame(0.0, $quote->payableZar);
        $this->assertTrue($quote->isEmpty());
    }

    public function test_deactivated_and_archived_users_are_not_billed(): void
    {
        $agency = $this->makeAgency('Margate Properties');

        $this->seatUsers($agency, 5);                              // 5 billable
        $this->seatUsers($agency, 2, ['is_active' => 0]);          // deactivated — free
        $archived = User::factory()->count(3)->create(['agency_id' => $agency->id, 'is_active' => 1]);
        $archived->each->delete();                                  // soft-deleted — free

        $this->assertSame(5, $this->pricing->billableSeats($agency));
        $this->assertSame(5 * 450.00, $this->total($agency));
    }

    /** Every role occupies a seat — an admin is billed exactly like an agent. */
    public function test_admins_and_principals_occupy_seats_just_like_agents(): void
    {
        $agency = $this->makeAgency('Shelly Beach Realty');

        $this->seatUsers($agency, 2, ['role' => 'agent']);
        $this->seatUsers($agency, 1, ['role' => 'admin']);
        $this->seatUsers($agency, 1, ['role' => 'principal']);

        $this->assertSame(4, $this->pricing->billableSeats($agency));
    }

    /**
     * Assistants are NOT billable seats (amends spec §3 D1, Johan 2026-07-22).
     * An assistant is an extension of one agent — active, with a login, but
     * never a separately-charged seat.
     */
    public function test_assistants_do_not_occupy_a_billable_seat(): void
    {
        $agency = $this->makeAgency('Uvongo Realty');

        $this->seatUsers($agency, 3, ['role' => 'agent']);                          // 3 billable
        $this->seatUsers($agency, 2, ['role' => 'assistant', 'is_assistant' => 1]); // active, but not billed

        $this->assertSame(3, $this->pricing->billableSeats($agency));
        $this->assertSame(3 * 450.00, $this->total($agency));
    }

    /** System Owners carry a NULL agency_id and must never land on anyone's bill. */
    public function test_system_owners_do_not_count_against_any_agency(): void
    {
        $agency = $this->makeAgency('Uvongo Estates');
        $this->seatUsers($agency, 3);

        User::factory()->create(['agency_id' => null, 'role' => 'super_admin', 'is_active' => 1]);

        $this->assertSame(3, $this->pricing->billableSeats($agency));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Branches — plan-agnostic, first one free (spec D2a)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_first_branch_is_free_and_the_rest_are_750_each(): void
    {
        $lines1 = $this->pricing->lines('team', seats: 5, branches: 1);
        $lines3 = $this->pricing->lines('team', seats: 5, branches: 3);

        $this->assertSame(2250.00, round(array_sum(array_column($lines1, 'amount')), 2));           // 5×450, no branch fee
        $this->assertSame(2250.00 + 1500.00, round(array_sum(array_column($lines3, 'amount')), 2)); // + 2×750
    }

    /**
     * D2a, Johan 2026-07-14: an 8-agent agency with 2 branches is on the TEAM
     * plan AND pays for the second branch. The plans are price shapes, not
     * feature sets — so a Team agency using a second branch is charged for it.
     */
    public function test_a_team_plan_agency_with_two_branches_is_charged_for_the_second(): void
    {
        $agency = $this->makeAgency('Port Shepstone Property Group');
        $this->seatUsers($agency, 8);

        Branch::create(['agency_id' => $agency->id, 'name' => 'Port Shepstone']);
        Branch::create(['agency_id' => $agency->id, 'name' => 'Uvongo']);

        $quote = $this->pricing->quoteFor($agency->fresh());

        $this->assertSame(AgencySubscription::PLAN_TEAM, $quote->derivedPlan);
        $this->assertSame(1, $quote->billableBranches);
        $this->assertSame((8 * 450.00) + 750.00, $quote->payableZar);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Custom amount (spec D5)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_a_custom_amount_replaces_the_computed_price_but_the_list_price_stays_visible(): void
    {
        $agency = $this->makeAgency('Ramsgate Realty');
        $this->seatUsers($agency, 6);   // list = 6 × 450 = R2 700

        AgencySubscription::forAgency((int) $agency->id)
            ->forceFill(['custom_amount_zar' => 1800.00, 'custom_amount_note' => 'Negotiated launch rate'])
            ->save();

        $quote = $this->pricing->quoteFor($agency->fresh());

        $this->assertSame(BillingQuote::BASIS_CUSTOM, $quote->basis);
        $this->assertSame(1800.00, $quote->payableZar);
        $this->assertSame(2700.00, $quote->computedZar, 'The list price must remain visible alongside the concession.');
        $this->assertSame(900.00,  $quote->savingZar());
    }

    /** 0.00 means "free, deliberately" and is NOT the same as NULL ("no override"). */
    public function test_a_custom_amount_of_zero_makes_the_agency_free(): void
    {
        $agency = $this->makeAgency('Founding Partner Agency');
        $this->seatUsers($agency, 9);

        AgencySubscription::forAgency((int) $agency->id)->forceFill(['custom_amount_zar' => 0.00])->save();

        $quote = $this->pricing->quoteFor($agency->fresh());

        $this->assertSame(BillingQuote::BASIS_CUSTOM, $quote->basis);
        $this->assertSame(0.0, $quote->payableZar);
        $this->assertSame(4050.00, $quote->computedZar);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Discount (spec D5)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_active_discount_comes_off_the_computed_price_and_counts_down(): void
    {
        $agency = $this->makeAgency('Southbroom Estates');
        $this->seatUsers($agency, 10);   // list = 10 × 450 = R4 500

        AgencySubscription::forAgency((int) $agency->id)->forceFill([
            'discount_percent'   => 20.00,
            'discount_months'    => 6,
            'discount_starts_on' => Carbon::now()->subMonths(2)->toDateString(),
            'discount_note'      => 'Launch offer',
        ])->save();

        $quote = $this->pricing->quoteFor($agency->fresh());

        $this->assertSame(BillingQuote::BASIS_DISCOUNTED, $quote->basis);
        $this->assertTrue($quote->discountActive);
        $this->assertSame(3600.00, $quote->payableZar);   // 4500 × 0.8
        $this->assertSame(900.00,  $quote->savingZar());
        $this->assertSame(4, $quote->discountMonthsRemaining, '2 of 6 months are gone, so 4 remain.');
    }

    /** Expiry is arithmetic — no cron, no cleanup, no way to forget. */
    public function test_an_expired_discount_silently_returns_the_price_to_normal(): void
    {
        $agency = $this->makeAgency('Hibberdene Homes');
        $this->seatUsers($agency, 4);

        AgencySubscription::forAgency((int) $agency->id)->forceFill([
            'discount_percent'   => 50.00,
            'discount_months'    => 3,
            'discount_starts_on' => Carbon::now()->subMonths(9)->toDateString(),   // long gone
        ])->save();

        $quote = $this->pricing->quoteFor($agency->fresh());

        $this->assertFalse($quote->discountActive);
        $this->assertSame(BillingQuote::BASIS_AUTOMATIC, $quote->basis);
        $this->assertSame(1800.00, $quote->payableZar);
        $this->assertSame(0, $quote->discountMonthsRemaining);
    }

    /** A discount agreed but not yet started must not discount anything yet. */
    public function test_a_future_dated_discount_is_not_active_yet(): void
    {
        $agency = $this->makeAgency('Scottburgh Property');
        $this->seatUsers($agency, 4);

        AgencySubscription::forAgency((int) $agency->id)->forceFill([
            'discount_percent'   => 25.00,
            'discount_months'    => 3,
            'discount_starts_on' => Carbon::now()->addMonth()->toDateString(),
        ])->save();

        $quote = $this->pricing->quoteFor($agency->fresh());

        $this->assertFalse($quote->discountActive);
        $this->assertSame(1800.00, $quote->payableZar);
    }

    /**
     * THE ABSORB LAYER. The UI and validation prevent this row from ever
     * existing — but if one is forced into the DB by hand, the read path must
     * still produce a correct price rather than double-discounting or crashing.
     * Custom amount wins; the discount is ignored entirely.
     */
    public function test_a_row_holding_both_a_custom_amount_and_a_discount_lets_custom_win(): void
    {
        $agency = $this->makeAgency('Impossible State Agency');
        $this->seatUsers($agency, 10);

        // Bypass the model and the form entirely — this is the SQL-hand-edit case.
        DB::table('agency_subscriptions')
            ->where('agency_id', $agency->id)
            ->update([
                'custom_amount_zar'  => 2000.00,
                'discount_percent'   => 50.00,
                'discount_months'    => 12,
                'discount_starts_on' => Carbon::now()->toDateString(),
            ]);

        $quote = $this->pricing->quoteFor($agency->fresh());

        $this->assertSame(BillingQuote::BASIS_CUSTOM, $quote->basis);
        $this->assertSame(2000.00, $quote->payableZar, 'Custom amount wins; the discount must NOT also apply.');
        $this->assertFalse($quote->discountActive);
    }

    /** What one section of the receipt (base | seats | branches) adds up to. */
    private function groupAmount(array $lines, string $group): float
    {
        $inGroup = array_filter($lines, fn (array $l) => $l['group'] === $group);

        return round(array_sum(array_column($inGroup, 'amount')), 2);
    }

    /** @param  list<array{group:string,label:string,note:string,qty:int,unit:float,amount:float}>  $lines */
    private function lineAmount(array $lines, string $label): float
    {
        foreach ($lines as $line) {
            if ($line['label'] === $label) {
                return round((float) $line['amount'], 2);
            }
        }

        $this->fail("Expected a billing line labelled '{$label}', found: "
            . implode(', ', array_column($lines, 'label')));
    }
}
