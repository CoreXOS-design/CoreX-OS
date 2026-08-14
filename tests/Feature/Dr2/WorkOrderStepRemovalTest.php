<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\AgencyServiceType;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealStepWorkOrder;
use App\Models\User;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-320 — an un-ticked COC REMOVES its pipeline step entirely (soft-delete, reversible),
 * superseding the earlier auto-N/A behaviour. Re-ticking restores the SAME step + its work
 * order cleanly. No hard delete; no row orphaned against a vanished step.
 */
final class WorkOrderStepRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Dr1PipelineService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(Dr1PipelineService::class);
    }

    public function test_untick_removes_the_step_and_retick_restores_it(): void
    {
        [$deal] = $this->dealWithCocPipeline();
        $this->assertFalse($this->step($deal, 'Electrical COC')->trashed());

        // 1. TICK the COC → a pending work order is created, the step stays live.
        $this->save($deal, true)->assertOk();
        $wo = DealStepWorkOrder::withTrashed()->where('dr1_deal_id', $deal->id)->where('service_type', 'COC')->firstOrFail();
        $this->assertFalse($wo->trashed(), 'ticked → work order live');
        $this->assertFalse($this->step($deal, 'Electrical COC')->trashed(), 'ticked → step live');

        // 2. UN-TICK → the step is REMOVED (soft-deleted), not N/A'd; the WO is soft-deleted too.
        $this->save($deal, false)->assertOk();

        $removed = DealStepInstance::withTrashed()->where('dr1_deal_id', $deal->id)->where('name', 'Electrical COC')->firstOrFail();
        $this->assertTrue($removed->trashed(), 'un-ticked → step soft-removed');
        $this->assertNull(
            DealStepInstance::where('dr1_deal_id', $deal->id)->where('name', 'Electrical COC')->first(),
            'the removed step is gone from the live board (not lingering as N/A)'
        );
        $this->assertNotSame('skipped', $removed->status, 'removed, NOT the old N/A (skipped) state');
        $this->assertTrue($wo->fresh()->trashed(), 'un-ticked → work order soft-deleted, no orphan against the vanished step');

        // 3. RE-TICK → the SAME step row is restored to the live board, WO revived.
        $this->save($deal, true)->assertOk();

        $restored = DealStepInstance::where('dr1_deal_id', $deal->id)->where('name', 'Electrical COC')->first();
        $this->assertNotNull($restored, 're-ticked → step is back on the live board');
        $this->assertFalse($restored->trashed());
        $this->assertSame($removed->id, $restored->id, 'the SAME step row is restored — reversible, no duplicate');
        $this->assertFalse($wo->fresh()->trashed(), 're-ticked → work order revived');
    }

    private function save(Deal $deal, bool $applies)
    {
        return $this->post(route('deals-dr2.pipeline.coc-config.save', $deal), [
            'items' => [[
                'code' => 'COC', 'applies' => $applies,
                'responsible_party' => 'supplier', 'service_provider_id' => null,
            ]],
        ]);
    }

    private function step(Deal $deal, string $name): DealStepInstance
    {
        return DealStepInstance::where('dr1_deal_id', $deal->id)->where('name', $name)->firstOrFail()->fresh();
    }

    /** @return array{0:Deal,1:User} DR1 deal at 'P' with a live pipeline carrying an Electrical COC step. */
    private function dealWithCocPipeline(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        AgencyServiceType::seedDefaultsFor($agencyId); // COC → label "Electrical COC"

        $deal = Deal::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'property_value' => 2_150_000, 'total_commission' => 107_500,
            'buyer_name' => 'Thandi Mkhize', 'accepted_status' => 'P',
        ]);

        $this->svc->createPipeline($deal, $this->makeTemplate($agencyId, $admin->id)->id, ['from_date' => '2026-03-01']);
        $this->actingAs($admin);

        return [$deal->fresh(), $admin];
    }

    private function makeTemplate(int $agencyId, int $creatorId): DealPipelineTemplate
    {
        $template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);
        foreach ([[1, 'OTP Signed'], [2, 'Electrical COC']] as $r) {
            DealPipelineStep::create([
                'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
                'position' => $r[0], 'name' => $r[1],
                'is_locked' => false, 'is_milestone' => false,
                'completion_type' => 'date_input', 'trigger_type' => 'on_creation', 'days_offset' => 0,
                'rag_amber_days' => 7, 'rag_red_days' => 3,
                'notify_agent' => false, 'notify_bm' => false, 'notify_admin' => false,
            ]);
        }
        return $template;
    }
}
