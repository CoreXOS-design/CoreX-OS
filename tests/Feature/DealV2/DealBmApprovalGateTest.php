<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

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
 * AT-158 WS-R3 (Ruling 2) — the DR2 pipeline BM-approval gate ships OFF by
 * default (agency-configurable). Off → a status-trigger step applies its status
 * immediately (pipeline is a tracking overlay). On → the WS0 hold-for-BM flow.
 */
final class DealBmApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private DealPipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(DealPipelineService::class);
        Carbon::setTestNow('2026-03-01 09:00:00');
    }

    private function makeDeal(bool $bmApprovalEnabled): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'deal_v2_bm_approval_enabled' => $bmApprovalEnabled,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '1 Test Rd', 'address' => '1 Test Rd',
            'agent_id' => $agent->id, 'branch_id' => $agencyId, 'agency_id' => $agencyId,
        ]));

        $template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $agent->id,
        ]);
        $rows = [
            [1, 'OTP Signed',   true,  'date_input', 'on_creation', null,          0,  'cancelled', 'OTP Rejected',  false],
            [2, 'Bond Approved', false, 'date_input', 'after_step', 'OTP Signed',   30, 'cancelled', 'Bond Declined', true],
            [3, 'Registration', true,  'date_input', 'after_step', 'Bond Approved', 15, null,        null,            false],
        ];
        $statusTriggers = ['OTP Signed' => null, 'Bond Approved' => 'granted', 'Registration' => 'completed'];
        $map = [];
        foreach ($rows as [$pos, $name, $ms, $ct, $tt, $tName, $off, $neg, $negLabel, $bm]) {
            $map[$name] = $template->steps()->create([
                'agency_id' => $agencyId, 'name' => $name, 'position' => $pos, 'is_locked' => true, 'is_milestone' => $ms,
                'completion_type' => $ct, 'trigger_type' => $tt, 'days_offset' => $off,
                'rag_amber_days' => 7, 'rag_red_days' => 3, 'status_trigger' => $statusTriggers[$name],
                'negative_status_trigger' => $neg, 'negative_outcome_label' => $negLabel, 'requires_bm_approval' => $bm,
            ]);
        }
        foreach ($rows as [$pos, $name, $ms, $ct, $tt, $tName]) {
            if ($tName) { $map[$name]->update(['trigger_step_id' => $map[$tName]->id]); }
        }

        $deal = $this->svc->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id, 'listing_agent_id' => $agent->id,
            'pipeline_template_id' => $template->id, 'purchase_price' => 1_950_000,
            'commission_amount' => 97_500, 'commission_vat' => 14_625, 'offer_date' => '2026-03-01',
            'branch_id' => $agencyId, 'created_by_id' => $agent->id,
            'agents' => [['side' => 'listing', 'user_id' => $agent->id]],
        ]);

        return [$deal, $agent];
    }

    public function test_gate_off_by_default_applies_status_immediately(): void
    {
        [$deal, $agent] = $this->makeDeal(bmApprovalEnabled: false);

        $otp = $deal->stepInstances()->where('name', 'OTP Signed')->first();
        $this->svc->completeStep($otp->fresh(), $agent, ['outcome' => 'positive', 'date' => '2026-03-01']);

        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        $this->svc->completeStep($bond->fresh(), $agent, ['outcome' => 'positive']);

        $bond->refresh();
        $deal->refresh();
        // No hold — the status trigger applied immediately.
        $this->assertSame('not_required', $bond->approval_status, 'gate off → not held');
        $this->assertSame('granted', $deal->status, 'granted applied immediately (overlay)');
        $this->assertSame('active', $deal->stepInstances()->where('name', 'Registration')->first()->status,
            'downstream activated without a BM approval step');
    }

    public function test_gate_on_holds_for_bm_then_applies_on_approve(): void
    {
        [$deal, $agent] = $this->makeDeal(bmApprovalEnabled: true);
        $bm = User::factory()->create(['agency_id' => $deal->agency_id, 'branch_id' => $deal->agency_id, 'role' => 'branch_manager']);

        $otp = $deal->stepInstances()->where('name', 'OTP Signed')->first();
        $this->svc->completeStep($otp->fresh(), $agent, ['outcome' => 'positive', 'date' => '2026-03-01']);

        $bond = $deal->stepInstances()->where('name', 'Bond Approved')->first();
        $this->svc->completeStep($bond->fresh(), $agent, ['outcome' => 'positive']);

        $this->assertSame('pending', $bond->fresh()->approval_status, 'gate on → held for BM');
        $this->assertSame('active', $deal->fresh()->status, 'status not changed while pending');

        $this->svc->approveStep($bond->fresh(), $bm);
        $this->assertSame('granted', $deal->fresh()->status, 'applied on approval');
    }
}
