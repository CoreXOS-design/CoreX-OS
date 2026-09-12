<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
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
 * cc2's finding, 2026-09-13 — whether a highlighter captured a ledger line
 * used to be decided by a raw string match on its LABEL ("Income" /
 * "Expense"). Highlighters are freely renameable by the agency, so renaming
 * "Income" to "Salary" silently stopped it capturing — no error, the pen
 * still drew, the line just never reached the affordability panel. Fixed
 * with a real, stable capture_type column, independent of the display
 * name. This proves: renaming genuinely does not break capture, a
 * mismatched highlighter/entry_type pairing is refused server-side (not
 * just avoided client-side), and a highlighter with no capture_type draws
 * but can never capture.
 */
final class RentalApplicationHighlighterCaptureTypeTest extends TestCase
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

    private function attachDocument(RentalApplication $rentalApplication, User $agent): Document
    {
        Storage::fake('local');
        $storagePath = 'rental-applications/' . $rentalApplication->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($storagePath, '%PDF-1.4 fake content');

        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'Bank Statement.pdf', 'storage_path' => $storagePath, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 100,
            'document_type_id' => null, 'source_type' => 'rental_application', 'source_id' => $rentalApplication->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Document::findOrFail($documentId);
    }

    private function capturePayload(int $highlighterId, string $entryType, string $markUid): array
    {
        return [
            'mark_uid' => $markUid, 'page' => 0, 'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
            'highlighter_id' => $highlighterId, 'entry_type' => $entryType, 'entry_amount' => 500,
        ];
    }

    public function test_renaming_a_capture_highlighter_does_not_stop_it_capturing(): void
    {
        $agent = $this->agent();
        $app = $this->application($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $income = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        self::assertSame('income', $income->capture_type, 'the seeded Income highlighter must have a real capture_type from the start');

        // The exact scenario cc2's finding describes: an agency renames its
        // Income pen to something else entirely.
        $income->update(['label' => 'Salary (net)']);

        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), $this->capturePayload($income->id, 'income', 'renamed-still-captures'));

        $response->assertOk();
        self::assertSame(
            1,
            RentalApplicationDocumentMark::where('mark_uid', 'renamed-still-captures')->count(),
            'renaming the highlighter must not have stopped it from capturing'
        );
    }

    public function test_a_highlighter_cannot_capture_an_entry_type_it_does_not_own(): void
    {
        $agent = $this->agent();
        $app = $this->application($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $income = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        // A client claiming this Income-only pen captured an EXPENSE —
        // the server, not just the UI, must refuse this.
        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), $this->capturePayload($income->id, 'expense', 'mismatched-entry-type'));

        $response->assertStatus(422);
        self::assertSame(0, RentalApplicationDocumentMark::where('mark_uid', 'mismatched-entry-type')->count());
    }

    public function test_a_highlighter_with_no_capture_type_can_never_capture(): void
    {
        $agent = $this->agent();
        $app = $this->application($agent);
        $document = $this->attachDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $unpaid = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Unpaid')->where('role_scope', 'agent')->firstOrFail();

        self::assertNull($unpaid->capture_type, 'Unpaid must have a genuinely null capture_type — a deliberate default, not a bug');

        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), $this->capturePayload($unpaid->id, 'income', 'no-capture-type-refused'));

        $response->assertStatus(422);
        self::assertSame(0, RentalApplicationDocumentMark::where('mark_uid', 'no-capture-type-refused')->count());
    }

    // NOTE — the "prove it on real data, not a fresh fixture" requirement
    // is deliberately NOT a test in this file: RefreshDatabase (used by
    // every test above) runs against the isolated test database
    // (hfc_dash_test_3), never the real corex_qa1 data this worktree
    // otherwise shares with QA1 — a test here would only ever see an
    // empty table and prove nothing. That check was run directly against
    // the real database instead (documented in this round's commit/spec
    // entry): every one of the 23 real capture marks with a highlighter_id
    // resolves to a highlighter whose capture_type now matches its own
    // entry_type exactly, zero mismatches, backfilled from the same label
    // match the old code used to make live at request time.
}
