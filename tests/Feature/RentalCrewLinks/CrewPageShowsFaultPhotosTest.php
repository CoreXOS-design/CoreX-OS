<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Models\Contact;
use App\Models\Lease;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalFaultPhotoAnnotationService as Annotations;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * Johan, 14 Oct 2026: the crew's job page shows EVERY photo of the fault behind the job, read-only in the shared viewer - the same as the
 * contractor's page. It used to show only the photos the agent ticked "Share" for the owner, so a tenant's photo nobody ticked never reached
 * the crew.
 */
final class CrewPageShowsFaultPhotosTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private function faultWithPhotos(int $photos = 2): RentalFaultReport
    {
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 't' . uniqid() . '@example.invalid']);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $type = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Geyser', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Switch it off.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id, 'rental_fault_type_id' => $type->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'title' => 'Geyser leaks', 'description' => 'Drips.',
            'created_by_user_id' => $this->admin->id, 'reported_at' => now(), 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);
        for ($i = 1; $i <= $photos; $i++) {
            RentalFaultReportPhoto::create([
                'agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id,
                'storage_path' => "/storage/properties/1/crew-fault-{$i}.png", 'uploaded_by_user_id' => null, 'file_size_bytes' => 1000,
            ]);
        }
        // the owner version was saved with NO photo ticked - what the walk did
        $fault->forceFill(['owner_version_saved_at' => now(), 'owner_title' => 'Geyser leaks', 'owner_photo_ids' => []])->save();

        return $fault->fresh();
    }

    public function test_the_crew_payload_lists_every_fault_photo_not_only_the_ones_shared_with_the_owner(): void
    {
        $this->buildCrewLinkWorld('Crew Photos');
        $fault = $this->faultWithPhotos(2);
        $card = $this->makeJobCard(['rental_fault_report_id' => $fault->id]);

        $list = app(Annotations::class)->forJobCard($card);

        self::assertCount(2, $list, 'both photos, though none was ticked for the owner');
        self::assertSame($fault->photos()->orderBy('id')->pluck('id')->all(), array_column($list, 'id'));
    }

    public function test_the_crew_job_page_shows_them_read_only_in_the_shared_viewer(): void
    {
        $this->buildCrewLinkWorld('Crew Photos');
        $fault = $this->faultWithPhotos(2);
        $card = $this->makeJobCard(['rental_fault_report_id' => $fault->id]);
        $raw = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];

        $resp = $this->get("/secure/crews/{$raw}/job-cards/{$card->id}")->assertOk();

        $resp->assertSee('data-crew-reported-photos', false);
        $resp->assertSee('data-open-report-photo="0"', false)->assertSee('data-open-report-photo="1"', false);
        $resp->assertSee('editable: false', false);
    }

    public function test_a_photo_added_after_the_work_order_was_made_reaches_the_crew_too(): void
    {
        $this->buildCrewLinkWorld('Crew Photos');
        $fault = $this->faultWithPhotos(1);
        $card = $this->makeJobCard(['rental_fault_report_id' => $fault->id]);
        $wo = RentalWorkOrder::withoutGlobalScopes()->find($card->rental_work_order_id);
        $first = $fault->photos()->first();
        $wo->photos()->withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'photo_type' => RentalWorkOrder::PHOTO_REPORTED, 'storage_path' => $first->storage_path,
            'uploaded_by_user_id' => $this->admin->id, 'file_size_bytes' => 1000,
        ]);
        RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/properties/1/later.png', 'uploaded_by_user_id' => null, 'file_size_bytes' => 1000]);

        $list = app(Annotations::class)->forJobCard($card->fresh());

        self::assertCount(2, $list, 'the copied one once, the later one once');
    }

    public function test_a_job_with_no_fault_shows_no_photo_card_and_another_faults_photos_never_appear(): void
    {
        $this->buildCrewLinkWorld('Crew Photos');
        $this->faultWithPhotos(2);   // a different fault with photos, not this job's
        $card = $this->makeJobCard();
        $raw = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];

        self::assertSame([], app(Annotations::class)->forJobCard($card));
        $this->get("/secure/crews/{$raw}/job-cards/{$card->id}")->assertOk()->assertDontSee('data-crew-reported-photos', false);
    }
}
