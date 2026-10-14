<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\RentalFaultPhotoAnnotationService as Annotations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rental re-walk, 14 Oct 2026: the tenant's photo was stored on the fault, yet the contractor's private link page showed no pictures.
 * A work order only started with the photos the agent ticked "Share" for the OWNER (none were ticked), so the contractor's page - which
 * showed just the work order's own copies - had nothing. The contractor now sees every photo of the fault, read-only.
 */
final class ContractorPageShowsFaultPhotosTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private RentalFaultReport $fault;
    private RentalWorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Photo Agency', 'slug' => 'pa-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id,
            'title' => 'Photo Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(),
            'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $tenant = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 't' . uniqid() . '@example.test']);
        $type = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'lease_id' => $lease->id, 'rental_fault_type_id' => $type->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'title' => 'Tap leaks', 'description' => 'Under the sink.',
            'created_by_user_id' => $this->agent->id, 'reported_at' => now(), 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);
        $this->workOrder = RentalWorkOrder::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'title' => 'Tap leaks', 'description' => 'Under the sink.',
            'assignment_type' => 'outside_supplier', 'status' => 'reported', 'owner_approval_status' => 'not_required',
            'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $this->fault->forceFill(['rental_work_order_id' => $this->workOrder->id])->save();
    }

    private function faultPhoto(string $path, ?int $uploadedBy = null): RentalFaultReportPhoto
    {
        return RentalFaultReportPhoto::create([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => $this->fault->id,
            'storage_path' => $path, 'uploaded_by_user_id' => $uploadedBy, 'file_size_bytes' => 1000,
        ]);
    }

    public function test_a_tenants_photo_the_agent_never_ticked_for_the_owner_still_reaches_the_contractor_page(): void
    {
        $tenantsPhoto = $this->faultPhoto('/storage/properties/1/tenant.png');
        // the owner version was saved with NO photo ticked: the work order starts with none
        $this->fault->forceFill(['owner_version_saved_at' => now(), 'owner_title' => 'Tap leaks', 'owner_photo_ids' => []])->save();
        self::assertCount(0, $this->workOrder->photos()->withoutGlobalScopes()->get(), 'the work order has no copies - the situation the walk hit');

        $photos = app(Annotations::class)->contractorProblemPhotos($this->workOrder->fresh());

        self::assertCount(1, $photos);
        self::assertSame($tenantsPhoto->id, $photos[0]['id']);
        self::assertNotSame('', (string) $photos[0]['url']);
        self::assertSame('reported', $photos[0]['type']);
    }

    public function test_a_photo_added_after_the_work_order_was_made_is_shown_and_none_is_listed_twice(): void
    {
        $first = $this->faultPhoto('/storage/properties/1/first.png');
        $this->workOrder->photos()->withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'photo_type' => RentalWorkOrder::PHOTO_REPORTED, 'storage_path' => $first->storage_path,
            'uploaded_by_user_id' => $this->agent->id, 'file_size_bytes' => 1000,
        ]);
        $later = $this->faultPhoto('/storage/properties/1/later.png');

        $photos = app(Annotations::class)->contractorProblemPhotos($this->workOrder->fresh());

        self::assertCount(2, $photos, 'the copied one once, the later one once');
        self::assertSame(['Reported photo 1', 'Reported photo 2'], array_column($photos, 'alt'));
        self::assertContains($later->id, array_column($photos, 'id'));
    }

    public function test_the_contractors_link_page_lists_the_photos_in_the_read_only_viewer(): void
    {
        $this->faultPhoto('/storage/properties/1/tenant.png');
        $issued = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueFor($this->workOrder->fresh(), $this->agent);

        $res = $this->get(route('rentals.secure-link.show', $issued['raw_token']))->assertOk();

        $res->assertSee('Photos of the problem');
        $res->assertSee('data-contractor-photos', false);
        $res->assertSee('data-open-job-photo="0"', false);
        $res->assertSee('editable: false', false);
    }

    public function test_a_work_order_with_no_photos_anywhere_shows_no_photo_card(): void
    {
        $issued = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueFor($this->workOrder->fresh(), $this->agent);

        $this->get(route('rentals.secure-link.show', $issued['raw_token']))->assertOk()->assertDontSee('Photos of the problem');
    }

    public function test_a_different_faults_photos_never_leak_onto_the_page(): void
    {
        $otherFault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->fault->property_id, 'lease_id' => $this->fault->lease_id,
            'rental_fault_type_id' => $this->fault->rental_fault_type_id, 'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT,
            'reported_by_contact_id' => $this->fault->reported_by_contact_id, 'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Other', 'description' => 'Other', 'created_by_user_id' => $this->agent->id, 'reported_at' => now(),
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);
        RentalFaultReportPhoto::create([
            'agency_id' => $this->agency->id, 'rental_fault_report_id' => $otherFault->id,
            'storage_path' => '/storage/properties/9/secret.png', 'uploaded_by_user_id' => null, 'file_size_bytes' => 1000,
        ]);

        self::assertSame([], app(Annotations::class)->contractorProblemPhotos($this->workOrder->fresh()));
    }
}
