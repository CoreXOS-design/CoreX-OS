<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-439 item 4 — SignatureService::createLeaseFromSignedDocument()
 * previously always created a brand-new Lease, even when an existing
 * DRAFT lease already existed on the same property (e.g. one an agent
 * started manually, or from an approved rental application, before
 * sending it for signature). Proves: a matching draft is now PROMOTED
 * (terms + source_document_id set, activated) instead of a duplicate
 * being created; the no-draft case still creates a new Lease exactly as
 * before; and a genuine conflict (another lease already active on the
 * property) is absorbed gracefully rather than throwing.
 */
final class LeaseFromSignedDocumentPromotionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Promotion Agency', 'slug' => 'promo-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Promotion test property', 'status' => 'active', 'listing_type' => 'rental',
            'address' => 'Uniqueleasepromotion9001 Test Avenue',
        ]);
    }

    private function signedLeaseTemplate(array $fieldsOverride = []): SignatureTemplate
    {
        $document = Document::create([
            'name' => 'Lease Agreement',
            'owner_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'agency_id' => $this->agency->id,
            'fields_json' => array_merge([
                'street_address' => 'Uniqueleasepromotion9001 Test Avenue',
                'lease_start' => '2026-11-01',
                'lease_end' => '2027-10-31',
                'rental_amount' => '12500',
            ], $fieldsOverride),
        ]);

        return SignatureTemplate::create([
            'document_id' => $document->id,
            'status' => 'completed',
            'agency_id' => $this->agency->id,
            'parties_json' => [
                ['role' => 'lessee', 'name' => 'Jane Tenant', 'email' => 'jane-' . uniqid() . '@example.test'],
            ],
        ]);
    }

    public function test_an_existing_draft_lease_on_the_property_is_promoted_not_duplicated(): void
    {
        $draft = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 0, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $template = $this->signedLeaseTemplate();
        $lease = app(SignatureService::class)->createLeaseFromSignedDocument($template);

        self::assertNotNull($lease);
        self::assertSame($draft->id, $lease->id, 'The existing draft must be promoted, not replaced by a new row.');
        self::assertSame(Lease::STATUS_ACTIVE, $lease->status);
        self::assertSame('esign_document', $lease->source);
        self::assertSame($template->document_id, $lease->source_document_id);
        self::assertEquals(12500.0, (float) $lease->rental_amount);
        self::assertSame('2026-11-01', $lease->start_date->toDateString());
        self::assertSame('2027-10-31', $lease->end_date->toDateString());
        self::assertSame(1, Lease::where('property_id', $this->property->id)->count(), 'Exactly one lease row must exist on this property — no duplicate.');
        self::assertSame(1, $lease->tenants()->count());

        self::assertDatabaseHas('signature_audit_log', [
            'signature_template_id' => $template->id,
            'action' => 'lease_promoted_from_document',
        ]);
    }

    public function test_no_existing_draft_still_creates_a_new_lease_exactly_as_before(): void
    {
        self::assertSame(0, Lease::where('property_id', $this->property->id)->count());

        $template = $this->signedLeaseTemplate();
        $lease = app(SignatureService::class)->createLeaseFromSignedDocument($template);

        self::assertNotNull($lease);
        self::assertSame(Lease::STATUS_ACTIVE, $lease->status);
        self::assertSame($this->property->id, $lease->property_id);
        self::assertSame(1, Lease::where('property_id', $this->property->id)->count());

        self::assertDatabaseHas('signature_audit_log', [
            'signature_template_id' => $template->id,
            'action' => 'lease_created_from_document',
        ]);
    }

    public function test_calling_it_twice_for_the_same_document_is_idempotent(): void
    {
        $template = $this->signedLeaseTemplate();

        $first = app(SignatureService::class)->createLeaseFromSignedDocument($template);
        $second = app(SignatureService::class)->createLeaseFromSignedDocument($template);

        self::assertNotNull($first);
        self::assertNull($second, 'source_document_id is already claimed — must not create or promote a second time.');
        self::assertSame(1, Lease::where('property_id', $this->property->id)->count());
    }

    public function test_a_genuine_active_lease_conflict_is_absorbed_not_thrown(): void
    {
        // A DIFFERENT lease is already active on this property.
        Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8000, 'start_date' => now()->subMonths(6)->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        // And a SEPARATE draft also exists (e.g. a renewal being prepared).
        $draft = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 0, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);

        $template = $this->signedLeaseTemplate();

        // Must not throw — the signing ceremony that already succeeded must
        // never be undone by a post-completion lease-linking conflict.
        $lease = app(SignatureService::class)->createLeaseFromSignedDocument($template);

        self::assertNotNull($lease);
        self::assertSame($draft->id, $lease->id);
        // Terms/link are saved even though activation could not proceed...
        self::assertSame($template->document_id, $lease->source_document_id);
        self::assertEquals(12500.0, (float) $lease->rental_amount);
        // ...but status is left exactly as it was — never force-activated
        // over a genuine conflict.
        self::assertSame(Lease::STATUS_DRAFT, $lease->status);
    }

    public function test_a_draft_for_a_different_tenant_on_the_same_property_is_left_alone(): void
    {
        $otherTenantDraft = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 0, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        \App\Models\LeaseTenant::create([
            'lease_id' => $otherTenantDraft->id,
            'contact_id' => \App\Models\Contact::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                'first_name' => 'Someone', 'last_name' => 'Else', 'email' => uniqid() . '@example.test',
            ])->id,
            'is_primary' => true,
        ]);

        $template = $this->signedLeaseTemplate();
        $lease = app(SignatureService::class)->createLeaseFromSignedDocument($template);

        self::assertNotNull($lease);
        self::assertNotSame($otherTenantDraft->id, $lease->id, 'A draft tied to a DIFFERENT tenant must not be promoted for this one.');
        self::assertSame(2, Lease::where('property_id', $this->property->id)->count());
    }
}
