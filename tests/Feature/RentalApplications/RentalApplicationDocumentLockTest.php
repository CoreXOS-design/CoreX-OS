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
 * AT-392 — Johan, 2026-09-07 (2nd pass): "submitted docs are submitted.
 * they can add, but not replace or remove." Evidentiary rule, not a
 * setting — no agency toggle, same for every agency. Reasoning: once an
 * agent has received the application, the applicant must not be able to
 * quietly swap a payslip or pull a document the agent has already seen —
 * but they must still be able to send more (an agent asking "also send
 * your bank statements" is normal and must not require reopening anything).
 *
 * This is exactly the class of rule that gets quietly undone by a later
 * refactor (a status-check moved, an early-return reordered) with no
 * failing assertion to catch it — hence locking it in here, driving the
 * REAL public routes end to end rather than asserting on
 * RentalApplication::isSubmitted() in isolation.
 */
final class RentalApplicationDocumentLockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        // This worktree has no built frontend bundle (no npm install/build
        // run here) — irrelevant to what this test asserts (rendered HTML
        // text), so fake Vite rather than requiring an asset build just to
        // exercise the lock's own view-layer copy.
        $this->withoutVite();
        Storage::fake('local');
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        // Submission identity gate, 2026-09-13 — this file's fixtures
        // submit() through the REAL route (not a direct 'returned'
        // construction), and the contact's own email would otherwise
        // route every submit() here into the new gate screen instead of
        // completing immediately — not what document-locking is about.
        \App\Models\RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id], ['identity_gate_enabled' => false],
        );
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

    private function uploadDocument(RentalApplication $application, string $name = 'doc.pdf'): Document
    {
        $file = UploadedFile::fake()->create($name, 100, 'application/pdf');
        $this->post(route('rental-applications.public.documents', $application->token), ['supporting_files' => [$file]])
            ->assertRedirect();

        return $application->refresh()->documents()->latest('id')->first();
    }

    private function submit(RentalApplication $application): void
    {
        $this->post(route('rental-applications.public.submit', $application->token), [
            'declaration_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'tpn_consent_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ])->assertRedirect();

        $application->refresh();
    }

    public function test_before_submission_the_applicant_has_full_document_crud(): void
    {
        $application = $this->application();
        $doc = $this->uploadDocument($application, 'first.pdf');

        // Replace succeeds pre-submission.
        $replacement = UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf');
        $this->post(route('rental-applications.public.documents.replace', [$application->token, $doc->id]), [
            'replacement_file' => $replacement,
        ])->assertRedirect();

        $this->assertNotNull($doc->fresh()->deleted_at, 'The replaced document must be archived, not left active.');
        $newDoc = $application->refresh()->documents()->first();
        $this->assertSame('replacement.pdf', $newDoc->original_name);

        // Remove succeeds pre-submission — archived, never hard-deleted.
        $this->post(route('rental-applications.public.documents.remove', [$application->token, $newDoc->id]))
            ->assertRedirect();

        $this->assertNotNull($newDoc->fresh()->deleted_at);
        $this->assertDatabaseHas('documents', ['id' => $newDoc->id]); // row still exists — soft delete, not gone
    }

    public function test_submission_locks_replace_and_remove_but_not_add(): void
    {
        $application = $this->application();
        $original = $this->uploadDocument($application, 'original.pdf');

        $this->submit($application);
        $this->assertTrue($application->isSubmitted());

        // REPLACE is refused server-side — real request, real refusal.
        $replacement = UploadedFile::fake()->create('sneaky-replacement.pdf', 100, 'application/pdf');
        $replaceResponse = $this->post(route('rental-applications.public.documents.replace', [$application->token, $original->id]), [
            'replacement_file' => $replacement,
        ]);
        $replaceResponse->assertRedirect(route('rental-applications.public.show', $application->token));
        $replaceResponse->assertSessionHas('error');
        $this->assertNull($original->fresh()->deleted_at, 'A locked replace must not touch the original document at all.');
        $this->assertSame(1, $application->documents()->count(), 'A locked replace must not file a new document either.');

        // REMOVE is refused server-side.
        $removeResponse = $this->post(route('rental-applications.public.documents.remove', [$application->token, $original->id]));
        $removeResponse->assertRedirect(route('rental-applications.public.show', $application->token));
        $removeResponse->assertSessionHas('error');
        $this->assertNull($original->fresh()->deleted_at, 'A locked remove must not archive the original document.');

        // ADD still works fully, after submission.
        $late = $this->uploadDocument($application, 'bank-statement.pdf');
        $this->assertSame(2, $application->documents()->count());
        // >= not >: submit() never creates a document itself, so anything
        // that exists at submission time was necessarily created in an
        // earlier, separate request — a same-second tie can only mean a
        // genuinely later upload that a fast synchronous test outran
        // second-precision timestamp columns on, never a false positive.
        $this->assertTrue(
            $late->created_at->greaterThanOrEqualTo($application->submitted_at),
            'The late document must be timestamped at/after submitted_at so the agent side can tell it apart.'
        );

        // The original is still exactly what was submitted.
        $this->assertSame('original.pdf', $original->fresh()->original_name);
        $this->assertNull($original->fresh()->deleted_at);
    }

    public function test_the_applicant_sees_why_documents_are_locked(): void
    {
        $application = $this->application();
        $this->uploadDocument($application, 'original.pdf');
        $this->submit($application);

        $page = $this->get(route('rental-applications.public.show', $application->token));
        $page->assertOk();
        $page->assertSee("can't be changed", false);
        $page->assertDontSee('Replace');
        $page->assertDontSee('Remove');
        $page->assertSee('Submitted — locked', false);
    }

    public function test_locked_actions_never_hard_delete(): void
    {
        $application = $this->application();
        $doc = $this->uploadDocument($application);
        $this->submit($application);

        $this->post(route('rental-applications.public.documents.remove', [$application->token, $doc->id]));

        // The row is untouched, not merely "still findable via withTrashed" —
        // a locked remove must be a full no-op, not even a soft delete.
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'deleted_at' => null]);
    }
}
