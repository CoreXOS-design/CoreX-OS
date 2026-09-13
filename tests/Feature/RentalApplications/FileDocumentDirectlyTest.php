<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\RentalApplicationDocumentMark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-410, 2026-09-13 — Johan: "this applicant sent split docs. so I know
 * what they are. dont need to run them through the splitter. can we give
 * the option right here to file directly as well." Proves
 * RentalApplicationReviewController::fileDocumentDirectly()/retypeDocument()
 * produce the exact hard constraints Johan named: same live-mark guard as
 * the splitter, same review-lock/scoping guards as every other write on
 * this screen, correctable in place without delete/re-upload, audit trail,
 * no hard deletes, any mime type (not PDF-only).
 */
final class FileDocumentDirectlyTest extends TestCase
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

        // DocumentType rows are seeded by a DATA migration
        // (2026_04_21_121927_seed_fica_document_types_to_document_types_table),
        // not the schema snapshot RefreshDatabase actually loads (CLAUDE.md
        // Non-negotiable #12a — the snapshot is schema-only, so data-seeding
        // migrations never replay against it). Created directly here instead
        // of assuming the global catalogue is populated.
        foreach ([
            ['slug' => 'payslip', 'label' => 'Payslip'],
            ['slug' => 'ids', 'label' => 'IDs / Identity'],
            ['slug' => 'bank_statement', 'label' => 'Bank Statement'],
            ['slug' => 'levy_statement', 'label' => 'Levy Statement'],
        ] as $i => $type) {
            DocumentType::firstOrCreate(['slug' => $type['slug']], $type + ['sort_order' => $i, 'is_active' => true]);
        }
    }

    private function agent(?Agency $agency = null, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'agency_id' => ($agency ?? $this->agency)->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'role' => 'admin',
        ]);
    }

    private function application(array $overrides = []): RentalApplication
    {
        $agent = $overrides['created_by_user_id'] ?? null;
        $agent = $agent ? User::find($agent) : $this->agent();

        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'returned', 'submitted_at' => now()->subDay(),
        ], $overrides));
    }

    private function untypedDocument(RentalApplication $rentalApplication, User $agent, array $overrides = []): Document
    {
        Storage::fake('local');
        $storagePath = 'rental-applications/' . $rentalApplication->id . '/documents/' . Str::random(20) . '.' . ($overrides['ext'] ?? 'pdf');
        Storage::disk('local')->put($storagePath, $overrides['bytes'] ?? '%PDF-1.4 fake content');

        $documentId = (int) DB::table('documents')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => $overrides['original_name'] ?? 'untitled upload.pdf', 'storage_path' => $storagePath, 'disk' => 'local',
            'mime_type' => $overrides['mime_type'] ?? 'application/pdf', 'size' => 100,
            'document_type_id' => $overrides['document_type_id'] ?? null,
            'source_type' => 'rental_application', 'source_id' => $rentalApplication->id,
            'uploaded_by' => $agent->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Document::findOrFail($documentId);
    }

    public function test_filing_an_untyped_document_directly_reproduces_the_splitters_output_shape(): void
    {
        $agent = $this->agent();
        $app = $this->application(['created_by_user_id' => $agent->id]);
        $document = $this->untypedDocument($app, $agent, ['original_name' => 'payslip scan.pdf']);
        $payslipType = DocumentType::where('slug', 'payslip')->firstOrFail();

        $response = $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.file-direct', [$app, $document]),
            ['document_type_id' => $payslipType->id]
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $newId = $response->json('document.id');
        self::assertNotSame($document->id, $newId, 'a direct-filed document must be a NEW row, matching the splitter');

        $filed = Document::findOrFail($newId);
        self::assertSame($payslipType->id, $filed->document_type_id);
        self::assertSame('rental_application', $filed->source_type);
        self::assertSame($app->id, $filed->source_id);
        self::assertTrue($filed->contacts()->where('contact_id', $this->contact->id)->exists());
        self::assertSame($payslipType->label . '.pdf', $filed->original_name);

        $document->refresh();
        self::assertNotNull($document->deleted_at, 'the untyped original must be soft-deleted, never left sitting next to its typed replacement');
        self::assertTrue(Document::withTrashed()->where('id', $document->id)->exists(), 'soft delete only — the row itself must still exist');
    }

    public function test_filing_works_for_a_non_pdf_document_type(): void
    {
        $agent = $this->agent();
        $app = $this->application(['created_by_user_id' => $agent->id]);
        $document = $this->untypedDocument($app, $agent, ['original_name' => 'id-photo.jpg', 'mime_type' => 'image/jpeg', 'ext' => 'jpg', 'bytes' => 'fake-jpeg-bytes']);
        $idsType = DocumentType::where('slug', 'ids')->firstOrFail();

        $response = $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.file-direct', [$app, $document]),
            ['document_type_id' => $idsType->id]
        );

        $response->assertOk();
        $filed = Document::findOrFail($response->json('document.id'));
        self::assertSame('image/jpeg', $filed->mime_type);
        self::assertSame($idsType->id, $filed->document_type_id);
    }

    public function test_filing_a_document_with_a_live_capture_ledger_entry_is_blocked(): void
    {
        $agent = $this->agent();
        $app = $this->application(['created_by_user_id' => $agent->id]);
        $document = $this->untypedDocument($app, $agent);
        $payslipType = DocumentType::where('slug', 'payslip')->firstOrFail();

        RentalApplicationDocumentMark::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'rental_application_id' => $app->id,
            'mark_uid' => 'capture-at-risk', 'type' => 'highlight', 'page' => 0,
            'points' => [['x' => 10, 'y' => 10], ['x' => 100, 'y' => 10]], 'width' => 26,
            'author_user_id' => $agent->id, 'author_name' => $agent->name, 'author_role' => 'agent',
            'entry_type' => 'income', 'entry_amount' => 15000, 'entry_description' => 'Salary',
        ]);

        $response = $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.file-direct', [$app, $document]),
            ['document_type_id' => $payslipType->id]
        );

        $response->assertStatus(422);
        self::assertStringContainsString('R15,000.00', $response->json('error'));
        $document->refresh();
        self::assertNull($document->deleted_at, 'a blocked filing attempt must not touch the source document');
        self::assertSame(
            0,
            Document::where('source_type', 'rental_application')->where('source_id', $app->id)->where('id', '!=', $document->id)->count(),
            'nothing should have been filed — the guard must fire before any copy work runs'
        );
    }

    public function test_filing_is_refused_while_the_application_is_with_the_authoriser(): void
    {
        $agent = $this->agent();
        $app = $this->application([
            'created_by_user_id' => $agent->id,
            'status' => 'under_assessment',
            'submitted_for_approval_at' => now(),
        ]);
        $document = $this->untypedDocument($app, $agent);
        $payslipType = DocumentType::where('slug', 'payslip')->firstOrFail();

        $response = $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.file-direct', [$app, $document]),
            ['document_type_id' => $payslipType->id]
        );

        $response->assertStatus(423);
        $document->refresh();
        self::assertNull($document->document_type_id, 'a locked screen must not process the filing at all');
    }

    public function test_cross_agency_document_cannot_be_filed_against_another_agencys_application(): void
    {
        $agent = $this->agent();
        $app = $this->application(['created_by_user_id' => $agent->id]);

        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Elsewhere']);
        $otherAgent = $this->agent($otherAgency, $otherBranch);
        $otherContact = Contact::create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Other', 'last_name' => 'Person']);
        $otherApp = RentalApplication::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'contact_id' => $otherContact->id,
            'created_by_user_id' => $otherAgent->id, 'status' => 'returned',
        ]);
        $foreignDocument = $this->untypedDocument($otherApp, $otherAgent);
        // Re-scope the fixture document to the OTHER agency (untypedDocument()
        // above always writes $this->agency's id — this application's own
        // agency is the one under test here).
        DB::table('documents')->where('id', $foreignDocument->id)->update(['agency_id' => $otherAgency->id]);

        $payslipType = DocumentType::where('slug', 'payslip')->firstOrFail();

        $response = $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.file-direct', [$app, $foreignDocument]),
            ['document_type_id' => $payslipType->id]
        );

        self::assertContains($response->status(), [403, 404], 'a document belonging to a different agency\'s application must never be filable here');
    }

    public function test_retyping_an_already_filed_document_updates_in_place_without_a_new_row_or_delete(): void
    {
        $agent = $this->agent();
        $app = $this->application(['created_by_user_id' => $agent->id]);
        $bankStatementType = DocumentType::where('slug', 'bank_statement')->firstOrFail();
        $levyType = DocumentType::where('slug', 'levy_statement')->firstOrFail();
        $document = $this->untypedDocument($app, $agent, ['original_name' => 'Bank Statement.pdf', 'document_type_id' => $bankStatementType->id]);

        $response = $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.retype', [$app, $document]),
            ['document_type_id' => $levyType->id]
        );

        $response->assertOk();
        $response->assertJsonPath('document.id', $document->id);

        $document->refresh();
        self::assertSame($levyType->id, $document->document_type_id);
        self::assertSame($levyType->label . '.pdf', $document->original_name);
        self::assertNull($document->deleted_at, 'a retype must never delete the row it corrects');
        self::assertSame(
            1,
            Document::withTrashed()->where('source_type', 'rental_application')->where('source_id', $app->id)->count(),
            'a retype must never create a second document row'
        );
    }

    public function test_filing_and_retyping_both_write_an_audit_trail_entry(): void
    {
        $agent = $this->agent();
        $app = $this->application(['created_by_user_id' => $agent->id]);
        $payslipType = DocumentType::where('slug', 'payslip')->firstOrFail();
        $levyType = DocumentType::where('slug', 'levy_statement')->firstOrFail();
        $document = $this->untypedDocument($app, $agent);

        $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.file-direct', [$app, $document]),
            ['document_type_id' => $payslipType->id]
        )->assertOk();

        $filedLog = RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'filed_direct')->first();
        self::assertNotNull($filedLog, 'filing directly must write an audit entry');
        self::assertSame($agent->id, $filedLog->user_id);

        $filedDoc = Document::where('document_type_id', $payslipType->id)->where('source_id', $app->id)->firstOrFail();
        $this->actingAs($agent)->postJson(
            route('corex.rental-applications.documents.retype', [$app, $filedDoc]),
            ['document_type_id' => $levyType->id]
        )->assertOk();

        $retypeLog = RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'retyped')->first();
        self::assertNotNull($retypeLog, 'retyping must write an audit entry');
        self::assertSame($agent->id, $retypeLog->user_id);
    }
}
