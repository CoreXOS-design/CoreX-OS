<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Mail\Rentals\RentalOwnerFinalStatementMail;
use App\Mail\Rentals\RentalOwnerWorkReportedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\OwnerFinishNotice;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalContractorJobService;
use App\Services\Rentals\RentalGoAheadService;
use App\Services\Rentals\RentalOwnerHoldService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Audit fixes 19 Oct 2026 (C-B1..B5, C-A3 rows + alerts, C-C4) - spec rentals-faults-work-orders.md section 29.
 * Two DIFFERENT events about the same work order within a minute must BOTH reach the agent (the per-subject cooldown used to swallow the second); the Command Centre has a row
 * for every state the agent has to act on; the owner hears that work was reported finished by ONE rule for crew and contractor.
 */
final class AuditP1AlertsAndQueueTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $ownerAgent;
    private Property $property;
    private Lease $lease;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'P1 Agency', 'slug' => 'p1-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'P-' . $this->agency->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->ownerAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id, 'title' => 'P1 Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual', 'owner_agent_user_id' => $this->ownerAgent->id,
        ]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
    }

    private function workOrder(array $over = []): RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'assignment_type' => RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, 'title' => 'Fix the roof', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_ORDERED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'ordered_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $over));
    }

    /** How many in-app alerts of $eventKey reached $user. */
    private function alerts(string $eventKey, ?User $user = null): int
    {
        return DB::table('notifications')->get()
            ->filter(fn ($n) => (json_decode($n->data, true)['event_key'] ?? null) === $eventKey && ($user === null || (int) $n->notifiable_id === $user->id))->count();
    }

    // ── C-B1..B5: separate types, so the cooldown cannot swallow a different event ─────────────────────────────────────────────

    public function test_every_new_alert_type_is_registered_in_app_and_email_and_on_the_phone_only_where_the_agent_must_act(): void
    {
        // AT-508 (26 Oct 2026): the agent's phone is on for alerts the agent has to act on; the agent's own actions coming back stay in-app + email.
        $phoneOff = ['contractor_photo', 'go_ahead_sent'];
        foreach ([
            'contractor_accepted', 'contractor_declined', 'contractor_invoice', 'contractor_extra_money', 'contractor_photo', 'contractor_finished',
            'owner_started', 'owner_finished', 'tenant_started', 'tenant_not_fixed', 'go_ahead_sent',
        ] as $k) {
            $row = DB::table('notification_event_types')->where('key', 'rental_work_order.' . $k)->first();
            $this->assertNotNull($row, $k);
            $this->assertSame([1, 1, in_array($k, $phoneOff, true) ? 0 : 1, 1], [(int) $row->supports_in_app, (int) $row->supports_email, (int) $row->supports_push, (int) $row->default_enabled], $k);
        }
        foreach (['rental_fault_report.owner_resumed', 'rental_job_card.crew_completed'] as $k) {
            $this->assertNotNull(DB::table('notification_event_types')->where('key', $k)->first(), $k);
        }
    }

    public function test_a_photo_then_the_finished_report_on_the_same_work_order_both_reach_the_agent(): void
    {
        $wo = $this->workOrder();
        $svc = app(RentalWorkOrderService::class);
        $svc->notifyAgentOfOwnerAction($wo, RentalContractorJobService::EV_PHOTO, 'Contractor uploaded a before photo', 'x', now());
        $svc->notifyAgentOfOwnerAction($wo, RentalContractorJobService::EV_FINISHED, 'Finished - awaiting your check', 'x', now());

        $this->assertGreaterThan(0, $this->alerts(RentalContractorJobService::EV_PHOTO));
        $this->assertGreaterThan(0, $this->alerts(RentalContractorJobService::EV_FINISHED), 'the important alert is no longer swallowed by the photo');
        $this->assertSame(0, $this->alerts('rental_work_order.contractor_responded'), 'the shared legacy type is no longer fired');
    }

    public function test_accepted_declined_invoice_and_extra_money_are_each_their_own_alert(): void
    {
        $wo = $this->workOrder();
        $svc = app(RentalWorkOrderService::class);
        foreach ([RentalContractorJobService::EV_ACCEPTED, RentalContractorJobService::EV_DECLINED, RentalContractorJobService::EV_INVOICE, RentalContractorJobService::EV_EXTRA_MONEY] as $ev) {
            $svc->notifyAgentOfOwnerAction($wo, $ev, 'Event ' . $ev, 'x', now());
        }
        foreach ([RentalContractorJobService::EV_ACCEPTED, RentalContractorJobService::EV_DECLINED, RentalContractorJobService::EV_INVOICE, RentalContractorJobService::EV_EXTRA_MONEY] as $ev) {
            $this->assertGreaterThan(0, $this->alerts($ev), $ev);
        }
    }

    public function test_the_contractors_answer_uses_the_accepted_and_declined_types(): void
    {
        $wo = $this->workOrder();
        app(RentalContractorJobService::class)->recordResponse($wo, RentalContractorJobService::RESPONSE_ACCEPTED);
        app(RentalContractorJobService::class)->recordResponse($wo->fresh(), RentalContractorJobService::RESPONSE_DECLINED, 'Too busy');

        $this->assertGreaterThan(0, $this->alerts(RentalContractorJobService::EV_ACCEPTED));
        $this->assertGreaterThan(0, $this->alerts(RentalContractorJobService::EV_DECLINED), 'the decline is not swallowed by the accept');
    }

    public function test_owner_and_tenant_progress_reports_are_four_separate_types(): void
    {
        $wo = $this->workOrder();
        $svc = app(RentalWorkOrderService::class);
        foreach ([RentalWorkOrderService::EV_OWNER_STARTED, RentalWorkOrderService::EV_OWNER_FINISHED, RentalWorkOrderService::EV_TENANT_STARTED, RentalWorkOrderService::EV_TENANT_NOT_FIXED] as $ev) {
            $svc->notifyAgentOfOwnerAction($wo, $ev, 'Event ' . $ev, 'x', now());
        }
        foreach ([RentalWorkOrderService::EV_OWNER_STARTED, RentalWorkOrderService::EV_OWNER_FINISHED, RentalWorkOrderService::EV_TENANT_STARTED, RentalWorkOrderService::EV_TENANT_NOT_FIXED] as $ev) {
            $this->assertGreaterThan(0, $this->alerts($ev), $ev);
        }
        $this->assertSame(0, $this->alerts('rental_work_order.owner_progress'));
    }

    public function test_the_go_ahead_alert_is_its_own_type_beside_the_job_sent_alert(): void
    {
        $wo = $this->workOrder();
        $svc = app(RentalWorkOrderService::class);
        $svc->notifyAgentOfOwnerAction($wo, RentalContractorJobService::EV_SENT, 'Job sent', 'x', now());
        $svc->notifyAgentOfOwnerAction($wo, RentalGoAheadService::EV_GO_AHEAD, 'Go-ahead sent', 'x', now());

        $this->assertGreaterThan(0, $this->alerts(RentalContractorJobService::EV_SENT));
        $this->assertGreaterThan(0, $this->alerts('rental_work_order.go_ahead_sent'));
    }

    public function test_the_owner_hold_resume_is_not_swallowed_by_the_stop(): void
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'reported_by_type' => 'tenant',
            'reported_channel' => 'app', 'title' => 'Door', 'description' => 'x', 'status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING,
            'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $d = app(NotificationDispatcher::class);
        foreach ([RentalOwnerHoldService::EV_HOLD, RentalOwnerHoldService::EV_RESUMED] as $ev) {
            $d->fire($this->admin, $ev, $fault, ['title' => $ev, 'body' => 'x', 'severity' => 'info', 'threshold_hit_at' => now()]);
        }
        $this->assertSame(1, $this->alerts(RentalOwnerHoldService::EV_HOLD, $this->admin));
        $this->assertSame(1, $this->alerts(RentalOwnerHoldService::EV_RESUMED, $this->admin));
    }

    public function test_a_second_extra_on_the_same_work_order_is_its_own_alert(): void
    {
        $wo = $this->workOrder();
        $mk = fn (int $rev) => RentalWorkOrderVariation::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'revision' => $rev, 'status' => RentalWorkOrderVariation::STATUS_AWAITING_OWNER, 'origin' => 'contractor',
            'baseline_amount' => 1000, 'extra_amount' => 200 * $rev, 'new_total' => 1000 + 200 * $rev, 'raised_at' => now(),
        ]);
        $svc = app(RentalWorkOrderService::class);
        $svc->notifyVariationRaised($mk(1));
        $svc->notifyVariationRaised($mk(2));

        $this->assertSame(2, $this->alerts('rental_work_order.variation_raised', $this->ownerAgent), 'both extras were alerted - the second is not swallowed');
    }

    // ── C-A3: the queue rows ────────────────────────────────────────────────────────────────────────────────────────────────────

    private function rows(string $type): \Illuminate\Support\Collection
    {
        return app(RentalCommandCentreService::class)->queueItems($this->admin, 'all')->filter(fn ($i) => $i['type'] === $type)->values();
    }

    public function test_a_declined_contractor_job_says_pick_another_contractor_not_set_appointment(): void
    {
        $wo = $this->workOrder();
        $wo->forceFill(['contractor_response' => 'declined', 'contractor_responded_at' => now(), 'contractor_decline_reason' => 'Fully booked'])->save();

        $this->assertCount(1, $this->rows('wo_contractor_declined'));
        $row = $this->rows('wo_contractor_declined')->first();
        $this->assertSame('Pick another contractor', $row['label']);
        $this->assertStringContainsString('Fully booked', $row['detail']);
        $this->assertSame(['rentalWorkOrder' => $wo->id], $row['route_params']);
        $this->assertCount(0, $this->rows('wo_set_appointment'), 'no "Set appointment" row for a job nobody will do');
    }

    public function test_a_quote_first_ordered_work_order_with_an_unselected_quote_has_a_row(): void
    {
        $wo = $this->workOrder();
        $this->assertCount(1, $this->rows('wo_set_appointment'), 'before the quote: the ordinary appointment row');

        $wo->quotes()->create(['agency_id' => $this->agency->id, 'amount' => 3200, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace gutter', 'is_selected' => false]);
        $this->assertCount(1, $this->rows('wo_choose_quote'));
        $this->assertStringContainsString('received from the contractor', $this->rows('wo_choose_quote')->first()['detail']);
        $this->assertCount(0, $this->rows('wo_set_appointment'));
    }

    public function test_a_crew_job_card_the_crew_marked_complete_has_a_signoff_row(): void
    {
        $card = RentalJobCard::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'title' => 'Paint the wall', 'status' => RentalJobCard::STATUS_IN_PROGRESS,
            'scheduled_at' => now()->subDay(), 'created_by_user_id' => $this->admin->id, 'rental_crew_id' => \App\Models\RentalCrew::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Crew A'])->id,
            'worker_signed_off_at' => now(), 'worker_signed_off_by_name' => 'Sipho',
        ]);
        $this->assertCount(1, $this->rows('jc_agent_signoff'));
        $this->assertSame('Crew finished - sign off', $this->rows('jc_agent_signoff')->first()['label']);

        $card->forceFill(['agent_signed_off_at' => now()])->save();
        $this->assertCount(0, $this->rows('jc_agent_signoff'));
        $this->assertCount(1, $this->rows('jc_complete'));
    }

    public function test_the_crew_marking_a_job_complete_alerts_the_agent_in_app(): void
    {
        $card = RentalJobCard::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'title' => 'Paint the wall',
            'status' => RentalJobCard::STATUS_IN_PROGRESS, 'created_by_user_id' => $this->admin->id,
        ]);
        RentalJobCardCrewCompleted::dispatch($card, 'Sipho Dlamini', RentalJobCard::SIGN_OFF_VIA_CREW_LINK);

        $this->assertGreaterThan(0, $this->alerts('rental_job_card.crew_completed', $this->ownerAgent), 'the lease agent is told');
    }

    // ── C-C4: one rule for when the owner hears the work was reported finished ───────────────────────────────────────────

    public function test_the_default_is_the_later_ruling_the_owner_hears_at_the_agents_close(): void
    {
        $this->assertFalse(RentalPortalSetting::notifyLandlordOnCrewCompletionFor($this->agency->id));
        $this->assertFalse(OwnerFinishNotice::tellOwnerWhenReportedFinished($this->agency->id));
    }

    public function test_a_contractors_work_finished_emails_the_owner_only_when_the_agency_chose_to_and_says_so_in_the_history(): void
    {
        $wo = $this->workOrder();
        $notice = app(OwnerFinishNotice::class);

        $notice->contractorReportedFinished($wo, 'Acme Plumbing');
        Mail::assertNothingQueued();
        $this->assertSame(0, $wo->updates()->where('note', 'like', 'Owner emailed%')->count());

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_crew_completion' => true]);
        $notice->contractorReportedFinished($wo->fresh(), 'Acme Plumbing');
        Mail::assertQueued(RentalOwnerWorkReportedMail::class, fn ($m) => $m->hasTo($this->landlord->email));
        $this->assertSame(1, $wo->updates()->where('note', 'like', 'Owner emailed: the work was reported finished%')->count());
    }
}
