<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientAccessLog;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalPortalSetting;
use App\Models\SignedDocumentDistributionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §19 — Johan, 7 Oct 2026: "we have documents — we can put the lease agreement here, we
 * can put the inspection report here." The portal's Documents area for a tenant and an owner: the SIGNED lease agreement
 * (e-signed final PDF or the uploaded wet-ink copy), inspection reports that have been DISTRIBUTED, and documents the agency
 * shared — each only for the person it belongs to, opened only through an authorised route.
 */
final class PortalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = ['Origin' => 'http://localhost'];

    private Agency $agency;
    private Branch $branch;
    private User $staff;
    private Property $p1;
    private Property $p2;
    private Contact $tenant;     // Ayanda — on L1 and the renewal L1r
    private Contact $coTenant;   // on L1 and L1r too
    private Contact $oldTenant;  // earlier tenancy L0 on the same property
    private Contact $tenant2;    // a different property, a different owner
    private Contact $owner1;
    private Contact $owner2;
    /** @var array<string,Document> */
    private array $docs = [];
    private ?ClientUser $currentLogin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->staff = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $this->p1 = $this->property('Unit 33, 401 Margate Boulevard');
        $this->p2 = $this->property('7 Beach Road');
        $this->tenant = $this->contact('Ayanda');
        $this->coTenant = $this->contact('Co');
        $this->oldTenant = $this->contact('Old');
        $this->tenant2 = $this->contact('Other');
        $this->owner1 = $this->contact('Siyabonga');
        $this->owner2 = $this->contact('Owen');
        foreach ([[$this->owner1, $this->p1], [$this->owner2, $this->p2]] as [$o, $p]) {
            DB::table('contact_property')->insert(['contact_id' => $o->id, 'property_id' => $p->id, 'role' => 'landlord', 'created_at' => now(), 'updated_at' => now()]);
        }

        // ── leases on P1 ──
        $l0 = $this->lease($this->p1, [$this->oldTenant], ['status' => 'expired', 'start_date' => '2024-11-01', 'end_date' => '2025-10-31', 'signing_status' => 'signed_on_paper', 'source' => 'uploaded_signed_copy']);
        $l1 = $this->lease($this->p1, [$this->tenant, $this->coTenant], ['status' => 'expired', 'start_date' => '2025-11-01', 'end_date' => '2026-10-31', 'signing_status' => 'signed_on_paper', 'source' => 'uploaded_signed_copy']);
        $l1r = $this->lease($this->p1, [$this->tenant, $this->coTenant], ['status' => 'active', 'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'signing_status' => 'signed', 'signed_at' => '2026-10-20 10:00:00', 'previous_lease_id' => $l1->id, 'signature_template_id' => 4401]);
        $lc = $this->lease($this->p1, [$this->tenant], ['status' => 'cancelled', 'start_date' => '2023-11-01', 'end_date' => '2024-10-31', 'signing_status' => 'signed_on_paper', 'source' => 'uploaded_signed_copy']);
        $ld = $this->lease($this->p1, [$this->tenant], ['status' => 'draft', 'start_date' => '2028-11-01', 'end_date' => '2029-10-31', 'signing_status' => 'out_for_signing', 'signature_template_id' => 4402]);
        $la = $this->lease($this->p1, [$this->tenant], ['status' => 'expired', 'start_date' => '2022-11-01', 'end_date' => '2023-10-31', 'signing_status' => 'signed_on_paper', 'source' => 'uploaded_signed_copy']);
        $la->delete(); // archived
        $l2 = $this->lease($this->p2, [$this->tenant2], ['status' => 'active', 'start_date' => '2026-03-01', 'end_date' => '2027-02-28', 'signing_status' => 'signed_on_paper', 'source' => 'uploaded_signed_copy']);

        $this->docs['l0'] = $this->doc('lease', $l0->id, 'lease-0.pdf');
        $this->docs['l1'] = $this->doc('lease', $l1->id, 'lease-1.pdf');
        $this->docs['l1r'] = $this->doc('esign', 4401, 'lease-1-renewal-signed.pdf');
        $this->docs['lc'] = $this->doc('lease', $lc->id, 'lease-cancelled.pdf');
        $this->docs['ld'] = $this->doc('esign', 4402, 'lease-draft-awaiting-signatures.pdf');
        $this->docs['la'] = $this->doc('lease', $la->id, 'lease-archived.pdf');
        $this->docs['l2'] = $this->doc('lease', $l2->id, 'lease-2.pdf');
        $this->docs['l1_deleted'] = $this->doc('lease', $l1->id, 'lease-1-older-copy.pdf');
        $this->docs['l1_deleted']->delete();

        // ── inspections ──
        $this->docs['i_done'] = $this->inspectionDoc($l1, 'completed', RentalInspection::TYPE_IN);
        $this->docs['i_progress'] = $this->inspectionDoc($l1, 'in_progress', RentalInspection::TYPE_INTERIM);
        $this->docs['i_signing'] = $this->inspectionDoc($l1, 'awaiting_signature', RentalInspection::TYPE_INTERIM);
        $this->docs['i_draft'] = $this->inspectionDoc($l1, 'draft', RentalInspection::TYPE_AD_HOC);
        $this->docs['i_sent'] = $this->inspectionDoc($l1r, 'awaiting_signature', RentalInspection::TYPE_INTERIM, sentCopy: true);
        $this->docs['i_cancelled'] = $this->inspectionDoc($l1, 'cancelled', RentalInspection::TYPE_OUT);
        $this->docs['i_old_tenant'] = $this->inspectionDoc($l0, 'completed', RentalInspection::TYPE_OUT);
        $this->docs['i_archived_lease'] = $this->inspectionDoc($la, 'completed', RentalInspection::TYPE_OUT);
        $this->docs['i_p2'] = $this->inspectionDoc($l2, 'completed', RentalInspection::TYPE_IN);

        // ── documents the agency shared on purpose ──
        $this->docs['shared_tenant'] = $this->doc('manual', 1, 'house-rules.pdf', ['tenant_portal_visible' => true]);
        DB::table('document_contacts')->insert(['document_id' => $this->docs['shared_tenant']->id, 'contact_id' => $this->tenant->id, 'party_role' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        $this->docs['shared_owner'] = $this->doc('manual', 2, 'owner-guide.pdf', ['landlord_portal_visible' => true]);
        DB::table('document_contacts')->insert(['document_id' => $this->docs['shared_owner']->id, 'contact_id' => $this->owner1->id, 'party_role' => 'landlord', 'created_at' => now(), 'updated_at' => now()]);
        $this->docs['unshared'] = $this->doc('manual', 3, 'internal-note.pdf'); // attached to the tenant but NOT flagged
        DB::table('document_contacts')->insert(['document_id' => $this->docs['unshared']->id, 'contact_id' => $this->tenant->id, 'party_role' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    private function property(string $address): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->staff->id,
            'title' => $address, 'status' => 'active', 'listing_type' => 'rental', 'address' => $address,
        ]);
    }

    private function contact(string $first): Contact
    {
        $c = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => 'Test', 'email' => strtolower($first) . '@example.test']);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $this->agency->id]);

        return $c->fresh();
    }

    private function lease(Property $property, array $tenants, array $attrs): Lease
    {
        $lease = Lease::create($attrs + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'rental_amount' => 8500, 'source' => 'manual', 'created_by_user_id' => $this->staff->id,
        ]);
        foreach ($tenants as $i => $t) {
            LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $t->id, 'is_primary' => $i === 0]);
        }

        return $lease;
    }

    private function doc(string $sourceType, int $sourceId, string $name, array $extra = [], ?int $agencyId = null): Document
    {
        $path = "portal-docs-test/{$sourceType}-{$sourceId}-" . uniqid() . '.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4 content of {$name}");

        return Document::withoutAgencyStamping(fn () => Document::create($extra + [
            'original_name' => $name, 'storage_path' => $path, 'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 30,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'agency_id' => $agencyId ?? $this->agency->id,
        ]));
    }

    /** §49 — every party (each lease tenant and the agent) holds a live signature, as a report that was properly signed does. */
    private function signEveryone(RentalInspection $inspection): void
    {
        foreach (\App\Models\LeaseTenant::where('lease_id', $inspection->lease_id)->pluck('contact_id') as $contactId) {
            \App\Models\RentalInspectionSignature::forceCreate([
                'agency_id' => $inspection->agency_id, 'rental_inspection_id' => $inspection->id, 'party_role' => 'tenant',
                'party_contact_id' => $contactId, 'disposition' => 'signed', 'party_signature_path' => 'signatures/t.png', 'disposition_recorded_at' => now(),
            ]);
        }
        if ($landlord = $inspection->property()->first()?->sellerOwnerContact()) {
            \App\Models\RentalInspectionSignature::forceCreate([
                'agency_id' => $inspection->agency_id, 'rental_inspection_id' => $inspection->id, 'party_role' => 'landlord',
                'party_contact_id' => $landlord->id, 'disposition' => 'signed', 'party_signature_path' => 'signatures/l.png', 'disposition_recorded_at' => now(),
            ]);
        }
        \App\Models\RentalInspectionSignature::forceCreate([
            'agency_id' => $inspection->agency_id, 'rental_inspection_id' => $inspection->id, 'party_role' => 'agent',
            'disposition' => 'signed', 'party_signature_path' => 'signatures/a.png', 'disposition_recorded_at' => now(),
        ]);
    }

    private function inspectionDoc(Lease $lease, string $status, string $type, bool $sentCopy = false, bool $signed = true): Document
    {
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'property_id' => $lease->property_id,
            'type' => $type, 'status' => $status, 'created_by_user_id' => $this->staff->id,
            'completed_at' => $status === 'completed' ? now()->subDay() : null,
        ]);
        if ($signed && ($status === 'completed' || $sentCopy)) {
            $this->signEveryone($inspection);
        }
        if ($sentCopy) {
            SignedDocumentDistributionLog::create([
                'agency_id' => $this->agency->id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $inspection->id,
                'channel' => 'email', 'mode' => 'manual', 'recipient_role' => 'tenant', 'recipient_email' => 'x@example.test', 'status' => 'sent',
            ]);
        }

        return $this->doc('rental_inspection_report', $inspection->id, "inspection-{$inspection->id}.pdf");
    }

    private function asPortal(Contact $contact): ClientUser
    {
        $login = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $this->agency->id, 'password' => 'x-secret-pass-1', 'activated_at' => now()]);
        $contact->forceFill(['client_user_id' => $login->id])->saveQuietly();
        $this->currentLogin = $login;

        return $login;
    }

    private function ids(array $json): array
    {
        return collect($json['documents'])->pluck('id')->sort()->values()->all();
    }

    private function idsOf(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->docs[$k]->id)->sort()->values()->all();
    }

    private function portalGet(string $url)
    {
        // Each request starts clean, as its own PHP process does, then acts as the current portal person (if any).
        Auth::forgetGuards();
        Auth::shouldUse('web');
        if ($this->currentLogin) {
            Sanctum::actingAs($this->currentLogin, ['client']);
        }

        // The portal page asks for JSON (fetch with Accept: application/json); file links are plain navigations.
        return $this->withHeaders(self::BROWSER + (str_contains($url, '/file') || $url === '/portal' ? [] : ['Accept' => 'application/json']))->get($url);
    }

    // ── tenant ───────────────────────────────────────────────────────────────────────────────

    public function test_a_tenant_sees_their_signed_leases_distributed_reports_and_shared_documents_only(): void
    {
        $this->asPortal($this->tenant);

        $json = $this->portalGet('/api/v1/client/rentals/documents')->assertOk()->json();

        $this->assertSame(
            $this->idsOf('l1', 'l1r', 'lc', 'i_done', 'i_sent', 'shared_tenant'),
            $this->ids($json),
            'signed leases (paper, e-signed renewal, cancelled-as-history), distributed reports on THEIR leases, and the shared document — nothing else'
        );
        $json = collect($json['documents']);
        $this->assertSame('Lease renewal', $json->firstWhere('id', $this->docs['l1r']->id)['type']);
        $this->assertSame('Lease agreement', $json->firstWhere('id', $this->docs['l1']->id)['type']);
        $this->assertSame('In', $json->firstWhere('id', $this->docs['i_done']->id)['subtype']);
        $this->assertSame('Lease agreement — ' . $this->p1->buildDisplayAddress(), $json->firstWhere('id', $this->docs['l1']->id)['name']);
        $this->assertSame('Lease 1 Nov 2025 – 31 Oct 2026', $json->firstWhere('id', $this->docs['l1']->id)['belongs_to']['lease_label']);
        $this->assertSame('/api/v1/client/rentals/documents/' . $this->docs['l1']->id . '/file', $json->firstWhere('id', $this->docs['l1']->id)['view_url']);
        $this->assertStringEndsWith('?download=1', $json->firstWhere('id', $this->docs['l1']->id)['download_url']);
    }

    public function test_the_list_never_contains_a_draft_an_archived_lease_a_deleted_or_unshared_document_or_anyone_elses(): void
    {
        $this->asPortal($this->tenant);
        $ids = $this->ids($this->portalGet('/api/v1/client/rentals/documents')->json());

        foreach (['ld' => 'lease still out for signing', 'la' => 'archived lease', 'l1_deleted' => 'soft-deleted document', 'l0' => 'previous tenant\'s lease',
                  'l2' => 'another property\'s lease', 'i_progress' => 'inspection in progress', 'i_signing' => 'inspection still in signing', 'i_draft' => 'draft inspection',
                  'i_cancelled' => 'cancelled inspection', 'i_old_tenant' => 'previous tenant\'s inspection', 'i_archived_lease' => 'inspection of an archived lease',
                  'i_p2' => 'another property\'s inspection', 'shared_owner' => 'document shared with the OWNER', 'unshared' => 'attached but not shared'] as $key => $why) {
            $this->assertNotContains($this->docs[$key]->id, $ids, "must not appear: {$why}");
        }
    }

    public function test_co_tenants_on_one_lease_see_the_same_lease_documents(): void
    {
        $this->asPortal($this->tenant);
        $a = $this->ids($this->portalGet('/api/v1/client/rentals/documents')->json());
        $this->asPortal($this->coTenant);
        $b = $this->ids($this->portalGet('/api/v1/client/rentals/documents')->json());

        $this->assertSame($this->idsOf('l1', 'l1r', 'i_done', 'i_sent'), $b);
        $this->assertEmpty(array_diff($b, $a), 'everything the co-tenant sees, the first tenant sees too');
    }

    public function test_a_distributed_inspection_is_shown_by_completion_or_by_a_sent_copy(): void
    {
        $this->asPortal($this->tenant);
        $ids = $this->ids($this->portalGet('/api/v1/client/rentals/documents')->json());

        $this->assertContains($this->docs['i_done']->id, $ids, 'completed counts as distributed');
        $this->assertContains($this->docs['i_sent']->id, $ids, 'a logged sent email copy to the tenant counts as distributed');
    }

    // ── owner ────────────────────────────────────────────────────────────────────────────────

    public function test_a_sent_report_is_offered_only_once_everyone_has_signed_and_uses_the_shared_type_wording(): void
    {
        $l1 = \App\Models\Lease::withoutGlobalScopes()->find(RentalInspection::withoutGlobalScopes()->find($this->docs['i_done']->source_id)->lease_id);
        $unsigned = $this->inspectionDoc($l1, 'completed', RentalInspection::TYPE_OUT, signed: false);
        $routineNoSignatures = $this->inspectionDoc($l1, 'completed', RentalInspection::TYPE_AD_HOC, signed: false);
        $refusal = $this->inspectionDoc($l1, 'completed', RentalInspection::TYPE_INTERIM, signed: false);
        \App\Models\RentalInspectionSignature::forceCreate([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $refusal->source_id, 'party_role' => 'tenant',
            'party_contact_id' => $this->tenant->id, 'disposition' => 'refused', 'refusal_reason_preset' => 'other', 'refusal_reason_note' => 'x', 'disposition_recorded_at' => now(),
        ]);
        $this->asPortal($this->tenant);

        $json = $this->portalGet('/api/v1/client/rentals/documents')->assertOk()->json();
        $ids = $this->ids($json);
        $rows = collect($json['documents']);

        $this->assertNotContains($unsigned->id, $ids, 'sent but nobody signed, and signatures are required for an Out: not offered');
        $this->assertNotContains($refusal->id, $ids, 'a refusal is not a signature: not offered');
        $this->assertContains($routineNoSignatures->id, $ids, 'Routine signatures are optional by default: sent is enough');
        $this->assertContains($this->docs['i_done']->id, $ids, 'fully signed and sent: offered');

        // the ONE shared type wording — never "Ad hoc", never a portal-only "Move-in"
        $routine = $rows->firstWhere('id', $routineNoSignatures->id);
        $this->assertSame('Routine', $routine['subtype']);
        $this->assertStringStartsWith('Routine inspection report', $routine['name']);
        $this->assertStringStartsWith('In-inspection report', $rows->firstWhere('id', $this->docs['i_done']->id)['name']);
        $this->assertStringNotContainsString('Ad hoc', json_encode($json));

        // making Routine require signatures hides it again — the per-type setting is honoured
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signatures_required_routine' => true]);
        $this->assertNotContains($routineNoSignatures->id, $this->ids($this->portalGet('/api/v1/client/rentals/documents')->json()));

        // and the file route refuses it too, not just the list
        $this->portalGet('/api/v1/client/rentals/documents/' . $unsigned->id . '/file')->assertNotFound();
    }

    public function test_an_owner_sees_the_signed_leases_and_distributed_reports_of_their_own_properties(): void
    {
        $this->asPortal($this->owner1);

        $json = $this->portalGet('/api/v1/client/rentals/landlord/documents')->assertOk()->json();

        $this->assertSame(
            $this->idsOf('l0', 'l1', 'l1r', 'lc', 'i_done', 'i_sent', 'i_old_tenant', 'shared_owner'),
            $this->ids($json),
            'every signed lease and distributed report on P1 (incl. earlier tenancies) — but not those of an archived lease — + the document shared with them'
        );
        $this->assertNotContains($this->docs['shared_tenant']->id, $this->ids($json));
    }

    public function test_another_owner_sees_only_their_own_property(): void
    {
        $this->asPortal($this->owner2);

        $this->assertSame($this->idsOf('l2', 'i_p2'), $this->ids($this->portalGet('/api/v1/client/rentals/landlord/documents')->json()));
    }

    // ── the authorised file route ────────────────────────────────────────────────────────────

    public function test_a_party_opens_their_own_document_and_nothing_else_is_guessable(): void
    {
        $this->asPortal($this->tenant);

        $view = $this->portalGet('/api/v1/client/rentals/documents/' . $this->docs['l1']->id . '/file')->assertOk();
        $this->assertSame('application/pdf', $view->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $view->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $view->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $view->headers->get('Cache-Control'));
        $this->assertStringContainsString('content of lease-1.pdf', $view->streamedContent() ?: file_get_contents($view->getFile()->getPathname()));

        $download = $this->portalGet('/api/v1/client/rentals/documents/' . $this->docs['l1']->id . '/file?download=1')->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Lease agreement', (string) $download->headers->get('Content-Disposition'));

        $this->assertSame(1, ClientAccessLog::where('event', 'document_viewed')->count());
        $this->assertSame(1, ClientAccessLog::where('event', 'document_downloaded')->count());
    }

    public function test_every_cross_party_and_not_ready_document_is_a_404_on_the_file_route(): void
    {
        $this->asPortal($this->tenant);
        foreach (['l0', 'l2', 'ld', 'la', 'l1_deleted', 'i_progress', 'i_signing', 'i_draft', 'i_cancelled', 'i_old_tenant', 'i_archived_lease', 'i_p2', 'shared_owner', 'unshared'] as $key) {
            $this->portalGet('/api/v1/client/rentals/documents/' . $this->docs[$key]->id . '/file')->assertNotFound();
        }
        $this->portalGet('/api/v1/client/rentals/documents/9999999/file')->assertNotFound();

        // the tenant is not an owner: the owner's route gives them nothing, not even their own lease
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['l1']->id . '/file')->assertNotFound();

        $this->asPortal($this->tenant2);
        $this->portalGet('/api/v1/client/rentals/documents/' . $this->docs['l1']->id . '/file')->assertNotFound();
        $this->portalGet('/api/v1/client/rentals/documents/' . $this->docs['i_done']->id . '/file')->assertNotFound();

        $this->asPortal($this->owner2);
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['l1']->id . '/file')->assertNotFound();
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['i_done']->id . '/file')->assertNotFound();
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['shared_owner']->id . '/file')->assertNotFound();

        $this->asPortal($this->owner1);
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['ld']->id . '/file')->assertNotFound();
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['l2']->id . '/file')->assertNotFound();
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['shared_tenant']->id . '/file')->assertNotFound();
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['i_cancelled']->id . '/file')->assertNotFound();
        $this->portalGet('/api/v1/client/rentals/landlord/documents/' . $this->docs['l1']->id . '/file')->assertOk();
    }

    public function test_a_document_of_another_agency_is_never_served(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $theirs = $this->doc('lease', Lease::first()->id, 'theirs.pdf', [], $rival->id);
        $this->asPortal($this->tenant);

        $this->portalGet('/api/v1/client/rentals/documents/' . $theirs->id . '/file')->assertNotFound();
    }

    public function test_no_session_or_a_staff_session_alone_cannot_open_a_file(): void
    {
        $url = '/api/v1/client/rentals/documents/' . $this->docs['l1']->id . '/file';

        $this->withHeaders(self::BROWSER)->getJson($url)->assertStatus(401);

        $this->withSession([Auth::guard('web')->getName() => $this->staff->id]);
        Auth::forgetGuards();
        $this->withHeaders(self::BROWSER)->getJson($url)->assertStatus(401);
    }

    public function test_a_file_missing_from_disk_is_a_clean_404_not_an_error(): void
    {
        Storage::disk('local')->delete($this->docs['l1']->storage_path);
        $this->asPortal($this->tenant);

        $this->portalGet('/api/v1/client/rentals/documents/' . $this->docs['l1']->id . '/file')->assertNotFound();
        $this->assertContains($this->docs['l1']->id, $this->ids($this->portalGet('/api/v1/client/rentals/documents')->json()), 'still listed; only opening fails, and says so');
    }

    public function test_an_image_is_viewed_inline_and_a_word_file_is_always_a_download(): void
    {
        $img = $this->doc('manual', 7, 'photo.png', ['tenant_portal_visible' => true, 'mime_type' => 'image/png']);
        $docx = $this->doc('manual', 8, 'rules.docx', ['tenant_portal_visible' => true, 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
        foreach ([$img, $docx] as $d) {
            DB::table('document_contacts')->insert(['document_id' => $d->id, 'contact_id' => $this->tenant->id, 'party_role' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->asPortal($this->tenant);

        $this->assertStringContainsString('inline', (string) $this->portalGet("/api/v1/client/rentals/documents/{$img->id}/file")->assertOk()->headers->get('Content-Disposition'));
        $this->assertStringContainsString('attachment', (string) $this->portalGet("/api/v1/client/rentals/documents/{$docx->id}/file")->assertOk()->headers->get('Content-Disposition'));
    }

    public function test_the_portal_switch_closes_the_documents_for_that_audience(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_portal_enabled' => false]);
        $this->asPortal($this->tenant);
        $this->portalGet('/api/v1/client/rentals/documents')->assertStatus(403);
        $this->portalGet('/api/v1/client/rentals/documents/' . $this->docs['l1']->id . '/file')->assertStatus(403);

        $this->asPortal($this->owner1);
        $this->portalGet('/api/v1/client/rentals/landlord/documents')->assertOk();
    }

    // ── search / sort / filter / pagination / empty state ────────────────────────────────────

    public function test_search_sort_filter_and_pagination(): void
    {
        $this->asPortal($this->tenant);
        $list = fn (string $qs) => $this->portalGet('/api/v1/client/rentals/documents?' . $qs)->assertOk()->json();

        $this->assertSame($this->idsOf('i_done', 'i_sent'), $this->ids($list('q=inspection')), 'search matches the type and name');
        $this->assertSame($this->idsOf('l1', 'l1r', 'lc'), $this->ids($list('type=lease_agreement')));
        $this->assertSame($this->idsOf('shared_tenant'), $this->ids($list('type=shared_document')));
        $this->assertSame($this->idsOf('l1r'), $this->ids($list('q=renewal')));
        $this->assertSame([], $this->ids($list('q=zzz-no-match')));
        $this->assertSame(6, $list('q=zzz-no-match')['meta']['all_total'], 'the empty-search state knows the person does have documents');

        $byName = collect($list('sort=name&dir=asc')['documents'])->pluck('name')->all();
        $sorted = $byName;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
        $this->assertSame(array_map('mb_strtolower', $sorted), array_map('mb_strtolower', $byName));

        $newest = collect($list('')['documents'])->pluck('date')->all();
        $desc = $newest;
        rsort($desc);
        $this->assertSame($desc, $newest, 'default order is newest first');

        $page1 = $list('per_page=2&page=1');
        $page3 = $list('per_page=2&page=3');
        $this->assertCount(2, $page1['documents']);
        $this->assertSame(3, $page1['meta']['pages']);
        $this->assertCount(2, $page3['documents']);
        $this->assertSame([], array_intersect(collect($page1['documents'])->pluck('id')->all(), collect($page3['documents'])->pluck('id')->all()));

        $this->assertSame($this->idsOf('l1r'), $this->ids($list('from=2026-10-19&to=2026-10-21')), 'the date range filters on the signing date');
        $this->assertSame($this->idsOf('l1r'), $this->ids($list('from=2026-10-19')));
        $this->assertSame([], $this->ids($list('to=2000-01-01')));
        $this->portalGet('/api/v1/client/rentals/documents?type=nonsense')->assertStatus(422);
    }

    public function test_a_person_with_nothing_signed_yet_gets_an_empty_list_with_the_empty_state_facts(): void
    {
        $this->asPortal($this->oldTenant);
        DB::table('lease_tenants')->where('contact_id', $this->oldTenant->id)->delete();

        $json = $this->portalGet('/api/v1/client/rentals/documents')->assertOk()->json();

        $this->assertSame([], $json['documents']);
        $this->assertSame(0, $json['meta']['all_total']);
    }

    public function test_the_existing_keys_the_mobile_app_reads_are_still_there(): void
    {
        $this->asPortal($this->tenant);
        $first = $this->portalGet('/api/v1/client/rentals/documents')->json('documents.0');

        foreach (['id', 'name', 'type', 'uploaded_at'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        $this->assertArrayNotHasKey('_document', $first);
        $this->assertArrayNotHasKey('date_sort', $first);
    }

    // ── the page ─────────────────────────────────────────────────────────────────────────────

    public function test_the_portal_page_has_the_documents_panel_for_both_audiences(): void
    {
        $html = $this->portalGet('/portal')->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(2, substr_count($html, 'data-portal-documents>'), 'the same panel for the tenant and the owner');
        $this->assertStringContainsString("landlordTab==='documents'", $html);
        $this->assertStringContainsString("tenantTab==='documents'", $html);
        $this->assertStringContainsString('data-portal-documents-empty', $html);
        $this->assertStringContainsString('/api/v1/client/rentals/landlord', $html);
    }
}
