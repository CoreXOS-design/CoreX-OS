<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Models\Lease;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan's night test, 8-9 Oct 2026 (QA1): the appoint step open where the agent looks; a captured quote reaching the owner (WO 65 had a R4,120
 * quote captured but never selected, so the owner portal showed nothing to approve); the owner's decline with a reason and the agent being
 * told; the owner's progress buttons only when they will work; and a browser's one portal session never being used for the wrong person.
 * rental-work-orders.md §17.35, rental-portal-access.md §27.
 */
final class NightFlowFixesTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private const L = '/api/v1/client/rentals/landlord';

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Night');
    }

    private function approvedFault(): RentalFaultReport
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => 'Power tripping', 'description' => 'Tenant words', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault->saveOwnerVersion(['owner_title' => 'Power keeps tripping', 'owner_description' => 'The power trips.'], $this->admin);
        $fault->fresh()->requestApproval($this->admin);
        $fault->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok']);

        return $fault->fresh();
    }

    private function postQuote(RentalWorkOrder $wo, float $amount, array $extra = [])
    {
        $supplier = $this->supplier('Quote Giver ' . uniqid());

        return $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $wo), array_merge([
            'agency_service_provider_id' => $supplier->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace the breaker',
        ], $extra));
    }

    // ── 1. the appoint step is open at the top of the page ───────────────────

    public function test_an_owner_approved_fault_opens_with_the_appoint_step_above_the_description_and_no_hidden_toggle(): void
    {
        $fault = $this->approvedFault();
        $this->supplier('Ramsgate Electrical');

        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();

        $appoint = strpos($html, 'data-appoint-contractor');
        $description = strpos($html, 'Description (as reported)');
        $this->assertNotFalse($appoint, 'the appoint step is on the page');
        $this->assertLessThan($description, $appoint, 'above the description, where the agent is looking');
        $this->assertStringContainsString('Owner approved', $html);
        $this->assertStringContainsString('Ramsgate Electrical', $html);
        $this->assertDoesNotMatchRegularExpression('/<form id="raise-work-order-form"[^>]*class="[^"]*\bhidden\b/', $html, 'open by default - not behind a toggle');
        $this->assertStringNotContainsString("getElementById('raise-work-order-form').classList.toggle", $html, 'no button that opens a section somewhere else');
        // exactly one appoint form on the page (the early-creation path is for faults the owner has not decided yet)
        $this->assertSame(1, substr_count($html, 'data-raise-work-order'));
    }

    // ── 2. a captured quote reaches the owner ────────────────────────────────

    public function test_a_first_quote_over_the_limit_is_selected_on_capture_and_the_owner_sees_it_with_approve_decline_and_gets_the_signed_mail(): void
    {
        $wo = $this->externalWorkOrder();

        // NOTHING but the quote is posted - no "select" box ticked (that was WO 65's state: captured, never selected, owner never asked)
        $this->postQuote($wo, 4120)->assertSessionHasNoErrors();

        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $fresh->owner_approval_status, 'the quote went through the no-approval limit');
        $this->assertTrue((bool) $fresh->quotes()->first()->is_selected);

        // the owner's mail: the signed link straight to THIS work order, in the owner's view
        $mail = $this->sent(RentalOwnerQuoteMail::class)[0];
        $this->assertSame($this->landlord->email, $this->mailbox->sent[0][0]);
        $html = $mail->render();
        $this->assertStringContainsString('R4,120.00', $html);
        $this->assertMatchesRegularExpression('#/portal\?r=[^"&]+&(?:amp;)?as=owner&(?:amp;)?wo=' . $wo->id . '#', $html);

        // the owner's portal: the work order is in the list with the total and "needs your decision"; the decision list has it too
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $list = $this->getJson(self::L . '/work-orders')->assertOk()->json('work_orders');
        $this->assertCount(1, $list);
        $this->assertSame('pending', $list[0]['client']['owner_approval_status']);
        $this->assertEquals(4120, $list[0]['client']['owner_facing_amount']);
        $this->getJson(self::L . '/decisions')->assertOk()->assertJsonPath('work_orders.0.id', $wo->id)->assertJsonPath('work_orders.0.selected_quote_amount', 4120);
    }

    public function test_a_quote_within_the_limit_is_approved_on_capture_and_a_second_quote_does_not_steal_the_selection(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        $wo = $this->externalWorkOrder();

        $this->postQuote($wo, 300)->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->fresh()->owner_approval_status);
        $this->assertNotNull($wo->fresh()->approved_amount, 'approved on the spot (basis: the no-approval limit)');
        $first = $wo->quotes()->first();
        $this->assertTrue((bool) $first->is_selected);

        // a second quote is only a candidate until the agent chooses it
        $this->postQuote($wo, 450)->assertSessionHasNoErrors();
        $this->assertSame([true, false], $wo->quotes()->orderBy('id')->pluck('is_selected')->map(fn ($v) => (bool) $v)->all());
        $this->postQuote($wo, 460, ['is_selected' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $wo->quotes()->where('is_selected', true)->count(), 'exactly one selected at a time');
    }

    public function test_the_work_order_screen_explains_the_first_quote_rule_and_flags_a_quote_that_is_captured_but_not_selected(): void
    {
        $wo = $this->externalWorkOrder();
        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()
            ->assertSee('selected automatically', false)->assertDontSee('Select this quote now');

        // the state WO 65 was in: a quote exists, none is selected -> said loudly, not silently
        $quote = $this->postQuote($wo, 4120);
        $wo->quotes()->update(['is_selected' => false]);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();
        $this->assertStringContainsString('data-quote-not-selected', $html);
        $this->assertStringContainsString('No quote chosen yet', $html);
        $this->assertStringContainsString('the owner has not been asked', $html);
    }

    // ── the owner's decision: reason, and the agent is told ──────────────────

    public function test_the_owners_decline_carries_the_reason_and_the_agent_is_told_either_way(): void
    {
        $notifier = \Mockery::spy(NotificationDispatcher::class);
        $this->app->instance(NotificationDispatcher::class, $notifier);
        $wo = $this->externalWorkOrder();
        $this->postQuote($wo, 4120);
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);

        $this->postJson(self::L . "/work-orders/{$wo->id}/decision", ['decision' => 'decline', 'note' => 'Too expensive, please get another quote.'])->assertOk();

        $wo = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_DECLINED, $wo->owner_approval_status);
        $this->assertSame('Too expensive, please get another quote.', $wo->approvals()->latest('id')->first()->evidence_text, 'the reason is on the record');
        $notifier->shouldHaveReceived('fire')->withArgs(fn ($user, $key, $subject, $payload) => $key === 'rental_work_order.owner_decided'
            && str_contains($payload['title'], 'declined') && str_contains($payload['body'], 'Too expensive'))->once();
    }

    public function test_the_owners_approval_tells_the_agent_to_send_it_to_the_contractor(): void
    {
        $notifier = \Mockery::spy(NotificationDispatcher::class);
        $this->app->instance(NotificationDispatcher::class, $notifier);
        $wo = $this->externalWorkOrder();
        $this->postQuote($wo, 4120);
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);

        $this->postJson(self::L . "/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertOk();

        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);
        $notifier->shouldHaveReceived('fire')->withArgs(fn ($user, $key, $subject, $payload) => $key === 'rental_work_order.owner_decided'
            && str_contains($payload['title'], 'approved') && str_contains($payload['title'], 'R4,120.00'))->once();
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('notification_event_types')->where('key', 'rental_work_order.owner_decided')->whereNull('deleted_at')->exists(), 'the event is registered by the migration');
    }

    // ── 3. progress buttons only when they work; one browser, one portal session ──

    public function test_the_owner_is_offered_progress_buttons_only_when_pressing_them_would_work(): void
    {
        $svc = app(RentalWorkOrderClientViewService::class);
        $payload = fn (RentalWorkOrder $w) => $svc->payload($w->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);

        $wo = $this->externalWorkOrder();   // reported: not yet given to the contractor
        $p = $payload($wo);
        $this->assertFalse($p['owner_can_start']);
        $this->assertFalse($p['owner_can_finish']);
        $this->assertStringContainsString('sent this to the contractor', $p['owner_progress_note']);

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED])->save();
        $p = $payload($wo);
        $this->assertTrue($p['owner_can_start']);
        $this->assertTrue($p['owner_can_finish']);
        $this->assertNull($p['owner_progress_note']);

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $p = $payload($wo);
        $this->assertFalse($p['owner_can_start'], 'already started');
        $this->assertTrue($p['owner_can_finish']);

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_COMPLETED])->save();
        $p = $payload($wo);
        $this->assertFalse($p['owner_can_start'] || $p['owner_can_finish']);

        // the agency's own team reports through its job card; the tenant's payload never carries any of this
        [$card, $internal] = $this->internalJob();
        $p = $payload($internal);
        $this->assertFalse($p['owner_can_start'] || $p['owner_can_finish']);
        $tenant = $svc->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT);
        $this->assertArrayNotHasKey('owner_can_start', $tenant);
        $this->assertArrayNotHasKey('owner_can_finish', $tenant);
    }

    public function test_the_appointment_shows_to_the_tenant_and_to_the_owner_never_the_price_to_the_tenant(): void
    {
        $fault = $this->approvedFault();
        $supplier = $this->supplier('Ramsgate Electrical');
        $wo = app(\App\Services\Rentals\RentalWorkOrderService::class)->createFromFaultDecision($fault, $this->admin, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id])['work_order'];
        $this->postQuote($wo, 4120);
        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL]);   // appointments exist only once the job is approved
        app(\App\Services\Rentals\RentalWorkOrderService::class)->setAppointment($wo->fresh(), now()->addDays(2)->setTime(9, 0), 'Electrician will hoot at the gate', $this->admin);

        $svc = app(RentalWorkOrderClientViewService::class);
        $tenant = $svc->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT);
        $owner = $svc->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        foreach ([$tenant, $owner] as $view) {
            $this->assertNotNull($view['appointment_at']);
            $this->assertSame('Electrician will hoot at the gate', $view['appointment_note']);
        }
        $this->assertArrayNotHasKey('owner_facing_amount', $tenant, 'the tenant never sees a price');
        $this->assertArrayNotHasKey('owner_approval_status', $tenant);
        $this->assertSame('Appointment set', $owner['stage_label']);

        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->getJson(self::L . '/work-orders')->assertOk()->assertJsonPath('work_orders.0.client.appointment_note', 'Electrician will hoot at the gate');
        $this->getJson(self::L . "/fault-reports/{$fault->id}")->assertOk()->assertJsonPath('fault_report.work_order.appointment_note', 'Electrician will hoot at the gate');
    }

    // ── B. no appointment, no contractor name for the tenant, before the job is approved ──

    public function test_an_appointment_cannot_be_set_before_the_job_is_approved_by_anyone_and_nobody_is_emailed(): void
    {
        $fault = $this->approvedFault();
        $supplier = $this->supplier('Ramsgate Electrical');
        $wo = app(\App\Services\Rentals\RentalWorkOrderService::class)->createFromFaultDecision($fault, $this->admin, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id])['work_order'];
        $at = now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i');

        // office: nothing is priced yet -> refused with the plain reason, and the screen does not even offer the box
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => $at])->assertSessionHasErrors('rental_work_order');
        $this->assertNull($wo->fresh()->appointment_at);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-appointment-locked', $html);
        $this->assertStringNotContainsString('name="appointment_at"', $html);

        // above the limit and waiting for the owner: still refused (office and the owner's own portal)
        $this->postQuote($wo, 4120);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => $at])->assertSessionHasErrors('rental_work_order');
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->postJson(self::L . "/work-orders/{$wo->id}/appointment", ['appointment_at' => $at])->assertStatus(422);
        $this->assertNull($wo->fresh()->appointment_at);
        \Illuminate\Support\Facades\Mail::assertNotSent(\App\Mail\Rentals\RentalWorkOrderAppointmentMail::class);
        \Illuminate\Support\Facades\Mail::assertNotQueued(\App\Mail\Rentals\RentalWorkOrderAppointmentMail::class);

        // the owner approves -> the box appears and the appointment can be set
        $this->postJson(self::L . "/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertOk();
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('name="appointment_at"', $html);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => $at])->assertSessionHasNoErrors();
        $this->assertNotNull($wo->fresh()->appointment_at);
    }

    public function test_an_appointment_within_the_limit_is_allowed_straight_after_the_auto_approval(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        $wo = $this->externalWorkOrder();
        $at = now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i');
        $this->postQuote($wo, 300);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => $at])->assertSessionHasNoErrors();
        $this->assertNotNull($wo->fresh()->appointment_at);
    }

    public function test_the_tenant_sees_neither_the_contractor_nor_an_old_early_appointment_until_the_job_is_approved(): void
    {
        $fault = $this->approvedFault();
        $supplier = $this->supplier('Ramsgate Electrical');
        $wo = app(\App\Services\Rentals\RentalWorkOrderService::class)->createFromFaultDecision($fault, $this->admin, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id])['work_order'];
        // an appointment booked BEFORE the rule existed (WO 65): stored, but not shown to anyone but the office
        $wo->forceFill(['appointment_at' => now()->addDays(2), 'appointment_note' => 'hoot at the gate', 'appointment_set_at' => now()])->save();
        $svc = app(RentalWorkOrderClientViewService::class);

        $tenant = $svc->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT);
        $this->assertNull($tenant['contractor_name']);
        $this->assertNull($tenant['appointment_at']);
        $this->assertNull($tenant['appointment_note']);
        $this->assertSame('Created', $tenant['stage_label'], 'not "Appointment set"');
        $owner = $svc->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertNull($owner['appointment_at']);
        $this->assertFalse($owner['owner_can_appoint']);
        $this->assertSame('Ramsgate Electrical', $owner['contractor_name'], 'the owner is shown who is quoting');
        $this->assertSame('Appointment set', $wo->fresh()->stageLabel('agent'), 'the office still sees what is stored');

        // approved -> both see it
        $this->postQuote($wo, 300);   // 8 Oct threshold R500 default: auto-approved
        $tenant = $svc->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT);
        $this->assertSame('Ramsgate Electrical', $tenant['contractor_name']);
        $this->assertNotNull($tenant['appointment_at']);
        $this->assertTrue($svc->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD)['owner_can_appoint']);
    }

    // ── C. the tenant's progress line follows real state ──

    public function test_the_progress_line_never_ticks_sent_or_appointment_before_approval_and_says_what_it_waits_for(): void
    {
        $fault = $this->approvedFault();
        $supplier = $this->supplier('Ramsgate Electrical');
        $wo = app(\App\Services\Rentals\RentalWorkOrderService::class)->createFromFaultDecision($fault, $this->admin, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $supplier->id])['work_order'];
        $wo->forceFill(['appointment_at' => now()->addDays(2), 'appointment_set_at' => now()])->save();   // the early appointment of WO 65
        $progress = app(\App\Services\Rentals\RentalFaultProgressService::class);
        $line = fn (string $aud) => collect($progress->forFault($fault->fresh(), $aud)['steps'])->keyBy('key');

        $t = $line('tenant');
        $this->assertSame('todo', $t['sent_to_contractor']['state']);
        $this->assertSame('todo', $t['appointment_set']['state'], 'no appointment is "done" before approval');
        $this->assertSame('Waiting for a quote', $t['sent_to_contractor']['detail']);
        $this->assertSame('owner_decided', $progress->forFault($fault->fresh(), 'tenant')['current']);

        $this->postQuote($wo, 4120);   // over the limit -> the owner is asked
        $this->assertSame('Waiting for the owner to approve the quote', $line('tenant')['sent_to_contractor']['detail']);
        $this->assertSame('Waiting for your approval of the quote', $line('owner')['sent_to_contractor']['detail']);
        $this->assertSame('todo', $line('tenant')['appointment_set']['state']);

        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL]);
        $t = $line('tenant');
        $this->assertSame('Being arranged with the contractor', $t['sent_to_contractor']['detail']);
        $this->assertSame('todo', $t['sent_to_contractor']['state'], 'approved is not sent');
        $this->assertSame('done', $t['appointment_set']['state'], 'now the appointment is real');
    }

    public function test_the_tenants_session_pressing_an_owner_button_is_a_plain_refusal_never_an_action(): void
    {
        // (the stale-tab guard itself - X-Portal-Expect / 409 session_changed - is cc1's, rental-portal-access.md section 28)
        // a tenant-session press on an owner endpoint is a plain refusal (what the stale owner tab got) - not data, not an action
        $tenantContact = \App\Models\Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina-' . uniqid() . '@example.invalid']);
        $wo = $this->externalWorkOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);
        Sanctum::actingAs($this->clientFor($tenantContact), ['client']);
        $this->postJson(self::L . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertStatus(404);
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
    }
}
