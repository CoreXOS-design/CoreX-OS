<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS-V1 — the AND-gate / fan-in dependency model.
 *
 * A step may declare additional predecessors BEYOND its single primary trigger.
 * It activates only when its primary trigger AND every additional dependency are
 * complete, and its relative clock then starts from the LATEST of those
 * completions. Mirrors SA conveyancing reality: Deeds Office Lodgement cannot
 * begin until every certificate + clearance is in (fan-in), not just one.
 *
 * The template here models: OTP → { Rates Clearance ∥ Electrical COC } →
 * Lodgement (primary trigger = Rates, AND-gate dependency = Electrical).
 */
final class DealStepDependencyTest extends TestCase
{
    use RefreshDatabase;

    private DealPipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(DealPipelineService::class);
    }

    public function test_and_gate_step_stays_blocked_until_all_predecessors_complete(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent] = $this->makeDeal();

        $lodgement = $this->step($deal, 'Lodgement');
        $this->assertSame('not_started', $lodgement->status);

        // Complete OTP → both parallel legs activate.
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->complete($deal, $agent, 'OTP Signed');
        $this->assertSame('active', $this->step($deal, 'Rates Clearance')->status);
        $this->assertSame('active', $this->step($deal, 'Electrical COC')->status);
        $this->assertSame('not_started', $this->step($deal, 'Lodgement')->status);

        // Complete the PRIMARY trigger (Rates) only — Lodgement must stay blocked
        // because its AND-gate dependency (Electrical COC) is still open.
        Carbon::setTestNow('2026-03-20 11:00:00');
        $this->complete($deal, $agent, 'Rates Clearance');
        $lodgement = $this->step($deal, 'Lodgement');
        $this->assertSame('not_started', $lodgement->status, 'blocked while Electrical COC still open');
        $this->assertStringContainsString('Waiting on Electrical COC', $lodgement->blockedByLabel());
        $this->assertStringContainsString('1 of 2 done', $lodgement->blockedByLabel());

        // Complete the last blocker (Electrical) LATER — Lodgement now activates,
        // clock anchored to the LATEST completion (03-25), not the earlier one.
        Carbon::setTestNow('2026-03-25 15:00:00');
        $this->complete($deal, $agent, 'Electrical COC');
        $lodgement = $this->step($deal, 'Lodgement');
        $this->assertSame('active', $lodgement->status, 'activates once every predecessor is complete');
        $this->assertSame('2026-04-01', $lodgement->due_date->format('Y-m-d'), 'due = latest completion (03-25) + 7d');
        $this->assertNull($lodgement->blockedByLabel(), 'no blocker once active');
    }

    public function test_and_gate_anchors_to_the_last_blocker_regardless_of_order(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent] = $this->makeDeal();

        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->complete($deal, $agent, 'OTP Signed');

        // Complete the AND-gate DEPENDENCY (Electrical) first…
        Carbon::setTestNow('2026-03-10 09:00:00');
        $this->complete($deal, $agent, 'Electrical COC');
        $this->assertSame('not_started', $this->step($deal, 'Lodgement')->status, 'primary trigger still open');

        // …then the PRIMARY trigger (Rates) last → Lodgement anchors to Rates (03-18).
        Carbon::setTestNow('2026-03-18 09:00:00');
        $this->complete($deal, $agent, 'Rates Clearance');
        $lodgement = $this->step($deal, 'Lodgement');
        $this->assertSame('active', $lodgement->status);
        $this->assertSame('2026-03-25', $lodgement->due_date->format('Y-m-d'), 'due = latest completion (03-18) + 7d');
    }

    public function test_linear_fast_path_is_unchanged_for_steps_without_extra_dependencies(): void
    {
        // Rates Clearance has a single primary trigger (OTP) and no AND-gate deps —
        // it must behave exactly as the pre-WS-V1 linear engine did.
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent] = $this->makeDeal();

        $rates = $this->step($deal, 'Rates Clearance');
        $this->assertSame('not_started', $rates->status);
        $this->assertSame('Waiting on "OTP Signed"', $rates->blockedByLabel());

        Carbon::setTestNow('2026-03-11 09:00:00');
        $this->complete($deal, $agent, 'OTP Signed');
        $rates = $this->step($deal, 'Rates Clearance');
        $this->assertSame('active', $rates->status);
        $this->assertSame('2026-03-16', $rates->due_date->format('Y-m-d'), 'due = actual OTP completion (03-11) + 5d');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function step(DealV2 $deal, string $name): DealStepInstance
    {
        return $deal->stepInstances()->where('name', $name)->first();
    }

    private function complete(DealV2 $deal, User $user, string $name): void
    {
        $this->svc->completeStep($this->step($deal, $name)->fresh(), $user, ['outcome' => 'positive', 'value' => '2026-03-11']);
    }

    private function makeDeal(): array
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

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '8 Marine Drive, Uvongo',
            'address' => '8 Marine Drive, Uvongo',
            'agent_id' => $agent->id,
            'branch_id' => $agencyId,
            'agency_id' => $agencyId,
        ]));

        $template = $this->makeTemplate($agencyId, $agent->id);

        $deal = $this->svc->createDeal([
            'deal_type' => 'bond',
            'property_id' => $property->id,
            'listing_agent_id' => $agent->id,
            'pipeline_template_id' => $template->id,
            'purchase_price' => 2_150_000,
            'commission_amount' => 107_500,
            'commission_vat' => 16_125,
            'offer_date' => '2026-03-01',
            'branch_id' => $agencyId,
            'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
        ]);

        return [$deal, $agent];
    }

    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Fan-in Bond', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        $rows = [
            // [pos, name, is_milestone, completion_type, trigger_type, trigger_name, offset]
            [1, 'OTP Signed',      true,  'date_input',      'on_creation', null,         0],
            [2, 'Rates Clearance', false, 'document_upload', 'after_step',  'OTP Signed', 5],
            [3, 'Electrical COC',  false, 'document_upload', 'after_step',  'OTP Signed', 10],
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

        // WS-V1 — Lodgement's AND-gate dependency on Electrical COC (in addition
        // to its primary trigger, Rates Clearance).
        DB::table('deal_pipeline_step_dependencies')->insert([
            'agency_id' => $agencyId,
            'pipeline_step_id' => $byName['Lodgement']->id,
            'depends_on_step_id' => $byName['Electrical COC']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $template;
    }
}
