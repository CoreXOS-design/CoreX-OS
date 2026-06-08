<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\AI\AiUsageEvent;
use App\Models\Agency;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /admin/ai-usage dashboard reads the unified ledger and shows a per-source
 * breakdown that sums to the hero total.
 *
 * Spec: .ai/specs/ai-cost-ledger.md §9 (acceptance criteria 5).
 *
 * With role_permissions unseeded, PermissionService grants every permission, so
 * a factory admin passes the `permission:mic.view_ai_costs` route middleware.
 */
class AiUsageDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    public function test_dashboard_renders_ledger_totals_and_source_breakdown(): void
    {
        $agency = Agency::create(['name' => 'Dash Co', 'slug' => 'dash-co', 'ai_monthly_budget_zar' => 100]);
        $user = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);

        AiUsageEvent::create([
            'agency_id' => $agency->id, 'source' => AiUsageEvent::SOURCE_MIC_NARRATIVE,
            'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'output_tokens' => 50,
            'cost_zar' => 3.25, 'occurred_at' => now(),
        ]);
        AiUsageEvent::create([
            'agency_id' => $agency->id, 'source' => AiUsageEvent::SOURCE_MOBILE_VOICE,
            'model' => 'claude-haiku-4-5', 'input_tokens' => 200, 'output_tokens' => 25,
            'cost_zar' => 1.75, 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/admin/ai-usage');

        $response->assertOk();
        $response->assertSee('Spend by source');
        // Source labels are humanised (mobile_voice → "Mobile Voice").
        $response->assertSee('Mobile Voice');
        $response->assertSee('Mic Narrative');
        // Hero total = 3.25 + 1.75 = R5.00.
        $response->assertSee('R 5.00');
    }
}
