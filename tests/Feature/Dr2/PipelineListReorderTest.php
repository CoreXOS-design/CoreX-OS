<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pipeline Dashboard Phase 3 — the LIST view's grab-to-reorder must change DISPLAY position ONLY and
 * NEVER rewire dependencies or dates (decision 4); Edit-dates sets start + end explicitly (decision 2).
 * Fixture: A(0) → B(+5), C(+10) after A (siblings); D(+3) after B, fan-in on C.
 */
final class PipelineListReorderTest extends TestCase
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

    private function steps(): \Illuminate\Support\Collection
    {
        return DealStepInstance::where('dr1_deal_id', $this->deal->id)->orderBy('id')->get();
    }

    private function depsSnapshot(): array
    {
        return DB::table('deal_step_instance_dependencies')
            ->whereIn('deal_step_instance_id', $this->steps()->pluck('id'))
            ->orderBy('deal_step_instance_id')->orderBy('depends_on_step_instance_id')
            ->get()->map(fn ($r) => $r->deal_step_instance_id . '>' . $r->depends_on_step_instance_id)->all();
    }

    public function test_reorder_changes_position_only_never_deps_or_dates(): void
    {
        // Snapshot the scheduling truth BEFORE.
        $before = $this->steps()->mapWithKeys(fn ($s) => [$s->id => [
            'trigger' => $s->trigger_step_instance_id,
            'start'   => $s->planned_start_date?->toDateString(),
            'due'     => $s->due_date?->toDateString(),
        ]])->all();
        $depsBefore = $this->depsSnapshot();

        // Reverse the display order.
        $reversed = $this->steps()->sortByDesc('position')->pluck('id')->values()->all();
        $this->postJson(route('deals-dr2.pipeline.reorder', $this->deal), ['order' => $reversed])
            ->assertOk()->assertJson(['ok' => true, 'count' => 4]);

        // Positions now follow the posted order (1..4).
        foreach ($reversed as $i => $id) {
            $this->assertSame($i + 1, (int) DealStepInstance::find($id)->position, "step $id moved to position " . ($i + 1));
        }

        // ...and NOTHING else moved: every trigger, dependency row, and date is identical.
        foreach ($this->steps() as $s) {
            $this->assertSame($before[$s->id]['trigger'], $s->trigger_step_instance_id, 'trigger unchanged');
            $this->assertSame($before[$s->id]['start'], $s->planned_start_date?->toDateString(), 'planned_start unchanged');
            $this->assertSame($before[$s->id]['due'], $s->due_date?->toDateString(), 'due_date unchanged');
        }
        $this->assertSame($depsBefore, $this->depsSnapshot(), 'dependency graph is untouched by a reorder');
    }

    public function test_reorder_ignores_ids_not_on_this_deal(): void
    {
        $own = $this->steps()->pluck('id')->values()->all();
        $this->postJson(route('deals-dr2.pipeline.reorder', $this->deal), ['order' => array_merge([999999], $own)])
            ->assertOk()->assertJson(['count' => 4]); // the foreign id is dropped, only the 4 own steps reorder
    }

    public function test_edit_dates_sets_start_and_end_and_pins_them(): void
    {
        $c = $this->steps()->firstWhere('name', 'C');
        $depsBefore = $this->depsSnapshot();

        $this->post(route('deals-dr2.pipeline.step.dates', [$this->deal, $c]), [
            'planned_start_date' => '2026-04-01', 'due_date' => '2026-04-15', 'from' => 'list',
        ])->assertRedirect();

        $c->refresh();
        $this->assertSame('2026-04-01', $c->planned_start_date->toDateString());
        $this->assertSame('2026-04-15', $c->due_date->toDateString());
        $this->assertSame(14, $c->duration_days, 'duration = end − start');
        $this->assertTrue($c->planned_start_manual);
        $this->assertTrue($c->due_date_manual);
        $this->assertSame($depsBefore, $this->depsSnapshot(), 'edit-dates never touches dependencies');
    }

    public function test_edit_dates_rejects_end_before_start(): void
    {
        $c = $this->steps()->firstWhere('name', 'C');
        $this->post(route('deals-dr2.pipeline.step.dates', [$this->deal, $c]), [
            'planned_start_date' => '2026-04-15', 'due_date' => '2026-04-01',
        ])->assertSessionHasErrors('due_date');
    }

    public function test_list_view_renders(): void
    {
        $this->withoutVite();
        $this->get(route('deals-dr2.pipeline.list', $this->deal))
            ->assertOk()->assertSee('Pipeline list');
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
                'position' => $r[0], 'name' => $r[1], 'is_locked' => false, 'is_milestone' => $r[5],
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
        DB::table('deal_pipeline_step_dependencies')->insert([
            'agency_id' => $this->agencyId,
            'pipeline_step_id' => $byName['D']->id, 'depends_on_step_id' => $byName['C']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(Dr1PipelineService::class)->createPipeline($deal, $template->id, ['from_date' => '2026-03-01']);
        return $deal->fresh('pipelineSteps');
    }
}
