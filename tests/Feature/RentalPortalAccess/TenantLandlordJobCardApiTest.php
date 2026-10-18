<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsRentalPortalFixtures;
use Tests\TestCase;

/**
 * W4 (Johan, 8 Oct 2026) - the JOB CARD is INTERNAL: tenants and owners see the WORK ORDER's progress, never the job
 * card. This file used to prove the job-card portal endpoints (rental-work-orders.md §14.29); those endpoints are gone,
 * so it now proves (a) they are gone, and (b) the agency's photo-visibility rule and "never a price" still hold on the
 * WORK ORDER and FAULT endpoints that replaced them. Real HTTP through the real routes (Sanctum as the client user).
 */
class TenantLandlordJobCardApiTest extends TestCase
{
    use BuildsRentalPortalFixtures;
    use RefreshDatabase;

    private function tenantScenario(): array
    {
        $agency = $this->makeAgency();
        $agent = $this->makeAgent($agency);
        $property = $this->makeProperty($agency, $agent);
        $lease = $this->makeLease($agency, $property);
        $tenant = $this->makeTenant($agency, $lease, ['first_name' => 'Thandi']);

        return [$agency, $agent, $property, $lease, $tenant];
    }

    public function test_the_job_card_portal_endpoints_are_gone_for_tenant_and_owner(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $card = $this->makeJobCard($agency, $property, $lease);

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);
        $this->getJson('/api/v1/client/rentals/job-cards')->assertNotFound();
        $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->assertNotFound();

        $landlord = $this->makeLandlord($agency, $property);
        Sanctum::actingAs($this->clientUserFor($landlord), ['client']);
        $this->getJson('/api/v1/client/rentals/landlord/job-cards')->assertNotFound();
        $this->getJson('/api/v1/client/rentals/landlord/job-cards/' . $card->id)->assertNotFound();
    }

    public function test_photo_visibility_follows_the_agency_setting_on_the_work_order_and_reported_photos_are_never_shown(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $workOrder = $this->makeWorkOrder($agency, $property, $lease);
        $card = $this->makeJobCard($agency, $property, $lease, ['rental_work_order_id' => $workOrder->id]);
        $this->makePhoto($card, 'reported');
        $inProgress = $this->makePhoto($card, 'in_progress');
        $completed = $this->makePhoto($card, 'completed');

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);
        $url = '/api/v1/client/rentals/work-orders/' . $workOrder->id;

        $photos = $this->getJson($url)->json('work_order.photos');
        $this->assertEqualsCanonicalizing([$inProgress->id, $completed->id], collect($photos)->pluck('id')->all());
        $this->assertNotContains('reported', collect($photos)->pluck('photo_type')->all());

        $this->setCrewPhotoVisibility($agency, RentalPortalSetting::CREW_PHOTOS_COMPLETED_ONLY);
        $this->assertSame([$completed->id], collect($this->getJson($url)->json('work_order.photos'))->pluck('id')->all());

        $this->setCrewPhotoVisibility($agency, RentalPortalSetting::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED);
        $this->assertCount(2, $this->getJson($url)->json('work_order.photos'));
    }

    public function test_the_tenant_never_sees_a_price_a_quote_or_the_crews_sign_off_on_the_work_order(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $workOrder = $this->makeWorkOrder($agency, $property, $lease);
        $this->makeJobCard($agency, $property, $lease, [
            'rental_work_order_id' => $workOrder->id, 'total_amount' => 1850.50,
            'worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Sipho Dlamini',
        ]);
        RentalWorkOrderQuote::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'rental_work_order_id' => $workOrder->id, 'amount' => 1850.50,
            'quote_date' => now()->toDateString(), 'is_selected' => true,
        ]);

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $body = $this->getJson('/api/v1/client/rentals/work-orders/' . $workOrder->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('1850', $body);
        $this->assertStringNotContainsString('quote', strtolower($body));
        $this->assertStringNotContainsString('total_amount', $body);
        $this->assertStringNotContainsString('Sipho Dlamini', $body, 'the crew sign-off is a job-card detail - internal');
        $this->assertStringNotContainsString('crew_completion', $body);
    }

    public function test_tenant_work_order_show_gains_a_photos_array_following_the_same_rule(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $workOrder = $this->makeWorkOrder($agency, $property, $lease);
        $card = $this->makeJobCard($agency, $property, $lease, ['rental_work_order_id' => $workOrder->id]);
        $this->makePhoto($card, 'reported');
        $kept = $this->makePhoto($card, 'completed');

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $photos = $this->getJson('/api/v1/client/rentals/work-orders/' . $workOrder->id)->assertOk()->json('work_order.photos');
        // The card's photos are stored against BOTH the card and the linked work order — shown once.
        $this->assertSame([$kept->id], collect($photos)->pluck('id')->all());
    }

    public function test_tenant_fault_report_show_gains_the_work_photos_not_the_reporters_own(): void
    {
        [$agency, $agent, $property, $lease, $tenant] = $this->tenantScenario();
        $workOrder = $this->makeWorkOrder($agency, $property, $lease);
        $fault = RentalFaultReport::create([
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => 'No hot water', 'description' => 'Geyser dead',
            'status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'rental_work_order_id' => $workOrder->id,
        ]);
        $fault->photos()->create([
            'agency_id' => $agency->id, 'storage_path' => '/storage/properties/' . $property->id . '/tenant-photo.jpg',
            'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'file_size_bytes' => 100,
        ]);
        $card = $this->makeJobCard($agency, $property, $lease, ['rental_work_order_id' => $workOrder->id, 'rental_fault_report_id' => $fault->id]);
        $work = $this->makePhoto($card, 'in_progress');

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $photos = $this->getJson('/api/v1/client/rentals/fault-reports/' . $fault->id)->assertOk()->json('fault_report.photos');
        $this->assertSame([$work->id], collect($photos)->pluck('id')->all());
    }

    public function test_landlord_work_orders_index_gains_a_photos_array(): void
    {
        $agency = $this->makeAgency();
        $agent = $this->makeAgent($agency);
        $property = $this->makeProperty($agency, $agent);
        $landlord = $this->makeLandlord($agency, $property);
        $workOrder = $this->makeWorkOrder($agency, $property);
        $card = $this->makeJobCard($agency, $property, null, ['rental_work_order_id' => $workOrder->id]);
        $this->makePhoto($card, 'reported');
        $this->makePhoto($card, 'completed');

        Sanctum::actingAs($this->clientUserFor($landlord), ['client']);

        $row = collect($this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertOk()->json('work_orders'))->firstWhere('id', $workOrder->id);
        $this->assertCount(1, $row['photos']);
        $this->assertSame('completed', $row['photos'][0]['photo_type']);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/client/rentals/work-orders')->assertStatus(401);
        $this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertStatus(401);
    }
}
