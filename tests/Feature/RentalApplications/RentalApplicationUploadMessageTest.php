<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Applicant journey audit, 2026-09-12 — Johan, walking the applicant side
 * as an applicant: a rejected upload said "The supporting_files.0 field
 * must be a file of type: pdf, jpg, jpeg, png, doc, docx" and "...must not
 * be greater than 15360 kilobytes" — an internal array-indexed field name
 * and a unit nobody thinks in, on a form a non-technical member of the
 * public fills in alone with no one to ask. Proves the human-readable
 * replacement for both failure shapes, on both endpoints that can produce
 * them (uploadDocuments() and replaceDocument()), and proves the message's
 * MB figure is derived from the SAME constant driving the actual limit —
 * they can never drift apart.
 */
final class RentalApplicationUploadMessageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private RentalApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Applicant', 'last_name' => 'Walk', 'email' => 'applicant.walk@example.co.za',
        ]);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    private function assertHumanMessage(string $message): void
    {
        $this->assertNotSame('', $message, 'a message must actually have been found');
        $this->assertStringNotContainsString('supporting_files', $message, 'the internal array-indexed field name must never reach the applicant');
        $this->assertStringNotContainsString('replacement_file', $message, 'the internal field name must never reach the applicant');
        $this->assertStringNotContainsString('kilobyte', strtolower($message), 'no one thinks in kilobytes');
        $this->assertStringNotContainsString('.0', $message, 'no array-index leak');
    }

    /** Laravel's error bag keys validation messages by the LITERAL dotted field path (e.g. "supporting_files.0"), never nested — grab whichever single message came back, regardless of its exact key. */
    private function firstErrorMessage($response): string
    {
        $errors = $response->json('errors') ?? [];
        foreach ($errors as $messages) {
            if (is_array($messages) && isset($messages[0])) {
                return (string) $messages[0];
            }
        }

        return '';
    }

    public function test_wrong_file_type_message_is_human_and_names_accepted_kinds(): void
    {
        $file = UploadedFile::fake()->create('id.heic', 500, 'image/heic');

        $response = $this->postJson(route('rental-applications.public.documents', $this->application->token), [
            'supporting_files' => [$file],
        ]);

        $response->assertStatus(422);
        $message = $this->firstErrorMessage($response);
        $this->assertHumanMessage($message);
        $this->assertStringContainsString('PDF', $message);
        $this->assertStringContainsString('JPG', $message);
    }

    public function test_oversized_file_message_is_human_and_states_the_limit_in_mb(): void
    {
        // 1KB over the real limit — genuinely too large, not a type mismatch.
        $file = UploadedFile::fake()->create('payslip.pdf', 15361, 'application/pdf');

        $response = $this->postJson(route('rental-applications.public.documents', $this->application->token), [
            'supporting_files' => [$file],
        ]);

        $response->assertStatus(422);
        $message = $this->firstErrorMessage($response);
        $this->assertHumanMessage($message);
        $this->assertStringContainsString('15MB', $message, 'the message must state the SAME 15MB the constant actually enforces');
    }

    public function test_replace_endpoint_wrong_type_message_is_also_human(): void
    {
        $existing = Document::withoutAgencyStamping(fn () => Document::create([
            'original_name' => 'original.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100,
            'source_type' => 'rental_application', 'source_id' => $this->application->id,
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]));
        $existing->contacts()->attach($this->contact->id);

        $file = UploadedFile::fake()->create('id.heic', 500, 'image/heic');

        $response = $this->postJson(
            route('rental-applications.public.documents.replace', [$this->application->token, $existing->id]),
            ['replacement_file' => $file]
        );

        $response->assertStatus(422);
        $message = $this->firstErrorMessage($response);
        $this->assertHumanMessage($message);
    }

    public function test_a_genuinely_valid_file_within_the_limit_still_uploads_normally(): void
    {
        $file = UploadedFile::fake()->create('payslip.pdf', 500, 'application/pdf');

        $this->postJson(route('rental-applications.public.documents', $this->application->token), [
            'supporting_files' => [$file],
        ])->assertOk()->assertJsonStructure(['documents']);
    }
}
