<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\LeaseHubService;
use App\Services\Rentals\LeaseTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-440 — Lease Hub: tenancy log (lease-scoped, never property-scoped),
 * lifecycle derivation, next-step rules, landlord derivation, and the
 * per-record scope guard on the two new endpoints this stage adds.
 */
final class LeaseHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenancy_log_never_leaks_a_previous_tenants_fault_report(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();

        $leaseA = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_EXPIRED,
            'end_date' => now()->subMonths(6)->toDateString(),
        ]));
        $tenantA = $this->makeContact($agency, $branch, 'Tenant', 'A');
        LeaseTenant::create(['lease_id' => $leaseA->id, 'contact_id' => $tenantA->id, 'is_primary' => true]);

        $leaseB = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'start_date' => now()->subMonths(5)->toDateString(),
        ]));
        $tenantB = $this->makeContact($agency, $branch, 'Tenant', 'B');
        LeaseTenant::create(['lease_id' => $leaseB->id, 'contact_id' => $tenantB->id, 'is_primary' => true]);

        $this->makeFaultReport($agency, $branch, $property, $leaseA, 'Fault for Tenant A');
        $this->makeFaultReport($agency, $branch, $property, $leaseB, 'Fault for Tenant B');

        $timeline = app(LeaseTimelineService::class);
        $entriesA = $timeline->allEntriesFor($leaseA->fresh())->pluck('description')->implode(' | ');
        $entriesB = $timeline->allEntriesFor($leaseB->fresh())->pluck('description')->implode(' | ');

        self::assertStringContainsString('Fault for Tenant A', $entriesA);
        self::assertStringNotContainsString('Fault for Tenant B', $entriesA);
        self::assertStringContainsString('Fault for Tenant B', $entriesB);
        self::assertStringNotContainsString('Fault for Tenant A', $entriesB);
    }

    public function test_timeline_is_sorted_newest_first(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        $this->makeFaultReport($agency, $branch, $property, $lease, 'Older fault', now()->subDays(10));
        $this->makeFaultReport($agency, $branch, $property, $lease, 'Newer fault', now()->subDay());

        $entries = app(LeaseTimelineService::class)->allEntriesFor($lease->fresh());
        $descriptions = $entries->pluck('description')->values()->all();

        $newerIndex = array_search('Fault reported: Newer fault', $descriptions, true);
        $olderIndex = array_search('Fault reported: Older fault', $descriptions, true);

        self::assertNotFalse($newerIndex);
        self::assertNotFalse($olderIndex);
        self::assertLessThan($olderIndex, $newerIndex);
    }

    public function test_timeline_search_and_type_filter(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));
        $this->makeFaultReport($agency, $branch, $property, $lease, 'Leaky geyser');

        $timeline = app(LeaseTimelineService::class);

        $bySearch = $timeline->paginatedFor($lease->fresh(), 'geyser');
        self::assertSame(1, $bySearch['total']);

        $byTypeMiss = $timeline->paginatedFor($lease->fresh(), null, ['work_order']);
        self::assertSame(0, $byTypeMiss['total']);

        $byTypeHit = $timeline->paginatedFor($lease->fresh(), null, ['fault']);
        self::assertSame(1, $byTypeHit['total']);
    }

    public function test_lifecycle_marks_in_inspection_current_on_an_active_lease_with_no_completed_in_inspection(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));

        $steps = app(LeaseHubService::class)->lifecycle($lease->fresh());
        $byKey = collect($steps)->keyBy('key');

        self::assertSame('done', $byKey['lease_signed']['state']);
        self::assertSame('current', $byKey['in_inspection']['state']);
        self::assertSame('pending', $byKey['tenancy']['state']);
    }

    public function test_lifecycle_marks_lease_signed_current_on_a_draft_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_DRAFT]));

        $steps = app(LeaseHubService::class)->lifecycle($lease);
        $byKey = collect($steps)->keyBy('key');

        self::assertSame('current', $byKey['lease_signed']['state']);
        self::assertSame('pending', $byKey['in_inspection']['state']);
    }

    public function test_next_step_prioritises_in_inspection_over_renewal_window(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'end_date' => now()->addDays(10)->toDateString(),
        ]));

        $nextStep = app(LeaseHubService::class)->nextStep($lease->fresh());

        self::assertNotNull($nextStep);
        self::assertSame('Start in-inspection', $nextStep['label']);
    }

    public function test_next_step_is_null_for_a_healthy_mid_term_active_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'end_date' => now()->addYear()->toDateString(),
        ]));
        \App\Models\RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'property_id' => $property->id,
            'type' => \App\Models\RentalInspection::TYPE_IN, 'status' => \App\Models\RentalInspection::STATUS_COMPLETED,
            'completed_at' => now()->subMonths(11),
        ]);

        $nextStep = app(LeaseHubService::class)->nextStep($lease->fresh());

        self::assertNull($nextStep);
    }

    /**
     * AT-444 follow-up 2 (2026-10-05) — once notice is on file, the Next:
     * banner must point at the out-inspection, overriding the "Start
     * in-inspection" branch even when the in-inspection was never
     * completed (the exact state the real QA1 lease was found in).
     */
    public function test_next_step_points_at_out_inspection_once_notice_is_active(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'end_date' => now()->addDays(10)->toDateString(),
            'notice_date' => now()->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
            'move_out_date' => now()->addDays(30)->toDateString(),
        ]));

        $nextStep = app(LeaseHubService::class)->nextStep($lease->fresh());

        self::assertNotNull($nextStep);
        self::assertSame('Start out-inspection', $nextStep['label']);
        self::assertSame('corex.rental-inspections.create', $nextStep['route_name']);
        self::assertSame(['lease_id' => $lease->id, 'type' => 'out'], $nextStep['route_param']);
    }

    public function test_next_step_out_inspection_suppressed_once_out_inspection_completed(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'notice_date' => now()->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
            'move_out_date' => now()->toDateString(),
        ]));
        \App\Models\RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'property_id' => $property->id,
            'type' => \App\Models\RentalInspection::TYPE_OUT, 'status' => \App\Models\RentalInspection::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $nextStep = app(LeaseHubService::class)->nextStep($lease->fresh());

        self::assertNotSame('Start out-inspection', $nextStep['label'] ?? null);
    }

    public function test_lifecycle_lights_renewal_notice_node_once_notice_is_active(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'notice_date' => now()->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
            'move_out_date' => now()->addDays(30)->toDateString(),
        ]));

        $steps = app(LeaseHubService::class)->lifecycle($lease->fresh());
        $byKey = collect($steps)->keyBy('key');

        self::assertSame('current', $byKey['renewal_notice']['state']);
    }

    public function test_lifecycle_lights_renewal_notice_node_once_month_to_month(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'is_month_to_month' => true,
            'end_date' => null,
        ]));

        $steps = app(LeaseHubService::class)->lifecycle($lease->fresh());
        $byKey = collect($steps)->keyBy('key');

        self::assertSame('current', $byKey['renewal_notice']['state']);
    }

    /**
     * AT-444 follow-up 2 — the Lease Hub's own success flash must surface
     * exactly once: the app's standard toast reads session('success') on
     * every page load, so an inline banner reading the same key doubled
     * the message.
     */
    public function test_success_flash_is_not_rendered_twice_on_the_lease_hub(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)
            ->withSession(['success' => 'Notice recorded.'])
            ->get(route('corex.leases.show', $lease));

        $response->assertOk();
        self::assertSame(1, substr_count($response->getContent(), 'Notice recorded.'));
    }

    public function test_landlord_derives_from_the_property_contact_pivot_not_a_stored_column(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        self::assertTrue($lease->landlordContacts()->isEmpty());

        $landlordContact = $this->makeContact($agency, $branch, 'Owner', 'Person');
        ContactPropertyLinker::link($landlordContact->id, $property->id, 'landlord');

        $landlords = $lease->fresh()->landlordContacts();
        self::assertCount(1, $landlords);
        self::assertSame('Owner Person', $landlords->first()->full_name);
    }

    /**
     * AT-444 follow-up 3 (2026-10-05) — Johan's ruling: restore the
     * single-contact Property::sellerOwnerContact() fallback this method
     * lost in the 2026-10-04 QA1 outage merge (96b4f3ca0), which only ever
     * picked one of two versions to stop a duplicate-declaration 500 and
     * flagged the loss rather than deciding it was correct. Fixes
     * LeaseController::show()'s Lease Terms card, RentalDocumentPdfService
     * ::leaseTenancyReportPdf(), and the shared rental-context-bar
     * component — all three read this one method, never duplicated.
     */
    public function test_landlord_contacts_falls_back_to_seller_owner_contact_when_nothing_is_tagged_landlord(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        $owner = $this->makeContact($agency, $branch, 'Owner', 'OnlyTaggedSeller');
        ContactPropertyLinker::link($owner->id, $property->id, 'seller');

        $landlords = $lease->fresh()->landlordContacts();

        self::assertCount(1, $landlords);
        self::assertSame('Owner OnlyTaggedSeller', $landlords->first()->full_name);
    }

    public function test_landlord_contacts_does_not_fall_back_when_a_real_landlord_is_tagged(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        $landlord = $this->makeContact($agency, $branch, 'Real', 'Landlord');
        ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');
        $seller = $this->makeContact($agency, $branch, 'Unrelated', 'Seller');
        ContactPropertyLinker::link($seller->id, $property->id, 'seller');

        $landlords = $lease->fresh()->landlordContacts();

        self::assertCount(1, $landlords);
        self::assertSame('Real Landlord', $landlords->first()->full_name);
    }

    public function test_open_item_counts_are_lease_scoped(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $leaseA = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_EXPIRED]));
        $leaseB = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));

        $this->makeFaultReport($agency, $branch, $property, $leaseA, 'Old open fault', now(), 'reported');

        $countsA = app(LeaseHubService::class)->openItemCounts($leaseA->fresh());
        $countsB = app(LeaseHubService::class)->openItemCounts($leaseB->fresh());

        self::assertSame(1, $countsA['faults']);
        self::assertSame(0, $countsB['faults']);
    }

    public function test_tenancy_log_endpoint_blocks_cross_agency_access(): void
    {
        [$agencyA, $branchA, $propertyA] = $this->makeAgencyBranchProperty();
        [$agencyB] = $this->makeAgencyBranchProperty();

        $lease = Lease::create($this->baseLeaseAttributes($agencyA, $branchA, $propertyA));

        $userB = User::factory()->create(['agency_id' => $agencyB->id, 'role' => 'admin']);
        $this->actingAs($userB)
            ->getJson(route('v1.leases.tenancy-log', $lease))
            ->assertNotFound();
    }

    public function test_tenancy_log_endpoint_returns_entries_for_an_authorised_user(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));
        $this->makeFaultReport($agency, $branch, $property, $lease, 'A reachable fault');

        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->getJson(route('v1.leases.tenancy-log', $lease));

        $response->assertOk();
        $response->assertJsonFragment(['description' => 'Fault reported: A reachable fault']);
    }

    public function test_tenancy_report_pdf_blocks_cross_agency_access(): void
    {
        [$agencyA, $branchA, $propertyA] = $this->makeAgencyBranchProperty();
        [$agencyB] = $this->makeAgencyBranchProperty();

        $lease = Lease::create($this->baseLeaseAttributes($agencyA, $branchA, $propertyA));

        $userB = User::factory()->create(['agency_id' => $agencyB->id, 'role' => 'admin']);
        $this->actingAs($userB)
            ->get(route('corex.leases.tenancy-report', $lease))
            ->assertNotFound();
    }

    public function test_tenancy_report_pdf_renders_for_an_authorised_user(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.tenancy-report', $lease));

        $response->assertOk();
        self::assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * AT-444 follow-up 2 (2026-10-05) — the header must carry a one-line
     * state marker next to the status badge for each of the four outcomes;
     * only the single highest-priority one shows at a time.
     */
    public function test_header_shows_notice_given_marker(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'notice_date' => now()->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_TENANT,
            'move_out_date' => '2026-11-30',
        ]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('Notice given · move-out 30 Nov 2026');
    }

    public function test_header_shows_landlord_not_renewing_marker(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'notice_date' => now()->toDateString(),
            'notice_given_by' => Lease::NOTICE_BY_LANDLORD,
            'move_out_date' => '2026-11-30',
        ]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertSee('Landlord not renewing · move-out 30 Nov 2026');
    }

    public function test_header_shows_month_to_month_marker(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE,
            'is_month_to_month' => true,
            'end_date' => null,
        ]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        self::assertStringContainsString('ds-badge-info">Month-to-month</span>', $response->getContent());
    }

    public function test_header_has_no_marker_for_a_healthy_active_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));
        $response->assertOk();

        $response->assertDontSee('Notice given');
        $response->assertDontSee('Renewal in progress');
        // The "Month-to-month (no fixed end date)" edit-panel checkbox label
        // is always rendered regardless of lease state, so assert the exact
        // marker-badge markup is absent rather than the bare word.
        self::assertStringNotContainsString('ds-badge-info">Month-to-month</span>', $response->getContent());
    }

    /**
     * AT-444 follow-up 3 (2026-10-05) — once CoreX has already drafted a
     * renewal, the "Renew lease…" dialog shows that draft (and a link
     * straight into its e-sign flow) instead of a blank term-entry
     * invitation that would just create a second one.
     */
    public function test_renew_dialog_shows_the_pending_draft_when_one_exists(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $draft = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'previous_lease_id' => $lease->id, 'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9800,
            'start_date' => now()->addDay()->toDateString(), 'source' => 'manual', 'renewal_draft_flow_id' => 42,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('CoreX already prepared a renewal draft');
        $response->assertSee('R9,800.00');
        $response->assertDontSee('Continue to renewal');
    }

    public function test_renew_dialog_shows_blank_entry_when_no_draft_exists(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('Continue to renewal');
        $response->assertDontSee('CoreX already prepared a renewal draft');
    }

    public function test_lease_hub_show_renders_for_a_brand_new_lease_with_nothing_attached(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.leases.show', $lease));

        $response->assertOk();
        $response->assertSee('Nothing recorded yet on this tenancy');
    }

    /** @return array{0: Agency, 1: Branch, 2: Property} */
    private function makeAgencyBranchProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $property];
    }

    private function baseLeaseAttributes(Agency $agency, Branch $branch, Property $property, array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides);
    }

    private function makeContact(Agency $agency, Branch $branch, string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
        ]);
    }

    private function makeFaultReport(Agency $agency, Branch $branch, Property $property, Lease $lease, string $title, $reportedAt = null, string $status = 'reported'): RentalFaultReport
    {
        return RentalFaultReport::create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'lease_id' => $lease->id,
            'reported_by_type' => 'agent',
            'reported_channel' => 'agent_portal',
            'title' => $title,
            'description' => 'Test description',
            'status' => $status,
            'reported_at' => $reportedAt ?? now(),
        ]);
    }
}
