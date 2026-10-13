<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Lease;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.16 (Johan, 14 Oct 2026): the OWNER may set the repair appointment from their portal ONLY when there is
 * no tenant in the property. With a tenant they only see it. Enforced in the service the portal route calls (not just by hiding the control), and the staff page
 * line follows the same condition.
 */
final class OwnerAppointmentTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private const L = '/api/v1/client/rentals/landlord';

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('OwnerAppt');
    }

    /** A job the owner has approved (appointments exist only once approved), on the fixture property. */
    private function approvedJob(): RentalWorkOrder
    {
        $this->ownerLimits(null);
        $supplier = $this->supplier();
        $wo = $this->externalWorkOrder(['agency_service_provider_id' => $supplier->id, 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now()]);
        $quote = $wo->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 800, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], $this->admin);
        $wo->selectQuote($quote, $this->admin);
        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => 'portal', 'evidence_text' => 'ok', 'approved_up_to' => 800.0]);

        return $wo->fresh();
    }

    private function makePropertyEmpty(): void
    {
        Lease::withoutGlobalScopes()->where('property_id', $this->property->id)->update(['status' => Lease::STATUS_EXPIRED]);
    }

    private function makePropertyLet(): void
    {
        Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000,
            'deposit_amount' => 9000, 'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
    }

    private function when(): string
    {
        return now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i');
    }

    public function test_with_a_tenant_in_the_property_the_owner_cannot_set_or_change_the_appointment_but_sees_it(): void
    {
        $wo = $this->approvedJob();
        $this->makePropertyLet();
        // the agent sets it
        app(RentalWorkOrderService::class)->setAppointment($wo->fresh(), now()->addDays(3)->setTime(10, 0), 'Agent arranged it', $this->admin);

        $owner = app(RentalWorkOrderClientViewService::class)->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertTrue($owner['owner_tenant_in_property']);
        $this->assertFalse($owner['owner_can_appoint'], 'the control is hidden');
        $this->assertNotNull($owner['appointment_at'], 'but the owner still SEES the appointment');

        // the server refuses it too (the portal route), with the plain reason
        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->postJson(self::L . "/work-orders/{$wo->id}/appointment", ['appointment_at' => $this->when(), 'note' => 'owner tries'])
            ->assertStatus(422)->assertJsonPath('message', 'Your tenant lives in the property, so your agent arranges the appointment with them. You can see it here once it is set.');
        $this->assertSame('Agent arranged it', $wo->fresh()->appointment_note, 'nothing changed');
    }

    public function test_in_an_empty_property_the_owner_may_set_the_appointment(): void
    {
        $wo = $this->approvedJob();
        $this->makePropertyEmpty();

        $owner = app(RentalWorkOrderClientViewService::class)->payload($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD);
        $this->assertFalse($owner['owner_tenant_in_property']);
        $this->assertTrue($owner['owner_can_appoint']);

        Sanctum::actingAs($this->clientFor($this->landlord), ['client']);
        $this->postJson(self::L . "/work-orders/{$wo->id}/appointment", ['appointment_at' => $this->when(), 'note' => 'Owner booked it'])->assertOk();
        $this->assertSame('Owner booked it', $wo->fresh()->appointment_note);
    }

    public function test_the_agent_can_always_set_it_and_the_staff_line_follows_the_same_condition(): void
    {
        $wo = $this->approvedJob();

        $this->makePropertyLet();
        $page = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-appointment-agent-only', $page);
        $this->assertStringNotContainsString('The owner can also set it from their portal', $page);
        $this->assertTrue(app(RentalWorkOrderService::class)->setAppointment($wo->fresh(), now()->addDays(2)->setTime(9, 0), null, $this->admin));

        $this->makePropertyEmpty();
        $page = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-appointment-owner-too', $page);
        $this->assertStringContainsString('the owner can also set it from their portal', $page);
    }

    public function test_the_owner_portal_page_says_why_when_there_is_a_tenant(): void
    {
        $shell = file_get_contents(base_path('resources/views/rentals/portal/shell.blade.php'));
        $this->assertStringContainsString('data-owner-appointment-tenant', $shell);
        $this->assertStringContainsString('Your tenant lives in the property, so your agent arranges the appointment with them.', $shell);
    }
}
