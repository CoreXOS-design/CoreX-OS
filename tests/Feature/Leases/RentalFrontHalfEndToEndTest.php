<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Rentals\LeaseRenewalService;
use App\Services\Rentals\RentalPortalScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rentals front-half walk (QA1, 8 Oct 2026) - the one test that chains the hand-offs the per-stage tests each cover alone:
 * approved application -> lease captured from it (screen POST) -> the agreement goes out -> everyone signs and the signed
 * copy is filed -> the lease is ACTIVE with the captured terms and still linked to its application -> the tenant (and the
 * owner) can see it in the portal, and could not before -> the lease can be renewed into a draft next term, whose manual
 * activation expires the old term. A break at any hand-off fails here with the step named.
 */
final class RentalFrontHalfEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_application_to_active_lease_to_portal_to_renewal(): void
    {
        Mail::fake();
        $agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Cape Town']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $tenant = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi@example.test', 'id_number' => '8002025009081', 'agent_id' => $agent->id,
        ]);
        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $tenant->id, 'created_by_user_id' => $agent->id,
            'status' => 'approved', 'approved_rental_amount' => 9000, 'approved_deposit_amount' => 9000, 'full_name' => 'Thandi Nkosi',
        ]);

        // 1 - capture from the application
        $this->actingAs($agent)->post(route('corex.leases.store'), [
            'intent' => 'lease_only', 'property_id' => $property->id, 'rental_application_id' => $application->id,
            'tenant_contact_ids' => [$tenant->id], 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'capture_key' => bin2hex(random_bytes(8)),
        ])->assertSessionDoesntHaveErrors();
        $lease = Lease::withoutGlobalScopes()->where('rental_application_id', $application->id)->sole();
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status, 'step: capture');

        // 2 - not visible in the portal while a draft
        $scope = app(RentalPortalScopeService::class);
        $this->assertSame([], $scope->tenantLeaseIds($tenant), 'step: draft hidden from tenant portal');

        // 3 - the agreement goes out and everyone signs (the engine's completion announcement)
        $document = Document::create([
            'name' => 'Lease agreement', 'document_type' => 'agreement', 'owner_id' => $agent->id,
            'agency_id' => $agency->id, 'web_template_data' => ['merged_html' => '<p>x</p>'],
        ]);
        $envelope = SignatureTemplate::create([
            'agency_id' => $agency->id, 'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_TENANT, 'created_by' => $agent->id,
        ]);
        $lease->forceFill(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING, 'signature_template_id' => $envelope->id, 'agreement_document_id' => $document->id])->save();

        $this->actingAs($agent)->post(route('corex.leases.activate', $lease))->assertSessionHasErrors('lease');
        $this->assertSame(Lease::STATUS_DRAFT, $lease->fresh()->status, 'step: nobody can activate it by hand while it is out for signing');

        $envelope->update(['status' => SignatureTemplate::STATUS_COMPLETED, 'completed_at' => now(), 'finalization_status' => SignatureTemplate::FINALIZATION_SUCCEEDED]);
        event(new SignatureEnvelopeFinalized($envelope->id, $document->id, $agency->id));

        $lease = $lease->fresh();
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status, 'step: signed -> active');
        $this->assertSame(Lease::SIGNING_SIGNED, $lease->signing_status);
        $this->assertSame($application->id, $lease->rental_application_id, 'step: still linked to its application');
        $this->assertEquals(9000, $lease->rental_amount);

        // 4 - portal
        $this->assertSame([$lease->id], $scope->tenantLeaseIds($tenant), 'step: tenant sees the live lease');

        // 5 - renewal
        $renewal = app(LeaseRenewalService::class)->createRenewalTerm($lease, [
            'start_date' => '2027-11-01', 'end_date' => '2028-10-31', 'rental_amount' => 9900,
        ], $agent);
        $this->assertSame(Lease::STATUS_DRAFT, $renewal->status, 'step: renewal starts as a draft');
        $this->assertSame([$lease->id], $scope->tenantLeaseIds($tenant), 'step: the draft next term is not in the portal yet');

        $this->actingAs($agent)->post(route('corex.leases.activate', $renewal))->assertSessionHas('success');
        $this->assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status, 'step: renewal activated');
        $this->assertSame(Lease::STATUS_EXPIRED, $lease->fresh()->status, 'step: old term expired');
    }
}
