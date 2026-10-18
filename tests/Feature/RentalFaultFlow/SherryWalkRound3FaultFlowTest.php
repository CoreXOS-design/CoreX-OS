<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\RentalContractorJobMail;
use App\Mail\Rentals\RentalContractorUpdateMail;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 13 Oct 2026 - cc5's walk, the maintenance chain: the contractor hears about their appointment and the owner's answer on extra money,
 * the job email promises photos only when there are some, one money format on the contractor's page / the go-ahead words / the portal,
 * the tenant portal's buttons share one height, and a fault type cannot be added twice.
 */
final class SherryWalkRound3FaultFlowTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->approvalWorld('Round3');
    }

    /** @return array{0: RentalWorkOrder, 1: string} an authorised R850 job with the contractor appointed; the raw link token */
    private function job(): array
    {
        $this->ownerLimits(500.0);
        $supplier = $this->supplier('Acme Plumbing', 'acme@example.invalid');
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now(), 'contractor_response' => 'accepted']);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 850, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 850.0]);
        $wo = $wo->fresh();

        return [$wo, app(RentalSecureAccessTokenService::class)->issueFor($wo, $this->admin)['raw_token']];
    }

    public function test_the_contractor_is_emailed_when_the_appointment_is_set_and_again_when_it_moves(): void
    {
        [$wo] = $this->job();
        $svc = app(RentalWorkOrderService::class);

        $svc->setAppointment($wo->fresh(), now()->addDays(2)->setTime(9, 0), 'Gate code 1234', $this->admin);
        $mails = $this->sent(RentalContractorUpdateMail::class);
        $this->assertCount(1, $mails);
        $html = $mails[0]->render();
        $this->assertSame('Appointment set', $mails[0]->heading);
        $this->assertStringContainsString('The appointment for this job is now', $html);
        $this->assertStringContainsString('09:00', $html);
        $this->assertStringContainsString('Gate code 1234', $html);

        $svc->setAppointment($wo->fresh(), now()->addDays(3)->setTime(14, 30), null, $this->admin);
        $mails = $this->sent(RentalContractorUpdateMail::class);
        $this->assertCount(2, $mails);
        $this->assertSame('Appointment changed', $mails[1]->heading);
        $this->assertStringContainsString('14:30', $mails[1]->render());

        // the same appointment again changes nothing, so says nothing
        $svc->setAppointment($wo->fresh(), now()->addDays(3)->setTime(14, 30), null, $this->admin);
        $this->assertCount(2, $this->sent(RentalContractorUpdateMail::class));

        // 14 Oct 2026: the agent's PRIVATE note is for the contractor - it is in their email (and only theirs; the tenant's mail is built from the other note)
        $svc->setAppointment($wo->fresh(), now()->addDays(3)->setTime(14, 30), null, $this->admin, null, 'Phone the tenant first');
        $mails = $this->sent(RentalContractorUpdateMail::class);
        $this->assertCount(3, $mails);
        $this->assertStringContainsString('Note for you from the agent: Phone the tenant first', $mails[2]->render());
    }

    public function test_a_job_whose_contractor_has_no_email_simply_skips_the_note(): void
    {
        $this->ownerLimits(500.0);
        $supplier = $this->supplier('No Email Plumbing', null);
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now(), 'contractor_response' => 'accepted']);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 300, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);

        $this->assertFalse(app(RentalWorkOrderService::class)->emailContractorUpdate($wo->fresh(), 'Appointment set', 'x', $this->admin));
        $this->assertCount(0, $this->sent(RentalContractorUpdateMail::class));
    }

    public function test_the_contractor_is_told_the_owners_answer_to_their_request_for_extra_money(): void
    {
        foreach (['approve' => ['Extra work approved', 'You may do it'], 'decline' => ['Extra work not approved', 'Please do not do it']] as $decision => [$heading, $words]) {
            [$wo, $raw] = $this->job();
            $this->post(route('rentals.secure-link.extra', $raw), ['extra_amount' => 150, 'reason' => 'Rotten pipe'])->assertSessionHasNoErrors();
            $variation = RentalWorkOrderVariation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
            $before = count($this->sent(RentalContractorUpdateMail::class));

            app(RentalApprovalGateService::class)->recordVariationDecision($variation->fresh(), $decision, ['revision' => 1, 'via' => 'portal', 'note' => 'ok'], ['contact' => $this->landlord]);

            $mails = $this->sent(RentalContractorUpdateMail::class);
            $this->assertCount($before + 1, $mails, $decision);
            $this->assertSame($heading, $mails[$before]->heading);
            $this->assertStringContainsString($words, $mails[$before]->render());
            $this->assertStringContainsString('R150.00', $mails[$before]->render());
        }
    }

    public function test_the_job_email_promises_photos_only_when_there_are_some(): void
    {
        [$wo] = $this->job();
        $make = fn (int $n) => (new RentalContractorJobMail($wo, 'Acme Plumbing', 'https://x.test/l', null, $n, null, null, null, $this->admin))->render();

        $with = $make(2);
        $this->assertStringContainsString('look at the photos', $with);
        $without = $make(0);
        $this->assertStringNotContainsString('look at the photos', $without);
        $this->assertStringContainsString('accept or decline the job', $without);
    }

    public function test_one_money_format_across_the_fault_flow(): void
    {
        $this->assertSame('R1,350.00', (new \ReflectionMethod(\App\Services\Rentals\RentalGoAheadService::class, 'rand'))->invoke(null, 1350.0));

        $shell = (string) file_get_contents(resource_path('views/rentals/portal/shell.blade.php'));
        $detail = (string) file_get_contents(resource_path('views/rentals/portal/_fault-detail.blade.php'));
        $this->assertDoesNotMatchRegularExpression("/'R ' \\+ Number\\([^)]*\\)\\.toFixed\\(2\\)/", $shell, 'the owner portal wrote R 1350.00');
        $this->assertStringNotContainsString("'R ' + Number(faultDetail.arranged.amount", $detail);
        $this->assertStringContainsString("'R' + Number(v.extra_amount).toLocaleString('en-US'", $shell);
    }

    public function test_the_portal_buttons_are_one_height_whatever_their_colour(): void
    {
        $shell = (string) file_get_contents(resource_path('views/rentals/portal/shell.blade.php'));
        $this->assertStringContainsString('.btn { display:inline-block; box-sizing:border-box; width:100%; text-align:center; padding:12px; border-radius:10px; border:1px solid transparent;', $shell);
        $this->assertStringContainsString('.btn-outline { background:transparent; border:1px solid var(--border);', $shell);
    }

    public function test_a_fault_type_cannot_be_added_twice_and_an_archived_name_can_come_back(): void
    {
        $this->actingAs($this->admin);
        $body = ['name' => 'Pool pump is not running', 'category' => 'Pool', 'urgency' => 'routine', 'first_aid_steps' => '1. Check the breaker.'];

        $this->post(route('corex.rental-fault-types.store'), $body)->assertSessionHasNoErrors();
        $this->post(route('corex.rental-fault-types.store'), ['name' => '  pool PUMP is not running '] + $body)
            ->assertSessionHasErrors('name');
        $this->assertSame(1, RentalFaultType::where('agency_id', $this->agency->id)->where('name', 'Pool pump is not running')->count());

        // renaming another type onto it is refused too; saving a type under its own name is fine
        $other = RentalFaultType::create(['agency_id' => $this->agency->id, 'name' => 'Gate motor', 'category' => 'Security', 'urgency' => 'routine', 'is_active' => true, 'is_default' => false, 'sort_order' => 9, 'created_by_user_id' => $this->admin->id]);
        $this->put(route('corex.rental-fault-types.update', $other), ['name' => 'Pool pump is not running', 'urgency' => 'routine'])->assertSessionHasErrors('name');
        $this->put(route('corex.rental-fault-types.update', $other), ['name' => 'Gate motor', 'urgency' => 'urgent'])->assertSessionHasNoErrors();

        // an ARCHIVED one does not block its own name coming back
        $first = RentalFaultType::where('name', 'Pool pump is not running')->firstOrFail();
        $first->delete();
        $this->post(route('corex.rental-fault-types.store'), $body)->assertSessionHasNoErrors();
        $this->assertSame(1, RentalFaultType::where('agency_id', $this->agency->id)->where('name', 'Pool pump is not running')->count());

        // the form shuts itself after the first press
        $this->assertStringContainsString("this.dataset.sent", $this->get(route('corex.rental-fault-types.create'))->assertOk()->getContent());
    }
}
