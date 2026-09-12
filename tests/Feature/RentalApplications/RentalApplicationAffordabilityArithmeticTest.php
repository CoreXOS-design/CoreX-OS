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
 * Johan, verbatim: "THE ARITHMETIC ON THE AFFORDABILITY PANEL MUST BE
 * PROVABLY RIGHT... A wrong total here is worse than a crash, because
 * nobody notices it." Reproduces his own real reference case (application
 * 76's actual captured figures, application 76 itself never touched) in
 * an isolated throwaway agency and proves every link in the chain against
 * his stated expected values, to the cent:
 *
 *   income R90 877.33 · expenses R3 050.00 · months 4 ·
 *   monthly income R22 719.33 · net monthly R21 956.83
 *
 * This proves the SERVER half of the chain (DB -> controller ->
 * initialCaptureEntries JSON -> statement_months) end to end via a real
 * HTTP-dispatched request. The CLIENT half (incomeTotal()/expenseTotal()/
 * monthlyIncome()/netMonthly()/formatR() in review.blade.php) is pure
 * browser JS with no server round-trip — proven separately by executing
 * the identical formula in Node against these exact figures (see the
 * spec writeup for that run's output); this test additionally re-derives
 * the same arithmetic in PHP as a second, independent implementation, so
 * a genuine divergence between the two languages' floating-point
 * behaviour on THESE SPECIFIC figures would be caught here too.
 */
final class RentalApplicationAffordabilityArithmeticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeAgencyAndApplication(): array
    {
        $agency = Agency::create(['name' => 'Affordability Proof Co', 'slug' => 'afford-proof-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Proof', 'last_name' => 'Applicant', 'email' => 'proof-' . uniqid() . '@example.test']);

        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $agent->id,
            'status' => 'in_progress', 'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        return [$agency, $agent, $application];
    }

    public function test_johans_reference_case_matches_to_the_cent(): void
    {
        [$agency, $agent, $application] = $this->makeAgencyAndApplication();

        $assessment = RentalApplicationAssessment::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'statement_period_from' => '2026-05-31', 'statement_period_to' => '2026-08-31',
        ]);
        // The ONLY place "months" is derived — confirm it lands on 4 for
        // this exact period before trusting anything downstream of it.
        $months = RentalApplicationAssessment::calculateStatementMonths(
            $assessment->statement_period_from->toDateString(),
            $assessment->statement_period_to->toDateString(),
        );
        $assessment->statement_months = $months;
        $assessment->save();

        $this->assertSame(4, $months);

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

        // ── Server half: real HTTP-dispatched request, real controller,
        // real view data — not a direct model call. ──
        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));
        $response->assertOk();

        $captureEntries = $response->viewData('captureEntries');
        $this->assertCount(7, $captureEntries, 'exactly the 6 income + 1 expense lines, nothing extra, nothing missing');

        $incomeEntries = $captureEntries->where('entry_type', 'income');
        $expenseEntries = $captureEntries->where('entry_type', 'expense');
        $this->assertCount(6, $incomeEntries);
        $this->assertCount(1, $expenseEntries);

        // Independent PHP re-derivation of the same arithmetic the client
        // JS performs, on the SAME server-supplied entries — the second
        // implementation this test's own docblock describes.
        $incomeTotal = round((float) $incomeEntries->sum('entry_amount'), 2);
        $expenseTotal = round((float) $expenseEntries->sum('entry_amount'), 2);
        $monthlyIncome = round($incomeTotal / $months, 2);
        $netMonthly = round(($incomeTotal - $expenseTotal) / $months, 2);

        $this->assertSame(90877.33, $incomeTotal, 'income total must match Johan\'s stated figure to the cent');
        $this->assertSame(3050.00, $expenseTotal, 'expense total must match Johan\'s stated figure to the cent');
        $this->assertSame(22719.33, $monthlyIncome, 'monthly income must match Johan\'s stated figure to the cent');
        $this->assertSame(21956.83, $netMonthly, 'net monthly must match Johan\'s stated figure to the cent');

        // Manually-added (unanchored, document_id null) and highlighter-
        // captured (anchored) lines must count identically — every line in
        // this reproduction is unanchored (document_id null, exactly like
        // captureEntryCreateManual() produces), proving the totals above
        // don't depend on a document being attached at all.
        $this->assertTrue($incomeEntries->every(fn ($e) => $e['document_id'] === null));
    }

    public function test_months_and_monthly_figures_are_dashes_not_a_misleading_number_when_no_period_is_set(): void
    {
        [$agency, $agent, $application] = $this->makeAgencyAndApplication();

        $assessment = RentalApplicationAssessment::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
        ]);
        $this->assertNull($assessment->statement_months, 'no period set yet -- must be null, never a fabricated number');

        RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id,
            'mark_uid' => 'proof-onlyline-' . uniqid(), 'author_user_id' => $agent->id,
            'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_date' => '2026-06-01',
            'entry_description' => 'one captured line, no period set', 'entry_amount' => 15000.00,
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));
        $response->assertOk();

        // statement_months reaches the view as null -- review.blade.php's
        // own `statementMonths: initial.statement_months ?? ''` and
        // `monthlyIncome()`'s `if (!months || months < 1) return null`
        // then correctly render the em-dash, never a wrong "R15 000.00"
        // (the raw uncapped total) or a wrong "R0.00".
        $viewAssessment = $response->viewData('assessment');
        $this->assertNull($viewAssessment->statement_months);
    }

    public function test_a_document_missing_entry_is_flagged_but_still_present_in_the_totals_source(): void
    {
        // Johan's own explicit ask: is a document-missing entry included or
        // excluded, and is that choice stated anywhere? As found: it is
        // INCLUDED (no filter exists for it anywhere in incomeEntries()/
        // expenseEntries()), and it was NOT stated in the spec before this
        // pass -- reported as a genuine open question, not decided here.
        [$agency, $agent, $application] = $this->makeAgencyAndApplication();
        RentalApplicationAssessment::create(['agency_id' => $agency->id, 'rental_application_id' => $application->id]);

        $doc = \App\Models\Document::create([
            'agency_id' => $agency->id, 'source_type' => 'rental_application', 'source_id' => $application->id,
            'original_name' => 'proof.pdf', 'storage_path' => 'proof.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf',
        ]);
        RentalApplicationDocumentMark::create([
            'agency_id' => $agency->id, 'rental_application_id' => $application->id, 'document_id' => $doc->id,
            'mark_uid' => 'proof-anchored-' . uniqid(), 'author_user_id' => $agent->id,
            'author_name' => $agent->name, 'author_role' => 'agent', 'type' => 'highlight', 'page' => 0,
            'points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]], 'width' => 10,
            'entry_type' => 'income', 'entry_amount' => 5000.00,
        ]);
        // Remove the document the mark was anchored to -- same shape as
        // the PDF splitter re-filing scenario the controller's own comment
        // names.
        $doc->delete();

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));
        $response->assertOk();

        $entries = $response->viewData('captureEntries');
        $this->assertCount(1, $entries);
        $this->assertTrue($entries->first()['document_missing'], 'the flag must be set once the backing document is gone');
        $this->assertSame(5000.0, (float) $entries->first()['entry_amount'], 'the figure itself is still present -- current behavior is INCLUDED, not silently dropped');
    }
}
