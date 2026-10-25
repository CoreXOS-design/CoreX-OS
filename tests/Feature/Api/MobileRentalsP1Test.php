<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Events\Rentals\LeaseNoticeReviewed;
use App\Events\Rentals\RentalOwnerDecisionNeeded;
use App\Events\Rentals\RentalTenantFaultStatusChanged;
use App\Models\LeaseNotice;
use App\Models\RentalFaultReport;
use App\Models\RentalPortalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 P1 — push to tenants and owners (mandatory vs switchable), "waiting for you", and the fault timeline.
 * .ai/specs/at-508-mobile-rentals.md §4 P1 / §13.
 */
final class MobileRentalsP1Test extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;

    private const CLIENT = '/api/v1/client/rentals';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    // ── what a person may switch ─────────────────────────────────────────────────────────────

    public function test_each_side_lists_only_the_switchable_notifications_and_mandatory_ones_cannot_be_switched_off(): void
    {
        $tenant = $this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/push-preferences')->assertOk()->json('switchable');
        $this->assertSame(['fault_status'], array_column($tenant, 'key'));
        $this->assertTrue($tenant[0]['enabled'], 'on until the agency or the person says otherwise');

        $owner = $this->asClient($this->ownerLogin)->getJson(self::CLIENT . '/landlord/push-preferences')->assertOk()->json('switchable');
        $this->assertSame(['work_order_update'], array_column($owner, 'key'));

        // the mandatory ones: refused, and nothing is stored
        foreach (['decision_needed', 'appointment', 'notice_outcome', 'work_finished', 'work_check_requested'] as $key) {
            $this->asClient($this->ownerLogin)->putJson(self::CLIENT . '/landlord/push-preferences', ['preferences' => [$key => false]])
                ->assertStatus(422)->assertJsonPath('code', 'not_switchable');
        }
        $this->assertSame(0, \App\Models\RentalPushPreference::count());
        // a tenant cannot touch an owner-only switch
        $this->asClient($this->tenantLogin)->putJson(self::CLIENT . '/push-preferences', ['preferences' => ['work_order_update' => false]])->assertStatus(422);
    }

    public function test_the_agency_default_applies_until_the_person_chooses(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['push_informational_default' => false]);
        $row = $this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/push-preferences')->json('switchable.0');
        $this->assertFalse($row['enabled']);
        $this->assertFalse($row['default']);

        $this->asClient($this->tenantLogin)->putJson(self::CLIENT . '/push-preferences', ['preferences' => ['fault_status' => true]])
            ->assertOk()->assertJsonPath('switchable.0.enabled', true);
    }

    // ── pushes ───────────────────────────────────────────────────────────────────────────────

    public function test_a_decision_needed_reaches_the_owner_even_with_the_email_setting_off_and_only_once(): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_decision_needed' => false]);
        $this->phoneFor($this->ownerLogin, 'tok-owner');
        $this->phoneFor($this->tenantLogin, 'tok-tenant');
        $fault = $this->fault();

        RentalOwnerDecisionNeeded::dispatch($fault, 0);
        RentalOwnerDecisionNeeded::dispatch($fault, 0);   // the same fact again (a retried chase)

        $sent = $this->pushedTo('tok-owner');
        $this->assertCount(1, $sent);
        $this->assertSame('A decision is waiting for you', $sent[0]['payload']['notification']['title']);
        $this->assertSame('decision_needed', $sent[0]['payload']['data']['category']);
        $this->assertSame('1', $sent[0]['payload']['data']['mandatory']);
        $this->assertSame([], $this->pushedTo('tok-tenant'), 'the tenant is never told an owner decision is waiting');

        RentalOwnerDecisionNeeded::dispatch($fault, 1);   // a reminder is a new fact
        $this->assertCount(2, $this->pushedTo('tok-owner'));
    }

    public function test_a_fault_status_push_is_switchable_a_decision_is_not(): void
    {
        $this->phoneFor($this->tenantLogin, 'tok-tenant');
        $fault = $this->fault();

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['push_informational_default' => false]);
        RentalTenantFaultStatusChanged::dispatch($fault, 'owner_approved');
        $this->assertSame([], $this->pushedTo('tok-tenant'), 'the agency default is off');

        // the person turns it on for themselves
        $this->asClient($this->tenantLogin)->putJson(self::CLIENT . '/push-preferences', ['preferences' => ['fault_status' => true]])->assertOk();
        RentalTenantFaultStatusChanged::dispatch($fault, 'owner_approved');
        $sent = $this->pushedTo('tok-tenant');
        $this->assertCount(1, $sent);
        $this->assertSame('Your fault has been approved', $sent[0]['payload']['notification']['title']);
        $this->assertSame('0', $sent[0]['payload']['data']['mandatory']);

        // ...and a person's own "off" holds even when the agency default is on
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['push_informational_default' => true]);
        $this->asClient($this->tenantLogin)->putJson(self::CLIENT . '/push-preferences', ['preferences' => ['fault_status' => false]])->assertOk();
        RentalTenantFaultStatusChanged::dispatch($fault, 'not_approved');
        $this->assertCount(1, $this->pushedTo('tok-tenant'));
    }

    public function test_a_rejected_notice_tells_only_the_giver_an_accepted_one_tells_both_and_the_tenant_text_names_no_one(): void
    {
        $this->phoneFor($this->ownerLogin, 'tok-owner');
        $this->phoneFor($this->tenantLogin, 'tok-tenant');
        $notice = LeaseNotice::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'given_by' => 'landlord', 'via' => 'portal',
            'status' => 'received', 'notice_date' => now()->toDateString(), 'intended_end_date' => now()->addMonths(2)->toDateString(),
        ]);

        LeaseNoticeReviewed::dispatch($notice, 'rejected', $this->agent->id);
        $this->assertCount(1, $this->pushedTo('tok-owner'));
        $this->assertSame([], $this->pushedTo('tok-tenant'));

        LeaseNoticeReviewed::dispatch($notice, 'accepted', $this->agent->id);
        $this->assertCount(2, $this->pushedTo('tok-owner'));
        $tenant = $this->pushedTo('tok-tenant');
        $this->assertCount(1, $tenant);
        $this->assertStringNotContainsStringIgnoringCase('Lenny', json_encode($tenant[0]['payload']));
    }

    public function test_an_appointment_tells_the_tenant_and_the_owner_unless_the_owner_set_it_themselves(): void
    {
        $this->phoneFor($this->ownerLogin, 'tok-owner');
        $this->phoneFor($this->tenantLogin, 'tok-tenant');
        $wo = \App\Models\RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => \App\Models\RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'reported_by_type' => \App\Models\RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->agent->id,
            'title' => 'Tap', 'description' => 'Tap', 'status' => \App\Models\RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => \App\Models\RentalWorkOrder::APPROVAL_NOT_REQUIRED,
            'reported_at' => now(), 'created_by_user_id' => $this->agent->id, 'appointment_at' => now()->addDays(2),
        ]);

        \App\Events\Rentals\RentalAppointmentSet::dispatch($wo, false, 'staff', $this->agent->id);
        $this->assertCount(1, $this->pushedTo('tok-tenant'));
        $this->assertCount(1, $this->pushedTo('tok-owner'));
        $this->assertSame('A repair appointment has been booked', $this->pushedTo('tok-tenant')[0]['payload']['notification']['title']);

        $wo->forceFill(['appointment_at' => now()->addDays(3)])->save();
        \App\Events\Rentals\RentalAppointmentSet::dispatch($wo->fresh(), true, 'owner');
        $this->assertCount(2, $this->pushedTo('tok-tenant'), 'the tenant hears the change');
        $this->assertCount(1, $this->pushedTo('tok-owner'), 'the owner who set it is not buzzed about their own action');
    }

    public function test_the_visibility_descriptor_says_which_rental_screens_a_person_may_show(): void
    {
        $this->grantScope($this->agency, 'agent', 'own', ['rental_fault_reports.view', 'leases.view']);
        $res = $this->asStaff($this->agent)->getJson('/api/v1/mobile/visibility')->assertOk();

        $this->assertTrue($res->json('rentals.fault_reports.can_view'));
        $this->assertFalse($res->json('rentals.fault_reports.can_act'), 'view only - no create right');
        $this->assertSame('own', $res->json('rentals.fault_reports.scope'));
        $this->assertTrue($res->json('rentals.leases.can_view'));
        $this->assertFalse($res->json('rentals.work_orders.can_view'));
        $this->assertNull($res->json('rentals.work_orders.scope'));
    }

    // ── waiting for me ───────────────────────────────────────────────────────────────────────

    public function test_staff_waiting_lists_what_needs_them_inside_their_agency_only(): void
    {
        $this->fault(['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);

        $res = $this->asStaff($this->admin)->getJson('/api/v1/mobile/rentals/waiting')->assertOk();
        $this->assertGreaterThanOrEqual(1, $res->json('waiting_count'));
        $item = collect($res->json('data'))->firstWhere('type', 'fault_awaiting_approval');
        $this->assertSame('fault_report', $item['subject_type']);
        $this->assertSame('12 Sea Road', explode(',', $item['property']['address'])[0]);

        // another agency's agent sees none of it (an 'admin' role is an owner role and sees every agency, by design)
        $outsider = \App\Models\User::factory()->create(['agency_id' => $this->other->id, 'role' => 'agent', 'is_active' => true]);
        $none = $this->asStaff($outsider)->getJson('/api/v1/mobile/rentals/waiting')->assertOk();
        $this->assertSame(0, $none->json('waiting_count'));
        $this->assertSame([], $none->json('data'));
        $this->assertNotEmpty($none->json('meta.empty_reason'));
    }

    public function test_the_owner_waiting_count_matches_the_decisions_list_and_the_tenant_has_their_own(): void
    {
        $fault = $this->fault(['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);

        $owner = $this->asClient($this->ownerLogin)->getJson(self::CLIENT . '/landlord/waiting')->assertOk();
        $this->assertSame(1, $owner->json('waiting_count'));
        $this->assertSame('fault_report', $owner->json('items.0.kind'));
        $this->assertSame($fault->id, $owner->json('items.0.id'));
        $decisions = $this->asClient($this->ownerLogin)->getJson(self::CLIENT . '/landlord/decisions')->json('fault_reports');
        $this->assertCount($owner->json('waiting_count'), $decisions);

        $this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/waiting')->assertOk()->assertJsonPath('waiting_count', 0);
    }

    // ── timeline ─────────────────────────────────────────────────────────────────────────────

    public function test_a_fault_detail_carries_the_timeline_for_the_tenant_and_the_owner(): void
    {
        $fault = $this->fault(['reported_by_type' => 'tenant', 'status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);

        $t = $this->asClient($this->tenantLogin)->getJson(self::CLIENT . "/fault-reports/{$fault->id}")->assertOk()->json('fault_report.timeline') ?? $this->asClient($this->tenantLogin)->getJson(self::CLIENT . "/fault-reports/{$fault->id}")->json('timeline');
        $this->assertNotEmpty($t);
        $this->assertSame('sent_to_agent', $t[0]['key']);
        $this->assertSame(['key', 'label', 'state', 'current', 'at'], array_keys($t[0]));

        $o = $this->asClient($this->ownerLogin)->getJson(self::CLIENT . "/landlord/fault-reports/{$fault->id}")->assertOk();
        $this->assertNotEmpty($o->json('fault_report.timeline') ?? $o->json('timeline'));
    }
}
