<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Return leg, AT-392 round 5, 2026-09-13 — Johan/conductor: uploadDocument()
 * already saves a document to the DB and encrypted storage the instant it's
 * picked, independent of the form's own final submit — but form() never
 * loaded or displayed it back. An applicant who uploaded their ID, left,
 * and returned saw no sign of it and would upload it again — piling up
 * duplicate compliance documents against the same submission. Display-only
 * fix: filename and type shown, never the decrypted bytes re-served; no
 * remove control on an already-saved document (no hard deletes).
 */
final class FicaFormExistingDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

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
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function submission(): FicaSubmission
    {
        return FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => 'draft',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    public function test_a_document_uploaded_in_an_earlier_sitting_is_shown_back_as_already_on_file(): void
    {
        $submission = $this->submission();

        $upload = $this->post(route('fica.upload', $submission->token), [
            'file' => UploadedFile::fake()->image('id.jpg'),
            'document_type' => 'id_copy',
        ]);
        $upload->assertOk();

        $response = $this->get(route('fica.form', $submission->token));

        $response->assertOk();
        $response->assertSee('Already on file');
        $response->assertSee('id.jpg');
    }

    public function test_a_form_with_no_prior_uploads_shows_nothing_as_already_on_file(): void
    {
        $submission = $this->submission();

        $response = $this->get(route('fica.form', $submission->token));

        // "Already on file" is inside an x-show — always present in the
        // markup, only hidden client-side — so assertDontSee on that text
        // would be a false negative. The real signal is what got seeded
        // into the Alpine `uploads` state: nothing.
        $response->assertOk();
        $response->assertSee('uploads: []', false);
    }

    public function test_re_uploading_the_same_document_type_replaces_what_is_shown_not_duplicates_it_visually(): void
    {
        $submission = $this->submission();

        $this->post(route('fica.upload', $submission->token), [
            'file' => UploadedFile::fake()->image('first.jpg'),
            'document_type' => 'id_copy',
        ])->assertOk();
        $this->post(route('fica.upload', $submission->token), [
            'file' => UploadedFile::fake()->image('second.jpg'),
            'document_type' => 'id_copy',
        ])->assertOk();

        $response = $this->get(route('fica.form', $submission->token));

        $response->assertOk();
        // Both DB rows exist (create(), not updateOrCreate() — a pre-existing
        // characteristic of uploadDocument() this fix doesn't change), but
        // the applicant-facing seed picks the MOST RECENT one per type so
        // the screen shows one coherent "already on file" state, not two.
        $this->assertSame(2, FicaDocument::where('fica_submission_id', $submission->id)->where('document_type', 'id_copy')->count());
        $response->assertSee('second.jpg');
        $response->assertDontSee('first.jpg');
    }
}
