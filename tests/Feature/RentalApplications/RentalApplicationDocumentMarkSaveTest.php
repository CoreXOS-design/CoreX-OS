<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDocumentHighlight;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-392, 2026-09-08 — Johan, driving the real screen: "the unit tests
 * passed while this was completely broken" — there was no test at all that
 * actually opened a document PROGRESSIVELY (the real /first then /remaining
 * sequence a browser does) and then saved, so nothing would have caught a
 * regression in that exact path. This is that test.
 *
 * Exercises the real pipeline end to end — real pdftoppm/pdfinfo
 * rasterization of a real 2-page PDF (no mocking of the Process layer),
 * real HTTP requests through the actual routes/controller/service, real
 * assertions against the persisted row — matching this repo's own
 * established pattern for rasterization-dependent tests (see
 * ViewingPackRedactionEndpointTest, the only other test in this codebase
 * that touches pdftoppm for real).
 */
final class RentalApplicationDocumentMarkSaveTest extends TestCase
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

    /**
     * A real, genuinely two-page PDF — generated on the fly via dompdf
     * (already a dependency, the same library the highlight service itself
     * uses to reassemble a flattened copy) rather than checking in a new
     * binary fixture. Two pages specifically so the test exercises BOTH
     * highlight-data/first (page 1 alone) and highlight-data/remaining
     * (page 2) — the exact progressive-load split Johan's report named.
     */
    private function attachTwoPageDocument(RentalApplication $rentalApplication, User $agent): \App\Models\Document
    {
        Storage::fake('local');

        $html = '<!doctype html><html><body>'
            . '<div style="page-break-after:always;">Page one — rent statement line A</div>'
            . '<div>Page two — rent statement line B</div>'
            . '</body></html>';
        $pdfBytes = (string) Pdf::loadHTML($html)->output();

        $storagePath = 'rental-applications/' . $rentalApplication->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($storagePath, $pdfBytes);

        $docTypeId = (int) DB::table('document_types')->insertGetId([
            'slug' => 'rental-application-doc-' . uniqid(), 'label' => 'Rental Application Document', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'Statement.pdf', 'storage_path' => $storagePath, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => strlen($pdfBytes),
            'document_type_id' => $docTypeId, 'source_type' => 'rental_application', 'source_id' => $rentalApplication->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return \App\Models\Document::findOrFail($documentId);
    }

    public function test_progressive_open_then_save_persists_the_mark_and_the_next_open_sees_the_correct_version(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        $document = $this->attachTwoPageDocument($app, $agent);

        // ── Step 1: the real progressive-load sequence a browser does ──
        $first = $this->actingAs($agent)
            ->getJson(route('corex.rental-applications.documents.highlight-data.first', [$app, $document]))
            ->assertOk()
            ->json();

        self::assertSame(2, $first['total_pages'], 'fixture must genuinely be two pages for this test to mean anything');
        self::assertSame(0, $first['page']['index']);
        self::assertSame(0, $first['marks_version'], 'a document nobody has marked up yet starts at version 0');
        $baseVersion = $first['marks_version'];

        $remaining = $this->actingAs($agent)
            ->getJson(route('corex.rental-applications.documents.highlight-data.remaining', [$app, $document]))
            ->assertOk()
            ->json();

        self::assertCount(1, $remaining['pages'], 'remaining pages after page 1 of a 2-page document');
        self::assertSame(1, $remaining['pages'][0]['index']);

        // ── Step 2: save one mark on each page, using the version the
        // client actually loaded — this is the exact scenario Johan
        // reported failing with a false 409. ──
        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.highlight', [$app, $document]), [
                'base_version' => $baseVersion,
                'marks' => [
                    0 => [[
                        'id' => 'test-mark-page-0', 'type' => 'highlight', 'category' => 'income',
                        'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 16,
                    ]],
                    1 => [], // page 2 genuinely has no marks — still named, per the completeness rule
                ],
            ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'marks_version' => 1]);
        self::assertSame(1, $response->json('mark_count'));

        // ── Step 3: the mark is really in the database, not just in the response body. ──
        $highlight = RentalApplicationDocumentHighlight::where('document_id', $document->id)->firstOrFail();
        self::assertSame(1, $highlight->marks_version);
        self::assertCount(1, $highlight->marks_json[0] ?? []);
        self::assertSame('income', $highlight->marks_json[0][0]['category']);
        self::assertSame($agent->id, $highlight->marks_json[0][0]['author_user_id']);
        self::assertSame('agent', $highlight->marks_json[0][0]['author_role']);

        // ── Step 4: a SECOND open (as a browser reload would do) sees the
        // real, current version — not stale, not zero. This is the exact
        // three-value chain Johan asked to see proven: /first → base_version
        // sent → server's own version after save, and now a fresh /first
        // confirming they all agree. ──
        $secondOpen = $this->actingAs($agent)
            ->getJson(route('corex.rental-applications.documents.highlight-data.first', [$app, $document]))
            ->assertOk()
            ->json();
        self::assertSame(1, $secondOpen['marks_version']);
        self::assertCount(1, $secondOpen['marks'][0] ?? []);
    }

    public function test_a_genuinely_stale_version_is_refused_not_silently_overwritten(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        $document = $this->attachTwoPageDocument($app, $agent);

        // Someone else's save already landed (version is now 1).
        $this->actingAs($agent)->postJson(route('corex.rental-applications.documents.highlight', [$app, $document]), [
            'base_version' => 0,
            'marks' => [0 => [], 1 => []],
        ])->assertOk();

        // This client is still holding the ORIGINAL version 0 it loaded before that happened.
        $response = $this->actingAs($agent)->postJson(route('corex.rental-applications.documents.highlight', [$app, $document]), [
            'base_version' => 0,
            'marks' => [
                0 => [[
                    'id' => 'stale-client-mark', 'type' => 'highlight', 'category' => 'unpaid',
                    'points' => [['x' => 5, 'y' => 5], ['x' => 50, 'y' => 5]], 'width' => 16,
                ]],
                1 => [],
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJson(['reason' => 'version_conflict']);

        // And the stale client's mark was NOT silently written.
        $highlight = RentalApplicationDocumentHighlight::where('document_id', $document->id)->firstOrFail();
        self::assertSame(1, $highlight->marks_version, 'the refused save must not have moved the version at all');
        self::assertEmpty($highlight->marks_json[0] ?? []);
    }
}
