<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepComment;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\PipelineUserPreference;
use App\Models\User;
use App\Services\Deal\Dr1PipelineService;
use App\Services\Deal\Pipeline\PipelineEventService;
use App\Support\Pipeline\PipelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pipeline Dashboard Phase 1 — the shared foundation contracts:
 *  - every step gets a sane planned_start span (planned_start = due − days_offset; milestone = point);
 *  - the event normalizer surfaces step comments as PipelineEvents (scope=step, author, body, date);
 *  - the per-agent view preference round-trips.
 * Spec: .ai/specs/pipeline-dashboard.md §7
 */
final class PipelineFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Dr1PipelineService $svc;
    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(Dr1PipelineService::class);
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
    }

    public function test_create_pipeline_populates_a_sane_planned_start_span_per_step(): void
    {
        $deal = $this->dealWithPipeline();

        foreach ($deal->pipelineSteps as $step) {
            $this->assertNotNull($step->planned_start_date, "step {$step->name} has a planned start");
            $this->assertTrue(
                $step->planned_start_date->lte($step->due_date),
                "step {$step->name}: start must not be after end (no negative-width bar)"
            );
            // The projection rule: duration == the step's own offset.
            $this->assertSame((int) $step->days_offset, $step->duration_days, "step {$step->name}: duration = days_offset");
            // planned_end accessor is the due_date.
            $this->assertEquals($step->due_date, $step->planned_end_date);
        }

        // The milestone (OTP Signed, on_creation, offset 0) is a zero-width point.
        $milestone = $deal->pipelineSteps->firstWhere('name', 'OTP Signed');
        $this->assertSame(0, $milestone->duration_days, 'milestone/zero-offset step is a point');
        $this->assertEquals($milestone->planned_start_date, $milestone->due_date);
    }

    public function test_activation_reanchors_planned_start_to_the_real_completion(): void
    {
        $deal = $this->dealWithPipeline();
        $otp  = $deal->pipelineSteps->firstWhere('name', 'OTP Signed');

        $this->svc->completeStep($otp->fresh(), $this->admin->id);

        // Rates Clearance (after OTP) is now active; its start re-anchored to OTP's real completion,
        // and remains consistent (duration still = its own offset).
        $rates = DealStepInstance::where('dr1_deal_id', $deal->id)->where('name', 'Rates Clearance')->firstOrFail();
        $this->assertSame('active', $rates->status);
        $this->assertNotNull($rates->planned_start_date);
        $this->assertEquals(
            $rates->due_date->toDateString(),
            $rates->planned_start_date->copy()->addDays((int) $rates->days_offset)->toDateString(),
            'start + offset == end after activation'
        );
    }

    public function test_normalizer_surfaces_a_step_comment_as_a_pipeline_event(): void
    {
        $deal = $this->dealWithPipeline();
        $step = $deal->pipelineSteps->firstWhere('name', 'Rates Clearance');

        DealStepComment::create([
            'agency_id'             => $this->agencyId,
            'deal_step_instance_id' => $step->id,
            'user_id'               => $this->admin->id,
            'body'                  => 'Awaiting the clearance figures from the municipality.',
        ]);

        $events = app(PipelineEventService::class)->eventsForDeal($deal);

        $this->assertCount(1, $events);
        /** @var PipelineEvent $e */
        $e = $events->first();
        $this->assertSame('comment', $e->type);
        $this->assertSame(PipelineEvent::SCOPE_STEP, $e->scope);
        $this->assertSame($step->id, $e->stepId);
        $this->assertNull($e->direction);
        $this->assertSame($this->admin->id, $e->authorId);
        $this->assertSame($this->admin->name, $e->authorName);
        $this->assertStringContainsString('clearance figures', $e->body);
        $this->assertNotNull($e->occurredAt);

        // The per-step filter returns it too.
        $this->assertCount(1, app(PipelineEventService::class)->eventsForStep($deal, $step->id));
        $this->assertCount(0, app(PipelineEventService::class)->eventsForStep($deal, $step->id + 99999));
    }

    public function test_per_agent_view_preference_round_trips(): void
    {
        // Default when unset.
        $this->assertSame('timeline', PipelineUserPreference::viewForUser($this->admin->id));

        PipelineUserPreference::setViewForUser($this->admin->id, 'list');
        $this->assertSame('list', PipelineUserPreference::viewForUser($this->admin->id));

        // Idempotent upsert (no duplicate row) + invalid values ignored.
        PipelineUserPreference::setViewForUser($this->admin->id, 'timeline');
        PipelineUserPreference::setViewForUser($this->admin->id, 'bogus');
        $this->assertSame('timeline', PipelineUserPreference::viewForUser($this->admin->id));
        $this->assertSame(1, PipelineUserPreference::where('user_id', $this->admin->id)->count());
    }

    private function dealWithPipeline(): Deal
    {
        $deal = Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'property_value' => 2_150_000, 'total_commission' => 107_500,
            'buyer_name' => 'Thandi Mkhize', 'accepted_status' => 'P',
        ]);
        $this->svc->createPipeline($deal, $this->makeTemplate()->id, ['from_date' => '2026-03-01']);
        return $deal->fresh('pipelineSteps');
    }

    private function makeTemplate(): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);

        // pos, name, trigger_type, trigger-after, days_offset, is_milestone
        $rows = [
            [1, 'OTP Signed',      'on_creation', null,          0, true],
            [2, 'Rates Clearance', 'after_step',  'OTP Signed',  5, false],
            [3, 'Electrical COC',  'after_step',  'OTP Signed', 10, false],
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
        return $template;
    }
}
