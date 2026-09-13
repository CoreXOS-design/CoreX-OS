<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-392 — Johan, QA1: "I select docs and click submit... no docs arrive
 * back because i never clicked upload" and, on the SAME root cause,
 * "I complete all the information... attach a file, click upload and the
 * screen refreshes, and all my typed info is gone."
 *
 * Both are the same defect: the old upload action was a synchronous
 * form-POST-redirect, so using it reloaded the whole page — this public
 * form has no separate "save" step, so anything typed but not yet
 * submitted lived only in the browser and was discarded by that reload.
 *
 * The fix is async: uploadDocuments()/removeDocument()/replaceDocument()
 * now respond with JSON when the caller asks for it (real browser JS sends
 * Accept: application/json), so a document attaches with NO navigation at
 * all. These tests drive that JSON contract directly — the actual
 * no-reload behaviour lives in show.blade.php's Alpine component and
 * can't be exercised by a server-side HTTP test, but what CAN be proven
 * here, and is the thing that actually matters, is: a document attached
 * via this endpoint before submit() is called is genuinely on the
 * application when submit() runs — exactly what "I never clicked upload"
 * used to lose.
 */
final class RentalApplicationAsyncUploadTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
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
            'created_by_user_id' => $agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    public function test_the_public_show_page_actually_renders_with_documents_already_attached(): void
    {
        // Regression for a real incident: the Alpine data-seeding line
        // (`documents: @json($application->documents->map(fn ($d) => [...]))`)
        // nested a multi-line arrow-function/array literal — including a
        // route() call taking an array argument — inside @json()'s own
        // parentheses. Blade::compileString() passed (it only proves the
        // Blade->PHP string transform succeeds), but the compiled PHP was
        // genuinely invalid and the page 500'd on every real request once
        // an application had at least one document. None of the other
        // tests in this file ever GET the show page itself — they only
        // hit the JSON endpoints directly — so this shipped to QA1 undetected
        // until proven live. A real render is the only thing that catches
        // this class of bug.
        $application = $this->application();
        $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create('payslip.pdf', 100, 'application/pdf')],
        ])->assertOk();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertSee('payslip.pdf');
    }

    public function test_uploading_via_the_json_endpoint_attaches_the_document_before_submit_is_ever_called(): void
    {
        $application = $this->application();

        // This mirrors exactly what the async JS does the moment a file is
        // chosen — well before Submit is clicked, let alone pressed.
        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create('payslip.pdf', 100, 'application/pdf')],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['documents' => [['id', 'name', 'view_url']]]);

        $this->assertSame(1, $application->refresh()->documents()->count(), 'The document must be attached immediately, with no separate upload/submit step.');

        // Now submit — the document that was NEVER part of this request must
        // still be there afterwards. This is the exact "I never clicked
        // upload... no docs arrive back" scenario, proven false.
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $this->post(route('rental-applications.public.submit', $application->token), [
            'declaration_signature' => $sig,
            'tpn_consent_signature' => $sig,
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('returned', $application->status);
        $this->assertSame(1, $application->documents()->count(), 'The document attached before submit must survive it.');
    }

    public function test_a_rejected_upload_via_json_reports_exactly_which_file_and_why(): void
    {
        $application = $this->application();

        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create('not-a-real-doc.exe', 100, 'application/octet-stream')],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['supporting_files.0']);
        $this->assertSame(0, $application->refresh()->documents()->count(), 'A rejected file must never attach.');
    }

    public function test_json_replace_and_remove_never_hard_delete_and_respond_without_a_redirect(): void
    {
        $application = $this->application();

        $upload = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create('original.pdf', 100, 'application/pdf')],
        ]);
        $docId = $upload->json('documents.0.id');

        $replaceResponse = $this->postJson(route('rental-applications.public.documents.replace', [$application->token, $docId]), [
            'replacement_file' => UploadedFile::fake()->create('corrected.pdf', 100, 'application/pdf'),
        ]);
        $replaceResponse->assertOk();
        $replaceResponse->assertJsonStructure(['document' => ['id', 'name', 'view_url'], 'replaced_id']);

        $this->assertNotNull(\App\Models\Document::withTrashed()->find($docId)->deleted_at, 'Replace must archive the old row, not delete it.');
        $newDocId = $replaceResponse->json('document.id');

        $removeResponse = $this->postJson(route('rental-applications.public.documents.remove', [$application->token, $newDocId]));
        $removeResponse->assertOk();
        $this->assertNotNull(\App\Models\Document::withTrashed()->find($newDocId)->deleted_at);
        $this->assertDatabaseHas('documents', ['id' => $newDocId]); // still exists — soft delete only
    }

    // ── already-submitted.blade.php — found during the input-loss sweep:
    // this page still used the OLD synchronous form-POST-and-reload upload,
    // the exact mechanism the main show.blade.php form was already fixed
    // away from. Same fix, same reasoning: a partial multi-file failure
    // must never force reselecting files that already succeeded. ─────────

    public function test_the_already_submitted_page_renders_and_uploads_via_the_json_endpoint_with_no_redirect(): void
    {
        // Submission identity gate, 2026-09-13 — identity_verified_at set
        // so these fixtures (constructed directly, never through a real
        // submit()) don't collide with the new gate; not what these
        // tests are about.
        $application = $this->application(['status' => 'returned', 'submitted_at' => now(), 'identity_verified_at' => now()]);
        // AT-392 round 4, 2026-09-13 — the return gate: isSubmitted() is
        // true, so a fresh session must pass the gate before show()
        // renders the real content. Not what this test is about.
        $this->withSession(["rental_application_return_gate_passed:{$application->token}" => true]);

        $show = $this->get(route('rental-applications.public.show', $application->token));
        $show->assertOk();
        $show->assertSee('Application already received');
        $show->assertDontSee('enctype="multipart/form-data"', false); // no more plain sync upload form

        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create('bank-statement.pdf', 100, 'application/pdf')],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['documents' => [['id', 'name', 'view_url']]]);
        $this->assertSame(1, $application->refresh()->documents()->count());
    }

    public function test_the_already_submitted_page_lists_existing_documents_as_locked(): void
    {
        // Submission identity gate, 2026-09-13 — identity_verified_at set
        // so these fixtures (constructed directly, never through a real
        // submit()) don't collide with the new gate; not what these
        // tests are about.
        $application = $this->application(['status' => 'returned', 'submitted_at' => now(), 'identity_verified_at' => now()]);
        // AT-392 round 4, 2026-09-13 — see the identical note above.
        $this->withSession(["rental_application_return_gate_passed:{$application->token}" => true]);
        $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create('id-copy.pdf', 100, 'application/pdf')],
        ]);

        $show = $this->get(route('rental-applications.public.show', $application->token));
        $show->assertOk();
        $show->assertSee('id-copy.pdf');
        $show->assertSee('Submitted — locked');
    }

    // ── RA-01 (cc5, independent testing): "A DRAFT application — created
    // but the agent has never clicked Send — is already fully fillable at
    // its public token URL, with nothing telling the applicant it was
    // never sent." Token is generated at creation (an earlier round's own
    // fix), which is what made this reachable at all. ────────────────────

    public function test_a_draft_applications_public_link_shows_not_ready_not_the_fillable_form(): void
    {
        $application = $this->application(['status' => 'draft']);

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertSee("isn't ready yet", false);
        $response->assertDontSee('name="full_name"', false);
        $response->assertDontSee('Submit Application');
    }

    public function test_a_draft_application_cannot_be_submitted_even_via_a_direct_post(): void
    {
        $application = $this->application(['status' => 'draft']);
        $sig = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $response = $this->post(route('rental-applications.public.submit', $application->token), [
            'full_name' => 'Sneaky Bypass',
            'declaration_signature' => $sig,
            'tpn_consent_signature' => $sig,
        ]);

        $response->assertRedirect(route('rental-applications.public.show', $application->token));
        $application->refresh();
        $this->assertSame('draft', $application->status, 'A draft must never be submittable before it has genuinely been sent.');
        $this->assertNull($application->submitted_at);
        $this->assertNull($application->full_name);
    }

    public function test_a_draft_application_cannot_receive_documents_via_the_json_endpoint_either(): void
    {
        $application = $this->application(['status' => 'draft']);

        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create('sneaky.pdf', 100, 'application/pdf')],
        ]);

        $response->assertStatus(410);
        $this->assertSame(0, $application->refresh()->documents()->count());
    }

    public function test_once_sent_the_same_link_becomes_the_real_fillable_form(): void
    {
        // Same token throughout — sending is a status flip, not a new link
        // (the token/link are generated at creation, spec-approved).
        $application = $this->application(['status' => 'sent']);

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee("isn't ready yet", false);
        $response->assertSee('name="full_name"', false);
    }
}
