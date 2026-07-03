<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS7 — two-threshold RAG (Johan). A pipeline step is configured with
 * amber_days + red_days ONLY; green is derived ("not yet amber"), no third
 * threshold. The DR2 setup UI + controller no longer require/accept
 * rag_green_days; the column is retained (the calendar tile resolver still
 * reads it, reconciled to derived-green when AT-164 lands) and new steps take
 * its default.
 */
final class DealPipelineThresholdTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private DealPipelineTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    public function test_step_saves_without_a_green_threshold_and_defaults_the_column(): void
    {
        $resp = $this->actingAs($this->admin)->postJson(
            route('deals-v2.pipeline.steps.store', $this->template),
            [
                'name' => 'Bond Approval',
                'completion_type' => 'date_input',
                'trigger_type' => 'on_creation',
                'days_offset' => 21,
                'rag_amber_days' => 7,
                'rag_red_days' => 3,
                // NOTE: no rag_green_days sent — must no longer be required.
            ]
        );

        $resp->assertOk();
        $step = DealPipelineStep::where('pipeline_template_id', $this->template->id)->firstOrFail();
        $this->assertSame(7, $step->rag_amber_days);
        $this->assertSame(3, $step->rag_red_days);
        // Column retained + defaulted (calendar reads it); not null, not required.
        $this->assertNotNull($step->rag_green_days, 'rag_green_days column keeps its default');
    }

    public function test_board_rag_is_two_threshold_regardless_of_green_days(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        // Build a step due in 20 days with a deliberately silly green_days=999:
        // the board RAG must ignore it entirely (green until amber_days).
        $step = new DealStepInstance([
            'rag_green_days' => 999, 'rag_amber_days' => 7, 'rag_red_days' => 3,
            'status' => 'active', 'due_date' => '2026-03-21',
        ]);

        $svc = app(DealPipelineService::class);
        // 20 days out → green (well beyond amber), NOT dictated by green_days.
        $this->assertSame('green', $svc->calculateRag($step));
        // 6 days out → amber.
        Carbon::setTestNow('2026-03-15 09:00:00');
        $this->assertSame('amber', $svc->calculateRag($step));
        // 2 days out → red.
        Carbon::setTestNow('2026-03-19 09:00:00');
        $this->assertSame('red', $svc->calculateRag($step));
        // Past due → overdue.
        Carbon::setTestNow('2026-03-25 09:00:00');
        $this->assertSame('overdue', $svc->calculateRag($step));
    }
}
