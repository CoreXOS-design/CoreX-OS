<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Exceptions\Deal\PipelineLockedException;
use App\Models\Deal;
use App\Models\DealLog;
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
 * AT-244 — the DR2 pipeline lock. ONE rule: the pipeline is live only on the
 * PROCEEDING offer. A Declined deal's pipeline is read-only history.
 *
 * The gate is proven SERVER-SIDE (not just hidden in the UI) at the service, because
 * CalendarController completes DR1 pipeline steps without passing through
 * PipelineController — a controller-only gate would be bypassable from the calendar.
 *
 * Input paths proven here:
 *   - each of the 7 pipeline mutations, individually, on a declined deal → rejected
 *   - a POST straight at the route (UI bypassed) → rejected, no state change
 *   - the rejection is AUDITED (deal_logs `pipeline_locked_rejected`)
 *   - Wave 2 AUTO-declined deal (not just manually declined) → same lock
 *   - a step whose own trigger DECLINES the deal is still allowed (kill, don't resurrect)
 *   - reinstating the deal (D → P/G, the existing register path) UNLOCKS the pipeline
 *   - a proceeding (P / G) deal is untouched — no collateral lock
 *   - a REGISTERED (R) deal stays unlocked (terminal but proceeded — deliberately not in scope)
 */
final class Dr2PipelineLockTest extends TestCase
{
    use RefreshDatabase;

    private Dr1PipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(Dr1PipelineService::class);
    }

    // ── the lock itself ──────────────────────────────────────────────────

    public function test_completing_a_step_on_a_declined_deal_is_rejected_and_audited(): void
    {
        [$deal] = $this->dealWithPipeline('D');
        $step = $this->step($deal, 'OTP Signed');

        try {
            $this->svc->completeStep($step, null);
            $this->fail('Expected the pipeline lock to reject a step completion on a declined deal.');
        } catch (PipelineLockedException $e) {
            $this->assertSame($deal->id, $e->deal->id);
        }

        // No state change: the step is exactly as it was.
        $this->assertSame('active', $this->step($deal, 'OTP Signed')->status);
        $this->assertNull($this->step($deal, 'OTP Signed')->completed_at);

        // The blocked attempt is on the deal's audit log — never silently swallowed.
        $log = DealLog::where('deal_id', $deal->id)->where('event_type', 'pipeline_locked_rejected')->first();
        $this->assertNotNull($log, 'a rejected pipeline transition must be audited');
        $this->assertSame('D', $log->from_value);
        $this->assertStringContainsString('Complete step', (string) $log->message);
    }

    /** Every mutation, individually — the whole class of hole, not just the one Johan clicked. */
    public function test_every_pipeline_mutation_is_locked_on_a_declined_deal(): void
    {
        [$deal] = $this->dealWithPipeline('D');
        $step = $this->step($deal, 'OTP Signed');

        $mutations = [
            'completeStep'      => fn () => $this->svc->completeStep($this->step($deal, 'OTP Signed'), null),
            'markNotApplicable' => fn () => $this->svc->markNotApplicable($this->step($deal, 'OTP Signed'), null, 'no gas'),
            'removeStep'        => fn () => $this->svc->removeStep($this->step($deal, 'OTP Signed'), null),
            'addCustomStep'     => fn () => $this->svc->addCustomStep($deal->fresh(), 'Plans approved', null, null, null),
            'updateStepDueDate' => fn () => $this->svc->updateStepDueDate($this->step($deal, 'OTP Signed'), '2026-04-01', null),
            'restoreRemovedStep' => fn () => $this->svc->restoreRemovedStep($deal->fresh(), $step->id, null),
            'reinstateStep'     => fn () => $this->svc->reinstateStep($this->step($deal, 'OTP Signed'), null),
        ];

        foreach ($mutations as $name => $call) {
            try {
                $call();
                $this->fail("Expected {$name}() to be refused on a declined deal.");
            } catch (PipelineLockedException $e) {
                $this->assertSame($deal->id, $e->deal->id, "{$name}() rejected the wrong deal");
            }
        }

        // Nothing moved.
        $this->assertSame('active', $this->step($deal, 'OTP Signed')->status);
        $this->assertSame(4, DealStepInstance::where('dr1_deal_id', $deal->id)->count(), 'no step added or removed');
    }

    /** The Wave 2 AUTO-declined deal is the same 'D' — it must lock identically. */
    public function test_a_wave2_auto_declined_deal_is_locked_too(): void
    {
        [$deal] = $this->dealWithPipeline('P');

        // Wave 2's auto-decline (grant cascade / capture-after-grant) writes a plain 'D'.
        $deal->accepted_status = 'D';
        $deal->saveQuietly(); // quiet, exactly as AutoDeclineNewDealOnCommittedProperty does

        $this->expectException(PipelineLockedException::class);
        $this->svc->completeStep($this->step($deal, 'OTP Signed'), null);
    }

    // ── the gate is server-side, not a hidden button ─────────────────────

    public function test_posting_straight_at_the_route_is_rejected_server_side(): void
    {
        [$deal, $agent] = $this->dealWithPipeline('D');
        $step = $this->step($deal, 'OTP Signed');

        // The UI hides the button; a hand-rolled POST must still be refused.
        $this->actingAs($agent)
            ->post(route('deals-dr2.pipeline.step.complete', [$deal, $step]))
            ->assertRedirect();

        $this->assertSame('active', $this->step($deal, 'OTP Signed')->status, 'the step must not have advanced');
        $this->assertDatabaseHas('deal_logs', [
            'deal_id'    => $deal->id,
            'event_type' => 'pipeline_locked_rejected',
        ]);
    }

    // ── the pipeline may KILL a deal, it may never RESURRECT one ─────────

    public function test_a_step_whose_trigger_declines_the_deal_is_still_allowed(): void
    {
        [$deal] = $this->dealWithPipeline('P');

        // Configure the live step to DECLINE the deal on completion.
        $step = $this->step($deal, 'OTP Signed');
        $step->update(['status_trigger' => 'declined']);

        // The deal is still 'P' at the moment of the click → the lock lets it through.
        $this->svc->completeStep($step->fresh(), null);

        $this->assertSame('D', $deal->fresh()->accepted_status, 'the pipeline may decline a deal');
        $this->assertSame('completed', $this->step($deal, 'OTP Signed')->status);

        // ...and now the pipeline is shut behind it.
        $this->expectException(PipelineLockedException::class);
        $this->svc->markNotApplicable($this->step($deal, 'Rates Clearance'), null, 'too late');
    }

    // ── the way back is the existing register path, and it unlocks ───────

    public function test_reinstating_the_deal_on_the_register_unlocks_the_pipeline(): void
    {
        [$deal, $agent] = $this->dealWithPipeline('D');

        // The DR2 revival path (unchanged by AT-244): a deliberate, audited status write.
        // A declined deal stays re-grantable while no other deal is committed on the property.
        $this->actingAs($agent)
            ->post(route('deals-dr2.quickUpdate', $deal), ['accepted_status' => 'P'])
            ->assertRedirect();

        $this->assertSame('P', $deal->fresh()->accepted_status);
        $this->assertDatabaseHas('deal_logs', [
            'deal_id'    => $deal->id,
            'event_type' => 'status_changed',
            'from_value' => 'D',
            'to_value'   => 'P',
        ]);

        // The lock is derived from status, so the pipeline is live again — no second mechanism.
        $this->svc->completeStep($this->step($deal, 'OTP Signed'), null);
        $this->assertSame('completed', $this->step($deal, 'OTP Signed')->status);
    }

    // ── no collateral damage: proceeding deals are untouched ─────────────

    /** @dataProvider proceedingStatuses */
    public function test_a_proceeding_deal_is_not_locked(string $status): void
    {
        [$deal] = $this->dealWithPipeline($status);

        $this->svc->completeStep($this->step($deal, 'OTP Signed'), null);

        $this->assertSame('completed', $this->step($deal, 'OTP Signed')->status);
        $this->assertDatabaseMissing('deal_logs', [
            'deal_id'    => $deal->id,
            'event_type' => 'pipeline_locked_rejected',
        ]);
    }

    public static function proceedingStatuses(): array
    {
        return [
            'pending'    => ['P'],
            'granted'    => ['G'],
            // Registered is terminal but PROCEEDED — it is not a "not proceeding" state,
            // so AT-244 deliberately leaves it workable (post-completion immutability is a
            // separate concern, and locking it here would strand admin corrections).
            'registered' => ['R'],
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function step(Deal $deal, string $name): DealStepInstance
    {
        return DealStepInstance::where('dr1_deal_id', $deal->id)->where('name', $name)->firstOrFail()->fresh();
    }

    /** @return array{0:Deal,1:User} a DR1 deal at $acceptedStatus with a live 4-step pipeline */
    private function dealWithPipeline(string $acceptedStatus): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);

        $deal = Deal::create([
            'agency_id'        => $agencyId,
            'branch_id'        => $agencyId,
            'period'           => '2026-03',
            'deal_date'        => '2026-03-01',
            'property_value'   => 2_150_000,
            'total_commission' => 107_500,
            'buyer_name'       => 'Thandi Mkhize',
        ]);

        // Attach the pipeline while the deal is still workable, then move it to the
        // status under test — mirrors reality (a deal is worked, THEN it falls through).
        $this->svc->createPipeline($deal, $this->makeTemplate($agencyId, $agent->id)->id, ['from_date' => '2026-03-01']);

        $deal->accepted_status = $acceptedStatus;
        $deal->saveQuietly();

        return [$deal->fresh(), $agent];
    }

    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        $rows = [
            [1, 'OTP Signed',      'on_creation', null,         0],
            [2, 'Rates Clearance', 'after_step',  'OTP Signed', 5],
            [3, 'Electrical COC',  'after_step',  'OTP Signed', 10],
            [4, 'Lodgement',       'after_step',  'Rates Clearance', 7],
        ];

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r[1]] = DealPipelineStep::create([
                'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
                'position' => $r[0], 'name' => $r[1],
                'is_locked' => false, 'is_milestone' => false,
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
