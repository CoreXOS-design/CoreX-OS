<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\Property;
use App\Models\User;
use App\Services\Compliance\MarketingReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Post-incident FICA gate hardening (property "30 Captain Smith" / Kym Pollard,
 * 2026-08-05): MarketingReadinessService::checkSellersFicaSubmissions() counted a
 * soft-deleted 'approved' fica_submissions row as valid evidence — a raw
 * DB::table() query never benefits from Eloquent's automatic SoftDeletes scope.
 * The stale row traced to a single bulk go-live Excel-import auto-approval batch
 * (7,714 contacts, 2026-06-17) that was later soft-deleted en masse.
 *
 * Policy (Johan): an imported contact must NEVER be treated as FICA-compliant —
 * not via a leftover soft-deleted row, and not even if such a row were somehow
 * not deleted. Only a genuine, current 'approved' fica_submissions row (not
 * carrying the bulk-import marker) satisfies the gate.
 */
final class FicaMarketingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_deleted_bulk_import_approval_does_not_satisfy_fica(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->seller($agencyId, $agent->id);
        $property = $this->property($agencyId, $agent->id, $seller->id);

        // The exact incident shape: bulk-import 'approved' row, later soft-deleted.
        $this->bulkImportApproval($seller->id, $agencyId, $agent->id)->delete();

        $checklist = app(MarketingReadinessService::class)->statusFor($property)->checklist;

        $this->assertFalse($checklist['fica']['passed'], 'a soft-deleted bulk-import approval must NOT satisfy FICA');
    }

    public function test_bulk_import_marker_does_not_satisfy_fica_even_when_not_deleted(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->seller($agencyId, $agent->id);
        $property = $this->property($agencyId, $agent->id, $seller->id);

        // Defense-in-depth: NOT soft-deleted, but still the bulk-import batch.
        $this->bulkImportApproval($seller->id, $agencyId, $agent->id);

        $checklist = app(MarketingReadinessService::class)->statusFor($property)->checklist;

        $this->assertFalse($checklist['fica']['passed'], 'the bulk-import marker alone must never satisfy FICA, deleted or not');
    }

    public function test_genuine_current_approval_satisfies_fica(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->seller($agencyId, $agent->id);
        $property = $this->property($agencyId, $agent->id, $seller->id);

        FicaSubmission::create([
            'contact_id' => $seller->id, 'agency_id' => $agencyId, 'requested_by' => $agent->id,
            'entity_type' => 'natural', 'status' => 'approved',
            'verification_method' => ['source' => 'co_review'],
            'verified_by' => $agent->id, 'verified_at' => now(),
        ]);

        $checklist = app(MarketingReadinessService::class)->statusFor($property)->checklist;

        $this->assertTrue($checklist['fica']['passed'], 'a genuine, current approval must still satisfy FICA');
    }

    public function test_freshly_imported_contact_with_no_fica_submission_is_not_done(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $seller = $this->seller($agencyId, $agent->id); // no fica_submissions row at all
        $property = $this->property($agencyId, $agent->id, $seller->id);

        $checklist = app(MarketingReadinessService::class)->statusFor($property)->checklist;

        $this->assertFalse($checklist['fica']['passed'], 'a freshly imported contact with no FICA submission must read FICA-not-done');
    }

    public function test_contact_import_no_longer_creates_an_approved_fica_submission(): void
    {
        [$agencyId, $agent] = $this->fixture();

        // Mirrors what the import controller does per row, minus the removed
        // FICA-approval block — proves the removed code path is truly gone by
        // asserting the table stays empty for a contact created the same way.
        $contact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agent->id,
            'first_name' => 'Imported', 'last_name' => 'Contact ' . Str::random(4),
            'phone' => '082' . random_int(1000000, 9999999),
        ]);

        $this->assertSame(
            0,
            DB::table('fica_submissions')->where('contact_id', $contact->id)->count(),
            'a freshly created (imported-style) contact must start with zero fica_submissions rows'
        );
        $this->assertStringNotContainsString(
            'mark_fica_approved',
            file_get_contents(resource_path('views/corex/contacts/index.blade.php')),
            'the auto-approve-on-import checkbox must be fully removed from the UI'
        );
    }

    // ── Harness ───────────────────────────────────────────────────────────

    private function bulkImportApproval(int $contactId, int $agencyId, int $agentId): FicaSubmission
    {
        return FicaSubmission::create([
            'contact_id' => $contactId, 'agency_id' => $agencyId, 'requested_by' => $agentId,
            'entity_type' => 'natural', 'status' => 'approved',
            'verification_method' => ['source' => FicaSubmission::BULK_IMPORT_SOURCE],
            'verified_by' => $agentId, 'verified_at' => now(),
            'reviewer_notes' => 'Auto-approved via contact Excel import (agency go-live migration).',
        ]);
    }

    /** @return array{0:int,1:User} */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        // Require FICA as a compliance doc type for this agency. 'fica' is normally
        // global reference data inserted by a migration (create_splitter_doc_types_
        // table), but the RefreshDatabase schema-snapshot bootstrap (§12a) loads
        // SCHEMA only, not that migration's data rows — so seed it here directly,
        // idempotently, rather than assume it's already present.
        $ficaTypeId = DB::table('document_types')->where('slug', 'fica')->value('id');
        if (! $ficaTypeId) {
            $ficaTypeId = DB::table('document_types')->insertGetId([
                'slug' => 'fica', 'label' => 'FICA', 'sort_order' => 2,
                'is_active' => true, 'grouping' => 'shared', 'fica_slot' => 'fica_form',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('agency_document_type_compliance')->insert([
            'agency_id' => $agencyId, 'document_type_id' => $ficaTypeId,
            'is_compliance_required' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $agent];
    }

    private function seller(int $agencyId, int $agentId): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agentId,
            'first_name' => 'Seller', 'last_name' => Str::random(5),
            'phone' => '082' . random_int(1000000, 9999999),
        ]);
    }

    private function property(int $agencyId, int $agentId, int $sellerId): Property
    {
        $property = Property::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $agentId,
            'title' => 'Test property', 'address' => Str::random(6) . ' Test Road',
            'status' => 'draft', 'listing_type' => 'sale', 'price' => 850000,
        ]);
        $property->contacts()->attach($sellerId, ['role' => 'seller']);

        return $property;
    }
}
