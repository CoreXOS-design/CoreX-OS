<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Document as EsignDocument;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\LeaseRenewalService;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-444 — term chain, one-click outcomes + reversal, draft path selection
 * (a/c — path (b) is item 5's own WAIT gate, not built here), completion →
 * activation, agency isolation, scope guards.
 */
final class LeaseRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_renewal_term_chains_previous_lease_id_and_copies_tenants(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $tenant = $this->makeContact($agency, $branch, 'Tenant', 'One');
        LeaseTenant::create(['lease_id' => $current->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $user = $this->makeUser($agency, $branch);

        $newTerm = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addYear()->toDateString(),
            'rental_amount' => 9900,
        ], $user);

        self::assertSame($current->id, $newTerm->previous_lease_id);
        self::assertSame(Lease::STATUS_DRAFT, $newTerm->status);
        self::assertCount(1, $newTerm->tenants);
        self::assertSame($tenant->id, $newTerm->tenants->first()->contact_id);
    }

    public function test_create_renewal_term_rejects_a_non_active_current_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_DRAFT]));
        $user = $this->makeUser($agency, $branch);

        $this->expectException(ValidationException::class);

        app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->toDateString(),
            'rental_amount' => 9000,
        ], $user);
    }

    public function test_activate_renewal_term_closes_previous_term_and_records_escalation(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000]));
        $user = $this->makeUser($agency, $branch);

        $newTerm = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addDay()->toDateString(),
            'rental_amount' => 9900,
        ], $user);

        $activated = app(LeaseRenewalService::class)->activateRenewalTerm($newTerm, $user);

        self::assertSame(Lease::STATUS_ACTIVE, $activated->status);
        self::assertSame(Lease::STATUS_EXPIRED, $current->fresh()->status);
        self::assertSame($activated->id, $current->fresh()->renewed_lease_id);

        $escalation = $activated->escalations()->first();
        self::assertNotNull($escalation);
        self::assertEquals(9000, (float) $escalation->previous_rental_amount);
        self::assertEquals(9900, (float) $escalation->new_rental_amount);
    }

    public function test_activate_renewal_term_records_no_escalation_when_rent_unchanged(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000]));
        $user = $this->makeUser($agency, $branch);

        $newTerm = app(LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => now()->addDay()->toDateString(),
            'rental_amount' => 9000,
        ], $user);

        $activated = app(LeaseRenewalService::class)->activateRenewalTerm($newTerm, $user);

        self::assertSame(0, $activated->escalations()->count());
    }

    public function test_activate_renewal_term_rejects_a_lease_with_no_previous_lease(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_DRAFT]));
        $user = $this->makeUser($agency, $branch);

        $this->expectException(ValidationException::class);

        app(LeaseRenewalService::class)->activateRenewalTerm($lease, $user);
    }

    public function test_month_to_month_outcome_clears_end_date_and_is_reversible(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'end_date' => now()->addDays(10)->toDateString(),
        ]));
        $user = $this->makeUser($agency, $branch);

        $updated = app(LeaseRenewalService::class)->recordMonthToMonth($lease, 'Tenant asked to stay on', $user);
        self::assertTrue($updated->is_month_to_month);
        self::assertNull($updated->end_date);
        self::assertSame(LeaseEvent::TYPE_MONTH_TO_MONTH_SET, $lease->fresh()->events->last()->event_type);

        $reversed = app(LeaseRenewalService::class)->reverseMonthToMonth($updated, $user);
        self::assertFalse($reversed->is_month_to_month);

        // History survives the reversal — both events are still on the log.
        $types = $lease->fresh()->events->pluck('event_type')->all();
        self::assertContains(LeaseEvent::TYPE_MONTH_TO_MONTH_SET, $types);
        self::assertContains(LeaseEvent::TYPE_MONTH_TO_MONTH_REVERSED, $types);
    }

    public function test_tenant_and_landlord_notice_write_distinct_descriptions_and_are_reversible(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $moveOut = now()->addDays(30)->toDateString();
        $updated = app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, $moveOut, 'Relocating for work', $user, Lease::NOTICE_OUTCOME_LEAVE);

        self::assertTrue($updated->hasActiveNotice());
        self::assertSame('tenant', $updated->notice_given_by);
        self::assertSame($moveOut, $updated->move_out_date->toDateString());

        $description = $lease->fresh()->events->last()->description;
        self::assertStringContainsString('Tenant gave notice', $description);

        $reversed = app(LeaseRenewalService::class)->reverseNotice($updated, $user);
        self::assertFalse($reversed->hasActiveNotice());
        self::assertNull($reversed->notice_given_by);

        // Landlord path, same mechanism, distinct description.
        $landlordUpdated = app(LeaseRenewalService::class)->recordNotice($reversed, Lease::NOTICE_BY_LANDLORD, $moveOut, null, $user, Lease::NOTICE_OUTCOME_LEAVE);
        $landlordDescription = $lease->fresh()->events->last()->description;
        self::assertStringContainsString('Landlord not renewing', $landlordDescription);
        self::assertTrue($landlordUpdated->hasActiveNotice());
    }

    /**
     * AT-444 follow-up 2 (2026-10-05) — the dialog's own min/max attributes
     * are a client-side convenience only; the server is the real guard
     * against a mistyped date (e.g. a 6-digit year typed on a keyboard
     * instead of picked) reaching the service layer at all.
     */
    public function test_tenant_notice_http_rejects_a_move_out_date_too_far_in_the_future(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.tenant-notice', $lease), [
            'move_out_date' => now()->addYears(5)->toDateString(),
            'notice_outcome' => Lease::NOTICE_OUTCOME_LEAVE,
        ]);

        $response->assertSessionHasErrors('move_out_date');
        self::assertFalse($lease->fresh()->hasActiveNotice());
    }

    public function test_tenant_notice_http_accepts_a_move_out_date_within_the_sane_window(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.tenant-notice', $lease), [
            'move_out_date' => now()->addDays(30)->toDateString(),
            'notice_outcome' => Lease::NOTICE_OUTCOME_LEAVE,
        ]);

        $response->assertSessionDoesntHaveErrors();
        self::assertTrue($lease->fresh()->hasActiveNotice());
    }

    /**
     * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (1): "the
     * dialog cannot be confirmed without a choice" — enforced server-side,
     * not just by the dialog's own `required` radios.
     */
    public function test_tenant_notice_http_rejects_a_missing_outcome_choice(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.tenant-notice', $lease), [
            'move_out_date' => now()->addDays(30)->toDateString(),
        ]);

        $response->assertSessionHasErrors('notice_outcome');
        self::assertFalse($lease->fresh()->hasActiveNotice());
    }

    public function test_tenant_notice_http_rejects_an_unrecognised_outcome_choice(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.tenant-notice', $lease), [
            'move_out_date' => now()->addDays(30)->toDateString(),
            'notice_outcome' => 'not-a-real-outcome',
        ]);

        $response->assertSessionHasErrors('notice_outcome');
        self::assertFalse($lease->fresh()->hasActiveNotice());
    }

    public function test_change_notice_outcome_http_updates_the_choice(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);
        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $user, Lease::NOTICE_OUTCOME_LEAVE);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.notice.change-outcome', $lease), [
            'notice_outcome' => Lease::NOTICE_OUTCOME_WITHDRAW,
        ]);

        $response->assertRedirect();
        self::assertSame(Lease::NOTICE_OUTCOME_WITHDRAW, $lease->fresh()->notice_outcome);
        self::assertSame('withdrawn', $property->fresh()->status);
    }

    public function test_change_notice_outcome_http_requires_a_choice(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);
        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $user, Lease::NOTICE_OUTCOME_LEAVE);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.notice.change-outcome', $lease), []);

        $response->assertSessionHasErrors('notice_outcome');
        self::assertSame(Lease::NOTICE_OUTCOME_LEAVE, $lease->fresh()->notice_outcome);
    }

    public function test_record_notice_rejects_an_invalid_given_by_value(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $this->expectException(ValidationException::class);

        app(LeaseRenewalService::class)->recordNotice($lease, 'agent', now()->toDateString(), null, $user, Lease::NOTICE_OUTCOME_LEAVE);
    }

    /** .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (1): the agent picks a choice every time, nothing defaulted server-side either. */
    public function test_record_notice_rejects_an_invalid_outcome_value(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $this->expectException(ValidationException::class);

        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->toDateString(), null, $user, 'not-a-real-outcome');
    }

    public function test_renewal_events_appear_in_the_tenancy_log_as_notice_type(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        app(LeaseRenewalService::class)->recordNotice($lease, Lease::NOTICE_BY_TENANT, now()->addDays(30)->toDateString(), null, $user, Lease::NOTICE_OUTCOME_LEAVE);

        $entries = app(\App\Services\Rentals\LeaseTimelineService::class)->paginatedFor($lease->fresh(), null, ['notice']);
        self::assertSame(1, $entries['total']);
    }

    public function test_copy_forward_rejects_a_lease_that_was_not_e_signed_through_corex(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE, 'source' => 'manual']));
        $user = $this->makeUser($agency, $branch);

        $this->expectException(ValidationException::class);

        app(RenewalDraftService::class)->copyForward($lease, ['start_date' => now()->toDateString(), 'rental_amount' => 9000], $user);
    }

    public function test_copy_forward_builds_a_prefilled_flow_with_recipients_and_details(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $template = Template::create(['name' => 'Lease agreement test', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $agency->id]);
        $document = EsignDocument::create([
            'name' => 'Signed lease', 'template_id' => $template->id, 'owner_id' => $this->makeUser($agency, $branch)->id,
            'agency_id' => $agency->id,
        ]);
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'status' => Lease::STATUS_ACTIVE, 'source' => 'esign_document', 'source_document_id' => $document->id,
        ]));
        $tenant = $this->makeContact($agency, $branch, 'Tenant', 'Renewing');
        LeaseTenant::create(['lease_id' => $current->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $landlord = $this->makeContact($agency, $branch, 'Owner', 'Landlord');
        ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');
        $user = $this->makeUser($agency, $branch);

        $result = app(RenewalDraftService::class)->copyForward($current->fresh(), [
            'start_date' => now()->addDay()->toDateString(),
            'rental_amount' => 9900,
        ], $user);

        self::assertSame($template->id, $result['flow']->template_id);
        self::assertSame($result['flow']->id, $result['lease']->renewal_draft_flow_id);

        $recipients = $result['flow']->step_data['recipients']['recipients'];
        $roles = array_column($recipients, 'role');
        self::assertContains('tenant', $roles);
        self::assertContains('landlord', $roles);
        self::assertContains('agent', $roles);
        self::assertSame('9900.00', $result['flow']->step_data['details']['monthly_rental']);
    }

    public function test_upload_renewal_creates_and_activates_a_new_term_with_filed_document(): void
    {
        Storage::fake('local');

        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $current = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE, 'rental_amount' => 8500]));
        $user = $this->makeUser($agency, $branch);

        $response = $this->actingAs($user)->post(route('corex.leases.renewal.upload', $current), [
            'start_date' => now()->addDay()->toDateString(),
            'rental_amount' => 9200,
            'signed_document' => UploadedFile::fake()->create('renewal.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $newTerm = Lease::where('previous_lease_id', $current->id)->first();
        self::assertNotNull($newTerm);
        self::assertSame(Lease::STATUS_ACTIVE, $newTerm->status);
        self::assertSame(Lease::STATUS_EXPIRED, $current->fresh()->status);

        $document = \App\Models\Document::where('source_type', 'lease')->where('source_id', $newTerm->id)->first();
        self::assertNotNull($document);
        self::assertTrue($document->properties->contains($property->id));
    }

    public function test_renewal_actions_block_cross_agency_access(): void
    {
        [$agencyA, $branchA, $propertyA] = $this->makeAgencyBranchProperty();
        [$agencyB, $branchB] = $this->makeAgencyBranchProperty();

        $lease = Lease::create($this->baseLeaseAttributes($agencyA, $branchA, $propertyA, ['status' => Lease::STATUS_ACTIVE]));
        $userB = $this->makeUser($agencyB, $branchB);

        $this->actingAs($userB)->get(route('corex.leases.renewal.create', $lease))->assertNotFound();
        $this->actingAs($userB)->post(route('corex.leases.renewal.month-to-month', $lease), [])->assertNotFound();
    }

    public function test_renewal_screen_renders_for_an_authorised_user(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['status' => Lease::STATUS_ACTIVE]));
        $user = $this->makeUser($agency, $branch);

        $response = $this->actingAs($user)->get(route('corex.leases.renewal.create', $lease));

        $response->assertOk();
        $response->assertSee('Renew this lease');
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

    private function makeUser(Agency $agency, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }
}
