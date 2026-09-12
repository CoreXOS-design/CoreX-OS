<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\RentalApplicationDocumentMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan's decision, 2026-09-14: "A struck-out line is EXCLUDED from the
 * totals... The line stays VISIBLE with its date, description and amount.
 * It is struck, not hidden. An agent must be able to see what she excluded
 * and un-strike it... The authoriser must see the same thing the agent
 * saw." Proves this the same way the underlying arithmetic itself was
 * proved: Johan's own reference case (application 76's real figures,
 * reproduced in a throwaway agency, app 76 itself never touched), with one
 * line struck, derived independently, to the cent — both directions.
 */
final class RentalApplicationCaptureEntryStrikeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeAgencyAndApplication(): array
    {
        $agency = Agency::create(['name' => 'Strike Proof Co', 'slug' => 'strike-proof-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Proof', 'last_name' => 'Applicant', 'email' => 'proof-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $agent->id,
            'status' => 'in_progress', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        return [$agency, $branch, $agent, $contact, $application];
    }

    /** Reproduces Johan's own reference case (application 76's real figures) exactly as the arithmetic audit did. */
    private function seedJohansReferenceCase($agency, $agent, $application): void
    {
        RentalApplicationAssessment::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'statement_period_from' => '2026-05-31', 'statement_period_to' => '2026-08-31',
            'statement_months' => 4,
        ]);

        $incomeLines = [
            ['date' => '2026-06-24', 'desc' => 'salary', 'amount' => 28861.34],
            ['date' => '2026-07-04', 'desc' => 'wages', 'amount' => 1270.81],
            ['date' => '2026-07-20', 'desc' => 'wages', 'amount' => 1270.61],
            ['date' => '2026-07-24', 'desc' => 'salary', 'amount' => 28863.00],
            ['date' => '2026-08-24', 'desc' => 'salary', 'amount' => 29340.99],
            ['date' => null, 'desc' => 'wages', 'amount' => 1270.58],
        ];
        foreach ($incomeLines as $line) {
            RentalApplicationDocumentMark::create([
                'agency_id' => $agency->id, 'rental_application_id' => $application->id,
                'mark_uid' => 'proof-income-' . uniqid(), 'author_user_id' => $agent->id,
                'author_name' => $agent->name, 'author_role' => 'agent',
                'entry_type' => 'income', 'entry_date' => $line['date'],
                'entry_description' => $line['desc'], 'entry_amount' => $line['amount'],
            ]);
        }
        RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'mark_uid' => 'proof-expense-' . uniqid(), 'author_user_id' => $agent->id,
            'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'expense', 'entry_date' => '2026-06-30',
            'entry_description' => 'utilities', 'entry_amount' => 3050.00,
        ]);
    }

    public function test_striking_and_unstriking_the_reference_case_matches_to_the_cent_both_directions(): void
    {
        [$agency, $branch, $agent, $contact, $application] = $this->makeAgencyAndApplication();
        $this->seedJohansReferenceCase($agency, $agent, $application);

        // The line to strike — "wages — R1,270.58" (no date), the same
        // unanchored line the earlier arithmetic audit used to prove
        // manual/migrated lines count identically. Struck via the REAL
        // HTTP endpoint, not a direct DB write.
        $struckMark = RentalApplicationDocumentMark::where('rental_application_id', $application->id)
            ->where('entry_description', 'wages')->whereNull('entry_date')->firstOrFail();

        // ── Baseline: confirm the untouched reference case still matches
        // Johan's own stated figures before anything is struck. ──
        $before = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));
        $before->assertOk();
        $beforeEntries = $before->viewData('captureEntries');
        $beforeIncome = round((float) $beforeEntries->where('entry_type', 'income')->sum('entry_amount'), 2);
        $this->assertSame(90877.33, $beforeIncome, 'unstruck baseline must still match Johan\'s reference figure to the cent');

        // ── Strike it. ──
        $strikeResponse = $this->actingAs($agent)->post(route('corex.rental-applications.capture-entries.strike', [$application, $struckMark->mark_uid]));
        $strikeResponse->assertOk();
        $strikeResponse->assertJson(['ok' => true]);
        $this->assertTrue($strikeResponse->json('entry.struck_out'), 'the toggle response itself must report struck_out true');
        $this->assertSame($agent->name, $strikeResponse->json('entry.struck_out_by'), 'who struck it must be recorded');

        // ── Independently derive the expected NEW totals in PHP — the
        // remaining 5 income lines, same months, same formula, no
        // reliance on the app's own total functions. ──
        $expectedIncomeAfterStrike = round(28861.34 + 1270.81 + 1270.61 + 28863.00 + 29340.99, 2); // 89606.75
        $expectedMonthlyIncomeAfterStrike = round($expectedIncomeAfterStrike / 4, 2); // 22401.69
        $expectedNetMonthlyAfterStrike = round(($expectedIncomeAfterStrike - 3050.00) / 4, 2); // 21639.19

        $afterStrike = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));
        $afterStrike->assertOk();
        $afterStrikeEntries = $afterStrike->viewData('captureEntries');

        // Struck, but still IN the list — visible, not hidden.
        $this->assertCount(7, $afterStrikeEntries, 'the struck line must still be present in the ledger, not removed');
        $struckEntry = $afterStrikeEntries->firstWhere('id', $struckMark->mark_uid);
        $this->assertNotNull($struckEntry, 'the struck line must still be findable by its own id');
        $this->assertTrue($struckEntry['struck_out']);
        $this->assertSame(1270.58, $struckEntry['entry_amount'], 'the struck line\'s own amount is still shown, unchanged');

        // The totals a fresh derivation would compute, excluding the struck line.
        $incomeExcludingStruck = round((float) $afterStrikeEntries->where('entry_type', 'income')->where('struck_out', false)->sum('entry_amount'), 2);
        $this->assertSame($expectedIncomeAfterStrike, $incomeExcludingStruck, 'income total must exclude the struck line, to the cent');
        $this->assertSame(89606.75, $incomeExcludingStruck);
        $this->assertSame(22401.69, round($incomeExcludingStruck / 4, 2), 'monthly income must exclude the struck line, to the cent');
        $this->assertSame(21639.19, round(($incomeExcludingStruck - 3050.00) / 4, 2), 'net monthly must exclude the struck line, to the cent');

        // ── Persistence: a completely fresh request cycle (new GET) must
        // still show it struck — this is read straight from the database,
        // not carried over from the toggle response. ──
        $reload = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));
        $reloadEntries = $reload->viewData('captureEntries');
        $this->assertTrue($reloadEntries->firstWhere('id', $struckMark->mark_uid)['struck_out'], 'struck state must survive a reload, not just the toggle\'s own response');

        // ── Un-strike — same call, same row, the exact toggle back. ──
        $unstrikeResponse = $this->actingAs($agent)->post(route('corex.rental-applications.capture-entries.strike', [$application, $struckMark->mark_uid]));
        $unstrikeResponse->assertOk();
        $this->assertFalse($unstrikeResponse->json('entry.struck_out'), 'un-striking must flip the flag back');
        $this->assertNull($unstrikeResponse->json('entry.struck_out_by'), 'un-striking clears who-struck-it, same as it was never struck');

        $afterUnstrike = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));
        $afterUnstrikeEntries = $afterUnstrike->viewData('captureEntries');
        $incomeAfterUnstrike = round((float) $afterUnstrikeEntries->where('entry_type', 'income')->where('struck_out', false)->sum('entry_amount'), 2);

        // Back to EXACTLY Johan's original reference figures — proving the
        // restore is a true round-trip, not an approximation.
        $this->assertSame(90877.33, $incomeAfterUnstrike, 'un-striking must restore Johan\'s exact original reference total, to the cent');
        $this->assertSame(22719.33, round($incomeAfterUnstrike / 4, 2));
        $this->assertSame(21956.83, round(($incomeAfterUnstrike - 3050.00) / 4, 2));
    }

    public function test_authoriser_sees_the_identical_struck_state_and_totals_the_agent_set(): void
    {
        [$agency, $branch, $agent, $contact, $application] = $this->makeAgencyAndApplication();
        $this->seedJohansReferenceCase($agency, $agent, $application);
        $application->update(['status' => 'under_assessment', 'submitted_for_approval_at' => now()]);

        $ro = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $agency->update(['rental_application_ro_user_ids' => [$ro->id]]);
        $agency->refresh();

        $struckMark = RentalApplicationDocumentMark::where('rental_application_id', $application->id)
            ->where('entry_description', 'wages')->whereNull('entry_date')->firstOrFail();

        // Agent strikes it from their OWN screen before submitting, while
        // the application is still 'in_progress' — same call the review
        // screen makes.
        $application->update(['status' => 'in_progress']);
        $this->actingAs($agent)->post(route('corex.rental-applications.capture-entries.strike', [$application, $struckMark->mark_uid]))->assertOk();
        $application->update(['status' => 'under_assessment', 'submitted_for_approval_at' => now()]);

        $authoriserView = $this->actingAs($ro)->get(route('corex.rental-applications.authorisation.show', $application));
        $authoriserView->assertOk();
        $authoriserEntries = $authoriserView->viewData('captureEntries');

        $this->assertTrue($authoriserEntries->firstWhere('id', $struckMark->mark_uid)['struck_out'], 'the authoriser must see the exact same struck flag the agent set');
        $authoriserIncomeTotal = round((float) $authoriserEntries->where('entry_type', 'income')->where('struck_out', false)->sum('entry_amount'), 2);
        $this->assertSame(89606.75, $authoriserIncomeTotal, 'the authoriser\'s own derivation of the total must exclude the struck line identically to the agent\'s');
    }

    public function test_cross_agency_strike_attempt_is_refused(): void
    {
        [$agencyA, , $agentA, , $applicationA] = $this->makeAgencyAndApplication();
        $this->seedJohansReferenceCase($agencyA, $agentA, $applicationA);
        $mark = RentalApplicationDocumentMark::where('rental_application_id', $applicationA->id)->where('entry_type', 'income')->firstOrFail();

        [$agencyB, $branchB] = $this->makeAgencyAndApplication();
        $agentB = User::factory()->create(['agency_id' => $agencyB->id, 'branch_id' => $branchB->id, 'role' => 'admin']);

        // Agency B's own agent, hitting Agency A's application id directly —
        // RentalApplication's BelongsToAgency global scope means the route
        // model binding itself can never resolve this record for agentB,
        // regardless of what the controller does afterwards.
        $response = $this->actingAs($agentB)->post(route('corex.rental-applications.capture-entries.strike', [$applicationA, $mark->mark_uid]));
        $response->assertNotFound();

        $mark->refresh();
        $this->assertFalse($mark->isStruckOut(), 'a refused cross-agency attempt must never actually strike the line');
    }

    public function test_a_different_agents_line_cannot_be_struck_by_another_agent(): void
    {
        [$agency, $branch, $agentOne] = $this->makeAgencyAndApplication();
        $agentTwo = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'ab-' . uniqid() . '@example.test']);
        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $agentOne->id, 'status' => 'in_progress', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);
        $mark = RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'mark_uid' => 'proof-owned-' . uniqid(), 'author_user_id' => $agentOne->id,
            'author_name' => $agentOne->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_description' => 'salary', 'entry_amount' => 5000.00,
        ]);

        // Same agency, same role, but NOT the mark's own author — wrong-author, not cross-agency.
        $response = $this->actingAs($agentTwo)->post(route('corex.rental-applications.capture-entries.strike', [$application, $mark->mark_uid]));
        $response->assertForbidden();

        $mark->refresh();
        $this->assertFalse($mark->isStruckOut());
    }

    public function test_authoriser_cannot_strike_an_agents_own_captured_line(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyAndApplication();
        $ro = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $agency->update(['rental_application_ro_user_ids' => [$ro->id]]);
        $agency->refresh();

        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'ab2-' . uniqid() . '@example.test']);
        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'under_assessment', 'submitted_for_approval_at' => now(),
            'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);
        $mark = RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'mark_uid' => 'proof-agent-owned-' . uniqid(), 'author_user_id' => $agent->id,
            'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_description' => 'salary', 'entry_amount' => 5000.00,
        ]);

        // Same guard update()/delete() already enforce — an authoriser may
        // never touch an agent's own entry, strike included.
        $response = $this->actingAs($ro)->post(route('corex.rental-applications.authorisation.capture-entries.strike', [$application, $mark->mark_uid]));
        $response->assertForbidden();

        $mark->refresh();
        $this->assertFalse($mark->isStruckOut());
    }

    public function test_authoriser_can_strike_an_unattributed_legacy_line(): void
    {
        [$agency, $branch, $agent] = $this->makeAgencyAndApplication();
        $ro = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $agency->update(['rental_application_ro_user_ids' => [$ro->id]]);
        $agency->refresh();

        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'A', 'last_name' => 'B', 'email' => 'ab3-' . uniqid() . '@example.test']);
        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'under_assessment', 'submitted_for_approval_at' => now(),
            'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);
        // author_role null — an unattributed/legacy mark, same shape the
        // 2026-09-11 backfill migration produced for old struck items.
        $mark = RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'mark_uid' => 'proof-unattributed-' . uniqid(), 'author_user_id' => null,
            'author_name' => null, 'author_role' => null,
            'entry_type' => 'income', 'entry_description' => 'legacy salary', 'entry_amount' => 5000.00,
        ]);

        $response = $this->actingAs($ro)->post(route('corex.rental-applications.authorisation.capture-entries.strike', [$application, $mark->mark_uid]));
        $response->assertOk();

        $mark->refresh();
        $this->assertTrue($mark->isStruckOut());
        $this->assertSame($ro->id, $mark->struck_out_by_user_id);
    }
}
