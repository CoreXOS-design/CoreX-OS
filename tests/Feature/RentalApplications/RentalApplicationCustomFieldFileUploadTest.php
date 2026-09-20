<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationCustomField;
use App\Models\RentalApplicationGeneration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/rental-application-field-config.md §7, piece (c)(4) — file
 * upload for a TYPE_FILE custom field. Johan's explicit "GO" ruling for
 * this piece: reuse the existing document pipeline (never a second upload
 * path), and — his non-negotiable design ruling — a file survives its
 * custom field being retired, because CoreX is a no-delete system and
 * FICA requires five years' retention after the relationship ends. That
 * case is pinned down explicitly below so nobody re-litigates it.
 */
final class RentalApplicationCustomFieldFileUploadTest extends TestCase
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

    private function agent(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function fileField(array $attrs = []): RentalApplicationCustomField
    {
        $label = $attrs['label'] ?? 'Proof of Income';

        return RentalApplicationCustomField::create(array_merge([
            'agency_id' => $this->agency->id,
            'key' => RentalApplicationCustomField::generateKey($this->agency->id, $label),
            'label' => $label,
            'field_type' => RentalApplicationCustomField::TYPE_FILE,
            'sort_order' => 0,
        ], $attrs));
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'token' => Str::random(64), 'status' => 'sent',
        ], $attrs));
    }

    // ── Public (applicant, token-based) upload ──────────────────────────

    public function test_applicant_can_upload_a_file_for_a_file_type_custom_field(): void
    {
        $field = $this->fileField();
        $application = $this->application();
        $file = UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf');

        $response = $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => $file,
        ]);

        $response->assertRedirect();
        $fresh = $application->fresh();
        $documentId = $fresh->custom_field_values[$field->key];
        $this->assertNotNull($documentId);

        $document = Document::find($documentId);
        $this->assertSame('payslip.pdf', $document->original_name);
        $this->assertSame('rental_application', $document->source_type);
        $this->assertSame($application->id, $document->source_id);
        $this->assertSame($field->key, $document->custom_field_key);
        $this->assertSame('in_progress', $fresh->status);
    }

    public function test_applicant_upload_never_leaks_into_the_generic_supporting_documents_relation_discriminator(): void
    {
        // The generic documents() relation has no further filtering beyond
        // source_type/source_id — custom_field_key is the discriminator
        // that keeps a field's own answer out of the generic Supporting
        // Documents list.
        $field = $this->fileField();
        $application = $this->application();
        $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf'),
        ]);

        $document = $application->fresh()->documents()->first();
        $this->assertSame($field->key, $document->custom_field_key);
    }

    public function test_applicant_upload_is_refused_when_the_slot_already_has_a_file(): void
    {
        $field = $this->fileField();
        $application = $this->application();
        $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf'),
        ]);

        $response = $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip-2.pdf', 200, 'application/pdf'),
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(1, Document::where('source_type', 'rental_application')->where('source_id', $application->id)->count());
    }

    public function test_applicant_upload_404s_for_a_non_file_custom_field(): void
    {
        $textField = RentalApplicationCustomField::create([
            'agency_id' => $this->agency->id,
            'key' => RentalApplicationCustomField::generateKey($this->agency->id, 'Pet Details'),
            'label' => 'Pet Details', 'field_type' => 'text', 'sort_order' => 0,
        ]);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $textField->key]), [
            'file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf'),
        ]);

        $response->assertStatus(404);
    }

    // ── Public replace / remove ─────────────────────────────────────────

    public function test_applicant_can_replace_a_file_which_atomically_soft_deletes_the_old_one(): void
    {
        $field = $this->fileField();
        $application = $this->application();
        $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip-old.pdf', 200, 'application/pdf'),
        ]);
        $oldDocumentId = $application->fresh()->custom_field_values[$field->key];

        $response = $this->post(route('rental-applications.public.custom-fields.replace', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip-new.pdf', 200, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $fresh = $application->fresh();
        $newDocumentId = $fresh->custom_field_values[$field->key];
        $this->assertNotSame($oldDocumentId, $newDocumentId);

        $this->assertSoftDeleted('documents', ['id' => $oldDocumentId]);
        $this->assertSame('payslip-new.pdf', Document::find($newDocumentId)->original_name);
    }

    public function test_applicant_replace_is_refused_when_the_slot_is_empty(): void
    {
        $field = $this->fileField();
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.custom-fields.replace', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf'),
        ]);

        $response->assertSessionHas('error');
    }

    public function test_applicant_can_remove_a_file_leaving_the_slot_empty(): void
    {
        $field = $this->fileField();
        $application = $this->application();
        $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf'),
        ]);
        $documentId = $application->fresh()->custom_field_values[$field->key];

        $response = $this->post(route('rental-applications.public.custom-fields.remove', [$application->token, $field->key]));

        $response->assertRedirect();
        $this->assertNull($application->fresh()->custom_field_values[$field->key]);
        $this->assertSoftDeleted('documents', ['id' => $documentId]);
    }

    public function test_replace_and_remove_are_blocked_once_documents_are_locked_post_submission(): void
    {
        $field = $this->fileField();
        // identity_verified_at set — this test is about the document lock,
        // not the separate identity gate (default-enabled per agency), which
        // would otherwise 403 first for an unverified submitted application.
        $application = $this->application(['status' => 'under_assessment', 'submitted_at' => now(), 'identity_verified_at' => now()]);
        $document = Document::withoutAgencyStamping(fn () => Document::create([
            'original_name' => 'payslip.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $application->id,
            'agency_id' => $application->agency_id, 'branch_id' => $application->branch_id,
            'custom_field_key' => $field->key,
        ]));
        $application->update(['custom_field_values' => [$field->key => $document->id]]);

        // isSubmitted() is true (submitted_at is set), so returnGatePassed()
        // requires the real session flag the applicant earns by passing the
        // return gate on a fresh visit — set it directly rather than
        // walking the gate, since this test is about the document lock,
        // not the return gate itself.
        $sessionKey = "rental_application_return_gate_passed:{$application->token}";

        $this->withSession([$sessionKey => true])->postJson(
            route('rental-applications.public.custom-fields.replace', [$application->token, $field->key]),
            ['file' => UploadedFile::fake()->create('new.pdf', 200, 'application/pdf')]
        )->assertStatus(423);

        $this->withSession([$sessionKey => true])->postJson(
            route('rental-applications.public.custom-fields.remove', [$application->token, $field->key])
        )->assertStatus(423);

        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
    }

    // ── Agent (authenticated) upload — upload-only ceiling ──────────────

    public function test_agent_can_upload_a_file_for_a_file_type_custom_field(): void
    {
        $agent = $this->agent();
        $field = $this->fileField();
        $application = $this->application(['status' => 'draft']);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.custom-fields.upload', [$application, $field->key]),
            ['file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf')]
        );

        $response->assertRedirect();
        $documentId = $application->fresh()->custom_field_values[$field->key];
        $this->assertNotNull($documentId);
        $this->assertSame($agent->id, Document::find($documentId)->uploaded_by);
    }

    public function test_agent_upload_is_refused_when_the_slot_already_has_a_file_no_replace_ui_exists(): void
    {
        $agent = $this->agent();
        $field = $this->fileField();
        $application = $this->application(['status' => 'draft']);
        $this->actingAs($agent)->post(
            route('corex.rental-applications.custom-fields.upload', [$application, $field->key]),
            ['file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf')]
        );

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.custom-fields.upload', [$application, $field->key]),
            ['file' => UploadedFile::fake()->create('payslip-2.pdf', 200, 'application/pdf')]
        );

        $response->assertSessionHas('error');
        $this->assertSame(1, Document::where('source_id', $application->id)->count());
    }

    // ── The non-negotiable: a file survives its custom field being retired ──

    public function test_a_files_document_and_download_survive_the_custom_field_itself_being_retired(): void
    {
        // Johan's explicit design ruling: "an uploaded file survives its
        // field being retired. Not a judgement call — CoreX is a no-delete
        // system and FICA requires five years after the business
        // relationship ends." Pinned down here so nobody re-litigates it.
        $agent = $this->agent();
        $field = $this->fileField();
        $application = $this->application();
        $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf'),
        ]);
        $documentId = $application->fresh()->custom_field_values[$field->key];

        $field->delete();

        $fresh = $application->fresh();
        $this->assertSame($documentId, $fresh->custom_field_values[$field->key], 'the answer itself must not be wiped by retiring the field');
        $this->assertNotSoftDeleted('documents', ['id' => $documentId]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.documents.download', [$application, $documentId]));
        $response->assertOk();
    }

    public function test_a_trashed_documents_generation_history_still_renders_and_downloads(): void
    {
        // The historical-integrity gap found while building this piece: IF
        // a custom field's document is ever soft-deleted after a
        // generation's snapshot has already frozen a reference to it, that
        // generation's own screen must still show it — Johan's ruling that
        // the record shows what was asked and given AT THE TIME.
        //
        // Note on reachability: today's document-lock design (see
        // assertDocumentsNotLocked()) makes replace/remove permanently
        // unavailable for a field once the application has ever been
        // submitted — so a file, once captured in a sealed generation,
        // can never actually be replaced through either the public or
        // agent HTTP surface today. That means this exact chain (trash
        // AFTER seal) has no live trigger yet. The fix still stands as a
        // structural invariant — it does not depend on how a document
        // became trashed, only on correctly serving one that is — so it's
        // proven directly here rather than faked through a flow that the
        // product deliberately blocks.
        $agent = $this->agent();
        $field = $this->fileField(['label' => 'Proof of Income']);
        $application = $this->application(['current_generation' => 1]);
        $document = Document::withoutAgencyStamping(fn () => Document::create([
            'original_name' => 'payslip-original.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $application->id,
            'agency_id' => $application->agency_id, 'branch_id' => $application->branch_id,
            'custom_field_key' => $field->key,
        ]));
        $application->update([
            'custom_field_values' => [$field->key => $document->id],
            'status' => 'returned', 'submitted_at' => now()->subDay(),
        ]);
        RentalApplicationGeneration::seal($application, \Illuminate\Http\Request::create('/fake', 'POST'));

        $document->delete();
        $this->assertSoftDeleted('documents', ['id' => $document->id]);

        $genResponse = $this->actingAs($agent)->get(route('corex.rental-applications.generations.show', [$application, 1]));
        $genResponse->assertOk();
        $genResponse->assertSee('Proof of Income');
        $genResponse->assertSee('payslip-original.pdf');

        $downloadResponse = $this->actingAs($agent)->get(route('corex.rental-applications.documents.download', [$application, $document->id]));
        $downloadResponse->assertOk();
    }

    public function test_review_screen_shows_a_download_link_for_a_file_type_custom_fields_current_answer(): void
    {
        $agent = $this->agent();
        $field = $this->fileField(['label' => 'Proof of Income']);
        $application = $this->application();
        $this->post(route('rental-applications.public.custom-fields.upload', [$application->token, $field->key]), [
            'file' => UploadedFile::fake()->create('payslip.pdf', 200, 'application/pdf'),
        ]);
        $application->refresh()->update([
            'status' => 'returned', 'submitted_at' => now(),
            'field_config_snapshot' => RentalApplication::resolvedFieldConfigFor($this->agency->id),
        ]);

        $response = $this->actingAs($agent)->get(route('corex.rental-applications.review', $application));

        $response->assertOk();
        $response->assertSee('Proof of Income');
        $response->assertSee('payslip.pdf');
    }
}
