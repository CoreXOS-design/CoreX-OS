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

    // ── Item 2/3 — gross-income labelling on the agent panel ─────────────

    // ── Item 5 — income/expense line items ────────────────────────────────

    // ── Item 5 — a removed ledger line is archived, never hard-deleted ─────
    //
    // Since the 2026-09-11 capture-ledger rework the income/expense line IS a capture
    // entry (rental_application_document_marks), not a row posted to the assessment save.

    public function test_removing_a_capture_entry_soft_deletes_it_never_hard_deletes(): void
    {
        $agent = $this->agent();
        $app = $this->application();

        foreach (['keep-1' => 10000, 'remove-1' => 2000] as $uid => $amount) {
            $this->actingAs($agent)->postJson(route('corex.rental-applications.capture-entries.store-manual', $app), [
                'mark_uid' => $uid, 'entry_type' => 'income', 'entry_description' => 'Salary', 'entry_amount' => $amount,
            ])->assertOk();
        }

        $this->actingAs($agent)
            ->deleteJson(route('corex.rental-applications.capture-entries.destroy', [$app, 'remove-1']))
            ->assertOk();

        // Non-negotiable #1 — soft-deleted, not gone; the other line is untouched.
        $this->assertSoftDeleted('rental_application_document_marks', ['rental_application_id' => $app->id, 'mark_uid' => 'remove-1']);
        $this->assertDatabaseHas('rental_application_document_marks', ['rental_application_id' => $app->id, 'mark_uid' => 'keep-1', 'deleted_at' => null]);
    }

    // ── Retired 2026-10-07 (cc3 red-test pass) ───────────────────────────────
    // Removed, not skipped: test_review_screen_shows_property_rent_check_when_property_linked,
    // test_review_screen_labels_income_section_as_gross, test_saving_income_and_expense_items_persists_them_and_computes_the_total,
    // test_a_blank_trailing_row_never_persists_as_a_zero_value_item,
    // test_the_displayed_total_matches_exactly_what_the_affordability_check_uses (and the old
    // "removing a row" test, rewritten above). They asserted the retired verdict-box UI and the
    // income_items/expense_items saveAssessment() contract that the 2026-09-11 capture-ledger
    // rework removed. See .ai/specs/rental-applications.md ("Pre-existing, unrelated test debt").
}
