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
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-12 — Johan-requested data-integrity audit found this endpoint had
 * ZERO test coverage since the capture-ledger feature shipped (2026-09-11),
 * and that gap is exactly how a real write-path bug went undetected for a
 * full day: confirmCaptureChip() (the client) sent points/width as bare 0-1
 * fractions instead of converting to raster px first, like every other mark
 * save does. Every anchored capture-chip entry ever confirmed (19/19 on
 * QA1) was stored with a bare fraction where a pixel value belongs — a
 * fractional width like 0.015 silently truncates to 0 in the
 * unsignedSmallInteger column, and the fractional points render
 * indistinguishable from the page's top-left corner. Fixed on both sides:
 * the client now converts before sending (matching applyHighlights()'s own
 * convention), and this endpoint now REJECTS a payload that can't possibly
 * be real raster pixels, rather than silently accepting and truncating one.
 */
final class RentalApplicationCaptureEntryTest extends TestCase
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

    /** Same fixture-building pattern as RentalApplicationDocumentMarkSaveTest — a real, single-page dompdf PDF is enough here (no progressive-load concern in this test). */
    private function attachOnePageDocument(RentalApplication $rentalApplication, User $agent): \App\Models\Document
    {
        Storage::fake('local');

        $pdfBytes = (string) Pdf::loadHTML('<!doctype html><html><body>Bank statement — line A</body></html>')->output();

        $storagePath = 'rental-applications/' . $rentalApplication->id . '/documents/' . Str::random(20) . '.pdf';
        Storage::disk('local')->put($storagePath, $pdfBytes);

        $docTypeId = (int) DB::table('document_types')->insertGetId([
            'slug' => 'rental-application-doc-' . uniqid(), 'label' => 'Rental Application Document', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'Bank Statement.pdf', 'storage_path' => $storagePath, 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => strlen($pdfBytes),
            'document_type_id' => $docTypeId, 'source_type' => 'rental_application', 'source_id' => $rentalApplication->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return \App\Models\Document::findOrFail($documentId);
    }

    public function test_a_capture_entry_with_real_raster_pixel_points_and_width_persists_exactly_as_sent(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        $document = $this->attachOnePageDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        // Real page dimensions, fetched the same way the client does before
        // ever drawing anything — proves this test's points/width are
        // genuinely inside the page, not arbitrary numbers that happen to
        // pass.
        $first = $this->actingAs($agent)
            ->getJson(route('corex.rental-applications.documents.highlight-data.first', [$app, $document]))
            ->assertOk()->json();
        $pageWidth = $first['page']['width'];
        $pageHeight = $first['page']['height'];
        self::assertGreaterThan(120, $pageWidth, 'fixture must genuinely rasterize to a real page size, or this test proves nothing');

        $points = [['x' => (int) ($pageWidth * 0.1), 'y' => (int) ($pageHeight * 0.5)], ['x' => (int) ($pageWidth * 0.4), 'y' => (int) ($pageHeight * 0.5)]];

        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), [
                'mark_uid' => 'capture-good', 'page' => 0, 'points' => $points, 'width' => 26,
                'highlighter_id' => $incomeHighlighter->id, 'entry_type' => 'income',
                'entry_date' => '2026-09-01', 'entry_description' => 'Salary', 'entry_amount' => 18139.05,
            ]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $mark = RentalApplicationDocumentMark::where('document_id', $document->id)->where('mark_uid', 'capture-good')->firstOrFail();
        self::assertSame($points, $mark->points, 'stored points must be the exact raster pixels sent, not re-scaled or altered');
        self::assertSame(26, $mark->width);
        self::assertSame('18139.05', (string) $mark->entry_amount);
    }

    public function test_a_bare_fraction_width_is_rejected_not_silently_truncated_to_zero(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        $document = $this->attachOnePageDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        // The exact shape of the real bug: points/width still in 0-1
        // fraction space, never converted to raster px.
        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), [
                'mark_uid' => 'capture-bad-fraction', 'page' => 0,
                'points' => [['x' => 0.0786377520015, 'y' => 0.62292124875526], ['x' => 0.10712076193039, 'y' => 0.62204595519886]],
                'width' => 0.015,
                'highlighter_id' => $incomeHighlighter->id, 'entry_type' => 'income',
                'entry_date' => null, 'entry_description' => null, 'entry_amount' => 18139.05,
            ]);

        $response->assertStatus(422);
        self::assertSame(
            0,
            RentalApplicationDocumentMark::where('document_id', $document->id)->where('mark_uid', 'capture-bad-fraction')->count(),
            'a payload shaped like the historical bug must never be persisted, not even with width silently truncated to 0'
        );
    }

    public function test_a_point_outside_the_pages_real_bounds_is_rejected(): void
    {
        $agent = $this->agent();
        $app = $this->application();
        $document = $this->attachOnePageDocument($app, $agent);
        RentalApplicationHighlighter::seedDefaultsFor($this->agency->id);
        $incomeHighlighter = RentalApplicationHighlighter::where('agency_id', $this->agency->id)
            ->where('label', 'Income')->where('role_scope', 'agent')->firstOrFail();

        // Ground truth for the page's real raster size.
        $first = $this->actingAs($agent)
            ->getJson(route('corex.rental-applications.documents.highlight-data.first', [$app, $document]))
            ->assertOk()->json();
        $wayOffPage = $first['page']['width'] + 5000;

        $response = $this->actingAs($agent)
            ->postJson(route('corex.rental-applications.documents.capture-entries.store', [$app, $document]), [
                'mark_uid' => 'capture-out-of-bounds', 'page' => 0,
                'points' => [['x' => $wayOffPage, 'y' => 10], ['x' => $wayOffPage + 50, 'y' => 10]],
                'width' => 26,
                'highlighter_id' => $incomeHighlighter->id, 'entry_type' => 'income',
                'entry_date' => null, 'entry_description' => null, 'entry_amount' => 500,
            ]);

        $response->assertStatus(422);
        self::assertSame(
            0,
            RentalApplicationDocumentMark::where('document_id', $document->id)->where('mark_uid', 'capture-out-of-bounds')->count(),
            'a point that could not possibly be on this page must never be persisted'
        );
    }
}
