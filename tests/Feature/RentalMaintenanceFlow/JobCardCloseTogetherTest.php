<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.10.9 / §17.23 defect #1 (reconciliation, 7 Oct 2026): a job card and its work order close
 * TOGETHER or not at all. `RentalJobCardService::complete()` used to swallow the work order's refusal, leaving a closed card on an
 * open work order. Build 3 stopped that for a tenant dispute; Build 2 added two more refusals (extra work still waiting for the owner;
 * a final cost above what the owner approved) and a completion-photo rule exists too — this proves EVERY refusal reaches the person,
 * in plain words, and leaves BOTH records exactly as they were.
 */
final class JobCardCloseTogetherTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private RentalWorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Close Together');
        [$card] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'Owner approved R1,000']);
        $this->card = $card->fresh();
        $this->wo = $wo->fresh();
        // Both sign-offs given — the only thing that can now stop the close is the work order's own refusal.
        $this->card->forceFill(['worker_signed_off_at' => now(), 'worker_sign_off_name' => 'Crew Lead', 'agent_signed_off_at' => now(), 'agent_signed_off_by_user_id' => $this->admin->id])->save();
        $this->travel(5)->seconds();
    }

    private function close()
    {
        return $this->actingAs($this->admin)->from(route('corex.rental-job-cards.show', $this->card))
            ->post(route('corex.rental-job-cards.complete', $this->card));
    }

    private function assertNothingClosed(string $why): void
    {
        $this->assertNotSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status, "card closed although: {$why}");
        $this->assertNull($this->card->fresh()->completed_at, "card has a completed_at although: {$why}");
        $this->assertNotSame(RentalWorkOrder::STATUS_COMPLETED, $this->wo->fresh()->status, "work order closed although: {$why}");
    }

    public function test_with_nothing_in_the_way_both_close_together(): void
    {
        $this->close()->assertSessionHasNoErrors();

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->wo->fresh()->status);
    }

    public function test_extra_work_still_waiting_for_the_owner_stops_both_and_says_so(): void
    {
        // The agency's default tolerance is 0 %, so any extra beyond the approved R1,000 goes to the owner.
        app(RentalJobCardService::class)->addLine($this->card->fresh(), ['type' => 'part', 'description' => 'Extra pipe', 'quantity' => 1, 'unit' => 'each', 'unit_price' => 300], $this->admin);
        $this->assertNotNull($this->wo->fresh()->openVariation(), 'precondition: the extra is waiting for the owner');

        $this->close()->assertSessionHasErrors('rental_job_card');

        $this->assertStringContainsString('still waiting for the owner', session('errors')->first('rental_job_card'));
        $this->assertNothingClosed('an extra is waiting for the owner');

        // …and the person actually SEES it on the card screen they land on.
        $this->actingAs($this->admin)->followingRedirects()->from(route('corex.rental-job-cards.show', $this->card))
            ->post(route('corex.rental-job-cards.complete', $this->card))
            ->assertSee('still waiting for the owner');
    }

    public function test_a_missing_completed_photo_stops_both_and_leaves_the_card_open(): void
    {
        $this->setting(['completion_requires_photo' => true]);

        $this->close()->assertSessionHasErrors('rental_job_card');

        $this->assertStringContainsString('completed" photo is required', session('errors')->first('rental_job_card'));
        $this->assertNothingClosed('the agency requires a completed photo');
    }

    public function test_a_refused_close_can_be_retried_once_the_reason_is_gone(): void
    {
        $this->setting(['completion_requires_photo' => true]);
        $this->close()->assertSessionHasErrors('rental_job_card');

        $this->setting(['completion_requires_photo' => false]);
        $this->close()->assertSessionHasNoErrors();

        $this->assertSame(RentalJobCard::STATUS_COMPLETED, $this->card->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $this->wo->fresh()->status);
    }

    public function test_the_service_itself_refuses_and_rolls_back_for_any_caller(): void
    {
        $this->setting(['completion_requires_photo' => true]);

        try {
            app(RentalJobCardService::class)->complete($this->card->fresh(), $this->admin);
            $this->fail('The service closed the card although the work order refused.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('photo is required', $e->getMessage());
        }

        $this->assertNothingClosed('a service caller (not the web screen) asked to close');
    }

    public function test_the_work_order_screens_complete_form_cannot_close_an_internal_job_behind_its_cards_back(): void
    {
        // An internal job is closed from its job card (which closes both). The work-order Complete form is for outside contractors; a
        // direct post of it for an internal job would close the work order and leave the card open — the same half-closed state.
        $this->actingAs($this->admin)->from(route('corex.rental-work-orders.show', $this->wo))
            ->post(route('corex.rental-work-orders.complete', $this->wo), ['paid_by' => 'owner'])
            ->assertSessionHasErrors('rental_work_order');

        $this->assertNothingClosed('the work order was completed on its own while its job card is open');
    }
}
