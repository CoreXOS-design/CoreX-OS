<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Communications\CommunicationStorageService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CX-114 (Johan, 2026-08-22) — Comms Suspense's fileToDeal() pushes an email's
 * attachments into the deal's document library; DR2's CommunicationDealLinkingService
 * ::link() didn't. This wires it through the SAME DealDocumentService Comms Suspense
 * uses — no second attachment-filing path.
 *
 * A separate file from Dr2CommunicationLinkTest.php (CX-108/CX-113) deliberately —
 * that file is owned by concurrent DR2 work; this one only touches the attachment
 * side of the same service.
 */
final class Dr2CommunicationAttachmentFilingTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;
    private Deal $deal;
    private int $dealV2Id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'view_deals', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->dealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 1_500_000, 'commission_amount' => 75_000, 'commission_vat' => 11_250,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_500_000, 'total_commission' => 86_250,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Test Seller', 'property_address' => '1 Test Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $this->dealV2Id,
        ]));
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function comm(array $over = []): Communication
    {
        return Communication::create(array_merge([
            'agency_id' => $this->agencyId,
            'channel' => Communication::CHANNEL_EMAIL,
            'direction' => Communication::DIRECTION_INBOUND,
            'external_id' => Str::random(14),
            'thread_key' => 'thread-' . Str::random(6),
            'from_identifier' => 'conveyancer@bbb-attorneys.co.za',
            'subject' => 'RE: Transfer documents — 1 Test Rd',
            'occurred_at' => now(),
            'captured_at' => now(),
            'owner_user_id' => $this->agent->id,
            'has_attachments' => true,
        ], $over));
    }

    /**
     * A real, content-addressed, correctly (en/de)crypted attachment — same path
     * ingest uses. $bytes is wrapped with a genuine PDF magic-number header
     * (%PDF-) so it passes the CX-114 PDF-only filter by CONTENT, matching how a
     * real PDF is verified (never trust filename/mime). Use nonPdfAttachment()
     * below for the deliberately-excluded case.
     */
    private function attachment(Communication $comm, string $bytes, string $filename = 'FICA copy.pdf', ?string $mime = 'application/pdf'): CommunicationAttachment
    {
        return $this->rawAttachment($comm, "%PDF-1.4\n" . $bytes, $filename, $mime);
    }

    /** Deliberately NOT a PDF by content — e.g. a signature image (image001.png). */
    private function nonPdfAttachment(Communication $comm, string $bytes, string $filename = 'image001.png', ?string $mime = 'image/png'): CommunicationAttachment
    {
        return $this->rawAttachment($comm, $bytes, $filename, $mime);
    }

    private function rawAttachment(Communication $comm, string $bytes, string $filename, ?string $mime): CommunicationAttachment
    {
        $stored = app(CommunicationStorageService::class)->store($this->agencyId, 'attachment', $bytes);

        return CommunicationAttachment::create([
            'agency_id' => $this->agencyId,
            'communication_id' => $comm->id,
            'filename' => $filename,
            'mime' => $mime,
            'size_bytes' => strlen($bytes),
            'content_hash' => $stored['content_hash'],
            'storage_path' => $stored['path'],
            'media_status' => CommunicationAttachment::MEDIA_STORED,
        ]);
    }

    public function test_filing_with_an_attachment_creates_a_document_via_deal_document_service(): void
    {
        $comm = $this->comm();
        $this->attachment($comm, 'FICA copy bytes ' . Str::random(20));

        $response = $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $doc = Document::where('deal_id', $this->dealV2Id)->first();
        $this->assertNotNull($doc, 'the attachment must land as a real deal document');
        $this->assertSame('FICA copy.pdf', $doc->original_name);
        $this->assertSame('application/pdf', $doc->mime_type);
        $this->assertSame('inbound_email', $doc->source_type);
        $this->assertSame($this->agent->id, $doc->uploaded_by);

        // Provenance link recorded (drives withdraw-on-move/unlink and dedup).
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id,
            'linkable_type' => Document::class,
            'linkable_id' => $doc->id,
            'link_method' => CommunicationLink::METHOD_ATTACHMENT,
        ]);

        // Failure visibility (Johan, 2026-08-22) — the filing outcome is no longer
        // silent; the response says exactly what happened to the attachment.
        $response->assertJson(['attachments' => ['filed' => 1, 'skipped_duplicate' => 0, 'skipped_non_pdf' => 0, 'failed' => 0]]);
    }

    public function test_filing_without_attachments_creates_no_documents_and_still_links(): void
    {
        $comm = $this->comm(['has_attachments' => false]);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $this->assertSame(0, Document::where('deal_id', $this->dealV2Id)->count());
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_type' => DealV2::class, 'linkable_id' => $this->dealV2Id,
        ]);
    }

    public function test_two_emails_with_the_identical_attachment_produce_one_document_not_two(): void
    {
        $bytes = 'identical signed contract ' . Str::random(20);
        $commA = $this->comm(['subject' => 'Signed contract']);
        $this->attachment($commA, $bytes, 'contract.pdf');
        $commB = $this->comm(['subject' => 'RE: Signed contract', 'external_id' => Str::random(14)]);
        $this->attachment($commB, $bytes, 'contract.pdf'); // SAME bytes -> same content_hash

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $commA->id])
            ->assertCreated();
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $commB->id])
            ->assertCreated();

        $this->assertSame(1, Document::where('deal_id', $this->dealV2Id)->count(), 'the same content filed twice to the same deal must not duplicate');

        // Both communications are still genuinely linked to the deal — only the
        // redundant DOCUMENT was skipped, not the filing itself.
        $this->assertSame(2, CommunicationLink::where('linkable_type', DealV2::class)->where('linkable_id', $this->dealV2Id)->count());
    }

    public function test_the_same_attachment_on_two_different_deals_is_not_treated_as_a_duplicate(): void
    {
        $otherDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Other Seller', 'property_address' => '2 Other Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $otherDealV2Id,
        ]));

        $bytes = 'shared attorney letterhead template ' . Str::random(20);
        $commA = $this->comm();
        $this->attachment($commA, $bytes);
        $commB = $this->comm(['external_id' => Str::random(14)]);
        $this->attachment($commB, $bytes);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $commA->id])
            ->assertCreated();
        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $otherDeal), ['communication_id' => $commB->id])
            ->assertCreated();

        $this->assertSame(1, Document::where('deal_id', $this->dealV2Id)->count());
        $this->assertSame(1, Document::where('deal_id', $otherDealV2Id)->count(), 'dedup is per-deal, not global');
    }

    public function test_moving_a_filed_email_withdraws_its_documents_from_the_old_deal_and_refiles_to_the_new(): void
    {
        $otherDealV2Id = (int) DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'cash', 'listing_agent_id' => $this->agent->id,
            'purchase_price' => 900_000, 'commission_amount' => 45_000, 'commission_vat' => 6_750,
            'offer_date' => '2026-03-01', 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'created_by_id' => $this->agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDeal = Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 900_000, 'total_commission' => 51_750,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => random_int(1000, 9999), 'deal_type' => 'cash',
            'seller_name' => 'Other Seller', 'property_address' => '2 Other Rd',
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'deal_v2_id' => $otherDealV2Id,
        ]));

        $comm = $this->comm();
        $this->attachment($comm, 'wrongly filed contract ' . Str::random(20));

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();
        $originalDoc = Document::where('deal_id', $this->dealV2Id)->firstOrFail();

        // Move via the SAME link() with move=true — the linking service's own
        // existing reassign mechanism, extended.
        app(\App\Services\Communications\CommunicationDealLinkingService::class)
            ->link($comm->fresh(), $otherDealV2Id, $this->agencyId, $this->agent, move: true);

        // Old deal's document is withdrawn -- soft, recoverable, not left as an orphan.
        $this->assertSoftDeleted('documents', ['id' => $originalDoc->id]);
        $this->assertSame(0, Document::where('deal_id', $this->dealV2Id)->count());

        // New deal has a fresh document for the same attachment.
        $this->assertSame(1, Document::where('deal_id', $otherDealV2Id)->count());
    }

    public function test_unlinking_a_filed_email_withdraws_its_documents_too(): void
    {
        $comm = $this->comm();
        $this->attachment($comm, 'removed after filing ' . Str::random(20));

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();
        $doc = Document::where('deal_id', $this->dealV2Id)->firstOrFail();
        $link = CommunicationLink::where('communication_id', $comm->id)
            ->where('linkable_type', DealV2::class)->firstOrFail();

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.unlink', ['deal' => $this->deal, 'link' => $link->id]))
            ->assertOk();

        $this->assertSoftDeleted('documents', ['id' => $doc->id]);
        $this->assertSoftDeleted('communication_links', ['communication_id' => $comm->id, 'linkable_type' => Document::class]);
    }

    public function test_a_missing_blob_is_skipped_and_does_not_break_the_filing(): void
    {
        $comm = $this->comm();
        $att = $this->attachment($comm, 'this blob will be deleted ' . Str::random(20));
        // Simulate a corrupted/missing physical file without touching the row.
        \Illuminate\Support\Facades\Storage::disk(app(CommunicationStorageService::class)->disk())->delete($att->storage_path);

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated(); // the LINK still succeeds

        $this->assertSame(0, Document::where('deal_id', $this->dealV2Id)->count(), 'no document for the missing blob, but nothing else broke');
    }

    // ──────────────────────── CX-114: PDF-only filter (Johan, 2026-08-22) ────────────────────────

    public function test_a_non_pdf_attachment_is_not_filed_but_the_email_still_links(): void
    {
        $comm = $this->comm();
        $this->nonPdfAttachment($comm, "\x89PNG\r\n" . Str::random(40), 'image001.png', 'image/png');

        $response = $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $this->assertSame(0, Document::where('deal_id', $this->dealV2Id)->count(), 'signature/logo images must never reach the deal document library');
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_type' => DealV2::class, 'linkable_id' => $this->dealV2Id,
        ], 'the email itself still files — only the document side is filtered');
        $response->assertJson(['attachments' => ['filed' => 0, 'skipped_duplicate' => 0, 'skipped_non_pdf' => 1, 'failed' => 0]]);
    }

    public function test_the_filter_checks_real_bytes_not_the_sender_supplied_mime_or_filename(): void
    {
        // Mislabelled: mime and extension both say PDF, content does not start with %PDF-.
        // A mislabelled file must not slip through (Johan, 2026-08-22).
        $comm = $this->comm();
        $this->nonPdfAttachment($comm, 'this is actually just a text file ' . Str::random(20), 'fake.pdf', 'application/pdf');

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $this->assertSame(0, Document::where('deal_id', $this->dealV2Id)->count(), 'mime/extension claiming PDF is not enough — content must actually be a PDF');
    }

    public function test_a_genuine_pdf_with_wrong_mime_and_odd_filename_is_still_filed(): void
    {
        // A correctly-formed PDF with an odd filename/mime must not be excluded
        // (Johan, 2026-08-22).
        $comm = $this->comm();
        $this->nonPdfAttachment($comm, "%PDF-1.4\n" . Str::random(20), 'attachment.dat', 'application/octet-stream');

        $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $doc = Document::where('deal_id', $this->dealV2Id)->first();
        $this->assertNotNull($doc, 'real PDF content must be filed regardless of a wrong mime or extension');
        $this->assertSame('application/pdf', $doc->mime_type, 'the stored mime is the VERIFIED type, not the untrusted sender-supplied one');
    }

    public function test_mixed_pdf_and_non_pdf_attachments_file_only_the_pdf_and_report_both(): void
    {
        $comm = $this->comm();
        $this->attachment($comm, 'genuine transfer document ' . Str::random(20), 'Transfer Docs.pdf');
        $this->nonPdfAttachment($comm, "\xFF\xD8\xFF" . Str::random(40), 'image002.jpg', 'image/jpeg');

        $response = $this->actingAs($this->agent)
            ->postJson(route('deals-dr2.communications.link', $this->deal), ['communication_id' => $comm->id])
            ->assertCreated();

        $this->assertSame(1, Document::where('deal_id', $this->dealV2Id)->count());
        $response->assertJson(['attachments' => ['filed' => 1, 'skipped_duplicate' => 0, 'skipped_non_pdf' => 1, 'failed' => 0]]);
    }
}
