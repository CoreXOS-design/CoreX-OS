<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\Deal\Dr1PipelineService;
use App\Services\Deal\Pipeline\PipelineRescheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pipeline Dashboard Phase 2 — drag-to-reschedule cascade (spec §4.1).
 * Graph: A(on_creation,0) → B(+5) and C(+10) both after A (siblings); D(+3) after B AND depends on C
 * (fan-in). Anchored at 2026-03-01: A[03-01], B[03-01→03-06], C[03-01→03-11], D[03-06→03-09].
 */
final class PipelineRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private Deal $deal;

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
        $this->deal = $this->dealWithGraph();
    }

    private function step(string $name): DealStepInstance
    {
        return DealStepInstance::where('dr1_deal_id', $this->deal->id)->where('name', $name)->firstOrFail()->fresh();
    }

    public function test_drag_cascades_fan_in_dependents_and_leaves_siblings(): void
    {
        $svc = app(PipelineRescheduleService::class);

        // Sanity: initial spans.
        $this->assertSame('2026-03-01', $this->step('C')->planned_start_date->toDateString());
        $this->assertSame('2026-03-11', $this->step('C')->due_date->toDateString());

        // PREVIEW: drag C forward 7 days → 03-08 (end 03-18). D (fan-in on C) cascades; B (sibling) stays.
        $preview = $svc->reschedule($this->step('C'), \Carbon\Carbon::parse('2026-03-08'), false);
        $this->assertSame(7, $preview['delta_days']);
        $movedNames = collect($preview['moved'])->pluck('name')->all();
        $this->assertContains('C', $movedNames);
        $this->assertContains('D', $movedNames);
        $this->assertNotContains('B', $movedNames, 'a concurrent sibling must not move');
        $this->assertNotContains('A', $movedNames);
        $this->assertEmpty($preview['held']);
        // Nothing persisted on a dry-run.
        $this->assertSame('2026-03-01', $this->step('C')->planned_start_date->toDateString());

        // COMMIT.
        $result = $svc->reschedule($this->step('C'), \Carbon\Carbon::parse('2026-03-08'), true, $this->admin->id);
        $this->assertTrue($result['committed']);

        $c = $this->step('C');
        $this->assertSame('2026-03-08', $c->planned_start_date->toDateString());
        $this->assertSame('2026-03-18', $c->due_date->toDateString(), 'duration preserved (10d)');
        $this->assertTrue($c->planned_start_manual, 'the dragged step is pinned');

        // D re-anchored to max(predecessor end) = C.end 03-18 (fan-in), NOT B.end 03-06.
        $d = $this->step('D');
        $this->assertSame('2026-03-18', $d->planned_start_date->toDateString());
        $this->assertSame('2026-03-21', $d->due_date->toDateString(), 'D duration preserved (3d)');
        $this->assertFalse($d->planned_start_manual, 'cascaded steps stay system-derived');

        // B (sibling of C) untouched.
        $b = $this->step('B');
        $this->assertSame('2026-03-01', $b->planned_start_date->toDateString());
        $this->assertSame('2026-03-06', $b->due_date->toDateString());

        // Audited.
        $this->assertDatabaseHas('deal_activity_log', [
            'dr1_deal_id' => $this->deal->id, 'deal_step_instance_id' => $c->id, 'action' => 'step_rescheduled',
        ]);
    }

    public function test_a_manually_dated_dependent_is_held(): void
    {
        // Pin D by hand → dragging C must HOLD D (not move it).
        $this->step('D')->forceFill(['due_date_manual' => true])->save();

        $preview = app(PipelineRescheduleService::class)->reschedule($this->step('C'), \Carbon\Carbon::parse('2026-03-08'), false);

        $this->assertContains('C', collect($preview['moved'])->pluck('name')->all());
        $this->assertNotContains('D', collect($preview['moved'])->pluck('name')->all());
        $this->assertContains('D', collect($preview['held'])->pluck('name')->all());
    }

    public function test_a_completed_dependent_is_frozen(): void
    {
        $this->step('D')->forceFill(['status' => 'completed'])->save();

        $preview = app(PipelineRescheduleService::class)->reschedule($this->step('C'), \Carbon\Carbon::parse('2026-03-08'), false);

        $this->assertNotContains('D', collect($preview['moved'])->pluck('name')->all());
        $this->assertSame('completed', collect($preview['held'])->firstWhere('name', 'D')['reason']);
    }

    public function test_timeline_view_renders_for_a_deal(): void
    {
        $this->withoutVite();
        $this->get(route('deals-dr2.pipeline.timeline', $this->deal))
            ->assertOk()
            ->assertSee('Pipeline timeline');
    }

    private function dealWithGraph(): Deal
    {
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'property_value' => 2_150_000, 'total_commission' => 107_500,
            'buyer_name' => 'Thandi Mkhize', 'accepted_status' => 'P',
        ]);

        $template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
        // pos, name, trigger_type, trigger-after, offset, milestone
        $rows = [
            [1, 'A', 'on_creation', null, 0, true],
            [2, 'B', 'after_step',  'A',  5, false],
            [3, 'C', 'after_step',  'A', 10, false],
            [4, 'D', 'after_step',  'B',  3, false],
        ];
        $byName = [];
        foreach ($rows as $r) {
            $byName[$r[1]] = DealPipelineStep::create([
                'pipeline_template_id' => $template->id, 'agency_id' => $this->agencyId,
                'position' => $r[0], 'name' => $r[1],
                'is_locked' => false, 'is_milestone' => $r[5],
                'completion_type' => 'date_input', 'trigger_type' => $r[2], 'days_offset' => $r[4],
                'rag_amber_days' => 7, 'rag_red_days' => 3,
                'notify_agent' => false, 'notify_bm' => false, 'notify_admin' => false,
            ]);
        }
        foreach ($rows as $r) {
            if ($r[3]) {
                $byName[$r[1]]->update(['trigger_step_id' => $byName[$r[3]]->id]);
            }
        }
        // Fan-in: D also depends on C (template dependency → copied to instance deps by createPipeline).
        DB::table('deal_pipeline_step_dependencies')->insert([
            'agency_id' => $this->agencyId,
            'pipeline_step_id' => $byName['D']->id,
            'depends_on_step_id' => $byName['C']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(Dr1PipelineService::class)->createPipeline($deal, $template->id, ['from_date' => '2026-03-01']);
        return $deal->fresh('pipelineSteps');
    }
}
