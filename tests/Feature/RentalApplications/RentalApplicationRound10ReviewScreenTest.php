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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-392 — Round 10, "finish what you were blocked on" (review.blade.php /
 * RentalApplicationReviewController.php released once cc6's investigation
 * and cc3's applicant-side work were confirmed clear). Covers:
 *
 * 1. net_income labelled unmistakably as reference-only on the review
 *    screen (cc5: "an agent sees a net figure sitting next to a pass or
 *    fail badge and reasonably assumes net is what was tested").
 * 2/3. Gross-income labelling on the agent's own assessment panel.
 * 5. Income/expense line items — auto-adding rows, live total, no
 *    zero-value trailing rows persisted, soft-delete (never hard-delete)
 *    when a row is removed, and the total agreeing exactly with what
 *    qualifyingResult() uses.
 *
 * The auto-add-row UI behaviour itself (typing into the last row makes a
 * new one appear) is Alpine.js reactivity — not observable from a
 * PHPUnit HTTP test — and was verified separately via a real headless
 * browser session against an isolated clone of real QA1 data (see the
 * spec's Round 10 section for the full transcript). This file covers
 * everything server-side: persistence, sync-by-id, soft-delete, and the
 * calculation itself.
 */
final class RentalApplicationRound10ReviewScreenTest extends TestCase
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

    // ── Item 1 — Round 16 superseded this: net_income is no longer shown ─

    public function test_review_screen_no_longer_displays_net_income_at_all(): void
    {
        // Round 16 — Johan's full panel redesign lists exactly four
        // blocks (income, expenses, unpaid, one qualifying info box) and
        // says "we dont need a massive hooha" — net_income (kept-but-
        // labelled-reference-only since Round 9) isn't among them and was
        // removed from the panel outright, not just relabelled again.
        $agent = $this->agent();
        $app = $this->application();
        $assessment = RentalApplicationAssessment::create(['agency_id' => $this->agency->id, 'rental_application_id' => $app->id, 'statement_months' => 1]);
        RentalApplicationIncomeItem::create(['agency_id' => $this->agency->id, 'rental_application_assessment_id' => $assessment->id, 'description' => 'Salary', 'amount' => 20000]);
        RentalApplicationExpenseItem::create(['agency_id' => $this->agency->id, 'rental_application_assessment_id' => $assessment->id, 'description' => 'Car', 'amount' => 3000]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertDontSee('For your reference only', false);
        $response->assertDontSee('Income left after expenses', false);
    }

    public function test_review_screen_shows_property_rent_check_when_property_linked(): void
    {
        $agent = $this->agent();
        $property = \App\Models\Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id,
            'title' => 'House to let in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
            'rental_amount' => 6000,
        ]);
        $app = $this->application(['property_id' => $property->id]);
        $assessment = RentalApplicationAssessment::create(['agency_id' => $this->agency->id, 'rental_application_id' => $app->id, 'statement_months' => 3]);
        RentalApplicationIncomeItem::create(['agency_id' => $this->agency->id, 'rental_application_assessment_id' => $assessment->id, 'description' => 'Salary', 'amount' => 25700]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertSee('Exceeds guideline', false);
        $response->assertSee('Property rent', false);
    }

    // ── Item 2/3 — gross-income labelling on the agent panel ─────────────

    public function test_review_screen_labels_income_section_as_gross(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app));

        $response->assertOk();
        $response->assertSee('Income (gross)', false);
        $response->assertSee('BEFORE tax and other deductions', false);
    }

    // ── Item 5 — income/expense line items ────────────────────────────────

    public function test_saving_income_and_expense_items_persists_them_and_computes_the_total(): void
    {
        $agent = $this->agent();
        $app = $this->application(['current_rental_amount' => 5000]);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.assessment', $app),
            [
                'income_items' => [
                    ['description' => 'Salary', 'amount' => '15,000'],
                    ['description' => 'Side income', 'amount' => '2 000'],
                ],
                'expense_items' => [
                    ['description' => 'Car payment', 'amount' => 'R1 500'],
                ],
                // Round 12 — gross_income now requires statement_months;
                // 1 month makes this a no-op division so the assertions
                // below stay a direct check of the raw captured totals.
                'statement_months' => 1,
            ]
        );

        $response->assertOk();
        $response->assertJson(['ok' => true]);
        $data = $response->json();
        // assertEquals, not assertSame — a JSON round trip collapses a
        // whole-number float (17000.0) to an int (17000); the value must
        // still match exactly, just not the PHP type.
        $this->assertEquals(17000.0, $data['result']['gross_income']);
        $this->assertEquals(15500.0, $data['result']['net_income']);
        $this->assertCount(2, $data['income_items']);
        $this->assertCount(1, $data['expense_items']);

        $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
        $this->assertCount(2, $assessment->incomeItems);
        $this->assertCount(1, $assessment->expenseItems);
    }

    public function test_a_blank_trailing_row_never_persists_as_a_zero_value_item(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        $this->actingAs($agent)->post(
            route('corex.rental-applications.review.assessment', $app),
            [
                'income_items' => [
                    ['description' => 'Salary', 'amount' => '10000'],
                    ['description' => '', 'amount' => ''], // the ever-present blank placeholder row
                ],
            ]
        )->assertOk();

        $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
        $this->assertCount(1, $assessment->incomeItems, 'the blank trailing row must never be saved');
        $this->assertSame('10000.00', $assessment->incomeItems->first()->amount);
    }

    public function test_removing_a_row_soft_deletes_it_never_hard_deletes(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        // First save — two income items.
        $first = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.assessment', $app),
            ['income_items' => [
                ['description' => 'Salary', 'amount' => '10000'],
                ['description' => 'Side income', 'amount' => '2000'],
            ]]
        )->json();
        $keptId = $first['income_items'][0]['id'];
        $removedId = $first['income_items'][1]['id'];

        // Second save — the agent cleared the second row (client sends only
        // the remaining, still-filled row, carrying its real id).
        $second = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.assessment', $app),
            ['income_items' => [
                ['id' => $keptId, 'description' => 'Salary', 'amount' => '10000'],
            ]]
        )->json();

        $this->assertCount(1, $second['income_items']);
        $this->assertSame($keptId, $second['income_items'][0]['id']);

        // Non-negotiable #1 — soft-deleted, not gone.
        $this->assertSoftDeleted('rental_application_income_items', ['id' => $removedId]);
        $this->assertDatabaseHas('rental_application_income_items', ['id' => $keptId, 'deleted_at' => null]);

        // Re-saving the SAME kept row again must UPDATE it, never create a
        // duplicate — this is what makes the id round-trip matter at all.
        $assessment = RentalApplicationAssessment::where('rental_application_id', $app->id)->first();
        $this->assertCount(1, $assessment->incomeItems);
    }

    public function test_the_displayed_total_matches_exactly_what_the_affordability_check_uses(): void
    {
        $agent = $this->agent();
        $property = \App\Models\Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id,
            'title' => 'House to let in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
            'rental_amount' => 5400,
        ]);
        $app = $this->application(['current_rental_amount' => 5400, 'property_id' => $property->id]);

        $saved = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.assessment', $app),
            [
                'income_items' => [
                    ['description' => 'Salary', 'amount' => '18000'],
                ],
                'statement_months' => 1,
            ]
        )->json();

        // The same number the aside panel's live JS total (incomeTotal())
        // and the "Suggested check" box (result.gross_income) both read from.
        $this->assertEquals(18000.0, $saved['result']['gross_income']);
        $this->assertEquals(5400.0, $saved['result']['max_affordable_rent']);
        $this->assertTrue($saved['result']['meets_threshold']);
    }
}
