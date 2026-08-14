<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\DealV2\DealDateCascade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan's production-line rule: a date change re-anchors downstream steps, same as
 * complete/reopen/N-A already do (PipelineController::reopenStep() et al). The Timeline's
 * right-edge resize AND its 📅 Set-dates modal both post to the SAME route (postDates()/
 * dr2tlSaveDates() in pipeline-timeline.blade.php → `pipeline.step.dates` →
 * PipelineListController::editDates()), so proving the cascade at that route proves both
 * interactions. Fixture is a NEW-MODEL deal (condition_key present) — DealDateCascade::
 * recompute() is a deliberate no-op on old-model deals (see
 * Dr2CascadeConvergenceTest::test_old_model_deal_is_untouched()); PipelineListReorderTest's
 * own editDates() coverage uses an old-model deal and stays a no-op cascade-wise, unaffected
 * by this change.
 * Graph: OTP (Deal Signed, completed 03-01) → A (Bond Application, condition_key=bond, +10 →
 *        03-11) → B (Bond Approved, condition_key=bond, follows A, +5 → 03-16).
 */
final class PipelineTimelineEdgeResizeCascadeTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private Deal $deal;
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        Carbon::setTestNow('2026-03-01 09:00:00');

        $this->deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'property_value' => 2_150_000, 'total_commission' => 107_500,
            'buyer_name' => 'Thandi Mkhize', 'accepted_status' => 'P',
        ]);

        $this->ids = $this->buildGraph();
        app(DealDateCascade::class)->recompute($this->deal->fresh()); // baseline dues before the resize
    }

    public function test_editing_a_steps_due_date_recomputes_the_downstream_dependent(): void
    {
        $a = DealStepInstance::find($this->ids['A']);
        $b = DealStepInstance::find($this->ids['B']);

        // Baseline: A = OTP actual (03-01) + 10 = 03-11; B follows A, +5 = 03-16.
        $this->assertSame('2026-03-11', $a->due_date->toDateString());
        $this->assertSame('2026-03-16', $b->due_date->toDateString());
        $this->assertFalse($a->due_date_manual);

        // Right-edge resize on A: drag its end out to 03-20 — same route the Timeline's
        // edge-resize handle and its Set-dates modal both post to.
        $this->post(route('deals-dr2.pipeline.step.dates', [$this->deal, $a]), [
            'planned_start_date' => '2026-03-05', 'due_date' => '2026-03-20',
        ])->assertRedirect();

        $a->refresh();
        $b->refresh();

        // A is pinned to exactly what was resized to — recompute() never clobbers the just-edited step.
        $this->assertSame('2026-03-20', $a->due_date->toDateString());
        $this->assertTrue($a->due_date_manual, 'the resized step stays pinned');

        // B (follows A) re-anchors to A's NEW due date + its own offset — production-line rule.
        $this->assertSame('2026-03-25', $b->due_date->toDateString(), 'downstream dependent re-anchors to the resized due date');
        $this->assertFalse($b->due_date_manual, 'the cascaded successor stays system-derived');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{OTP:int,A:int,B:int} */
    private function buildGraph(): array
    {
        $otp = $this->makeStep('Deal Signed', null, 0, ['status' => 'completed', 'actual_date' => '2026-03-01']);
        $a   = $this->makeStep('Bond Application', $otp->id, 10, ['condition_key' => 'bond']);
        $b   = $this->makeStep('Bond Approved', $a->id, 5, ['condition_key' => 'bond']);

        return ['OTP' => $otp->id, 'A' => $a->id, 'B' => $b->id];
    }

    private function makeStep(string $name, ?int $follows, int $offset, array $extra): DealStepInstance
    {
        static $pos = 0;
        $pos += 10;

        return DealStepInstance::create(array_merge([
            'deal_id'                  => null,
            'dr1_deal_id'              => $this->deal->id,
            'agency_id'                => $this->agencyId,
            'pipeline_step_id'         => null,
            'name'                     => $name,
            'position'                 => $pos,
            'is_locked'                => false,
            'is_milestone'             => false,
            'is_custom'                => false,
            'is_suspensive'            => false,
            'is_grant_marker'          => false,
            'condition_key'            => null,
            'completion_type'          => 'manual_tick',
            'status'                   => 'not_started',
            'trigger_type'             => $follows ? 'after_step' : 'on_creation',
            'trigger_step_instance_id' => $follows,
            'days_offset'              => $offset,
            'rag_green_days'           => 14,
            'rag_amber_days'           => 7,
            'rag_red_days'             => 3,
            'current_rag'              => 'grey',
            'notify_agent'             => true,
            'notify_bm'                => true,
            'notify_admin'             => false,
            'approval_status'          => 'not_required',
        ], $extra));
    }
}
