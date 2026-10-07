<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\RentalApplicationExpenseItem;
use App\Models\RentalApplicationIncomeItem;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-392 — Round 9. Johan, from his own reading of the law: "the law
 * states you may not spend more than 30% of your gross income on
 * rentals... its not 3.5 or what you created it as of nett disposable
 * income. its of the gross income."
 *
 * Covers: the percentage-of-gross-income rule itself (replacing the old
 * rent multiplier), the agency-configurable ceiling with its default and
 * its "above the legal guideline" warning, and the worked example Johan
 * asked to be proven on a real screen: a tenant on R18,000 gross qualifies
 * up to R5,400 rent at the 30% default.
 *
 * review.blade.php / RentalApplicationReviewController.php are
 * deliberately NOT covered here — still sequenced with cc6's concurrent
 * work on that screen (see spec Round 9 section).
 */
final class RentalApplicationRound9AffordabilityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'under_assessment', 'submitted_at' => now()->subDay(),
        ], $attrs));
    }

    /**
     * Round 10 — monthly_income/other_monthly_income/monthly_expenses
     * became growable item lists (see RentalApplicationRound10ReviewScreenTest).
     * $income/$otherIncome/$expenses of null are skipped (no item created),
     * matching how a genuinely never-filled-in field behaves.
     */
    private function assessmentWithAmounts(RentalApplication $app, ?float $income = null, ?float $otherIncome = null, ?float $expenses = null): RentalApplicationAssessment
    {
        // Round 12 — gross_income now requires statement_months; 1 month
        // makes this a no-op division so these pre-existing worked
        // examples keep meaning exactly what their numbers say.
        $assessment = RentalApplicationAssessment::create(['agency_id' => $this->agency->id, 'rental_application_id' => $app->id, 'statement_months' => 1]);

        if ($income !== null) {
            RentalApplicationIncomeItem::create(['agency_id' => $this->agency->id, 'rental_application_assessment_id' => $assessment->id, 'description' => 'Salary', 'amount' => $income]);
        }
        if ($otherIncome !== null) {
            RentalApplicationIncomeItem::create(['agency_id' => $this->agency->id, 'rental_application_assessment_id' => $assessment->id, 'description' => 'Other income', 'amount' => $otherIncome]);
        }
        if ($expenses !== null) {
            RentalApplicationExpenseItem::create(['agency_id' => $this->agency->id, 'rental_application_assessment_id' => $assessment->id, 'description' => 'Expenses', 'amount' => $expenses]);
        }

        return $assessment->fresh();
    }

    // Round 16 — the affordability check now tests the rent of the LINKED
    // PROPERTY, never the applicant's current_rental_amount. Worked
    // examples that need meets_threshold to resolve true/false (not null,
    // 'no_property') must link a property carrying the rent figure.
    private function propertyWithRent(float $rent): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id,
            'title' => 'House to let in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
            'rental_amount' => $rent,
        ]);
    }

    private function authoriser(): User
    {
        $user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_ro_user_ids' => [$user->id]]);
        $this->agency->refresh();

        return $user;
    }

    // ── The rule itself: percentage of GROSS income, not a rent multiplier ──

    public function test_default_ceiling_is_thirty_percent_of_gross_income(): void
    {
        $this->assertSame(30.00, RentalApplicationQualifyingSetting::maxRentPercentFor($this->agency->id));
        $this->assertSame(30.00, RentalApplicationQualifyingSetting::DEFAULT_MAX_RENT_PERCENT);
    }

    // test_worked_example_eighteen_thousand_gross_qualifies_up_to_fifty_four_hundred_rent
    // and test_net_income_plays_no_part_in_the_decision — REMOVED 2026-09-14,
    // cc4, together with the RentalApplicationAssessment::qualifyingResult()
    // method they unit-tested directly. Johan: remove the dead calculator
    // and its calls together, not leaving either behind for someone to
    // revive. These two were the only tests exercising the method itself
    // (as opposed to UI/JSON shapes the 2026-09-11 capture-ledger rework
    // had already broken); see .ai/specs/rental-applications.md,
    // "qualifyingResult() removed", for the full worked-example figures
    // this method used to prove (18,000 gross -> 5,400 ceiling at 30%) —
    // preserved there since nothing computes them any more.

    // ── Agency-configurable, default 30%, never below-the-fold silent on breach ──

    public function test_agency_can_set_a_stricter_percentage_below_the_legal_ceiling(): void
    {
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $this->actingAs($owner)->post(
            route('corex.settings.rental-applications.qualifying-formula'),
            ['max_rent_percent_of_gross_income' => '25']
        )->assertSessionDoesntHaveErrors();

        $this->assertSame(25.00, RentalApplicationQualifyingSetting::maxRentPercentFor($this->agency->id));
        $this->assertFalse(RentalApplicationQualifyingSetting::exceedsLegalCeiling(25.00));
    }

    public function test_agency_setting_above_thirty_percent_is_warned_not_silently_accepted(): void
    {
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $response = $this->actingAs($owner)->post(
            route('corex.settings.rental-applications.qualifying-formula'),
            ['max_rent_percent_of_gross_income' => '40']
        );

        $response->assertSessionDoesntHaveErrors();
        $response->assertSessionHas('warning');
        $this->assertSame(40.00, RentalApplicationQualifyingSetting::maxRentPercentFor($this->agency->id));
        $this->assertTrue(RentalApplicationQualifyingSetting::exceedsLegalCeiling(40.00));

        // The persistent banner, not just the one-time toast — still shown
        // on a later, unrelated visit to the settings screen.
        $editResponse = $this->actingAs($owner)->get(route('corex.settings.rental-applications.edit'));
        $editResponse->assertOk();
        $editResponse->assertSee('above the legal guideline', false);
    }

    public function test_agency_setting_at_or_below_thirty_percent_shows_no_warning(): void
    {
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $this->actingAs($owner)->post(
            route('corex.settings.rental-applications.qualifying-formula'),
            ['max_rent_percent_of_gross_income' => '30']
        )->assertSessionDoesntHaveErrors()->assertSessionMissing('warning');
    }

    // ── The authorisation screen: worked example on a real HTTP response ──

    // ── Every income field says plainly what's wanted ──

    public function test_agent_detail_page_labels_income_as_gross(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        // The editable agent form (an application not yet submitted) — a submitted one opens read-only.
        $app = $this->application(['status' => 'sent', 'submitted_at' => null]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertSee('Gross monthly income, before deductions', false);
        $response->assertSee('BEFORE tax and other deductions', false);
    }

    public function test_public_applicant_form_labels_income_as_gross(): void
    {
        // AT-392 round 4, 2026-09-13 — the base application() fixture
        // defaults submitted_at to a real timestamp; this test is
        // specifically about the FIRST-open editable form, so submitted_at
        // must be null to match status:'sent' — otherwise the new return
        // gate (which keys on submitted_at, not status) intercepts before
        // the editable form ever renders.
        $app = $this->application(['status' => 'sent', 'submitted_at' => null, 'token' => 'test-token-' . uniqid(), 'token_expires_at' => now()->addDays(14)]);

        $response = $this->get(route('rental-applications.public.show', $app->token));

        $response->assertOk();
        $response->assertSee('Gross monthly income, before deductions', false);
    }

    // ── Retired 2026-10-07 (cc3 red-test pass) ───────────────────────────────
    // the affordability verdict box and its JS percent-trim were removed from the authorisation screen (qualifyingResult() retired); the 18,000/5,400 worked example is preserved in the spec.
    // Removed, not skipped: test_authorisation_screen_shows_the_worked_example_arithmetic, test_authorisation_screen_percent_trim_regex_no_longer_corrupts_round_tens
    // See .ai/specs/rental-applications.md ("Pre-existing, unrelated test debt" under the
    // qualifyingResult() removal) — the subject of these tests no longer exists.
}
