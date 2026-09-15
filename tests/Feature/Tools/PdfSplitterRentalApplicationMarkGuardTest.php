<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDocumentMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-12 — cc4's second end-to-end walk found that re-filing/re-typing a
 * rental-application document through the PDF splitter (Split & File)
 * unconditionally soft-deleted the SOURCE document, with no check for marks
 * anchored to it — silently orphaning any highlight, note, or capture-ledger
 * entry drawn on that document (real DB evidence on QA1: 45 marks across 14
 * applications, including 2 real capture-ledger entries worth R15,000 and
 * R4,500 on application 135). The orphaned mark kept its real figure with
 * nothing on screen indicating its evidence was gone.
 *
 * Fixed by refusing the split/re-file outright when the source document has
 * any live mark on it, rather than attempting an automated page-remapping
 * migration under this deadline (Johan's explicit choice — see
 * PdfSplitterController::linkForRentalApplication()'s own comment). This
 * test proves the guard fires before ANY of the expensive split/file work
 * runs, and that a document with no marks is completely unaffected.
 */
final class PdfSplitterRentalApplicationMarkGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
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

    private function application(): RentalApplication
    {
        $agent = $this->agent();

        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'returned', 'submitted_at' => now()->subDay(),
        ]);
    }

    private function attachDocument(RentalApplication $rentalApplication, User $agent): Document
    {
        Storage::fake('local');
        $storagePath = 'rental-applications/' . $rentalApplication->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($storagePath, '%PDF-1.4 fake content');

        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'bundle.pdf', 'storage_path' => $storagePath, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100,
            'document_type_id' => null, 'source_type' => 'rental_application', 'source_id' => $rentalApplication->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Document::findOrFail($documentId);
    }

    public function test_splitting_a_document_with_a_live_capture_ledger_entry_is_blocked_not_silently_orphaned(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        $document = $this->attachDocument($app, $agent);

        RentalApplicationDocumentMark::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'rental_application_id' => $app->id,
            'mark_uid' => 'capture-at-risk', 'type' => 'highlight', 'page' => 0,
            'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
            'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_amount' => 15000, 'entry_description' => 'Salary',
        ]);

        session(['splitter_context' => ['rental_application_id' => $app->id, 'source_document_id' => $document->id]]);

        $response = $this->actingAs($agent)
            ->post(route('tools.pdf_splitter.link_rental_application', $app), []);

        $response->assertRedirect(route('corex.rental-applications.review', $app));
        $response->assertSessionHasErrors('pdf');
        self::assertStringContainsString('R15,000.00', session('errors')->first('pdf'));

        $document->refresh();
        self::assertNull($document->deleted_at, 'the source document must survive when it has a live mark on it');
        self::assertSame(
            0,
            Document::where('source_type', 'rental_application')->where('source_id', $app->id)->where('id', '!=', $document->id)->count(),
            'nothing should have been filed — the guard must fire before any split work runs'
        );
    }

    public function test_splitting_a_document_with_no_marks_is_unaffected_by_the_guard(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        $document = $this->attachDocument($app, $agent);

        session(['splitter_context' => ['rental_application_id' => $app->id, 'source_document_id' => $document->id]]);

        $response = $this->actingAs($agent)
            ->post(route('tools.pdf_splitter.link_rental_application', $app), []);

        // No manifest batch was ever built for this document (no real
        // splitter session was started), so this legitimately fails a
        // DIFFERENT, later step — loadCompleteBatchOrFail()'s own "session
        // expired or manifest not found" — proving the mark guard itself
        // let a mark-free document straight through rather than blocking
        // it. Both errors share the 'pdf' key, so the message text (not
        // the key) is what distinguishes them.
        $response->assertSessionHasErrors('pdf');
        self::assertStringNotContainsString(
            'drawn on it',
            (string) session('errors')->first('pdf'),
            'a document with no marks must never be refused by this guard'
        );
    }
}
