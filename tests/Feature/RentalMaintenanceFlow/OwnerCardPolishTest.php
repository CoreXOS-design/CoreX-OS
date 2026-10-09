<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Services\Rentals\RentalCommandCentreService;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan's look at the OWNER portal on WO 65, 9 Oct 2026 (rental-work-orders.md §17.37): P1 money formatted once, P2 what the quote covers,
 * P3 owner wording for the early stages, P4 the owner's buttons / appointment box fit the stage - and the owner reporting the work finished
 * closes nothing and raises ONE "Reported finished - check and close" row for the agent.
 */
final class OwnerCardPolishTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private const L = '/api/v1/client/rentals/landlord';

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('OwnerPolish');
        Storage::fake('local');
    }

    private function ownerPayload(RentalWorkOrder $wo): array
    {
        return app(RentalWorkOrderClientViewService::class)->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
    }

    private function quote(RentalWorkOrder $wo, float $amount, array $extra = []): RentalWorkOrderQuote
    {
        $supplier = $this->supplier('Quote Giver ' . uniqid());
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $wo), array_merge([
            'agency_service_provider_id' => $supplier->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace the breaker and test the circuit',
        ], $extra))->assertSessionHasNoErrors();

        return RentalWorkOrderQuote::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->latest('id')->firstOrFail();
    }

    public function test_p1_the_decision_card_shows_the_amount_once_and_formatted_like_everywhere_else(): void
    {
        $src = file_get_contents(resource_path('views/rentals/portal/shell.blade.php'));

        $this->assertStringContainsString("money(w.owner_facing_amount)", $src);
        $this->assertStringContainsString("'R' + Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })", $src);
        // the plain "Amount" line is hidden while the quote is waiting on the owner (the decision block shows it once)
        $this->assertStringContainsString("w.owner_approval_status !== 'pending'\" x-text=\"'Amount: ' + money(", $src);
        $this->assertStringNotContainsString("'R ' + (w.owner_facing_amount ?? 0)", $src);
        $this->assertStringNotContainsString("'Amount: R ' + w.owner_facing_amount", $src);
    }

    public function test_p2_the_owner_gets_the_details_text_and_a_link_to_the_quote_document(): void
    {
        $wo = $this->externalWorkOrder();
        $q = $this->quote($wo, 4120, ['document' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')]);
        $p = $this->ownerPayload($wo);

        $this->assertSame('Replace the breaker and test the circuit', $p['quote']['details']);
        $this->assertNotNull($p['quote']['document_url']);
        $this->assertSame(4120.0, (float) $p['owner_facing_amount']);

        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->get($p['quote']['document_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertNotNull($q);
    }

    public function test_p2_with_an_agency_fee_the_owner_sees_only_the_total_they_pay_and_not_the_contractors_document(): void
    {
        $this->setting(['external_quote_markup_type' => 'percent', 'external_quote_markup_value' => 10]);
        $wo = $this->externalWorkOrder();
        $this->quote($wo, 4000, ['document' => UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf')]);
        $p = $this->ownerPayload($wo);

        $this->assertSame(4400.0, (float) $p['owner_facing_amount'], 'the owner is asked to approve the total they pay (contractor R4,000 + 10% fee)');
        $this->assertNull($p['quote']['document_url'], 'the contractor\'s document shows the contractor\'s own total - never opened to the owner when a fee sits on top');
        $this->assertSame('Replace the breaker and test the circuit', $p['quote']['details']);
        $this->assertStringNotContainsString('4000', json_encode($p));

        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->getJson(self::L . "/work-orders/{$wo->id}/quote-file")->assertStatus(404);
    }

    public function test_p3_the_owner_never_reads_a_bare_created(): void
    {
        $view = app(RentalWorkOrderClientViewService::class);
        $wo = $this->externalWorkOrder();   // reported, no quote chosen yet
        $this->assertSame('Waiting for a quote', $view->stageLabel($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD));
        $this->assertSame('Created', $view->stageLabel($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT), 'the tenant keeps the plain stage');

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED])->save();
        $this->assertSame('With the contractor', $view->stageLabel($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD));
    }

    public function test_p4_progress_buttons_and_the_appointment_box_fit_the_stage(): void
    {
        $wo = $this->externalWorkOrder(['status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED, 'approved_amount' => 4120]);
        $p = $this->ownerPayload($wo);
        $this->assertTrue($p['owner_can_start']);
        $this->assertTrue($p['owner_can_appoint'], 'before the work starts the owner may set the appointment');
        $this->assertFalse($p['owner_work_started']);

        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $p = $this->ownerPayload($wo);
        $this->assertFalse($p['owner_can_start']);
        $this->assertTrue($p['owner_can_finish']);
        $this->assertFalse($p['owner_can_appoint'], 'once work has started there is no appointment to edit');
        $this->assertTrue($p['owner_work_started']);

        $src = file_get_contents(resource_path('views/rentals/portal/shell.blade.php'));
        $this->assertStringContainsString(":class=\"w.owner_can_start ? 'btn-outline' : 'btn-ok'\"", $src, 'only the NEXT step is the big green button');
    }

    public function test_the_owner_reporting_finished_closes_nothing_and_raises_one_check_and_close_row(): void
    {
        $wo = $this->externalWorkOrder(['status' => RentalWorkOrder::STATUS_ORDERED, 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED, 'approved_amount' => 4120, 'assignment_type' => RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, 'contractor_name' => 'Bob']);
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->postJson(self::L . "/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertOk();
        $this->postJson(self::L . "/work-orders/{$wo->id}/progress", ['action' => 'finished', 'note' => 'All done'])->assertOk();

        $wo = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->status, 'nothing is closed');
        $p = $this->ownerPayload($wo);
        $this->assertSame('reported_finished', $p['stage']);
        $this->assertSame('Reported finished', $p['stage_label']);
        $this->assertStringStartsWith('Reported finished by you on ', $p['reported_finished']['text']);
        $this->assertFalse($p['owner_can_start'] || $p['owner_can_finish']);
        $this->assertFalse($p['owner_can_appoint']);

        $rows = app(RentalCommandCentreService::class)->queueItems($this->admin, 'all')->filter(fn ($i) => ($i['route_params']['rentalWorkOrder'] ?? null) === $wo->id);
        $this->assertCount(1, $rows, 'exactly one row');
        $this->assertSame('Reported finished - check and close', $rows->first()['label']);
        $this->assertStringContainsString('reported finished by the owner', $rows->first()['detail']);
        $this->assertSame('Reported finished - check and close', app(RentalWorkOrderClientViewService::class)->stageLabel($wo, 'agent'));

        // the agent's Complete then closes the work order
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 4120])->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
        $this->assertCount(0, app(RentalCommandCentreService::class)->queueItems($this->admin, 'all')->filter(fn ($i) => ($i['route_params']['rentalWorkOrder'] ?? null) === $wo->id));
    }

    public function test_l1_no_unanswered_tenant_check_block_on_the_owner_or_tenant_card(): void
    {
        $src = file_get_contents(resource_path('views/rentals/portal/shell.blade.php'));

        $this->assertSame(2, substr_count($src, "w.rounds.filter(x => x.outcome !== 'awaiting_tenant')"), 'owner and tenant cards list ANSWERED checks only');
        $this->assertStringContainsString('Is it fixed?', $src);
        $this->assertStringNotContainsString('Waiting for the tenant', file_get_contents(app_path('Services/Rentals/RentalWorkOrderClientViewService.php')));
    }

    public function test_l2_the_agent_screen_of_a_reported_finished_job_says_what_to_do_and_hides_appointment_and_quote_capture(): void
    {
        $wo = $this->externalWorkOrder(['status' => RentalWorkOrder::STATUS_IN_PROGRESS, 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED, 'approved_amount' => 4120]);
        $before = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('id="appointment-card"', $before);
        $this->assertStringContainsString('Capture quote', $before);

        app(RentalCompletionService::class)->recordContractorDone($wo, ['reported_via' => 'phone', 'note' => 'Done'], $this->admin);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-reported-finished-step', $html);
        $this->assertStringContainsString('reported the work finished on', $html);
        $this->assertStringContainsString('Check it and press <strong>Complete</strong>', $html);
        $this->assertStringNotContainsString('id="appointment-card"', $html, 'no appointment change once the work is reported finished');
        $this->assertStringNotContainsString('Capture quote', $html, 'no quote capture once the work is reported finished');
        $this->assertStringContainsString('data-complete-work-order', $html, 'Complete stays the primary button');
        $this->assertStringContainsString('<details', $html);
    }
}
