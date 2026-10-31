<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 31.6 (S6, one click per stage) - ONE "Close": the final cost is prefilled and the press closes the job; on the crew route the
 * agent's sign-off and the completion are the same press. The hard stops stay, as sentences with the fix beside them.
 */
final class OneClickCloseTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('OneClickClose');
    }

    private function reportedFinishedJob(float $quoteAmount = 850.0): RentalWorkOrder
    {
        $this->ownerLimits(5000.0);
        $supplier = $this->supplier('Acme Plumbing', 'acme@example.invalid');
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_IN_PROGRESS, 'ordered_at' => now(), 'contractor_response' => 'accepted']);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => $quoteAmount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $this->authoriseCostOf($wo->fresh());

        return $wo->fresh();
    }

    private function nextHtml(RentalWorkOrder $wo): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
    }

    public function test_the_next_bar_is_one_close_press_with_the_final_cost_prefilled_from_the_authorised_amount(): void
    {
        $this->setting(['completion_requires_photo' => false]);
        $wo = $this->reportedFinishedJob(850.0);
        $html = $this->nextHtml($wo);

        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*rental-work-orders/' . $wo->id . '/complete"[^>]*>.*name="cost_amount" value="850.00".*data-next-button>Close</button>#s', $html);
        $this->assertStringContainsString('Close with details', $html);
    }

    public function test_pressing_close_closes_the_job_with_that_cost(): void
    {
        $this->setting(['completion_requires_photo' => false]);
        $wo = $this->reportedFinishedJob(850.0);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['cost_amount' => '850.00', 'paid_by' => 'owner'])->assertSessionHasNoErrors();

        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $fresh->status);
        $this->assertEquals(850.0, (float) $fresh->cost_amount);
    }

    public function test_a_missing_completed_photo_is_a_sentence_with_a_link_to_the_fix_and_no_close_button(): void
    {
        $this->setting(['completion_requires_photo' => true]);
        $wo = $this->reportedFinishedJob();
        $html = $this->nextHtml($wo);

        $this->assertStringContainsString('data-close-blocker', $html);
        $this->assertStringContainsString('A "completed" photo is needed before this job can be closed.', html_entity_decode($html));
        $this->assertStringContainsString('href="#wo-panel-photos"', $html);
        $this->assertDoesNotMatchRegularExpression('#data-next-button>Close</button>#', $html);
    }

    public function test_a_final_cost_above_the_authorised_amount_offers_to_send_the_extra_to_the_owner_instead_of_closing(): void
    {
        $this->setting(['completion_requires_photo' => false]);
        $wo = $this->reportedFinishedJob(850.0);
        // the contractor's invoice came in higher than what was authorised
        $wo->forceFill(['cost_amount' => 1000.00])->save();
        $html = $this->nextHtml($wo->fresh());

        $this->assertStringContainsString('data-close-blocker', $html);
        $this->assertStringContainsString('is above what was authorised', html_entity_decode($html));
        $this->assertStringContainsString('Send the extra R150.00 to the owner', $html);
        $this->assertDoesNotMatchRegularExpression('#data-next-button>Close</button>#', $html);
    }

    public function test_the_crew_route_closes_with_one_press_that_signs_off_and_completes(): void
    {
        $this->ownerLimits(2000.0);
        [$card, $wo] = $this->internalJob(345);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $card), ['worker_sign_off_name' => 'Sipho'])->assertSessionHasNoErrors();
        $this->assertNull($card->fresh()->agent_signed_off_at);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#action="[^"]*rental-job-cards/' . $card->id . '/close".*data-next-button>Close</button>#s', $html);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.close', $card), ['paid_by' => 'owner'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $fresh = $card->fresh();
        $this->assertNotNull($fresh->agent_signed_off_at, 'signed off in the same press');
        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status, 'the card and its work order close together');
    }

    public function test_closing_a_crew_job_before_the_crew_has_signed_off_is_refused_in_words_and_nothing_is_half_done(): void
    {
        $this->ownerLimits(2000.0);
        [$card] = $this->internalJob(345);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.close', $card), ['paid_by' => 'owner'])->assertSessionHasErrors('rental_job_card');

        $this->assertNull($card->fresh()->agent_signed_off_at, 'the agent sign-off rolled back with the refused close');
    }
}
