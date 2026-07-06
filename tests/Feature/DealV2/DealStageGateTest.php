<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStageMove;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Models\Property;
use App\Models\User;
use App\Services\DealV2\DealPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS-V2 — suspensive conditions + the auto-move stage gate.
 *
 * A deal moves to Granted only when EVERY suspensive-condition step completes
 * (AND-gate). Default = AUTO (move immediately, notify, undoable); the
 * agency-configurable alternative is PROMPT. A negative on a suspensive step is
 * a DECLINE (distinct terminal state, remaining steps voided). Registration →
 * Completed uses the same auto-move + undo pattern.
 */
final class DealStageGateTest extends TestCase
{
    use RefreshDatabase;

    private DealPipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->svc = app(DealPipelineService::class);
    }

    public function test_moves_to_granted_only_when_all_suspensive_conditions_complete(): void
    {
        [$deal, $agent] = $this->makeDeal(); // AUTO mode (default)

        $this->complete($deal, $agent, 'OTP Signed');
        $this->assertSame('active', $deal->fresh()->status);

        // One of two suspensive steps done → NOT granted yet.
        $this->complete($deal, $agent, 'Bond Approved');
        $this->assertSame('active', $deal->fresh()->status, 'Deposit still outstanding');

        // The LAST suspensive step completes → auto-move to Granted.
        $this->complete($deal, $agent, 'Deposit Paid');
        $this->assertSame('granted', $deal->fresh()->status);

        $move = DealStageMove::where('deal_id', $deal->id)->where('to_status', 'granted')->first();
        $this->assertNotNull($move, 'an undoable stage move was recorded');
        $this->assertSame('applied', $move->state);
        $this->assertSame('suspensive_conditions_met', $move->reason);
        $this->assertSame('auto', $move->mode);
        $this->assertTrue($move->isUndoable());
    }

    public function test_auto_granted_move_can_be_undone(): void
    {
        [$deal, $agent] = $this->makeDeal();
        $this->completeAll($deal, $agent, ['OTP Signed', 'Bond Approved', 'Deposit Paid']);
        $this->assertSame('granted', $deal->fresh()->status);

        $move = $deal->fresh()->undoableStageMove();
        $this->svc->undoStageMove($move, $agent, 'captured in error');
        $this->assertSame('active', $deal->fresh()->status, 'undo reverts to the prior status');
        $this->assertSame('undone', $move->fresh()->state);
    }

    public function test_negative_on_a_suspensive_step_declines_and_voids_downstream(): void
    {
        [$deal, $agent] = $this->makeDeal();
        $this->complete($deal, $agent, 'OTP Signed');

        // Bond declined → whole deal declined, remaining steps voided (not deleted).
        $bond = $this->step($deal, 'Bond Approved');
        $this->svc->completeStep($bond->fresh(), $agent, ['outcome' => 'negative', 'reason' => 'Bank declined the bond']);

        $this->assertSame('declined', $deal->fresh()->status);
        $this->assertSame('skipped', $this->step($deal, 'Registration')->status, 'downstream voided');
        $this->assertSame('skipped', $this->step($deal, 'Deposit Paid')->status);
    }

    public function test_prompt_mode_queues_a_pending_move_then_confirm_applies_it(): void
    {
        [$deal, $agent] = $this->makeDeal('prompt');
        $this->completeAll($deal, $agent, ['OTP Signed', 'Bond Approved', 'Deposit Paid']);

        // Prompt mode: conditions met but the deal has NOT moved yet.
        $this->assertSame('active', $deal->fresh()->status);
        $pending = $deal->fresh()->pendingStageMove();
        $this->assertNotNull($pending, 'a pending prompt is queued');
        $this->assertSame('pending', $pending->state);
        $this->assertSame('granted', $pending->to_status);

        // Confirm → the move applies.
        $this->svc->confirmStageMove($pending, $agent);
        $this->assertSame('granted', $deal->fresh()->status);
        $this->assertSame('confirmed', $pending->fresh()->state);
    }

    public function test_registration_step_auto_completes_and_is_undoable(): void
    {
        [$deal, $agent] = $this->makeDeal();
        $this->completeAll($deal, $agent, ['OTP Signed', 'Bond Approved', 'Deposit Paid']);
        $this->assertSame('granted', $deal->fresh()->status);

        $this->complete($deal, $agent, 'Registration');
        $fresh = $deal->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->actual_registration, 'registration date stamped');

        // Undo a registration clears the stamped date.
        $move = $fresh->undoableStageMove();
        $this->svc->undoStageMove($move, $agent);
        $this->assertSame('granted', $deal->fresh()->status);
        $this->assertNull($deal->fresh()->actual_registration);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function step(DealV2 $deal, string $name): DealStepInstance
    {
        return $deal->stepInstances()->where('name', $name)->first();
    }

    private function complete(DealV2 $deal, User $user, string $name): void
    {
        $this->svc->completeStep($this->step($deal, $name)->fresh(), $user, ['outcome' => 'positive', 'value' => '2026-03-15']);
    }

    private function completeAll(DealV2 $deal, User $user, array $names): void
    {
        foreach ($names as $n) {
            $this->complete($deal, $user, $n);
        }
    }

    private function makeDeal(string $gateMode = 'auto'): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'deal_v2_stage_gate_mode' => $gateMode,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '14 Marine Drive, Shelly Beach', 'address' => '14 Marine Drive, Shelly Beach',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));

        $template = $this->makeTemplate($agencyId, $agent->id);

        $deal = $this->svc->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id,
            'listing_agent_id' => $agent->id, 'pipeline_template_id' => $template->id,
            'purchase_price' => 2_300_000, 'commission_amount' => 115_000, 'commission_vat' => 17_250,
            'offer_date' => '2026-03-01', 'branch_id' => $agencyId, 'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
        ]);

        return [$deal, $agent];
    }

    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Two-Condition Bond', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        $rows = [
            // [pos, name, is_milestone, is_suspensive, completion_type, trigger_type, trigger_name, offset, status_trigger]
            [1, 'OTP Signed',    true,  false, 'date_input',   'on_creation', null,           0,  null],
            [2, 'Bond Approved', true,  true,  'date_input',   'after_step',  'OTP Signed',   20, 'granted'],
            [3, 'Deposit Paid',  false, true,  'amount_input', 'after_step',  'OTP Signed',   7,  null],
            [4, 'Registration',  true,  false, 'date_input',   'after_step',  'Bond Approved', 30, 'completed'],
        ];

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r[1]] = DealPipelineStep::create([
                'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
                'position' => $r[0], 'name' => $r[1],
                'is_locked' => false, 'is_milestone' => $r[2], 'is_suspensive' => $r[3],
                'completion_type' => $r[4], 'trigger_type' => $r[5], 'days_offset' => $r[7],
                'rag_amber_days' => 7, 'rag_red_days' => 3,
                'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
                'status_trigger' => $r[8],
                'negative_status_trigger' => $r[1] === 'Bond Approved' ? 'declined' : null,
            ]);
        }
        foreach ($rows as $r) {
            if ($r[6]) {
                $byName[$r[1]]->update(['trigger_step_id' => $byName[$r[6]]->id]);
            }
        }

        return $template;
    }
}
