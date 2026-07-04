<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
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
 * WS0 (AT-158 / DR2) — feature coverage for the previously-UNTESTED
 * DealPipelineService before it becomes the canonical deal store. Exercises the
 * real engine: createDeal + step materialisation, chain recalculation from the
 * ACTUAL completion date, positive/negative completion, status triggers, the
 * BM-approval gate, and expected-registration projection.
 */
final class DealPipelineEngineTest extends TestCase
{
    use RefreshDatabase;

    private DealPipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(DealPipelineService::class);
    }

    // ── createDeal + on_creation activation ──────────────────────────────

    public function test_create_deal_materialises_steps_and_activates_on_creation(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal] = $this->makeDeal();

        $this->assertSame('active', $deal->status);
        $this->assertSame(3, $deal->stepInstances()->count(), 'all template steps copied to instances');

        $otp = $deal->stepInstances()->where('name', 'OTP Signed')->first();
        $this->assertSame('active', $otp->status, 'on_creation step is active');
        $this->assertSame('2026-03-01', $otp->due_date->format('Y-m-d'), 'due = offer_date + 0d');

        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        $this->assertSame('not_started', $bond->status, 'downstream step not yet active');
    }

    // ── chain recalculation from the ACTUAL completion date ──────────────

    public function test_completing_a_step_activates_downstream_from_actual_completion_date(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent] = $this->makeDeal();
        $otp = $deal->stepInstances()->where('name', 'OTP Signed')->first();

        // Complete OTP LATE (10 days after the offer) — downstream must key off
        // the actual completion date, not the offer date.
        Carbon::setTestNow('2026-03-11 14:00:00');
        $this->svc->completeStep($otp->fresh(), $agent, ['outcome' => 'positive', 'date' => '2026-03-11']);

        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        $this->assertSame('active', $bond->status, 'downstream activated on completion');
        $this->assertSame('2026-04-10', $bond->due_date->format('Y-m-d'), 'due = actual completion (03-11) + 30d');
    }

    // ── positive status trigger (no approval) changes deal status ────────

    public function test_positive_completion_with_status_trigger_and_no_approval_changes_status(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        // Registration carries status_trigger 'completed' and no approval.
        [$deal, $agent] = $this->makeDeal();

        // March the deal to Registration: OTP → Bond (approved) → Registration.
        $this->completeByName($deal, $agent, 'OTP Signed');
        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        $this->svc->completeStep($bond->fresh(), $agent, ['outcome' => 'positive']);        // pending BM
        $this->svc->approveStep($bond->fresh(), $agent);                                     // → granted + activates Registration
        $reg = $deal->stepInstances()->where('name', 'Registration')->first();
        $this->svc->completeStep($reg->fresh(), $agent, ['outcome' => 'positive']);

        $deal->refresh();
        $this->assertSame('completed', $deal->status, 'Registration status_trigger completed the deal');
        $this->assertNotNull($deal->actual_registration, 'actual_registration stamped on completion');
    }

    // ── BM-approval gate: holds the status change until approve ──────────

    public function test_bm_approval_gate_holds_status_then_applies_on_approve(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent, $bm] = $this->makeDeal();
        $this->completeByName($deal, $agent, 'OTP Signed');

        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        $this->svc->completeStep($bond->fresh(), $agent, ['outcome' => 'positive']);

        $bond->refresh();
        $deal->refresh();
        $this->assertSame('pending', $bond->approval_status, 'held for BM');
        $this->assertSame('active', $deal->status, 'status NOT changed while pending');
        $this->assertSame('not_started', $deal->stepInstances()->where('name', 'Registration')->first()->status,
            'downstream NOT activated while pending');

        $this->svc->approveStep($bond->fresh(), $bm);
        $deal->refresh();
        $this->assertSame('granted', $deal->status, 'approval applied the granted status_trigger');
        $this->assertSame('active', $deal->stepInstances()->where('name', 'Registration')->first()->status,
            'downstream activated on approval');
    }

    public function test_bm_reject_reverts_step_to_active(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent, $bm] = $this->makeDeal();
        $this->completeByName($deal, $agent, 'OTP Signed');
        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        $this->svc->completeStep($bond->fresh(), $agent, ['outcome' => 'positive']);

        $this->svc->rejectStep($bond->fresh(), $bm, 'Bank letter missing');

        $bond->refresh();
        $this->assertSame('active', $bond->status, 'reverted to active');
        $this->assertSame('rejected', $bond->approval_status);
        $this->assertNull($bond->completed_at, 'completion cleared');
        $this->assertSame('active', $deal->fresh()->status, 'deal status unchanged by reject');
    }

    // ── negative outcome cancels downstream ──────────────────────────────

    public function test_negative_outcome_cancels_deal_and_skips_downstream(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent, $bm] = $this->makeDeal();
        $this->completeByName($deal, $agent, 'OTP Signed');

        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        // Negative on a status-gated step → needs BM approval to apply 'cancelled'.
        $this->svc->completeStep($bond->fresh(), $agent, ['outcome' => 'negative']);
        $this->svc->approveStep($bond->fresh(), $bm);

        $deal->refresh();
        $this->assertSame('cancelled', $deal->status, 'negative_status_trigger cancelled the deal');
        $this->assertSame('skipped', $deal->stepInstances()->where('name', 'Registration')->first()->status,
            'remaining downstream steps skipped');
    }

    // ── expected registration projection ─────────────────────────────────

    public function test_expected_registration_projects_from_the_trigger_chain(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal] = $this->makeDeal(); // offer_date 2026-03-01; chain 0 + 30 + 15 = 45d

        $this->assertSame('2026-04-15', $deal->fresh()->expected_registration->format('Y-m-d'),
            'expected registration = offer_date + summed chain offsets');
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /** @return array{0:DealV2,1:User,2:User} [deal, agent, bm] */
    private function makeDeal(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            // WS-R3: these engine tests exercise the BM-approval HOLD path, which
            // is now opt-in per agency — enable it here so they keep testing it.
            'deal_v2_bm_approval_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $bm = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'branch_manager']);

        // Minimal property (mute its heavy observers — geocoding / tracked-property).
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title' => '12 Marine Drive, Margate',
            'address' => '12 Marine Drive, Margate',
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
            'purchase_price' => 1_950_000,
            'commission_amount' => 97_500,
            'commission_vat' => 14_625,
            'offer_date' => '2026-03-01',
            'branch_id' => $agencyId,
            'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
        ]);

        return [$deal, $agent, $bm];
    }

    /** OTP (on_creation) → Bond Approved (+30, granted, BM approval, neg cancelled) → Registration (+15, completed). */
    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Test Bond', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);

        $rows = [
            // [pos, name, is_milestone, completion_type, trigger_type, trigger_name, offset, green, amber, red, status_trigger, neg_trigger, neg_label, needs_bm]
            [1, 'OTP Signed',   true,  'date_input', 'on_creation', null,           0,  14, 7, 3, null,        'cancelled', 'OTP Rejected',  false],
            [2, 'Bond Approved', false, 'date_input', 'after_step',  'OTP Signed',   30, 15, 7, 3, 'granted',   'cancelled', 'Bond Declined', true],
            [3, 'Registration', true,  'date_input', 'after_step',  'Bond Approved', 15, 10, 5, 2, 'completed', null,        null,            false],
        ];

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r[1]] = DealPipelineStep::create([
                'pipeline_template_id' => $template->id,
                'position' => $r[0], 'name' => $r[1], 'description' => null,
                'is_locked' => false, 'is_milestone' => $r[2],
                'completion_type' => $r[3], 'trigger_type' => $r[4], 'days_offset' => $r[6],
                'rag_green_days' => $r[7], 'rag_amber_days' => $r[8], 'rag_red_days' => $r[9],
                'notify_agent' => true, 'notify_bm' => false, 'notify_admin' => false,
                'status_trigger' => $r[10], 'negative_status_trigger' => $r[11],
                'negative_outcome_label' => $r[12], 'requires_bm_approval' => $r[13],
            ]);
        }
        // Resolve trigger_step_id by name (second pass).
        foreach ($rows as $r) {
            if ($r[5]) {
                $byName[$r[1]]->update(['trigger_step_id' => $byName[$r[5]]->id]);
            }
        }

        return $template;
    }

    private function completeByName(DealV2 $deal, User $user, string $name): void
    {
        $step = $deal->stepInstances()->where('name', $name)->first();
        $this->svc->completeStep($step->fresh(), $user, ['outcome' => 'positive']);
    }
}
