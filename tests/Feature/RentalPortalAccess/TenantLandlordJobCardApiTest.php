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
 * rental-work-orders.md §14.29 — tenants and landlords see the job card, its
 * crew completion and the photos the agency allows — for their OWN lease /
 * property only. Real HTTP through the real routes (Sanctum as the client
 * user), so scoping, middleware and serialisation are all exercised.
 *
 * Input paths proven: own card visible; another tenant's / landlord's card 404
 * by id AND absent from the list; another agency's card 404; draft hidden;
 * archived hidden; cancelled shown; card with no lease invisible to a tenant;
 * photo rule default / completed-only / per-agency; 'reported' never shown;
 * no photos → empty array; tenant never sees a price; landlord sees the
 * selected quote amount only; photos array on the three existing endpoints;
 * fault photos = the work done, never the reporter's own attachments.
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

    public function test_tenant_sees_own_leases_job_card_with_status_dates_and_allowed_photos(): void
    {
        [$agency, $agent, $property, $lease, $tenant] = $this->tenantScenario();
        $card = $this->makeJobCard($agency, $property, $lease, [
            'scheduled_at' => now()->addDay(), 'worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Sipho Dlamini',
        ]);
        $this->makePhoto($card, 'in_progress');
        $this->makePhoto($card, 'completed');

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $list = $this->getJson('/api/v1/client/rentals/job-cards')->assertOk();
        $this->assertSame([$card->id], collect($list->json('job_cards'))->pluck('id')->all());

        $show = $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->assertOk();
        $show->assertJsonPath('job_card.title', 'Replace geyser element')
            ->assertJsonPath('job_card.status', 'scheduled')
            ->assertJsonPath('job_card.crew_completion.signed_by', 'Sipho Dlamini');
        $this->assertNotNull($show->json('job_card.scheduled_at'));
        $this->assertCount(2, $show->json('job_card.photos'));
        $this->assertStringContainsString('12 Marine Drive', (string) $show->json('job_card.property_address'));
    }

    public function test_another_tenants_card_is_a_404_by_id_and_absent_from_the_list(): void
    {
        [$agency, $agent, $propertyA, $leaseA, $tenantA] = $this->tenantScenario();
        $propertyB = $this->makeProperty($agency, $agent, '4 Beach Road, Uvongo');
        $leaseB = $this->makeLease($agency, $propertyB);
        $this->makeTenant($agency, $leaseB, ['first_name' => 'Bob']);
        $mine = $this->makeJobCard($agency, $propertyA, $leaseA);
        $theirs = $this->makeJobCard($agency, $propertyB, $leaseB, ['title' => 'Bob\'s leaking tap']);

        Sanctum::actingAs($this->clientUserFor($tenantA), ['client']);

        $ids = collect($this->getJson('/api/v1/client/rentals/job-cards')->json('job_cards'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->getJson('/api/v1/client/rentals/job-cards/' . $theirs->id)->assertStatus(404);
    }

    public function test_another_agencys_card_is_a_404(): void
    {
        [$agencyA, , , , $tenantA] = $this->tenantScenario();
        $agencyB = $this->makeAgency('Cape Town Rentals');
        $agentB = $this->makeAgent($agencyB);
        $propertyB = $this->makeProperty($agencyB, $agentB, '9 Kloof Street, Gardens');
        $leaseB = $this->makeLease($agencyB, $propertyB);
        $foreign = $this->makeJobCard($agencyB, $propertyB, $leaseB);

        Sanctum::actingAs($this->clientUserFor($tenantA), ['client']);

        $this->getJson('/api/v1/client/rentals/job-cards/' . $foreign->id)->assertStatus(404);
        $this->assertSame([], $this->getJson('/api/v1/client/rentals/job-cards')->json('job_cards'));
    }

    public function test_draft_and_archived_cards_are_hidden_but_cancelled_is_shown(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $draft = $this->makeJobCard($agency, $property, $lease, ['status' => RentalJobCard::STATUS_DRAFT, 'title' => 'Draft prep']);
        $archived = $this->makeJobCard($agency, $property, $lease, ['title' => 'Archived job']);
        $archived->delete();
        $cancelled = $this->makeJobCard($agency, $property, $lease, ['status' => RentalJobCard::STATUS_CANCELLED, 'title' => 'Cancelled job']);
        $quoted = $this->makeJobCard($agency, $property, $lease, ['status' => RentalJobCard::STATUS_QUOTED, 'title' => 'Quoted job']);

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $ids = collect($this->getJson('/api/v1/client/rentals/job-cards')->json('job_cards'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$cancelled->id, $quoted->id], $ids);
        $this->getJson('/api/v1/client/rentals/job-cards/' . $draft->id)->assertStatus(404);
        $this->getJson('/api/v1/client/rentals/job-cards/' . $archived->id)->assertStatus(404);
    }

    public function test_a_card_with_no_lease_is_not_visible_to_the_tenant_even_on_their_property(): void
    {
        [$agency, , $property, , $tenant] = $this->tenantScenario();
        $vacancyCard = $this->makeJobCard($agency, $property, null, ['title' => 'Painting between tenants']);

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $this->getJson('/api/v1/client/rentals/job-cards/' . $vacancyCard->id)->assertStatus(404);
    }

    public function test_photo_visibility_follows_the_agency_setting_and_reported_photos_are_never_shown(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $card = $this->makeJobCard($agency, $property, $lease);
        $this->makePhoto($card, 'reported');
        $inProgress = $this->makePhoto($card, 'in_progress');
        $completed = $this->makePhoto($card, 'completed');

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        // Default (no settings row): in-progress + completed, never reported.
        $photos = $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->json('job_card.photos');
        $this->assertEqualsCanonicalizing([$inProgress->id, $completed->id], collect($photos)->pluck('id')->all());
        $this->assertNotContains('reported', collect($photos)->pluck('photo_type')->all());

        $this->setCrewPhotoVisibility($agency, RentalPortalSetting::CREW_PHOTOS_COMPLETED_ONLY);
        $photos = $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->json('job_card.photos');
        $this->assertSame([$completed->id], collect($photos)->pluck('id')->all());

        $this->setCrewPhotoVisibility($agency, RentalPortalSetting::CREW_PHOTOS_IN_PROGRESS_AND_COMPLETED);
        $photos = $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->json('job_card.photos');
        $this->assertCount(2, $photos);
    }

    public function test_the_photo_setting_is_per_agency(): void
    {
        [$agencyA, , $propertyA, $leaseA, $tenantA] = $this->tenantScenario();
        $this->setCrewPhotoVisibility($agencyA, RentalPortalSetting::CREW_PHOTOS_COMPLETED_ONLY);

        $agencyB = $this->makeAgency('Second Agency');
        $agentB = $this->makeAgent($agencyB);
        $propertyB = $this->makeProperty($agencyB, $agentB, '2 Long Street, Cape Town');
        $leaseB = $this->makeLease($agencyB, $propertyB);
        $tenantB = $this->makeTenant($agencyB, $leaseB);
        $cardB = $this->makeJobCard($agencyB, $propertyB, $leaseB);
        $this->makePhoto($cardB, 'in_progress');

        Sanctum::actingAs($this->clientUserFor($tenantB), ['client']);

        // Agency A chose completed-only; agency B never changed it and keeps the default.
        $this->assertCount(1, $this->getJson('/api/v1/client/rentals/job-cards/' . $cardB->id)->json('job_card.photos'));
    }

    public function test_a_card_with_no_photos_returns_an_empty_array_not_an_error(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $card = $this->makeJobCard($agency, $property, $lease);

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)
            ->assertOk()->assertJsonPath('job_card.photos', [])->assertJsonPath('job_card.crew_completion', null);
    }

    public function test_the_tenant_never_sees_a_price_or_a_quote(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $workOrder = $this->makeWorkOrder($agency, $property, $lease);
        $card = $this->makeJobCard($agency, $property, $lease, ['rental_work_order_id' => $workOrder->id, 'total_amount' => 1850.50]);
        RentalWorkOrderQuote::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'rental_work_order_id' => $workOrder->id, 'amount' => 1850.50,
            'quote_date' => now()->toDateString(), 'is_selected' => true,
        ]);

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $body = $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('1850', $body);
        $this->assertStringNotContainsString('quote', strtolower($body));
        $this->assertStringNotContainsString('total_amount', $body);
    }

    public function test_landlord_sees_cards_on_own_property_only_with_the_selected_quote_amount(): void
    {
        $agency = $this->makeAgency();
        $agent = $this->makeAgent($agency);
        $propertyA = $this->makeProperty($agency, $agent, '12 Marine Drive, Margate');
        $propertyB = $this->makeProperty($agency, $agent, '4 Beach Road, Uvongo');
        $leaseA = $this->makeLease($agency, $propertyA);
        $landlordA = $this->makeLandlord($agency, $propertyA);
        $this->makeLandlord($agency, $propertyB, ['first_name' => 'Anika']);

        $workOrder = $this->makeWorkOrder($agency, $propertyA, $leaseA);
        $mine = $this->makeJobCard($agency, $propertyA, $leaseA, ['rental_work_order_id' => $workOrder->id]);
        $noWorkOrder = $this->makeJobCard($agency, $propertyA, null, ['title' => 'Garden service']);
        $theirs = $this->makeJobCard($agency, $propertyB, null, ['title' => 'Other landlord\'s job']);
        RentalWorkOrderQuote::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'rental_work_order_id' => $workOrder->id, 'amount' => 2400.00,
            'quote_date' => now()->toDateString(), 'is_selected' => true,
        ]);
        $this->makePhoto($mine, 'completed');

        Sanctum::actingAs($this->clientUserFor($landlordA), ['client']);

        $list = collect($this->getJson('/api/v1/client/rentals/landlord/job-cards')->assertOk()->json('job_cards'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$mine->id, $noWorkOrder->id], $list->keys()->all());
        $this->assertEquals(2400.00, $list[$mine->id]['selected_quote_amount']);
        $this->assertNull($list[$noWorkOrder->id]['selected_quote_amount']);
        $this->assertCount(1, $list[$mine->id]['photos']);

        $this->getJson('/api/v1/client/rentals/landlord/job-cards/' . $mine->id)->assertOk();
        $this->getJson('/api/v1/client/rentals/landlord/job-cards/' . $theirs->id)->assertStatus(404);
    }

    public function test_a_landlord_cannot_open_a_card_via_the_tenant_endpoint_and_vice_versa(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $landlord = $this->makeLandlord($agency, $property);
        $card = $this->makeJobCard($agency, $property, $lease);

        // The landlord has no lease → the tenant route finds nothing of theirs.
        Sanctum::actingAs($this->clientUserFor($landlord), ['client']);
        $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->assertStatus(404);

        // The tenant owns no property → the landlord route finds nothing of theirs.
        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);
        $this->getJson('/api/v1/client/rentals/landlord/job-cards/' . $card->id)->assertStatus(404);
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

    public function test_a_job_card_on_an_archived_property_still_renders(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $card = $this->makeJobCard($agency, $property, $lease);
        // A property with a live lease cannot be archived — the tenancy ends first, then the property is archived.
        \App\Models\Lease::withoutGlobalScopes()->whereKey($lease->id)->update(['status' => \App\Models\Lease::STATUS_CANCELLED]);
        $property->delete();

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->assertOk();
    }

    public function test_switching_the_tenant_portal_off_closes_the_job_card_endpoints_too(): void
    {
        [$agency, , $property, $lease, $tenant] = $this->tenantScenario();
        $card = $this->makeJobCard($agency, $property, $lease);
        RentalPortalSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $agency->id], ['tenant_portal_enabled' => false]);

        Sanctum::actingAs($this->clientUserFor($tenant), ['client']);

        $this->assertContains($this->getJson('/api/v1/client/rentals/job-cards/' . $card->id)->status(), [403, 404]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/client/rentals/job-cards')->assertStatus(401);
        $this->getJson('/api/v1/client/rentals/landlord/job-cards')->assertStatus(401);
    }
}
