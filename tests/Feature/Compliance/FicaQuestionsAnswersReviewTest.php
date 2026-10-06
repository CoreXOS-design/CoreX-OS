<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Support\Compliance\FicaQuestionnaire;
use App\Models\Role;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, 6 Oct 2026 (from Elize): when a client completes FICA online, the agent must see the
 * QUESTIONS asked as well as the answers given, and the RO and CO the same, on both review
 * screens — /compliance/fica/{id} and /compliance/fica/{id}/compliance-review — in the top
 * section where the answers are displayed.
 *
 * Proves: the same partial renders on both; questions come in the questionnaire's own order
 * and grouping; follow-up questions appear only when the client was shown them; unanswered
 * questions say so; yes/no and choices are the client's wording; documents sit next to the
 * question that asked for them; nothing the client typed is hidden; paper (wet-ink) intakes
 * and not-yet-completed forms say what they are; and NO new access: permissions and scope
 * behave exactly as before (default agent role has no access_compliance; an admin who is not
 * an appointed officer still cannot open the CO screen; other agencies/branches are blocked).
 *
 * Input paths: natural with principal + PEP yes · natural with everything no (follow-ups
 * absent) · blank optional answers · blank conditional answer · company (beneficial owners
 * list) · trust (conditional beneficiaries both ways) · wet-ink · draft/no answers · unknown
 * stored key · unknown option value · documents uploaded / not uploaded / not tied to a question.
 */
final class FicaQuestionsAnswersReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchA;
    private int $branchB;
    private User $branchMgrA;    // access_compliance, BRANCH scope, branch A
    private User $admin;         // access_compliance, company scope, NOT an appointed officer
    private User $primaryCo;     // appointed primary compliance officer
    private User $mlro;          // appointed MLRO (the "RO")
    private User $plainAgent;    // default agent role: no access_compliance

    protected function setUp(): void
    {
        parent::setUp();

        // Real role grants, not the test suite's allow-all-when-unseeded shortcut: without this
        // every user passes `permission:access_compliance` and the "no new access" tests would
        // prove nothing. Same seeding pattern as RoleManagerFunctionalTest; production posture
        // = an empty grants table denies.
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
            'name' => 'FICA QA ' . Str::random(5), 'slug' => 'fica-qa-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchA = (int) DB::table('branches')->insertGetId(['agency_id' => $this->agencyId, 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $this->branchB = (int) DB::table('branches')->insertGetId(['agency_id' => $this->agencyId, 'name' => 'B', 'created_at' => now(), 'updated_at' => now()]);

        $mk = fn (string $role, int $branch) => User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $branch, 'role' => $role]);
        $this->branchMgrA = $mk('branch_manager', $this->branchA);
        $this->admin      = $mk('admin', $this->branchA);
        $this->primaryCo  = $mk('super_admin', $this->branchA);
        $this->mlro       = $mk('super_admin', $this->branchA);
        $this->plainAgent = $mk('agent', $this->branchA);

        $this->appoint($this->primaryCo, FicaOfficerAppointment::ROLE_PRIMARY);
        $this->appoint($this->mlro, FicaOfficerAppointment::ROLE_MLRO);
    }

    private function appoint(User $u, string $role): void
    {
        FicaOfficerAppointment::create([
            'agency_id' => $this->agencyId, 'branch_id' => null, 'user_id' => $u->id, 'role' => $role,
            'full_name' => $u->name, 'appointed_on' => now()->toDateString(), 'appointed_by' => $u->id,
        ]);
    }

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /** @return array<string,mixed> a complete online natural-person submission; $over deep-merges. */
    private function naturalAnswers(array $over = []): array
    {
        $base = [
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
        ];

        return array_replace_recursive($base, $over);
    }

    private function submission(array $answers, string $entityType = 'natural', array $attrs = [], ?int $branch = null, ?User $requestedBy = null): FicaSubmission
    {
        $requestedBy ??= $this->branchMgrA;
        $contact = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $branch ?? $this->branchA,
            'first_name' => 'Thandiwe', 'last_name' => 'Mkhize', 'created_by_user_id' => $requestedBy->id,
        ]));

        return FicaSubmission::withoutEvents(fn () => FicaSubmission::create($attrs + [
            'agency_id' => $this->agencyId, 'branch_id' => $branch ?? $this->branchA, 'contact_id' => $contact->id,
            'requested_by' => $requestedBy->id, 'token' => Str::random(40), 'token_expires_at' => now()->addDays(30),
            'entity_type' => $entityType, 'form_data' => $answers, 'status' => 'agent_approved',
            'agent_verified_by' => $requestedBy->id, 'agent_verified_at' => now(),
            'signature_data' => self::SIG, 'signed_at' => '2026-09-14 08:55:00', 'intake_type' => 'online',
        ]));
    }

    private function document(FicaSubmission $s, string $type, string $name): int
    {
        return (int) DB::table('fica_documents')->insertGetId([
            'fica_submission_id' => $s->id, 'agency_id' => $this->agencyId, 'document_type' => $type,
            'file_path' => 'fica/' . $s->id . '/' . Str::random(8), 'file_name' => $name, 'file_size' => 2048,
            'mime_type' => 'application/pdf', 'status' => 'uploaded', 'uploaded_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Question labels shown in the review block, in order (HTML-decoded). */
    private function shownQuestions(string $html): array
    {
        preg_match_all('/<div data-q [^>]*>(.*?)<\/div>/s', $html, $m);

        return array_map(fn ($s) => trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5)), $m[1]);
    }

    private function block(string $html): string
    {
        $start = strpos($html, 'data-fica-qa');
        self::assertNotFalse($start, 'the questions-and-answers block is not on the page');

        return substr($html, $start);
    }

    // ── Both screens, the people who may use them ───────────────────────

    public function test_the_reviewer_sees_questions_and_answers_on_the_record_page(): void
    {
        $s = $this->submission($this->naturalAnswers());

        $html = $this->actingAs($this->branchMgrA)->get(route('compliance.fica.show', $s))->assertOk()->getContent();

        foreach ([
            'Questions &amp; answers', 'Person Completing This Form',
            'Are you a South African citizen / permanent resident?', 'Telephone Number',
            'Purpose of Transaction', 'I/We wish to let out a property',
            'Will any payment involve R50,000 or more in cash?',
            'Do you now occupy, or have you in the past 12 months occupied, any prominent public position in a country OTHER than South Africa?',
            'Thandiwe Mkhize', '14 Marine Drive, Ramsgate', 'Durban, South Africa',
        ] as $needle) {
            self::assertStringContainsString(str_replace("'", '&#039;', $needle), $html, "missing on the record page: {$needle}");
        }
        self::assertStringContainsString('submitted 14 Sep 2026 08:55 by Thandiwe Mkhize', preg_replace('/\s+/', ' ', $html));
    }

    public function test_the_primary_co_and_the_ro_see_the_same_questions_on_both_screens(): void
    {
        $s = $this->submission($this->naturalAnswers());

        foreach ([$this->primaryCo, $this->mlro] as $officer) {
            $show = $this->actingAs($officer)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
            $review = $this->actingAs($officer)->get(route('compliance.fica.compliance-review', $s))->assertOk()->getContent();

            $expected = array_map(fn ($r) => $r['label'], array_merge(...array_map(fn ($sec) => $sec['rows'], FicaQuestionnaire::forSubmission($s->fresh(['documents']))['sections'])));

            self::assertSame($expected, $this->shownQuestions($show), 'record page: questions out of questionnaire order');
            self::assertSame($expected, $this->shownQuestions($review), 'compliance-review: questions out of questionnaire order');
            self::assertStringContainsString('Questions &amp; answers', $review);
            self::assertStringContainsString('I/We wish to let out a property', $review);
        }
    }

    public function test_the_two_screens_render_identical_question_and_answer_blocks(): void
    {
        $s = $this->submission($this->naturalAnswers(['principal' => ['acting_on_behalf' => 'yes', 'full_name' => 'Pieter Principal', 'id_number' => '7001015800083', 'sa_citizen' => 'yes',
            'residential_address' => '2 Beach Rd', 'phone' => '083 000 0000', 'email' => 'p@example.test', 'authority_source' => 'Power of attorney']]));
        $this->document($s, 'id_copy', 'id.pdf');

        $a = $this->block($this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->getContent());
        $b = $this->block($this->actingAs($this->primaryCo)->get(route('compliance.fica.compliance-review', $s))->getContent());

        // Same partial => same rows. Compare only the rows block (up to the first thing that differs by screen).
        $rows = fn (string $h) => implode('', array_map(fn ($x) => preg_replace('/\s+/', ' ', $x), (array) (preg_match_all('/<div data-q .*?<\/div>\s*<div class="text-sm.*?<\/div>\s*<\/div>/s', $h, $m) ? $m[0] : [])));
        self::assertNotSame('', $rows($a));
        self::assertSame($rows($a), $rows($b));
    }

    // ── Follow-ups, unanswered, the client's wording ────────────────────

    public function test_follow_up_questions_appear_only_when_the_client_was_shown_them(): void
    {
        $none = $this->submission($this->naturalAnswers());
        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $none))->assertOk()->getContent();
        $shown = $this->shownQuestions($html);

        self::assertContains('Are you dealing with us on behalf of another person?', $shown);
        self::assertNotContains("Principal's Full Name", $shown, 'acting for no one: principal follow-ups were never asked');
        self::assertNotContains('Please indicate your source of wealth', $shown);
        self::assertNotContains('Please indicate which position(s):', $shown);

        $pep = $this->submission($this->naturalAnswers([
            'principal' => ['acting_on_behalf' => 'yes', 'full_name' => 'Pieter Principal', 'id_number' => '7001015800083', 'sa_citizen' => 'no',
                'residential_address' => '2 Beach Rd', 'phone' => '083 000 0000', 'email' => 'p@example.test', 'authority_source' => 'Power of attorney'],
            'pep' => ['is_foreign_pep' => 'yes', 'foreign_pep' => ['head_of_state', 'cabinet_member'], 'source_of_wealth' => 'Inheritance'],
            'service' => ['cash_over_50k' => 'yes'],
        ]));
        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.compliance-review', $pep))->assertOk()->getContent();
        $shown = $this->shownQuestions($html);

        self::assertContains("Principal's Full Name", $shown);
        self::assertContains('Please indicate which position(s):', $shown);
        self::assertContains('Please indicate your source of wealth', $shown);
        self::assertStringContainsString('Pieter Principal', $html);
        self::assertStringContainsString('Head of state', $html);       // the option wording the client ticked
        self::assertStringContainsString('Cabinet member', $html);
        self::assertStringContainsString('Inheritance', $html);
        self::assertStringNotContainsString('Judge', $html);             // an option they did not tick is not listed
        // Yes/No in the client's own words, risky "Yes" answers flagged.
        self::assertMatchesRegularExpression('/font-semibold[^>]*crimson[^>]*>Yes</', $html);
    }

    public function test_unanswered_questions_are_marked_not_answered_and_missing_documents_not_uploaded(): void
    {
        $answers = $this->naturalAnswers(['principal' => ['acting_on_behalf' => 'yes', 'full_name' => 'Pieter Principal']]); // follow-ups shown but mostly blank
        $s = $this->submission($answers);
        $this->document($s, 'id_copy', 'id-copy.pdf');

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->assertOk()->getContent();

        self::assertGreaterThan(5, substr_count($html, 'not answered'), 'blank questions must say so, not vanish');
        self::assertStringContainsString('SA Income Tax Number', $html); // optional + blank: still listed
        self::assertStringContainsString('not uploaded', $html);        // proof of address was asked for, none uploaded
        self::assertStringContainsString('id-copy.pdf', $html);
    }

    public function test_documents_sit_next_to_the_question_that_asked_for_them_and_are_linked(): void
    {
        $s = $this->submission($this->naturalAnswers());
        $docId = $this->document($s, 'id_copy', 'mkhize-id.pdf');
        $this->document($s, 'agent_added_letter', 'staff-note.pdf'); // not a question the client was asked

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
        $block = $this->block($html);

        $labelPos = strpos($block, 'Copy of ID document / Passport of person completing form');
        $filePos = strpos($block, 'mkhize-id.pdf');
        $linkPos = strpos($block, route('compliance.fica.documents.view', [$s, $docId]));
        self::assertNotFalse($labelPos);
        self::assertNotFalse($filePos);
        self::assertNotFalse($linkPos);
        self::assertLessThan(400, $filePos - $labelPos, 'the file must be on the same row as its question');
        // A document that answers no question is still listed — apart, never hidden.
        self::assertStringContainsString('Other documents on this record', $block);
        self::assertStringContainsString('staff-note.pdf', $block);
    }

    // ── Other entity types ──────────────────────────────────────────────

    public function test_a_company_shows_its_own_sections_numbering_and_repeating_people(): void
    {
        $s = $this->submission([
            'entity_type' => 'company',
            'personal' => ['full_name' => 'Cara Director', 'id_number' => '9001015800087', 'sa_citizen' => 'yes', 'residential_address' => '1 Main Rd', 'phone' => '031 555 0000', 'email' => 'cara@example.test'],
            'entity' => [
                'company_name' => 'Ramsgate Holdings (Pty) Ltd', 'company_reg_number' => '2019/123456/07', 'company_sa_presence' => 'Head office in Ramsgate',
                'company_address' => '1 Main Rd', 'company_authority_source' => 'Board resolution', 'company_business_description' => 'Property letting',
                'company_ownership_structure' => 'Simple', 'beneficial_owner_method' => 'method_1',
                'beneficial_owners' => [
                    ['name' => 'Owner One', 'id_number' => '7001015800083', 'address' => 'Addr 1', 'phone' => '', 'email' => ''],
                    ['name' => 'Owner Two', 'id_number' => '7102025800085', 'address' => 'Addr 2', 'phone' => '082 111 2222', 'email' => 'two@example.test'],
                ],
            ],
            'service' => ['transaction_purpose' => 'sell', 'payment_method' => 'Paid by attorney', 'cash_over_50k' => 'no'],
            'pep' => ['is_foreign_pep' => 'no', 'is_domestic_pep' => 'no', 'is_family_associate' => 'no'],
            'declaration' => ['signed_at_location' => 'Durban'],
        ], 'company');

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.compliance-review', $s))->assertOk()->getContent();

        foreach (['1. Entity Type', '2. Person Completing This Form', '3. Company / Close Corporation Details', '4. Service &amp; Payment',
            '5. Politically Exposed Person (PEP)', '6. Document Uploads', '7. Declaration &amp; Signature'] as $title) {
            self::assertStringContainsString($title, $html, "company section numbering: {$title}");
        }
        self::assertStringNotContainsString('4. Principal', $html);      // natural-person-only sections
        self::assertStringContainsString('Method 1: Natural persons who individually or collectively own 5%+ of shares/interests', $html);
        self::assertStringContainsString('Owner One', $html);
        self::assertStringContainsString('Owner Two', $html);
        self::assertStringContainsString('two@example.test', $html);
        self::assertStringContainsString('Proof of company/CC existence — registration document', $html);
    }

    public function test_a_trust_shows_the_beneficiary_follow_up_the_client_actually_got(): void
    {
        $trust = fn (array $entity) => $this->submission([
            'entity_type' => 'trust',
            'personal' => ['full_name' => 'Tina Trustee', 'id_number' => '8001015800084', 'sa_citizen' => 'yes', 'residential_address' => 'x', 'phone' => '1', 'email' => 't@example.test'],
            'entity' => $entity + ['trust_name' => 'Marine Family Trust', 'trust_master_ref' => 'IT123/2015', 'trust_sa_presence' => 'Yes', 'trust_master_court' => 'Pietermaritzburg',
                'trust_authority_source' => 'Letters of authority', 'trust_purpose' => 'Family property', 'donor_name' => 'Dan Donor', 'donor_id_number' => '6001015800081', 'donor_address' => 'y',
                'trustees' => [['name' => 'Tina Trustee', 'id_number' => '8001015800084', 'address' => 'x']]],
            'service' => ['transaction_purpose' => 'let_out', 'payment_method' => 'z', 'cash_over_50k' => 'no'],
            'pep' => ['is_foreign_pep' => 'no', 'is_domestic_pep' => 'no', 'is_family_associate' => 'no'],
            'declaration' => ['signed_at_location' => 'Durban'],
        ], 'trust');

        $named = $trust(['has_named_beneficiaries' => 'yes', 'beneficiaries' => [['name' => 'Ben Beneficiary', 'id_number' => '1', 'address' => 'b']]]);
        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $named))->assertOk()->getContent();
        self::assertContains('Beneficiaries', $this->shownQuestions($html));
        self::assertNotContains('How are the beneficiaries determined?', $this->shownQuestions($html));
        self::assertStringContainsString('Ben Beneficiary', $html);

        $unnamed = $trust(['has_named_beneficiaries' => 'no', 'beneficiary_determination' => 'By the trustees at their discretion']);
        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $unnamed))->assertOk()->getContent();
        self::assertContains('How are the beneficiaries determined?', $this->shownQuestions($html));
        self::assertNotContains('Beneficiaries', $this->shownQuestions($html));
        self::assertStringContainsString('By the trustees at their discretion', $html);
    }

    // ── Other states ────────────────────────────────────────────────────

    public function test_a_paper_intake_says_so_and_does_not_invent_questions(): void
    {
        $s = $this->submission(
            ['personal' => ['first_name' => 'Wet', 'last_name' => 'Ink', 'id_number' => '1', 'phone' => '1', 'email' => 'w@example.test'], 'entity' => ['type' => 'natural'],
                'intake' => ['method' => 'in_person', 'received_date' => '2026-06-22', 'received_by' => 'Reception']],
            'natural',
            ['intake_type' => 'wet_ink', 'wet_ink_received_date' => '2026-06-22', 'signature_data' => null, 'signed_at' => null],
        );
        $this->document($s, 'fica_form', 'signed-paper-form.pdf');

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.compliance-review', $s))->assertOk()->getContent();

        self::assertStringContainsString('paper (wet-ink) form received 22 Jun 2026', $html);
        self::assertStringContainsString('questions and answers are on the uploaded form', $html);
        self::assertStringContainsString('signed-paper-form.pdf', $html);
        self::assertSame([], $this->shownQuestions($html), 'a paper form has no online questions to show');
    }

    public function test_a_form_the_client_has_not_completed_says_so(): void
    {
        $s = $this->submission([], 'natural', ['form_data' => null, 'status' => 'draft', 'signature_data' => null, 'signed_at' => null]);

        $html = $this->actingAs($this->admin)->get(route('compliance.fica.show', $s))->assertOk()->getContent();

        self::assertStringContainsString('has not completed the form yet', $html);
        self::assertSame([], $this->shownQuestions($html));
    }

    public function test_nothing_the_client_typed_is_hidden_even_if_the_question_list_does_not_know_it(): void
    {
        $s = $this->submission($this->naturalAnswers([
            'service' => ['transaction_purpose' => 'barter'],            // a value the form does not offer
            'extra_section' => ['mystery_field' => 'kept and shown'],    // a key the questionnaire does not know
        ]));

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->assertOk()->getContent();

        self::assertStringContainsString('barter', $html, 'an unknown stored option value is shown as stored');
        self::assertStringContainsString('Other recorded answers', $html);
        self::assertStringContainsString('extra_section.mystery_field', $html);
        self::assertStringContainsString('kept and shown', $html);
    }

    public function test_an_answer_with_markup_is_escaped(): void
    {
        $s = $this->submission($this->naturalAnswers(['personal' => ['residential_address' => '<script>alert(1)</script> 3 Sea St']]));

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->assertOk()->getContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; 3 Sea St', $html);
    }

    public function test_a_submission_older_than_the_first_recorded_wording_carries_a_caution(): void
    {
        $s = $this->submission($this->naturalAnswers(), 'natural', ['signed_at' => '2026-03-03 00:00:00']);

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.show', $s))->assertOk()->getContent();

        self::assertStringContainsString('the wording the client saw may differ', $html);
    }

    // ── NO NEW ACCESS: permissions and scope exactly as before ──────────

    public function test_the_default_agent_role_still_cannot_open_either_screen(): void
    {
        $s = $this->submission($this->naturalAnswers(), 'natural', [], null, $this->plainAgent);

        $this->actingAs($this->plainAgent)->get(route('compliance.fica.show', $s))->assertForbidden();
        $this->actingAs($this->plainAgent)->get(route('compliance.fica.compliance-review', $s))->assertForbidden();
    }

    public function test_an_admin_who_is_not_an_appointed_officer_sees_the_record_page_but_not_the_co_screen(): void
    {
        $s = $this->submission($this->naturalAnswers());

        $this->actingAs($this->admin)->get(route('compliance.fica.show', $s))->assertOk()->assertSee('Questions &amp; answers', false);
        $response = $this->actingAs($this->admin)->get(route('compliance.fica.compliance-review', $s));
        $response->assertForbidden();
        self::assertStringNotContainsString('Thandiwe Mkhize', $response->getContent());
    }

    public function test_a_branch_scoped_user_cannot_see_another_branchs_answers(): void
    {
        $other = $this->submission($this->naturalAnswers(), 'natural', [], $this->branchB, $this->admin);

        $r = $this->actingAs($this->branchMgrA)->get(route('compliance.fica.show', $other));

        $r->assertForbidden();
        self::assertStringNotContainsString('14 Marine Drive', $r->getContent());
        $this->actingAs($this->branchMgrA)->get(route('compliance.fica.compliance-review', $other))->assertForbidden();
    }

    public function test_another_agencys_admin_is_blocked_on_both_screens(): void
    {
        $s = $this->submission($this->naturalAnswers());
        $otherAgency = (int) DB::table('agencies')->insertGetId(['name' => 'Other', 'slug' => 'other-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        $otherBranch = (int) DB::table('branches')->insertGetId(['agency_id' => $otherAgency, 'name' => 'O', 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create(['agency_id' => $otherAgency, 'branch_id' => $otherBranch, 'role' => 'admin']);

        $a = $this->actingAs($stranger)->get(route('compliance.fica.show', $s));
        $b = $this->actingAs($stranger)->get(route('compliance.fica.compliance-review', $s));

        self::assertContains($a->getStatusCode(), [403, 404]);
        self::assertContains($b->getStatusCode(), [403, 404]);
        self::assertStringNotContainsString('Thandiwe Mkhize', $a->getContent() . $b->getContent());
    }
}
