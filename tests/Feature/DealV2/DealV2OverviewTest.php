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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 DR2 WS8 (§12) — the pipeline-overview verification gate: KPI card
 * counts match direct queries, the board places each deal in its current
 * milestone column, CSV row count == the filtered result count, and the scope
 * switcher is server-clamped (can only narrow, never widen).
 */
final class DealV2OverviewTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;
    private DealPipelineTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'c-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->template = DealPipelineTemplate::create([
            'name' => 'Bond', 'deal_type' => 'bond', 'agency_id' => $this->agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
        DealPipelineStep::create([
            'pipeline_template_id' => $this->template->id, 'position' => 1, 'name' => 'Bond Approval',
            'is_locked' => false, 'is_milestone' => true, 'completion_type' => 'date_input',
            'trigger_type' => 'on_creation', 'days_offset' => 20,
            'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => true, 'notify_bm' => true, 'notify_admin' => true,
        ]);
    }

    public function test_cards_and_board_match_direct_queries(): void
    {
        [$dealA, $dealB] = [$this->makeDeal('12 Marine Dr, Margate'), $this->makeDeal('9 Beach Rd, Uvongo')];
        // Push deal B's milestone step overdue (direct — the timer isn't run here).
        $dealB->stepInstances()->update(['status' => 'overdue']);

        $resp = $this->actingAs($this->admin)->get(route('deals-v2.overview'));
        $resp->assertOk();

        $cards = collect($resp->viewData('cards'))->keyBy('key');
        $this->assertSame(2, $cards['active']['value'], 'two active deals');
        $this->assertSame(
            DealStepInstance::whereHas('deal', fn ($q) => $q->visibleTo($this->admin))->where('status', 'overdue')->count(),
            $cards['overdue']['value'],
            'overdue-steps card matches a direct query'
        );

        // Board: both deals sit under the "Bond Approval" milestone column.
        $board = $resp->viewData('board');
        $this->assertTrue($board->has('Bond Approval'));
        $this->assertSame(2, $board->get('Bond Approval')->count());
    }

    public function test_csv_row_count_equals_result_count(): void
    {
        $this->makeDeal('12 Marine Dr, Margate');
        $this->makeDeal('9 Beach Rd, Uvongo');
        $this->makeDeal('4 Ridge Ave, Shelly Beach');

        $resp = $this->actingAs($this->admin)->get(route('deals-v2.export'));
        $resp->assertOk();
        $lines = array_values(array_filter(explode("\n", trim($resp->streamedContent()))));
        // header + 3 data rows.
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('Reference', $lines[0]);
    }

    public function test_scope_switcher_clamps_and_never_widens(): void
    {
        // A branch manager (permitted 'branch') cannot switch to 'all'.
        $this->assertSame('branch', DealV2::clampScope('all', 'branch'));
        $this->assertSame('branch', DealV2::clampScope('company', 'branch'));
        $this->assertSame('own', DealV2::clampScope('own', 'branch'));   // narrowing allowed
        $this->assertSame('all', DealV2::clampScope('all', 'all'));      // full access keeps all
        $this->assertSame('branch', DealV2::clampScope(null, 'branch')); // default = permitted
        $this->assertSame('own', DealV2::clampScope('garbage', 'own'));  // junk → permitted
    }

    private function makeDeal(string $address): DealV2
    {
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $this->admin->id, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));

        return app(DealPipelineService::class)->createDeal([
            'deal_type' => 'bond', 'property_id' => $property->id, 'listing_agent_id' => $this->admin->id,
            'pipeline_template_id' => $this->template->id, 'purchase_price' => 1_850_000,
            'commission_amount' => 92_500, 'commission_vat' => 13_875, 'offer_date' => now()->toDateString(),
            'branch_id' => $this->agencyId, 'created_by_id' => $this->admin->id,
            'agents' => [['side' => 'listing', 'user_id' => $this->admin->id]],
        ]);
    }
}
