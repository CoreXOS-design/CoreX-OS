<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Mail\Rentals\RentalContractorWorkOrderMail;
use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rentals\Concerns\DrivesRentalLifecycle;
use Tests\TestCase;

/**
 * THE RENTAL LIFE, ROUTE 2 - the agency's contractor, with a quote and the owner's authorisation. Same chain as route 1 up to
 * the owner's decision (listing -> application -> lease -> signed -> inspection -> fault); the owner then picks a contractor
 * from the agency's list, the agent raises the work order, captures and selects a quote that is over the no-approval limit, the
 * owner authorises it, the agent sends the work order to the contractor, and the job runs appointment -> started -> done ->
 * tenant confirms -> closed with who-pays. The tenant never sees a price. See RentalLifecycleEndToEndTest for the ledger.
 */
final class RentalLifecycleAgencyContractorTest extends TestCase
{
    use DrivesRentalLifecycle;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildLifecycleWorld();
    }

    public function test_the_agency_contractor_route_with_quote_and_authorisation_from_listing_to_closed(): void
    {
        $this->driveToActiveLeaseWithInspection();
        $supplier = $this->makeSupplierOnTheOwnersList();

        $this->ownerDecides(['decision' => 'approve', 'handled_by' => 'list', 'agency_service_provider_id' => $supplier->id], '8.1', 'the owner approves and picks the agency\'s contractor from the list');

        /** @var RentalWorkOrder|null $wo */
        $wo = $this->step('9.0', 'agent raises the work order for the contractor - waiting for a quote and the owner, not yet sent', 'cc6', function () use ($supplier) {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.raise-work-order', $this->fault), [
                'assignment_type' => 'outside_supplier', 'title' => 'Fix the kitchen tap', 'description' => 'Drips all night', 'agency_service_provider_id' => $supplier->id,
            ])->assertSessionHasNoErrors();
            $wo = $this->fault->fresh()->workOrder;
            $this->assertSame(RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, $wo->assignment_type);
            $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->status);
            $this->assertSame('owner_decided', $this->progress('tenant')['current'], 'not "sent to contractor" until it really is');

            return $wo;
        }, ['8.1']);

        $this->step('9.1', 'a R1,500 quote is captured and selected - over the limit, so the owner is asked (owner quote mail)', 'cc6', function () use ($wo, $supplier) {
            $this->asStaff();
            $this->post(route('corex.rental-work-orders.quotes.store', $wo), [
                'agency_service_provider_id' => $supplier->id, 'amount' => 1500, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace the tap',
            ])->assertSessionHasNoErrors();
            $quote = RentalWorkOrderQuote::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
            $this->post(route('corex.rental-work-orders.quotes.select', [$wo, $quote]))->assertSessionHasNoErrors();
            $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
            $this->assertCount(1, $this->mailer->sentOf(RentalOwnerQuoteMail::class));
        }, ['9.0']);

        $this->step('9.2', 'the owner sees the quote waiting; the tenant sees no price anywhere', 'cc6', function () use ($wo) {
            $this->asPortal($this->landlord);
            $row = collect($this->portalGet('/landlord/work-orders')->assertOk()->json('work_orders'))->firstWhere('id', $wo->id);
            $this->assertSame('pending', $row['client']['owner_approval_status']);
            $this->asPortal($this->tenant);
            foreach (['/work-orders', "/fault-reports/{$this->fault->id}", '/fault-reports'] as $uri) {
                $body = $this->portalGet($uri)->assertOk()->getContent();
                $this->assertStringNotContainsString('1500', $body);
                $this->assertStringNotContainsString('1,500', $body);
            }
        }, ['9.1']);

        $this->step('9.3', 'the work order cannot be sent to the contractor before the owner authorises it', 'cc6', function () use ($wo, $supplier) {
            $this->asStaff();
            $this->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])->assertSessionHasErrors('rental_work_order');
            $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->fresh()->status);
        }, ['9.1']);

        $this->step('9.4', 'the owner authorises the quote on the portal', 'cc6', function () use ($wo) {
            $this->asPortal($this->landlord);
            $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'], ['X-Submission-Key' => 'e2e-' . uniqid()])->assertOk();
            $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);
        }, ['9.1']);

        $this->step('9.5', 'the agent sends the work order to the contractor - ordered, contractor mailed, line moves', 'cc6', function () use ($wo, $supplier) {
            $this->asStaff();
            $this->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])->assertSessionHasNoErrors();
            $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
            $this->assertSame('sent_to_contractor', $this->progress('tenant')['current']);
            $this->assertSame('sent_to_contractor', $this->progress('owner')['current']);
            $mail = $this->mailer->sentOf(RentalContractorWorkOrderMail::class);
            $this->assertCount(1, $mail);
            $this->assertSame($supplier->email, $mail[0][0]);
        }, ['9.4']);

        $this->appointmentAndStart($wo, 'Ramsgate Plumbing', '9', '9.5');
        $this->completeConfirmAndClose($wo, '9', 'deposit_deduction', false);

        $this->finishLifecycle('route-2-agency-contractor');
    }
}
