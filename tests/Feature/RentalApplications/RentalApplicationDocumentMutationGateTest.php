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
 * Prod-audit 2026-09-16 — the applicant's document upload / replace / remove
 * actions were the only applicant-facing actions that never re-checked the
 * return gate or the identity gate. A forwarded or leaked link could attach
 * files to the applicant's contact record from a fresh session. They now run
 * the same two gates show()/pdf()/viewDocument() run, in the same order.
 */
final class RentalApplicationDocumentMutationGateTest extends TestCase
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

    /** A submitted application whose identity gate has already been passed — only the return gate applies. */
    private function submittedApplication(): RentalApplication
    {
        return $this->application(['status' => 'returned', 'submitted_at' => now(), 'identity_verified_at' => now()]);
    }

    private function passReturnGate(RentalApplication $application): void
    {
        $this->withSession(["rental_application_return_gate_passed:{$application->token}" => true]);
    }

    private function pdf(string $name = 'payslip.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    public function test_a_fresh_session_cannot_upload_to_a_submitted_application(): void
    {
        $application = $this->submittedApplication();

        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [$this->pdf()],
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, $application->refresh()->documents()->count());
    }

    public function test_a_fresh_session_gets_the_return_gate_page_on_a_plain_upload_post(): void
    {
        $application = $this->submittedApplication();

        $response = $this->post(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [$this->pdf()],
        ]);

        $response->assertOk(); // the return-gate view renders in place, exactly as show() does
        $this->assertSame(0, $application->refresh()->documents()->count());
    }

    public function test_the_same_session_can_upload_once_the_return_gate_is_passed(): void
    {
        $application = $this->submittedApplication();
        $this->passReturnGate($application);

        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [$this->pdf()],
        ]);

        $response->assertOk();
        $this->assertSame(1, $application->refresh()->documents()->count());
    }

    public function test_a_fresh_session_cannot_replace_or_remove_an_existing_document(): void
    {
        $application = $this->submittedApplication();

        // Attach one document through a session that has passed the gate…
        $this->passReturnGate($application);
        $upload = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [$this->pdf('original.pdf')],
        ]);
        $upload->assertOk();
        $docId = $upload->json('documents.0.id');

        // …then act from a brand-new session holding only the link.
        $this->flushSession();

        $this->postJson(route('rental-applications.public.documents.replace', [$application->token, $docId]), [
            'replacement_file' => $this->pdf('corrected.pdf'),
        ])->assertStatus(403);

        $this->postJson(route('rental-applications.public.documents.remove', [$application->token, $docId]))
            ->assertStatus(403);

        $this->assertSame(1, $application->refresh()->documents()->count());
        $this->assertNotNull($application->documents()->find($docId));
    }

    public function test_an_unsent_application_is_never_gated_on_upload(): void
    {
        // Return gate applies only once submitted (isSubmitted()); an applicant
        // filling in for the first time uploads freely, as before.
        $application = $this->application(['status' => 'in_progress']);

        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [$this->pdf()],
        ]);

        $response->assertOk();
        $this->assertSame(1, $application->refresh()->documents()->count());
    }

    public function test_an_unfinished_identity_gate_blocks_upload_even_when_the_return_gate_is_passed(): void
    {
        // Submitted with an email on file but identity never verified: the
        // identity gate is still awaiting the applicant, so uploads wait too.
        $application = $this->application([
            'status' => 'returned', 'submitted_at' => now(), 'email' => 'sipho@example.co.za',
            'identity_verified_at' => null, 'identity_gate_unreachable' => false,
        ]);

        if (! $application->identityVerificationAwaitingApplicantAction()) {
            $this->markTestSkipped('Identity gate not awaiting in this configuration; covered by RentalApplicationIdentityGateTest.');
        }

        $this->passReturnGate($application);

        $response = $this->postJson(route('rental-applications.public.documents', $application->token), [
            'supporting_files' => [$this->pdf()],
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, $application->refresh()->documents()->count());
    }
}
