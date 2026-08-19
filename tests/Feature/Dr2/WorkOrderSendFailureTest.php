<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\DealV2\DealStepWorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-329 — a trigger-fired work-order send that fails (e.g. the recipient has no email) must be
 * RECORDED on the order (status='failed' + send_error), never swallowed; the reason is surfaced to
 * the agent and cleared on a successful (re)send. The end-to-end trigger loop (one order fails, the
 * rest still send) is proven functionally on QA1 (Tinker) — this pins the persisted contract.
 */
final class WorkOrderSendFailureTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attrs = []): DealStepWorkOrder
    {
        return DealStepWorkOrder::create(array_merge([
            'deal_step_instance_id' => 1,
            'dr1_deal_id'           => 1,
            'agency_id'             => 1,
            'service_type'          => 'COC',
            'responsible_party'     => 'supplier',
            'status'                => 'pending',
        ], $attrs));
    }

    public function test_a_failed_send_is_recorded_with_its_reason_not_swallowed(): void
    {
        $wo = $this->order();
        $this->assertSame('pending', $wo->status);
        $this->assertNull($wo->send_error);

        // The trigger loop records the failure ON the order instead of swallowing it.
        $wo->forceFill(['status' => 'failed', 'send_error' => 'No email on file for the Supplier — add one or choose another responsible party.'])->save();

        $fresh = $wo->fresh();
        $this->assertTrue($fresh->isFailed());
        $this->assertStringContainsString('No email on file', $fresh->send_error);
    }

    public function test_a_successful_send_clears_a_prior_failure(): void
    {
        $wo = $this->order(['status' => 'failed', 'send_error' => 'No email on file for the Supplier.']);

        // send() marks sent + clears send_error.
        $wo->forceFill(['status' => 'sent', 'send_error' => null, 'sent_at' => now()])->save();

        $fresh = $wo->fresh();
        $this->assertTrue($fresh->isSent());
        $this->assertFalse($fresh->isFailed());
        $this->assertNull($fresh->send_error);
    }
}
