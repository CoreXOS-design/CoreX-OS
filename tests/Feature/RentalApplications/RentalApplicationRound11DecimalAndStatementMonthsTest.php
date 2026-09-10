<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AT-392 — Round 11. Johan: "the comma rule is bust. everyone will enter
 * amounts like 40638.40, the std that everyone uses. right now hitting the
 * . on an amount clears the values." Root cause: type="number" inputs
 * bound with Alpine's x-model write the browser's own parsed .value back
 * into the DOM on every keystroke; a lone trailing "." doesn't parse as a
 * number yet, so the write-back silently drops it mid-type. Fixed by
 * switching every money input to type="text" inputmode="decimal" (no
 * native number parsing to interfere) and rewriting
 * RentalApplication::sanitizeNumericInput() to Johan's own disambiguation
 * rule: the LAST separator followed by exactly two digits is the decimal
 * point; everything else is a thousands mark.
 *
 * Also covers item 3 — the "number of months this bank statement covers"
 * field and the monthly-average DISPLAY it produces, deliberately NOT yet
 * wired into qualifyingResult()'s gross_income/meets_threshold pending
 * Johan's confirmation.
 */
final class RentalApplicationRound11DecimalAndStatementMonthsTest extends TestCase
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
            'created_by_user_id' => $agent->id, 'status' => 'returned', 'submitted_at' => now()->subDay(),
        ], $attrs));
    }

    private function agent(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    // Round 16 — the affordability check now tests the rent of the LINKED
    // PROPERTY, never the applicant's current_rental_amount. Tests that
    // need meets_threshold to resolve to a real true/false (not null) must
    // link a property carrying the rent figure the worked example expects.
    private function propertyWithRent(float $rent, User $agent): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id,
            'title' => 'House to let in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
            'rental_amount' => $rent,
        ]);
    }

    // ── Item 1 — Johan's exact disambiguation rule, every stated format ──

    #[DataProvider('johansExactFormats')]
    public function test_disambiguation_rule_resolves_every_format_johan_gave(string $typed, string $expected): void
    {
        $result = RentalApplication::sanitizeNumericInput(['amount' => $typed], ['amount']);

        $this->assertSame($expected, $result['amount'], "typed '{$typed}' must resolve to {$expected}");
    }

    public static function johansExactFormats(): array
    {
        return [
            'plain decimal, the standard everyone uses' => ['40638.40', '40638.40'],
            'comma thousands, dot decimal' => ['40,638.40', '40638.40'],
            'space thousands, dot decimal' => ['40 638.40', '40638.40'],
            'R-prefixed, space thousands, dot decimal' => ['R40 638.40', '40638.40'],
            'comma AS the decimal point' => ['40638,40', '40638.40'],
            'comma thousands, no decimal at all' => ['40,638', '40638'],
            'plain whole number, untouched' => ['40638', '40638'],
            // Existing Round 8 cases — must still resolve identically.
            'existing case: comma thousands' => ['8,500', '8500'],
            'existing case: space thousands' => ['8 500', '8500'],
            'existing case: R-prefixed space thousands' => ['R8 500', '8500'],
            'existing case: plain decimal' => ['8500.50', '8500.50'],
        ];
    }

    public function test_a_keystroke_never_wipes_what_was_already_typed(): void
    {
        // The literal failure mode: typing the "." character on its own
        // must never be treated as invalid/rejected by the server-side
        // sanitizer (the CLIENT-side fix is the input type change; this
        // proves the server never makes the situation worse either).
        $result = RentalApplication::sanitizeNumericInput(['amount' => '40638.'], ['amount']);

        // No 2 digits after the last separator -> treated as a thousands
        // mark and stripped, same as any other incomplete/ambiguous case
        // — but critically, it does NOT throw, error, or return empty.
        $this->assertNotSame('', $result['amount']);
        $this->assertSame('40638', $result['amount']);
    }

    public function test_review_screen_income_amount_field_is_not_a_native_number_input(): void
    {
        // Regression guard for the actual root cause: a native
        // type="number" input combined with Alpine's x-model write-back
        // is what silently ate the "." — never let this field revert to
        // type="number".
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertDontSee('type="number" inputmode="decimal"', false);
        $response->assertSee('type="text" inputmode="decimal"', false);
    }

    public function test_authoriser_approve_amount_field_is_not_a_native_number_input(): void
    {
        $ro = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_ro_user_ids' => [$ro->id]]);
        $this->agency->refresh();
        $app = $this->application(['status' => 'under_assessment', 'submitted_for_approval_at' => now()]);

        $response = $this->actingAs($ro)->get(route('corex.rental-applications.authorisation.show', $app));

        $response->assertOk();
        $response->assertSee('type="text" inputmode="decimal" x-model="approveAmount"', false);
    }

    public function test_settings_percentage_field_is_not_run_through_the_money_disambiguation_rule(): void
    {
        // Johan's own rule assumes a Rand-and-cents shape (exactly two
        // decimal digits). "28.5" has only one — the money rule would
        // misread it as a thousands-separated whole number ("285"). This
        // was flagged to Johan rather than silently applied; confirms the
        // settings endpoint's own plain-trim path instead.
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $this->actingAs($owner)->post(
            route('corex.settings.rental-applications.qualifying-formula'),
            ['max_rent_percent_of_gross_income' => '28.5']
        )->assertSessionDoesntHaveErrors();

        $this->assertSame(28.50, \App\Models\RentalApplicationQualifyingSetting::maxRentPercentFor($this->agency->id));
    }

    // ── Item 3, Round 12 — statement months WIRED into the decision ──────
    // Johan, plainly, after the "confirm before wiring" question confused
    // him: "whatever the agent captured get averaged by the months
    // selected - 10000, 10000, 13000 tallies to 33000, agent selected 3
    // months - so the avg income is? 11000? what else are you on about?"

    public function test_johans_exact_worked_example(): void
    {
        $agent = $this->agent();
        $property = $this->propertyWithRent(3300, $agent);
        $app = $this->application(['current_rental_amount' => 3300, 'property_id' => $property->id]);

        // "Dates on entries" (2026-09-10) — statement_months is derived from
        // a from/to range now, never typed directly. Jan-Mar inclusive is
        // Johan's same "3 months."
        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.assessment', $app),
            [
                'income_items' => [
                    ['description' => 'Bank statement line 1', 'amount' => '10000'],
                    ['description' => 'Bank statement line 2', 'amount' => '10000'],
                    ['description' => 'Bank statement line 3', 'amount' => '13000'],
                ],
                'statement_period_from' => '2026-01-01',
                'statement_period_to' => '2026-03-31',
            ]
        );

        $response->assertOk();
        $data = $response->json();
        $this->assertEquals(33000.0, $data['result']['total_captured_income'], 'the three lines must tally to 33,000');
        $this->assertEquals(11000.0, $data['result']['gross_income'], '33,000 over 3 months must average to 11,000');
        $this->assertEquals(3300.0, $data['result']['max_affordable_rent'], '30% of 11,000 must be exactly 3,300');
        $this->assertTrue($data['result']['meets_threshold']);
        $this->assertSame('sufficient', $data['result']['label']);

        // The number stored must be the number displayed — no drift.
        $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
        $this->assertSame(3, $assessment->statement_months);
        $this->assertEquals(33000.0, (float) $assessment->incomeItems->sum('amount'));
    }

    public function test_monthly_average_now_drives_the_decision_not_the_raw_total(): void
    {
        // Same raw total (18000), different statement lengths — the
        // DECISION must now differ, proving the average, not the lump
        // sum, is what the 30% rule runs against.
        $agent = $this->agent();

        $oneMonthProperty = $this->propertyWithRent(5400, $agent);
        $oneMonth = $this->application(['current_rental_amount' => 5400, 'property_id' => $oneMonthProperty->id]);
        $oneMonthResult = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $oneMonth), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
            'statement_period_from' => '2026-01-01',
            'statement_period_to' => '2026-01-31',
        ])->json();

        $threeMonthsProperty = $this->propertyWithRent(5400, $agent);
        $threeMonths = $this->application(['current_rental_amount' => 5400, 'property_id' => $threeMonthsProperty->id]);
        $threeMonthsResult = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $threeMonths), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
            'statement_period_from' => '2026-01-01',
            'statement_period_to' => '2026-03-31',
        ])->json();

        // 1 month: 18000 / 1 = 18000 -> 30% = 5400 -> exactly meets 5400 rent.
        $this->assertEquals(18000.0, $oneMonthResult['result']['gross_income']);
        $this->assertTrue($oneMonthResult['result']['meets_threshold']);

        // 3 months: 18000 / 3 = 6000 -> 30% = 1800 -> 5400 rent now FAILS.
        $this->assertEquals(6000.0, $threeMonthsResult['result']['gross_income']);
        $this->assertEquals(1800.0, $threeMonthsResult['result']['max_affordable_rent']);
        $this->assertFalse($threeMonthsResult['result']['meets_threshold']);
    }

    public function test_missing_statement_months_never_divides_and_reports_incomplete_not_a_wrong_pass(): void
    {
        $agent = $this->agent();
        $app = $this->application(['current_rental_amount' => 100]); // trivially affordable if the raw total were ever used

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
        ]);

        $data = $response->json();
        $this->assertNull($data['result']['gross_income'], 'no months -> no decision figure, never the raw total');
        $this->assertNull($data['result']['max_affordable_rent']);
        $this->assertNull($data['result']['meets_threshold']);
        $this->assertSame('incomplete', $data['result']['label']);
        // The raw total is still visible for the agent (total_captured_income),
        // just never used as if it were monthly.
        $this->assertEquals(18000.0, $data['result']['total_captured_income']);
    }

    // "Dates on entries" (2026-09-10) — statement_months can no longer be
    // typed directly (there is no way to submit "0" any more), so the
    // invalid-input shapes worth guarding are the ones the date-range
    // input space actually allows: a "to" before its "from", and one date
    // supplied without the other (BUILD_STANDARD §2 — asymmetric/"wrong
    // order" input must be rejected clearly, never guessed at).

    public function test_a_to_date_before_the_from_date_is_rejected_at_validation(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
            'statement_period_from' => '2026-03-01',
            'statement_period_to' => '2026-01-01',
        ]);

        $response->assertSessionHasErrors('statement_period_to');
    }

    public function test_one_statement_period_date_without_the_other_is_rejected_at_validation(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
            'statement_period_from' => '2026-01-01',
            // statement_period_to deliberately omitted.
        ]);

        $response->assertSessionHasErrors('statement_period_to');
    }

    public function test_a_date_range_over_36_months_is_rejected_with_a_clear_message(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        // This rejection is a plain JSON response returned directly by the
        // controller (not a validate() rule), so it stays JSON regardless
        // of the request's Accept header — unlike the two tests above.
        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
            'statement_period_from' => '2020-01-01',
            'statement_period_to' => '2026-01-01',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('months', $response->json('error'));
    }

    // "Dates on entries" — existing records keep whatever month count they
    // hold; nothing recalculates retrospectively without a date range to
    // derive it from. A save with no dates on THIS request must never zero
    // out (or otherwise touch) an already-stored figure.
    public function test_a_save_with_no_dates_leaves_an_existing_statement_months_untouched(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        RentalApplicationAssessment::create([
            'agency_id' => $this->agency->id,
            'rental_application_id' => $app->id,
            'statement_months' => 6,
        ]);

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
        ]);

        $response->assertOk();
        $this->assertEquals(6, $response->json('result.statement_months'));
        $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
        $this->assertSame(6, $assessment->statement_months);
        $this->assertNull($assessment->statement_period_from);
    }

    // Once a date range IS picked on an assessment that already had an
    // old-style typed figure, the derived value takes over going forward.
    public function test_picking_a_date_range_overwrites_an_old_typed_statement_months_value(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        RentalApplicationAssessment::create([
            'agency_id' => $this->agency->id,
            'rental_application_id' => $app->id,
            'statement_months' => 6,
        ]);

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
            'statement_period_from' => '2026-01-01',
            'statement_period_to' => '2026-02-28',
        ]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('result.statement_months'));
    }

    public function test_applicant_reported_income_shown_for_comparison_never_affects_the_decision(): void
    {
        $agent = $this->agent();
        // Applicant claimed 10,000; the bank statement (agent-captured,
        // averaged) shows the real figure of 18,000 — Johan's own example.
        $app = $this->application(['current_rental_amount' => 5000, 'monthly_salary' => 10000]);

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
            'statement_period_from' => '2026-01-01',
            'statement_period_to' => '2026-01-31',
        ]);

        $data = $response->json();
        $this->assertEquals(10000.0, $data['result']['applicant_reported_income']);
        // The DECISION still runs off the bank-statement-derived figure.
        $this->assertEquals(18000.0, $data['result']['gross_income']);
    }

    // ── "Dates on entries" (2026-09-10) — income/expense line dates ──────

    public function test_income_and_expense_items_persist_and_echo_their_own_date(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000', 'entry_date' => '2026-08-25']],
            'expense_items' => [['description' => 'Car payment', 'amount' => '2500', 'entry_date' => '2026-08-26']],
        ]);

        $response->assertOk();
        $this->assertSame('2026-08-25', $response->json('income_items.0.entry_date'));
        $this->assertSame('2026-08-26', $response->json('expense_items.0.entry_date'));

        $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
        $this->assertSame('2026-08-25', $assessment->incomeItems->first()->entry_date->format('Y-m-d'));
        $this->assertSame('2026-08-26', $assessment->expenseItems->first()->entry_date->format('Y-m-d'));
    }

    // Optional-and-empty (BUILD_STANDARD §2) — an item with no date must
    // save exactly as it always did, never rejected or forced to a value.
    public function test_an_income_item_with_no_date_saves_fine(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000']],
        ]);

        $response->assertOk();
        $this->assertNull($response->json('income_items.0.entry_date'));
    }

    // Malformed-but-submitted (BUILD_STANDARD §2) — a future date can't be a
    // real bank-statement transaction; rejected clearly, not silently
    // accepted or truncated.
    public function test_a_future_entry_date_is_rejected_at_validation(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.review.assessment', $app), [
            'income_items' => [['description' => 'Salary', 'amount' => '18000', 'entry_date' => now()->addDay()->toDateString()]],
        ]);

        $response->assertSessionHasErrors('income_items.0.entry_date');
    }

    // The authoriser's own add-item endpoint writes the identical column —
    // same date field, same expectation, not a half-built sibling.
    public function test_authoriser_added_item_carries_its_own_entry_date(): void
    {
        $ro = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agency->update(['rental_application_ro_user_ids' => [$ro->id]]);
        $this->agency->refresh();
        $app = $this->application(['status' => 'under_assessment', 'submitted_for_approval_at' => now()]);

        $response = $this->actingAs($ro)->postJson(
            route('corex.rental-applications.authorisation.assessment.income-items.store', $app),
            ['description' => 'Overtime', 'amount' => '2000', 'entry_date' => '2026-08-20']
        );

        $response->assertOk();
        $this->assertSame('2026-08-20', $response->json('item.entry_date'));
    }
}
