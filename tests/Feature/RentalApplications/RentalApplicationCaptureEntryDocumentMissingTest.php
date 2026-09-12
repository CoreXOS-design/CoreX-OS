<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDocumentMark;
use App\Models\RentalApplicationHighlighter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-13 — Johan reported the ledger row's "Document removed" warning
 * showing for an entry whose document was genuinely present (application
 * 70, mark 3f4a57ba-...). Investigated: the server-side document_missing
 * computation (RentalApplicationReviewController::show()) and the client
 * template's x-show conditions were both already correct for that exact
 * case — confirmed live, both via a direct query against the real
 * database and via a real headless-Chrome render of application 70's own
 * review page, which showed no warning and no "Document removed" text for
 * that entry. Not reproducible in the current source. This test proves
 * BOTH states through the real controller output (not just live-browser
 * spot checks, so this is a permanent regression guard): a live document
 * shows no warning, and a genuinely soft-deleted one does.
 */
final class RentalApplicationCaptureEntryDocumentMissingTest extends TestCase
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

    private function application(User $agent): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'returned', 'submitted_at' => now()->subDay(),
        ]);
    }

    private function attachDocument(RentalApplication $rentalApplication, User $agent): \App\Models\Document
    {
        Storage::fake('local');
        $storagePath = 'rental-applications/' . $rentalApplication->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($storagePath, '%PDF-1.4 fake content');

        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'Rental Application.pdf', 'storage_path' => $storagePath, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100,
            'document_type_id' => null, 'source_type' => 'rental_application', 'source_id' => $rentalApplication->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return \App\Models\Document::findOrFail($documentId);
    }

    public function test_a_capture_entry_whose_document_is_present_shows_document_missing_false(): void
    {
        $agent = $this->agent();
        $app = $this->application($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        RentalApplicationDocumentMark::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'rental_application_id' => $app->id,
            'mark_uid' => 'present-doc-entry', 'type' => 'highlight', 'page' => 0,
            'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
            'highlighter_id' => $incomeHighlighter->id,
            'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_amount' => 28861.34, 'entry_date' => '2026-07-24',
            'entry_description' => 'Salary - ABC Holdings',
        ]);

        $html = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();

        self::assertStringContainsString('present-doc-entry', $html, 'the entry must actually be in the page payload, or this proves nothing');
        // The captureEntries JSON is HTML-attribute-escaped by Js::from() —
        // match the escaped shape rather than assuming a literal substring.
        self::assertMatchesRegularExpression(
            '/present-doc-entry.*?document_missing\\\\u0022:false/s',
            $html,
            'a mark on a live, present document must compute document_missing:false'
        );
    }

    public function test_a_capture_entry_whose_document_was_soft_deleted_shows_document_missing_true(): void
    {
        $agent = $this->agent();
        $app = $this->application($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        RentalApplicationDocumentMark::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'rental_application_id' => $app->id,
            'mark_uid' => 'orphaned-entry', 'type' => 'highlight', 'page' => 0,
            'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
            'highlighter_id' => $incomeHighlighter->id,
            'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_amount' => 15000, 'entry_date' => '2026-07-24',
            'entry_description' => 'Salary (evidence document later removed)',
        ]);

        // The exact real-world mechanism this whole feature exists for —
        // see PdfSplitterController::linkForRentalApplication()'s own
        // guard for why this can no longer happen via that specific path,
        // but the RENDER-SIDE indicator must still hold for ANY reason a
        // document might genuinely be gone.
        $document->delete();
        self::assertNotNull($document->fresh()->deleted_at, 'fixture must genuinely soft-delete the document, or this proves nothing');

        $html = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();

        self::assertStringContainsString('orphaned-entry', $html, 'the entry must actually be in the page payload, or this proves nothing');
        self::assertMatchesRegularExpression(
            '/orphaned-entry.*?document_missing\\\\u0022:true/s',
            $html,
            'a mark whose document was soft-deleted must compute document_missing:true'
        );
    }
}
