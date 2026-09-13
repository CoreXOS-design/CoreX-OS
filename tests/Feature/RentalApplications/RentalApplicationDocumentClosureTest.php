<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-392 round 2, 2026-09-13 — cc3's finding while investigating the
 * withdraw control, escalated to the conductor: uploadDocuments()/
 * removeDocument()/replaceDocument() never checked application status at
 * all, so a withdrawn or declined application's public link kept
 * accepting files indefinitely — a public, unauthenticated write into
 * agency storage with no closing condition.
 *
 * Johan's ruling (amended after his own reopen precedent was checked):
 * withdrawn/declined are ALWAYS closed to new documents — no setting —
 * because the one legitimate door back in (reopen(), same override-tier
 * + required-note + audited path already proven exhaustively in
 * RentalApplicationWithdrawnStatusGuardTest and RentalApplicationReopenTest)
 * already exists and already tells the applicant, in their own agent's
 * words, what's being asked for. Approved stays open BY DEFAULT (an
 * agency may legitimately want one more document from an approved
 * tenant) but is now an agency setting.
 */
final class RentalApplicationDocumentClosureTest extends TestCase
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
        Mail::fake();
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo@example.co.za',
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function application(string $status, array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status,
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
            'full_name' => 'Thabo Mokoena', 'id_number' => '8505125800086',
            'submitted_at' => now()->subDay(), 'current_generation' => 1,
        ], $attrs));
    }

    private function upload(RentalApplication $application, string $name = 'file.pdf'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [UploadedFile::fake()->create($name, 100, 'application/pdf')],
        ]);
    }

    public static function alwaysClosedStatusProvider(): array
    {
        return [['withdrawn'], ['declined']];
    }

    /** @dataProvider alwaysClosedStatusProvider */
    public function test_uploading_is_blocked_with_the_honest_message_on_always_closed_statuses(string $status): void
    {
        $application = $this->application($status);

        $response = $this->upload($application);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => "This application is currently closed and not accepting new documents. If you have something to add, please contact your agent — they're able to reopen your application for you.",
        ]);
        $this->assertStringNotContainsString('Too many attempts', $response->json('message'));
        $this->assertSame(0, $application->documents()->count(), 'a blocked upload must file nothing');
    }

    /** @dataProvider alwaysClosedStatusProvider */
    public function test_removing_is_blocked_on_always_closed_statuses_and_the_existing_document_is_untouched(string $status): void
    {
        $application = $this->application('sent');
        $this->upload($application, 'kept.pdf')->assertOk();
        $document = $application->documents()->first();

        $application->status = $status;
        $application->save();

        $response = $this->postJson(route('rental-applications.public.documents.remove', [$application->token, $document->id]));

        $response->assertStatus(403);
        $this->assertNotNull($document->fresh(), 'the document must not be removed while the application is closed');
        $this->assertSame(1, $application->documents()->count());
    }

    /** @dataProvider alwaysClosedStatusProvider */
    public function test_replacing_is_blocked_on_always_closed_statuses_and_the_original_document_is_untouched(string $status): void
    {
        $application = $this->application('sent');
        $this->upload($application, 'original.pdf')->assertOk();
        $document = $application->documents()->first();

        $application->status = $status;
        $application->save();

        $response = $this->postJson(route('rental-applications.public.documents.replace', [$application->token, $document->id]), [
            'replacement_file' => UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(403);
        $this->assertNotNull($document->fresh(), 'the original document must survive a blocked replace attempt');
        $this->assertSame(1, $application->documents()->count(), 'no replacement document must be filed');
    }

    public function test_uploading_stays_open_on_approved_by_default(): void
    {
        $application = $this->application('approved');

        $this->upload($application)->assertOk();

        $this->assertSame(1, $application->documents()->count());
    }

    public function test_uploading_is_blocked_on_approved_when_the_agency_has_turned_it_off(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['document_uploads_open_after_approval' => false],
        );
        $application = $this->application('approved');

        $response = $this->upload($application);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'Your application has been approved and is no longer accepting new documents. If you need to send something else, please contact your agent.',
        ]);
        $this->assertSame(0, $application->documents()->count());
    }

    public function test_document_uploads_open_after_approval_setting_has_a_sensible_default_and_is_agency_configurable(): void
    {
        $this->assertTrue(RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor($this->agency->id));

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['document_uploads_open_after_approval' => false],
        );

        $this->assertFalse(RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor($this->agency->id));
    }

    /**
     * Ties Item 1's two halves together end-to-end: reopening a withdrawn
     * application (the existing, already-audited door back — see
     * RentalApplicationWithdrawnStatusGuardTest) naturally reopens
     * document uploads too, since documentUploadsOpen() reads the
     * application's CURRENT status fresh every time. No special-case code
     * needed for this to work — proving that here guards against a future
     * change accidentally introducing one.
     */
    public function test_reopening_a_withdrawn_application_reopens_document_uploads(): void
    {
        $application = $this->application('withdrawn');
        $this->upload($application)->assertStatus(403);

        $this->actingAs($this->agent)->post(
            route('corex.rental-applications.review.reopen', $application),
            ['note' => 'Applicant has new evidence to submit.'],
        )->assertOk();

        $application->refresh();
        $this->assertSame('reopened', $application->status);

        $this->upload($application->fresh(), 'new-evidence.pdf')->assertOk();
        $this->assertSame(1, $application->documents()->count());
    }
}
