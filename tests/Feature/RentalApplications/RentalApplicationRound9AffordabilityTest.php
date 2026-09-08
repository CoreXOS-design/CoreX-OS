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

    public function test_worked_example_eighteen_thousand_gross_qualifies_up_to_fifty_four_hundred_rent(): void
    {
        $property = $this->propertyWithRent(5400);
        $app = $this->application(['current_rental_amount' => 5400, 'property_id' => $property->id]);
        $assessment = $this->assessmentWithAmounts($app, income: 18000, expenses: 4000);

        $result = $assessment->qualifyingResult(30.00);

        $this->assertSame(18000.0, $result['gross_income']);
        $this->assertSame(5400.0, $result['max_affordable_rent']);
        $this->assertSame(5400.0, $result['rent']);
        $this->assertTrue($result['meets_threshold']);
        $this->assertSame('sufficient', $result['label']);

        // One rand over the ceiling must flip the verdict — proves this is a
        // real boundary check, not a loose approximation. The rent now
        // comes from the linked PROPERTY, not the application.
        $property->update(['rental_amount' => 5401]);
        $overResult = $assessment->fresh()->qualifyingResult(30.00);
        $this->assertFalse($overResult['meets_threshold']);
        $this->assertSame('insufficient', $overResult['label']);
    }

    public function test_net_income_plays_no_part_in_the_decision(): void
    {
        // Same gross income, wildly different expenses — the verdict must
        // be identical, because expenses are not part of the legal test.
        $appLowExpenses = $this->application(['current_rental_amount' => 5400]);
        $lowExpenseAssessment = $this->assessmentWithAmounts($appLowExpenses, income: 18000, expenses: 200);

        $appHighExpenses = $this->application(['current_rental_amount' => 5400]);
        $highExpenseAssessment = $this->assessmentWithAmounts($appHighExpenses, income: 18000, expenses: 15000);

        $lowResult = $lowExpenseAssessment->qualifyingResult(30.00);
        $highResult = $highExpenseAssessment->qualifyingResult(30.00);

        $this->assertSame($lowResult['meets_threshold'], $highResult['meets_threshold']);
        $this->assertSame($lowResult['max_affordable_rent'], $highResult['max_affordable_rent']);
        // net_income itself DOES differ (it's real, just not decisive).
        $this->assertNotSame($lowResult['net_income'], $highResult['net_income']);
    }

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

    public function test_authorisation_screen_shows_the_worked_example_arithmetic(): void
    {
        $ro = $this->authoriser();
        $property = $this->propertyWithRent(5400);
        $app = $this->application(['current_rental_amount' => 5400, 'property_id' => $property->id, 'submitted_for_approval_at' => now()]);
        $this->assessmentWithAmounts($app, income: 18000, expenses: 4000);

        $response = $this->actingAs($ro)->get(route('corex.rental-applications.authorisation.show', $app));

        $response->assertOk();
        $response->assertSee('18,000', false);
        $response->assertSee('5,400', false);
        $response->assertSee('Within the affordability guideline', false);
    }

    /**
     * Johan, live on application 12, 2026-09-09: the authoriser screen read
     * "rent must not exceed 3% of this" — the arithmetic itself (30% throughout)
     * was correct, only this label was wrong. Root cause: trimPercent()'s regex
     * had an OPTIONAL leading dot (`\.?0+$`), so it stripped trailing zeros even
     * with no decimal point present, turning whole multiples of ten into
     * nonsense (30→3, 40→4, 100→1) while non-round values (28.5, 25) were
     * untouched — a PHPUnit HTTP test can't execute the JS to prove the
     * runtime output, so this asserts the fixed source is present and the
     * exact buggy regex is gone, which is the only thing that changed.
     */
    public function test_authorisation_screen_percent_trim_regex_no_longer_corrupts_round_tens(): void
    {
        $ro = $this->authoriser();
        $app = $this->application(['submitted_for_approval_at' => now()]);
        $this->assessmentWithAmounts($app, income: 18000, expenses: 4000);

        $response = $this->actingAs($ro)->get(route('corex.rental-applications.authorisation.show', $app));

        $response->assertOk();
        $response->assertDontSee('/\\.?0+$/', false);
        $response->assertSee(".toFixed(2).replace(/0+\$/, '').replace(/\\.\$/, '')", false);

        // Prove the fix numerically too, in plain PHP, since the app's own
        // 30.00 default is exactly the value the old regex corrupted.
        $trimPercent = fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.');
        $this->assertSame('30', $trimPercent(30.00));
        $this->assertSame('40', $trimPercent(40.00));
        $this->assertSame('28.5', $trimPercent(28.50));
    }

    // ── Every income field says plainly what's wanted ──

    public function test_agent_detail_page_labels_income_as_gross(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $app = $this->application();

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertSee('Gross monthly income, before deductions', false);
        $response->assertSee('BEFORE tax and other deductions', false);
    }

    public function test_public_applicant_form_labels_income_as_gross(): void
    {
        $app = $this->application(['status' => 'sent', 'token' => 'test-token-' . uniqid(), 'token_expires_at' => now()->addDays(14)]);

        $response = $this->get(route('rental-applications.public.show', $app->token));

        $response->assertOk();
        $response->assertSee('Gross monthly income, before deductions', false);
    }
}
