<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\RentalWorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rentals\Concerns\DrivesRentalLifecycle;
use Tests\TestCase;

/**
 * THE RENTAL LIFE, ROUTE 3 - the internal crew. Same chain up to the owner's decision ("my agent arranges it"); the work order
 * comes with a job card (the crew's paper), a priced quote goes to the owner, the booking mirrors to the tenant's appointment,
 * the crew finishes from its secure link, the tenant confirms, the agent signs the card off and closes it with who-pays. The
 * tenant and the owner never see the job card, a unit cost, a margin or the crew's sign-off name. See RentalLifecycleEndToEndTest.
 */
final class RentalLifecycleInternalCrewTest extends TestCase
{
    use DrivesRentalLifecycle;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildLifecycleWorld();
    }

    public function test_the_internal_crew_route_with_a_job_card_from_listing_to_closed(): void
    {
        $this->driveToActiveLeaseWithInspection();

        $this->ownerDecides(['decision' => 'approve', 'handled_by' => 'agency'], '8.1', 'the owner approves - "my agent arranges it"');

        /** @var RentalWorkOrder|null $wo */
        $wo = $this->step('9.0', 'agent raises the work order - an internal job with its job card; the line says "our maintenance team"', 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.raise-work-order', $this->fault), [
                'assignment_type' => 'internal', 'title' => 'Fix the kitchen tap', 'description' => 'Drips all night',
            ])->assertSessionHasNoErrors();
            $wo = $this->fault->fresh()->workOrder;
            $this->assertNotNull($wo->jobCard, 'the internal route creates the job card with the work order');
            $this->assertSame('Sent to our maintenance team for scheduling', $this->progress('tenant')['current_label']);

            return $wo;
        }, ['8.1']);

        $this->step('9.1', "the job card is made up: crew assigned, a task, priced parts and labour; the quote goes to the owner (over the limit)", 'cc6', function () use ($wo) {
            $this->asStaff();
            $card = $wo->fresh()->jobCard;
            $this->post(route('corex.rental-job-cards.assign-crew', $card), ['rental_crew_id' => $this->crew->id])->assertSessionHasNoErrors();
            $this->post(route('corex.rental-job-cards.tasks.store', $card), ['description' => 'Replace the washer'])->assertSessionHasNoErrors();
            $this->post(route('corex.rental-job-cards.lines.store', $card), ['type' => 'part', 'description' => 'Tap cartridge', 'quantity' => 1, 'unit_price' => 1200, 'unit_cost' => 700])->assertSessionHasNoErrors();
            $this->post(route('corex.rental-job-cards.lines.store', $card), ['type' => 'labour', 'description' => 'Fit cartridge', 'quantity' => 2, 'unit_price' => 300, 'unit_cost' => 150])->assertSessionHasNoErrors();
            $this->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
            $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
            $this->post(route('corex.rental-job-cards.start', $card))->assertSessionHasErrors('rental_job_card'); // not before the owner agrees
        }, ['9.0']);

        $this->step('9.2', 'the owner authorises it on the portal', 'cc6', function () use ($wo) {
            $this->asPortal($this->landlord);
            $row = collect($this->portalGet('/landlord/work-orders')->assertOk()->json('work_orders'))->firstWhere('id', $wo->id);
            $this->assertSame('pending', $row['client']['owner_approval_status']);
            $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'], ['X-Submission-Key' => 'e2e-' . uniqid()])->assertOk();
            $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);
        }, ['9.1']);

        $this->step('9.a', 'the job is scheduled - the booking mirrors to the work order, the tenant is mailed, the line says who is coming', 'cc6', function () use ($wo) {
            $this->asStaff();
            $this->post(route('corex.rental-job-cards.schedule', $wo->fresh()->jobCard), ['scheduled_at' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i')])->assertSessionHasNoErrors();
            \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\Rentals\RentalWorkOrderAppointmentMail::class, fn ($m) => $m->hasTo(self::TENANT_EMAIL));
            $line = $this->progress('tenant');
            $this->assertSame('appointment_set', $line['current']);
            $this->assertStringContainsString('our maintenance team', collect($line['steps'])->firstWhere('key', 'appointment_set')['detail']);
        }, ['9.2']);

        $this->step('9.b', 'the agent starts the job - work in progress', 'cc6', function () use ($wo) {
            $this->asStaff();
            $this->post(route('corex.rental-job-cards.start', $wo->fresh()->jobCard))->assertSessionHasNoErrors();
            $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
            $this->assertSame('in_progress', $this->progress('tenant')['current']);
            $this->assertSame('in_progress', $this->progress('owner')['current']);
        }, ['9.a']);

        $this->completeConfirmAndClose($wo, '9', 'tenant', true);

        $this->step('9.g', 'tenant and owner never saw the job card, a unit cost, a margin or the crew\'s sign-off name', 'cc6', function () {
            $this->assertTenantAndOwnerNeverSeeTheJobCard();
        }, ['9.0']);

        $this->finishLifecycle('route-3-internal-crew');
    }
}
