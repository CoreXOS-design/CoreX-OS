<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\Deal;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-216 (DR2 · WS-PIPELINE) — the DR1-anchored pipeline overlay.
 *
 * Proves a pipeline template can be attached to a plain DR1 `deals` row (NOT a deals_v2
 * twin): step instances materialise against dr1_deal_id, on_creation steps activate, the
 * AND-gate chain advances as steps complete, the deal's pipeline pointer is stamped, and
 * the audit trail anchors to the DR1 deal — all without the deals_v2 status machinery.
 */
final class Dr1PipelineAttachTest extends TestCase
{
    use RefreshDatabase;

    private Dr1PipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(Dr1PipelineService::class);
    }

    public function test_attaching_a_pipeline_materialises_steps_against_the_dr1_deal(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $template] = $this->makeDealAndTemplate();

        $deal = $this->svc->createPipeline($deal, $template->id, ['from_date' => '2026-03-01']);

        // Four instances, all anchored to the DR1 deal (dr1_deal_id), never to deals_v2.
        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)->get();
        $this->assertCount(4, $steps);
        $this->assertTrue($steps->every(fn ($s) => $s->deal_id === null), 'DR1-anchored: legacy deals_v2 pointer stays null');

        // on_creation step is active with a due date; the after_step ones wait.
        $otp = $steps->firstWhere('name', 'OTP Signed');
        $this->assertSame('active', $otp->status);
        $this->assertSame('2026-03-01', $otp->due_date->format('Y-m-d'), 'on_creation due = from_date + 0d');
        $this->assertSame('not_started', $steps->firstWhere('name', 'Rates Clearance')->status);
        $this->assertSame('not_started', $steps->firstWhere('name', 'Lodgement')->status);

        // The deal's pipeline pointer is stamped (the ONLY deal mutation the overlay makes).
        $deal->refresh();
        $this->assertSame($template->id, $deal->deal_pipeline_template_id);
        $this->assertNotNull($deal->pipeline_started_at);

        // Audit trail anchors to the DR1 deal, not deals_v2.
        $log = DealActivityLog::where('dr1_deal_id', $deal->id)->where('action', 'pipeline_started')->first();
        $this->assertNotNull($log);
        $this->assertNull($log->deal_id);
    }

    public function test_the_and_gate_chain_advances_on_dr1_completion(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $template] = $this->makeDealAndTemplate();
        $deal = $this->svc->createPipeline($deal, $template->id, ['from_date' => '2026-03-01']);

        // Complete OTP → both parallel legs activate; Lodgement stays blocked.
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->svc->completeStep($this->step($deal, 'OTP Signed'));
        $this->assertSame('active', $this->step($deal, 'Rates Clearance')->status);
        $this->assertSame('active', $this->step($deal, 'Electrical COC')->status);
        $this->assertSame('not_started', $this->step($deal, 'Lodgement')->status);

        // RE-ANCHOR (business rule, every step — not just the AND-gate): Rates' due runs
        // from OTP's ACTUAL completion (03-02) + 5d = 03-07, NOT the attach-time
        // projection (deal_date 03-01 + 5 = 03-06). Pre-fix this held the projection.
        $this->assertSame('2026-03-07', $this->step($deal, 'Rates Clearance')->due_date->format('Y-m-d'), 'due = actual OTP completion (03-02) + 5d');

        // Complete only the primary trigger (Rates) — Lodgement waits on its AND-gate dep.
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->svc->completeStep($this->step($deal, 'Rates Clearance'));
        $this->assertSame('not_started', $this->step($deal, 'Lodgement')->status, 'blocked while Electrical COC open');

        // Complete the last blocker later — Lodgement activates, clock anchored to the latest.
        Carbon::setTestNow('2026-03-25 15:00:00');
        $this->svc->completeStep($this->step($deal, 'Electrical COC'));
        $lodgement = $this->step($deal, 'Lodgement');
        $this->assertSame('active', $lodgement->status);
        $this->assertSame('2026-04-01', $lodgement->due_date->format('Y-m-d'), 'due = latest completion (03-25) + 7d');
    }

    /**
     * The re-anchor must NOT trample a genuine agent edit: if an agent set a step's due
     * date inline, it survives activation unchanged (only the SYSTEM projection re-anchors).
     */
    public function test_agent_edited_due_date_survives_activation(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $template] = $this->makeDealAndTemplate();
        $deal = $this->svc->createPipeline($deal, $template->id, ['from_date' => '2026-03-01']);

        // Agent hand-sets Lodgement's due while it is still not_started.
        $this->svc->updateStepDueDate($this->step($deal, 'Lodgement'), '2026-05-01', null);
        $this->assertTrue($this->step($deal, 'Lodgement')->due_date_manual);

        // Drive both blockers to completion (latest 03-25) so Lodgement activates.
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->svc->completeStep($this->step($deal, 'OTP Signed'));
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->svc->completeStep($this->step($deal, 'Rates Clearance'));
        Carbon::setTestNow('2026-03-25 15:00:00');
        $this->svc->completeStep($this->step($deal, 'Electrical COC'));

        $lodgement = $this->step($deal, 'Lodgement');
        $this->assertSame('active', $lodgement->status);
        // Preserved — NOT re-anchored to 03-25 + 7 = 04-01.
        $this->assertSame('2026-05-01', $lodgement->due_date->format('Y-m-d'), 'agent edit is authoritative');
    }

    public function test_double_attach_is_refused(): void
    {
        [$deal, $template] = $this->makeDealAndTemplate();
        $this->svc->createPipeline($deal, $template->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->createPipeline($deal->fresh(), $template->id);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function step(Deal $deal, string $name): DealStepInstance
    {
        return DealStepInstance::where('dr1_deal_id', $deal->id)->where('name', $name)->first()->fresh();
    }

    /** @return array{0:Deal,1:DealPipelineTemplate} */
    private function makeDealAndTemplate(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $deal = Deal::create([
            'agency_id'        => $agencyId,
            'branch_id'        => $agencyId,
            'period'           => '2026-03',
            'deal_date'        => '2026-03-01',
            'property_value'   => 2_150_000,
            'total_commission' => 107_500,
        ]);

        return [$deal, $this->makeTemplate($agencyId, $agent->id)];
    }

    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Fan-in Bond', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        $rows = [
            // [pos, name, is_milestone, completion_type, trigger_type, trigger_name, offset]
            [1, 'OTP Signed',      true,  'date_input',      'on_creation', null,              0],
            [2, 'Rates Clearance', false, 'document_upload', 'after_step',  'OTP Signed',      5],
            [3, 'Electrical COC',  false, 'document_upload', 'after_step',  'OTP Signed',      10],
            [4, 'Lodgement',       true,  'date_input',      'after_step',  'Rates Clearance', 7],
        ];

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r[1]] = DealPipelineStep::create([
                'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
                'position' => $r[0], 'name' => $r[1],
                'is_locked' => false, 'is_milestone' => $r[2],
                'completion_type' => $r[3], 'trigger_type' => $r[4], 'days_offset' => $r[6],
                'rag_amber_days' => 7, 'rag_red_days' => 3,
                'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
            ]);
        }
        foreach ($rows as $r) {
            if ($r[5]) {
                $byName[$r[1]]->update(['trigger_step_id' => $byName[$r[5]]->id]);
            }
        }

        // Lodgement's AND-gate dependency on Electrical COC (beyond its primary trigger, Rates).
        DB::table('deal_pipeline_step_dependencies')->insert([
            'agency_id' => $agencyId,
            'pipeline_step_id' => $byName['Lodgement']->id,
            'depends_on_step_id' => $byName['Electrical COC']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $template;
    }
}
