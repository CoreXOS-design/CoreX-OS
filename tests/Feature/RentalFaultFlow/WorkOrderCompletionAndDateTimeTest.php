<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalWorkOrderAppointmentMail;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Scopes\AgencyScope;
use App\Services\Rentals\RentalOwnerHoldService;
use App\Services\Rentals\RentalWhoPaysService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 24.4 / 24.5 - (1) ONE date-and-time control (a date + a time dropdown), read in the agency's time zone: 14:30 picked is 14:30 on the
 * page, in the tenant's email and on the owner portal; (2) the agent completes the work straight from "go-ahead sent", with what was found + the cause in the same form, and every gate
 * (cost authorised, the hard lock on the final cost, the owner's hold) still applies.
 */
final class WorkOrderCompletionAndDateTimeTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private Contact $tenant;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Complete');
        $this->setting(['completion_requires_photo' => false]);
        $this->ownerLimits(1500.0);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
    }

    private function fault(): RentalFaultReport
    {
        $type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);

        return RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'rental_fault_type_id' => $type->id,
            'reported_by_type' => 'agent_noticed', 'reported_channel' => 'app', 'title' => 'Tap', 'description' => 'drips', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
    }

    /** A job with the cost authorised ($amount, inside the owner's R1,500 limit) and the go-ahead sent: status "ordered", never "in progress". */
    private function goAheadSent(float $amount = 1000.0, bool $fromFault = true): RentalWorkOrder
    {
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->externalWorkOrder(['lease_id' => $this->lease->id]);
        if ($fromFault) {
            $wo->forceFill(['reported_fault_report_id' => $this->fault()->id])->save();
        }
        $quote = $wo->recordQuote(['agency_service_provider_id' => $sup->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $this->authoriseCostOf($wo->fresh());
        $wo->fresh()->assignSupplier($sup->id, null, $this->admin);
        app(RentalWorkOrderService::class)->sendContractorWorkOrder($wo->fresh(), $this->admin);

        return $wo->fresh();
    }

    private function page(RentalWorkOrder $wo): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();
    }

    private function complete(RentalWorkOrder $wo, array $data = [])
    {
        return $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), array_merge(['paid_by' => 'owner', 'cost_amount' => 1000.00], $data));
    }

    private function nextKey(string $html): string
    {
        preg_match('/data-next-step data-next="([a-z_]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    // ── 1. the date-and-time control ─────────────────────────────────────────

    public function test_the_appointment_uses_a_date_and_a_time_dropdown_not_the_browser_date_time_popup(): void
    {
        $html = $this->page($this->goAheadSent());

        $this->assertStringNotContainsString('datetime-local', $html, 'no native date-time popup anywhere on the page');
        $this->assertStringContainsString('data-date-time-field', $html);
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringContainsString('name="appointment_at"', $html, 'still ONE field posted');
        foreach (['07:00', '07:30', '12:00', '17:30', '18:00', 'Other time'] as $o) {
            $this->assertStringContainsString('>' . $o . '</option>', $html, $o);
        }
        $this->assertStringNotContainsString('>18:30</option>', $html);
        $this->assertStringNotContainsString('>06:30</option>', $html);
        $this->assertStringContainsString('data-dt-other', $html, 'a typed HH:MM for any other time');
        $this->assertStringContainsString('Set appointment', $html);
    }

    public function test_every_date_time_input_in_the_work_order_and_fault_screens_uses_the_one_control(): void
    {
        foreach (['rental-work-orders/show', 'rental-work-orders/_cost-decision', 'rental-fault-reports/_owner-hold-and-questions', 'rental-fault-reports/_record-owner-decision', 'rental-fault-reports/_tenant-picture'] as $v) {
            $src = file_get_contents(resource_path("views/corex/{$v}.blade.php"));
            $this->assertStringNotContainsString('datetime-local', $src, $v);
        }
        $this->assertStringContainsString('<x-date-time-field name="told_at"', file_get_contents(resource_path('views/corex/rental-work-orders/_cost-decision.blade.php')));
    }

    public function test_the_control_remembers_a_booked_time_and_offers_other_for_an_odd_one(): void
    {
        $wo = $this->goAheadSent();
        $wo->forceFill(['appointment_at' => Carbon::parse('2026-10-20 14:30', 'Africa/Johannesburg')])->save();
        $html = $this->page($wo);
        $this->assertStringContainsString('t: "14:30"', str_replace(["'", '&quot;'], '"', $html));
        $this->assertStringContainsString('d: "2026-10-20"', str_replace(["'", '&quot;'], '"', $html));

        $wo->forceFill(['appointment_at' => Carbon::parse('2026-10-20 14:10', 'Africa/Johannesburg')])->save();
        $odd = str_replace(["'", '&quot;'], '"', $this->page($wo));
        $this->assertStringContainsString('t: "other"', $odd);
        $this->assertStringContainsString('other: "14:10"', $odd);
    }

    public function test_14_30_picked_is_14_30_on_the_page_in_the_tenants_email_and_on_the_owner_portal(): void
    {
        $wo = $this->goAheadSent();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), ['appointment_at' => '2026-10-20T14:30', 'appointment_note' => 'Gate code 1234'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $wo = $wo->fresh();
        $this->assertSame('2026-10-20 14:30', $wo->appointment_at->setTimezone('Africa/Johannesburg')->format('Y-m-d H:i'), 'stored as the SAST moment that was picked');

        // the agent's page
        $text = $this->visibleText($this->page($wo));
        $this->assertStringContainsString('20 Oct 2026 14:30', $text);
        $this->assertStringNotContainsString('20 Oct 2026 12:30', $text, 'never shifted by two hours');

        // the tenant's email
        $mails = $this->sent(RentalWorkOrderAppointmentMail::class);
        $this->assertNotEmpty($mails, 'the tenant was emailed');
        $this->assertStringContainsString('14:30', end($mails)->when);
        $this->assertStringContainsString('Tuesday 20 October 2026', end($mails)->when);

        // the owner's portal (the API hands the browser an ISO moment with its offset; the portal prints it in the viewer's SA clock)
        $owner = app(RentalWorkOrderClientViewService::class)->payload($wo, RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertStringEndsWith('+02:00', $owner['appointment_at']);
        $this->assertSame('14:30', Carbon::parse($owner['appointment_at'])->setTimezone('Africa/Johannesburg')->format('H:i'));
        $this->assertSame('2026-10-20T14:30:00+02:00', $owner['appointment_at']);
    }

    // ── 2. completing the work straight from "go-ahead sent" ─────────────────

    public function test_once_the_go_ahead_has_gone_the_next_button_is_mark_the_work_as_complete_and_opens_the_form(): void
    {
        $wo = $this->goAheadSent();
        $html = $this->page($wo);

        $this->assertSame('go_ahead_sent', $this->nextKey($html));
        $this->assertMatchesRegularExpression('/data-wo-click="\[data-complete-work-order\]"[^>]*data-next-button|data-next-button[^>]*data-wo-click="\[data-complete-work-order\]"/', $html);
        $this->assertStringContainsString('>Mark the work as complete</a>', $html);
        $this->assertStringContainsString('data-complete-work-order', $html);
        $this->assertStringContainsString('id="complete-work-order-form"', $html);
        $this->assertStringContainsString('id="wo-start-progress-form"', $html, '"Mark in progress" is still there, small, for crews');
        $this->assertStringContainsString('data-complete-findings', $html, 'a job from a fault asks what was found + the cause in the same form');
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status, 'it is "go-ahead sent", not "in progress"');
    }

    public function test_complete_straight_from_go_ahead_sent_with_what_was_found_and_the_cause_in_one_step(): void
    {
        $wo = $this->goAheadSent(1000.0);

        $this->complete($wo, ['found_text' => 'The washer had perished', 'cause_note' => 'Old washer, wear and tear', 'completion_notes' => 'All good'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $wo = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->status, 'no "in progress" step in between');
        $this->assertSame('The washer had perished', $wo->found_text);
        $this->assertTrue(app(RentalWhoPaysService::class)->hasFindings($wo), 'the findings were saved by the same form');
        $this->assertSame(1000.0, (float) $wo->cost_amount);
        $this->assertSame('Completed (was: go-ahead sent)', $wo->updates()->where('to_status', 'completed')->value('note'), 'history says where it was');

        $html = $this->page($wo);
        $this->assertSame('rule_who_pays', $this->nextKey($html));
        $this->assertMatchesRegularExpression('/data-step="in_progress" data-state="done"/', $html, 'the strip ticks "Work in progress" through - not a missing step');
        $this->assertMatchesRegularExpression('/data-step="completed" data-state="done"/', $html);
        $this->assertMatchesRegularExpression('/data-step="who_pays" data-state="current"/', $html);
    }

    public function test_complete_straight_from_go_ahead_sent_without_found_or_cause_still_works_and_who_pays_follows(): void
    {
        $wo = $this->goAheadSent(1000.0);

        $this->complete($wo)->assertSessionHasNoErrors();

        $wo = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->status);
        $this->assertFalse(app(RentalWhoPaysService::class)->hasFindings($wo));
        $this->assertSame('rule_who_pays', $this->nextKey($this->page($wo)));
    }

    public function test_a_bad_cause_stops_before_anything_is_completed(): void
    {
        $wo = $this->goAheadSent(1000.0);

        $this->complete($wo, ['found_text' => 'Something', 'cause_id' => 999999])->assertSessionHasErrors('rental_work_order');

        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
    }

    public function test_a_job_not_from_a_fault_completes_the_same_way_without_the_findings_fields(): void
    {
        $wo = $this->goAheadSent(1000.0, false);
        $this->assertStringNotContainsString('data-complete-findings', $this->page($wo));

        $this->complete($wo)->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    public function test_the_final_cost_hard_lock_still_holds_one_cent_over_goes_to_the_owner_and_the_job_stays_open(): void
    {
        $wo = $this->goAheadSent(1000.0);

        $this->complete($wo, ['cost_amount' => 1000.01])->assertSessionHasErrors('rental_work_order');

        $wo = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status, 'the job stays open');
        $variation = $wo->openVariation();
        $this->assertNotNull($variation, 'the R0.01 went to the owner as a request for the extra');
        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $variation->status);
        $this->assertSame('extra_waiting', $this->nextKey($this->page($wo)));
    }

    public function test_exactly_the_authorised_amount_completes(): void
    {
        $wo = $this->goAheadSent(1000.0);

        $this->complete($wo, ['cost_amount' => 1000.00])->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    public function test_nothing_completes_before_its_cost_is_authorised(): void
    {
        $sup = $this->supplier('Quote Plumbing');
        $wo = $this->externalWorkOrder(['lease_id' => $this->lease->id]);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $sup->id, 'amount' => 1000.0, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);   // selected, inside the limit - but nobody authorised it
        $html = $this->page($wo);
        $this->assertStringNotContainsString('data-complete-work-order', $html);

        $this->complete($wo)->assertSessionHasErrors('rental_work_order');

        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    public function test_a_job_the_owner_put_on_hold_cannot_be_completed(): void
    {
        $wo = $this->goAheadSent(1000.0);
        $owner = $this->landlord;
        app(RentalOwnerHoldService::class)->stop($wo->fresh(), $owner, 'Not happy with this contractor', 'link');

        $this->complete($wo)->assertSessionHasErrors('rental_work_order');

        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertSame('on_hold', $this->nextKey($this->page($wo)));
    }
}
