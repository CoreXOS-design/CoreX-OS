<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Contact;
use App\Models\FicaStatusHistory;
use App\Models\FicaSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\Compliance\FicaQuestionsAnswersDocument;
use App\Services\PermissionService;
use App\Support\Compliance\FicaQuestionnaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, 7 Oct 2026: a Download PDF and a Print of the "Questions & answers" view — the questions WITH the client's answers
 * AND the client's signature — as a form the agent keeps on file (.ai/specs/compliance.md). Proves: the document carries
 * every question (same source as the screen), each answer, "not answered", documents by name, the signature image with signed
 * name / date / location and the declaration, plus the FICA reference, submitted date, who, and form wording version; the PDF
 * is a real PDF named FICA-questions-answers-<client>-<date>.pdf with page numbers; both screens carry the buttons; a paper
 * intake / an uncompleted form has no Q&A form and no button; the same access as the screens (no new access) and every
 * download/print is audited; a hostile signature value is never fetched.
 */
final class FicaQuestionsAnswersPrintTest extends TestCase
{
    use RefreshDatabase;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private int $agencyId;
    private int $branchA;
    private int $branchB;
    private User $branchMgrA;
    private User $branchMgrB;
    private User $admin;
    private User $primaryCo;
    private User $plainAgent;

    protected function setUp(): void
    {
        parent::setUp();

        // Real role grants (production posture), not the suite's allow-all-when-unseeded shortcut — see FicaQuestionsAnswersReviewTest.
        $now = now();
        foreach ([['super_admin', 'System Owner', 1, 1], ['admin', 'Administrator', 0, 2], ['branch_manager', 'Branch Manager', 0, 3], ['agent', 'Agent', 0, 4]] as [$name, $label, $owner, $sort]) {
            DB::table('roles')->insert(['name' => $name, 'label' => $label, 'is_owner' => $owner, 'can_be_deleted' => 0, 'sort_order' => $sort,
                'agency_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Cape Coast Realty ' . Str::random(4), 'slug' => 'fica-print-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchA = (int) DB::table('branches')->insertGetId(['agency_id' => $this->agencyId, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $this->branchB = (int) DB::table('branches')->insertGetId(['agency_id' => $this->agencyId, 'name' => 'B', 'created_at' => now(), 'updated_at' => now()]);

        $mk = fn (string $role, int $branch) => User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $branch, 'role' => $role]);
        $this->branchMgrA = $mk('branch_manager', $this->branchA);
        $this->branchMgrB = $mk('branch_manager', $this->branchB);
        $this->admin = $mk('admin', $this->branchA);
        $this->primaryCo = $mk('super_admin', $this->branchA);
        $this->plainAgent = $mk('agent', $this->branchA);

        FicaOfficerAppointment::create([
            'agency_id' => $this->agencyId, 'branch_id' => null, 'user_id' => $this->primaryCo->id, 'role' => FicaOfficerAppointment::ROLE_PRIMARY,
            'full_name' => $this->primaryCo->name, 'appointed_on' => now()->toDateString(), 'appointed_by' => $this->primaryCo->id,
        ]);
    }

    private function naturalAnswers(array $over = []): array
    {
        return array_replace_recursive([
            'entity_type' => 'natural',
            'personal' => [
                'full_name' => 'Thandiwe Mkhize', 'id_number' => '8501015800087', 'sa_citizen' => 'yes',
                'residential_address' => '14 Marine Drive, Ramsgate', 'phone' => '082 555 0101', 'email' => 'thandi@example.test',
                'tax_number' => '',
            ],
            'principal' => ['acting_on_behalf' => 'no'],
            'representative' => ['has_representative' => 'no'],
            'service' => ['transaction_purpose' => 'let_out', 'payment_method' => 'Agency fees are deducted from rental income.', 'cash_over_50k' => 'no'],
            'pep' => ['is_foreign_pep' => 'no', 'is_domestic_pep' => 'no', 'is_family_associate' => 'no'],
            'declaration' => ['signed_at_location' => 'Durban, South Africa'],
            'signature_data' => self::SIG,
        ], $over);
    }

    private function submission(array $answers, string $entityType = 'natural', array $attrs = [], ?int $branch = null, ?int $agencyId = null): FicaSubmission
    {
        $agencyId ??= $this->agencyId;
        $contact = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $branch ?? $this->branchA,
            'first_name' => 'Thandiwe', 'last_name' => 'Mkhize', 'created_by_user_id' => $this->branchMgrA->id,
        ]));

        return FicaSubmission::withoutEvents(fn () => FicaSubmission::create($attrs + [
            'agency_id' => $agencyId, 'branch_id' => $branch ?? $this->branchA, 'contact_id' => $contact->id,
            'requested_by' => $this->branchMgrA->id, 'token' => Str::random(40), 'token_expires_at' => now()->addDays(30),
            'entity_type' => $entityType, 'form_data' => $answers, 'status' => 'agent_approved',
            'agent_verified_by' => $this->branchMgrA->id, 'agent_verified_at' => now(),
            'signature_data' => self::SIG, 'signed_at' => '2026-09-14 08:55:00', 'intake_type' => 'online',
        ]));
    }

    private function document(FicaSubmission $s, string $type, string $name): void
    {
        DB::table('fica_documents')->insert([
            'fica_submission_id' => $s->id, 'agency_id' => $this->agencyId, 'document_type' => $type,
            'file_path' => 'fica/' . $s->id . '/' . Str::random(8), 'file_name' => $name, 'file_size' => 2048,
            'mime_type' => 'application/pdf', 'status' => 'uploaded', 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function printHtml(FicaSubmission $s, ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->branchMgrA)->get(route('compliance.fica.questions-answers.print', $s))->assertOk()->getContent();
    }

    /** Text of a PDF page range via poppler, or null when poppler is not installed (the PDF-content tests then skip). */
    private function pdfText(string $bytes): ?string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ficaqa') . '.pdf';
        file_put_contents($tmp, $bytes);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
        @unlink($tmp);

        return $text;
    }

    // ── the document: everything on file ─────────────────────────────────

    public function test_the_form_carries_every_question_answer_the_signature_and_the_header_facts(): void
    {
        $s = $this->submission($this->naturalAnswers());
        $this->document($s, 'id_document', 'thandi-id.pdf');

        $html = $this->printHtml($s);

        // Every question the screen shows, in the screen's order — same single source.
        $qa = FicaQuestionnaire::forSubmission($s->fresh(['documents']));
        $last = -1;
        foreach ($qa['sections'] as $section) {
            self::assertStringContainsString(e($section['title']), $html);
            foreach ($section['rows'] as $row) {
                if ($row['type'] === 'signature') {
                    continue;
                }
                $pos = strpos($html, e($row['label']));
                self::assertNotFalse($pos, 'question missing from the form: ' . $row['label']);
                self::assertGreaterThan($last, $pos, 'out of questionnaire order: ' . $row['label']);
                $last = $pos;
            }
        }

        foreach (['Thandiwe Mkhize', '8501015800087', '14 Marine Drive, Ramsgate', 'I/We wish to let out a property', 'Agency fees are deducted from rental income.', 'thandi-id.pdf'] as $answer) {
            self::assertStringContainsString(e($answer), $html, "answer missing: {$answer}");
        }

        $flat = preg_replace('/\s+/', ' ', strip_tags(str_replace('</div>', ' </div>', $html)));
        self::assertStringContainsString('FICA #' . $s->id, $flat);
        self::assertStringContainsString('14 Sep 2026 08:55 by Thandiwe Mkhize', $flat);
        self::assertMatchesRegularExpression('/Form wording version\s*' . preg_quote(FicaQuestionnaire::VERSION, '/') . '/', $flat);
        self::assertStringContainsString('Cape Coast Realty', $flat);                              // letterhead = THIS agency, not HFC
        self::assertStringNotContainsString('Home Finders', $flat);
        self::assertStringContainsString(e(FicaQuestionnaire::DECLARATION_TEXT), $html);          // the declaration the client agreed to
        self::assertStringContainsString('src="' . self::SIG . '"', $html);                        // the signature image itself
        self::assertStringContainsString('Signed by', $flat);
        self::assertStringContainsString('Durban, South Africa', $flat);
    }

    public function test_unanswered_and_unuploaded_say_so_and_signature_is_not_repeated_in_the_rows(): void
    {
        $s = $this->submission($this->naturalAnswers()); // tax_number blank, no documents uploaded
        $html = $this->printHtml($s);

        self::assertStringContainsString('not answered', $html);
        self::assertStringContainsString('not uploaded', $html);
        self::assertSame(1, substr_count($html, 'alt="Client signature"'), 'the signature prints once, in the declaration block');
    }

    public function test_a_company_form_is_headed_with_the_entity_name(): void
    {
        $s = $this->submission($this->naturalAnswers(['entity_type' => 'company', 'entity' => ['company_name' => 'Ramsgate Holdings (Pty) Ltd']]), 'company');

        self::assertSame('Ramsgate Holdings (Pty) Ltd', app(FicaQuestionsAnswersDocument::class)->clientName($s));
        self::assertSame('FICA-questions-answers-ramsgate-holdings-pty-ltd-2026-09-14.pdf', app(FicaQuestionsAnswersDocument::class)->fileName($s));
        self::assertStringContainsString('Ramsgate Holdings (Pty) Ltd', $this->printHtml($s));
    }

    public function test_a_signature_that_is_not_an_inline_image_is_never_placed_in_the_page(): void
    {
        foreach (['http://169.254.169.254/latest/meta-data/x.png', 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', 'javascript:alert(1)'] as $hostile) {
            $s = $this->submission($this->naturalAnswers(['signature_data' => $hostile]), 'natural', ['signature_data' => $hostile]);
            $html = $this->printHtml($s);

            self::assertStringNotContainsString($hostile, $html);
            self::assertStringContainsString('Signature not captured', $html);
        }
    }

    public function test_markup_in_an_answer_is_escaped(): void
    {
        $s = $this->submission($this->naturalAnswers(['personal' => ['residential_address' => '<script>alert(1)</script> 9 Beach Rd']]));
        $html = $this->printHtml($s);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; 9 Beach Rd', $html);
    }

    // ── the PDF ──────────────────────────────────────────────────────────

    public function test_the_download_is_a_real_pdf_with_the_right_name_the_questions_answers_page_numbers_and_the_signature(): void
    {
        if (! is_file('/usr/bin/chromium')) {
            self::markTestSkipped('No Chromium on this box to render the PDF.');
        }
        config(['services.pdf.puppeteer_browser_path' => '/usr/bin/chromium']);
        $s = $this->submission($this->naturalAnswers());

        $response = $this->actingAs($this->branchMgrA)->get(route('compliance.fica.questions-answers.pdf', $s));
        $response->assertOk();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="FICA-questions-answers-thandiwe-mkhize-2026-09-14.pdf"', (string) $response->headers->get('Content-Disposition'));

        $bytes = $response->getContent();
        self::assertStringStartsWith('%PDF', $bytes);

        $text = $this->pdfText($bytes);
        if ($text === null) {
            self::markTestIncomplete('pdftotext is not installed here — the PDF is a real PDF; its text was not inspected.');
        }
        self::assertStringContainsString('Are you a South African citizen / permanent resident?', $text);
        self::assertStringContainsString('Thandiwe Mkhize', $text);
        self::assertStringContainsString('I/We wish to let out a property', $text);
        self::assertStringContainsString('not answered', $text);
        self::assertStringContainsString('Declaration & signature', $text);
        self::assertStringContainsStringIgnoringCase('Signed by', $text);
        self::assertMatchesRegularExpression('/Page 1 of \d+/', $text, 'page numbers');

        // the signature is an embedded image
        $tmp = tempnam(sys_get_temp_dir(), 'ficaqa') . '.pdf';
        file_put_contents($tmp, $bytes);
        $images = (string) shell_exec('pdfimages -list ' . escapeshellarg($tmp) . ' 2>/dev/null');
        @unlink($tmp);
        self::assertMatchesRegularExpression('/\n\s*1\s+\d+\s+image/', $images, 'the signature image is not in the PDF');
    }

    // ── both screens ─────────────────────────────────────────────────────

    public function test_both_screens_carry_the_download_and_print_controls_for_an_online_submission(): void
    {
        $s = $this->submission($this->naturalAnswers());
        $pdf = route('compliance.fica.questions-answers.pdf', $s);
        $print = route('compliance.fica.questions-answers.print', $s);

        $show = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
        $review = $this->actingAs($this->primaryCo)->get(route('compliance.fica.compliance-review', $s))->assertOk()->getContent();

        foreach ([$show, $review] as $html) {
            self::assertStringContainsString('data-fica-qa-actions', $html);
            self::assertStringContainsString($pdf, $html);
            self::assertStringContainsString($print, $html);
            self::assertStringContainsString('Download PDF', $html);
        }
    }

    // ── paper intake, uncompleted form ───────────────────────────────────

    public function test_a_paper_intake_has_no_form_and_no_button(): void
    {
        $s = $this->submission(['intake' => ['method' => 'email']], 'natural', ['intake_type' => 'wet_ink', 'wet_ink_received_date' => '2026-06-22', 'signature_data' => null, 'signed_at' => null]);

        $this->actingAs($this->primaryCo)->get(route('compliance.fica.questions-answers.pdf', $s))->assertNotFound();
        $this->actingAs($this->primaryCo)->get(route('compliance.fica.questions-answers.print', $s))->assertNotFound();

        $show = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
        $review = $this->actingAs($this->primaryCo)->get(route('compliance.fica.compliance-review', $s))->assertOk()->getContent();
        self::assertStringNotContainsString('data-fica-qa-actions', $show);
        self::assertStringNotContainsString('data-fica-qa-actions', $review);
        self::assertStringContainsString('their questions and answers are on the uploaded form below', preg_replace('/\s+/', ' ', $review));
        self::assertSame(0, FicaStatusHistory::where('fica_submission_id', $s->id)->count(), 'nothing is audited for a form that was never produced');
    }

    public function test_a_form_the_client_has_not_completed_has_no_form_and_no_button(): void
    {
        $s = $this->submission([], 'natural', ['signature_data' => null, 'signed_at' => null]);

        $this->actingAs($this->primaryCo)->get(route('compliance.fica.questions-answers.pdf', $s))->assertNotFound();
        self::assertStringNotContainsString('data-fica-qa-actions', $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->getContent());
    }

    // ── no new access ────────────────────────────────────────────────────

    public function test_access_is_exactly_the_screens_and_out_of_scope_users_are_refused(): void
    {
        if (! is_file('/usr/bin/chromium')) {
            self::markTestSkipped('No Chromium on this box to render the PDF.');
        }
        config(['services.pdf.puppeteer_browser_path' => '/usr/bin/chromium']);
        $s = $this->submission($this->naturalAnswers());                       // branch A
        $otherAgencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Other', 'slug' => 'other-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        $otherBranch = (int) DB::table('branches')->insertGetId(['agency_id' => $otherAgencyId, 'name' => 'X', 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create(['agency_id' => $otherAgencyId, 'branch_id' => $otherBranch, 'role' => 'admin']);

        foreach (['compliance.fica.questions-answers.pdf', 'compliance.fica.questions-answers.print'] as $name) {
            $this->actingAs($this->plainAgent)->get(route($name, $s))->assertForbidden();   // default agent role: no access_compliance
            // Another branch / another agency: refused exactly as the record page refuses them (403, or 404 where the scope hides the record).
            self::assertContains($this->actingAs($this->branchMgrB)->get(route($name, $s))->getStatusCode(), [403, 404]);
            self::assertContains($this->actingAs($stranger)->get(route($name, $s))->getStatusCode(), [403, 404]);
            $this->actingAs($this->branchMgrA)->get(route($name, $s))->assertOk();          // same as the screen
            $this->actingAs($this->admin)->get(route($name, $s))->assertOk();
        }
        self::assertSame(4, FicaStatusHistory::where('fica_submission_id', $s->id)->count(), 'refused requests are not audited as downloads');
    }

    // ── audit ────────────────────────────────────────────────────────────

    public function test_every_download_and_print_is_audited_with_who_did_it(): void
    {
        if (! is_file('/usr/bin/chromium')) {
            self::markTestSkipped('No Chromium on this box to render the PDF.');
        }
        config(['services.pdf.puppeteer_browser_path' => '/usr/bin/chromium']);
        $s = $this->submission($this->naturalAnswers());

        $this->actingAs($this->branchMgrA)->get(route('compliance.fica.questions-answers.pdf', $s))->assertOk();
        $this->actingAs($this->primaryCo)->get(route('compliance.fica.questions-answers.print', $s))->assertOk();

        $rows = FicaStatusHistory::where('fica_submission_id', $s->id)->orderBy('id')->get();
        self::assertSame(['questions_answers_downloaded', 'questions_answers_printed'], $rows->pluck('action')->all());
        self::assertSame([$this->branchMgrA->id, $this->primaryCo->id], $rows->pluck('actor_user_id')->map(fn ($i) => (int) $i)->all());
        self::assertSame('agent_approved', $rows[0]->from_status);
        self::assertSame('agent_approved', $rows[0]->to_status, 'the workflow status is untouched');
        self::assertSame('agent_approved', $s->fresh()->status);
    }
}
