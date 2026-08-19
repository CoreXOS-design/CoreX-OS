<?php

namespace Tests\Unit;

use App\Http\Controllers\DealV2\DealPipelineStepController;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * AT-229 hotfix — a legitimate step must never be blocked from saving by a legacy/None value
 * in an OPTIONAL trigger enum (Johan hit "the selected negative status trigger is invalid" on
 * a "Bond Approved" step carrying a legacy negative_status_trigger='declined'). The controller
 * coerces any out-of-set optional-trigger value to null/default BEFORE validation.
 */
class PipelineStepTriggerNormalizeTest extends TestCase
{
    private function normalize(array $post): array
    {
        $ctrl = new DealPipelineStepController();
        $req = Request::create('/', 'POST', $post);
        $m = new \ReflectionMethod($ctrl, 'normalizeOptionalTriggers');
        $m->setAccessible(true);
        $m->invoke($ctrl, $req);
        return $req->all();
    }

    public function test_legacy_negative_status_trigger_is_coerced_to_null(): void
    {
        $out = $this->normalize(['negative_status_trigger' => 'declined']);
        $this->assertNull($out['negative_status_trigger'], "legacy 'declined' → None, so the step saves");
    }

    public function test_valid_cancelled_is_kept(): void
    {
        $out = $this->normalize(['negative_status_trigger' => 'cancelled', 'negative_outcome_label' => 'Bond Declined']);
        $this->assertSame('cancelled', $out['negative_status_trigger']);
        $this->assertSame('Bond Declined', $out['negative_outcome_label']);
    }

    public function test_none_negative_clears_the_label(): void
    {
        $out = $this->normalize(['negative_status_trigger' => '', 'negative_outcome_label' => 'stale']);
        $this->assertNull($out['negative_status_trigger']);
        $this->assertNull($out['negative_outcome_label'], 'a label without a negative trigger is meaningless');
    }

    public function test_out_of_set_status_trigger_is_coerced_to_null(): void
    {
        $out = $this->normalize(['status_trigger' => 'lapsed']);
        $this->assertNull($out['status_trigger']);
    }

    public function test_work_order_trigger_completed_is_valid_and_kept(): void
    {
        $out = $this->normalize(['work_order_trigger_point' => 'completed']);
        $this->assertSame('completed', $out['work_order_trigger_point']);
    }

    public function test_bad_work_order_trigger_defaults_to_activated(): void
    {
        $out = $this->normalize(['work_order_trigger_point' => 'whenever']);
        $this->assertSame('activated', $out['work_order_trigger_point']);
    }

    public function test_empty_trigger_step_id_becomes_null(): void
    {
        $out = $this->normalize(['trigger_step_id' => '']);
        $this->assertNull($out['trigger_step_id']);
    }
}
