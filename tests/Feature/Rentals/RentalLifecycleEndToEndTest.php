<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\RentalWorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rentals\Concerns\DrivesRentalLifecycle;
use Tests\TestCase;

/**
 * THE RENTAL LIFE, ROUTE 1 - the owner's own contractor. One test, one world, the real endpoints, start to finish:
 * rental listing -> application (invite, submit, FICA lifts on submitted) -> review -> approve -> lease (both agents, notice
 * terms) -> e-sign -> signed, active, portal logins -> incoming inspection captured, signed, distributed -> tenant reports a
 * fault -> agents notified, Command Centre "Review fault" -> owner version sent -> owner approves on the Faults screen ("my
 * own contractor") -> work order -> appointment (tenant mailed) -> started -> done -> tenant confirms -> closed with who-pays.
 *
 * It is a chain across lanes (cc3 applications, cc1 leases/notice, cc4 inspections, cc6 faults/work orders/portal): when any
 * lane breaks a link, THIS test goes red and its message is the ledger - which step, whose, and what it blocked
 * (RunsLifecycleSteps). Siblings: RentalLifecycleAgencyContractorTest, RentalLifecycleInternalCrewTest,
 * RentalLifecycleOwnerDeclinesTest, RentalLifecycleScopingTest.
 */
final class RentalLifecycleEndToEndTest extends TestCase
{
    use DrivesRentalLifecycle;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildLifecycleWorld();
    }

    public function test_the_owners_own_contractor_route_from_listing_to_closed(): void
    {
        $this->driveToActiveLeaseWithInspection();

        $this->ownerDecides(['decision' => 'approve', 'handled_by' => 'own', 'contractor_name' => 'Joe the plumber', 'contractor_phone' => '082 111 2222']);

        /** @var RentalWorkOrder|null $wo */
        $wo = $this->step('9.0', "agent raises the work order for the owner's own contractor - ordered at once, no job card", 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.raise-work-order', $this->fault), [
                'assignment_type' => 'owner_contractor', 'title' => 'Fix the kitchen tap', 'description' => 'Drips all night',
                'contractor_name' => 'Joe the plumber', 'contractor_phone' => '082 111 2222',
            ])->assertSessionHasNoErrors();
            $wo = $this->fault->fresh()->workOrder;
            $this->assertNotNull($wo);
            $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status);
            $this->assertNull($wo->jobCard);
            $this->assertSame('sent_to_contractor', $this->progress('tenant')['current']);
            $this->assertStringContainsString("owner's contractor", strtolower($this->progress('tenant')['current_label']));

            return $wo;
        }, ['8.1']);

        $this->appointmentAndStart($wo, "owner's contractor", '9');
        $this->completeConfirmAndClose($wo, '9', 'owner', false);

        $this->finishLifecycle('route-1-owners-own-contractor');
    }
}
