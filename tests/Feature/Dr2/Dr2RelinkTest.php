<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\DealV2\DealStructureAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-334 (concurrent-lanes rework) · Stage D — drag-to-relink backend.
 *
 * A drag posts a predecessor SET (depends_on[]) to the follows endpoint. The endpoint
 * writes the AND-gate rows, sets the primary follows pointer, re-cascades dates and reorders
 * — and refuses any relink that would close a loop. The Sequence modal's single-`follows`
 * path is untouched (no depends_on) and a convergence step keeps its fan-in.
 */
final class Dr2RelinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_drag_relink_writes_the_predecessor_set_and_rejects_loops(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent] = $this->makeDeal();
        $this->actingAs($agent);
        app(DealStructureAssembler::class)->assemble($deal, ['bond' => ['deposit' => false], 'cash' => ['payments' => 1]]);

        $get = fn (string $n) => DealStepInstance::where('dr1_deal_id', $deal->id)->where('name', $n)->first();
        $beetle = $get('Beetle Certificate');
        $rates  = $get('Rates Clearance');
        $elec   = $get('Electrical COC');

        // Drag Beetle onto a SET {Rates, Electrical} — Rates primary, Electrical fan-in.
        $this->post(route('deals-dr2.pipeline.step.follows', [$deal, $beetle]), [
            'depends_on' => [$rates->id, $elec->id], 'follows' => $rates->id, 'offset' => 14,
        ])->assertRedirect();

        $beetle->refresh();
        $this->assertSame((int) $rates->id, (int) $beetle->trigger_step_instance_id);
        $this->assertSame(
            [(int) $elec->id],
            DB::table('deal_step_instance_dependencies')->where('deal_step_instance_id', $beetle->id)
                ->pluck('depends_on_step_instance_id')->map(fn ($i) => (int) $i)->all(),
        );

        // Loop: Rates cannot depend on Beetle (Beetle already follows Rates).
        $ratesTrigBefore = (int) $rates->fresh()->trigger_step_instance_id;
        $this->post(route('deals-dr2.pipeline.step.follows', [$deal, $rates]), [
            'depends_on' => [$beetle->id], 'follows' => $beetle->id, 'offset' => 0,
        ])->assertRedirect();
        $this->assertSame($ratesTrigBefore, (int) $rates->fresh()->trigger_step_instance_id, 'loop rejected — Rates unchanged');
        $this->assertSame(0, DB::table('deal_step_instance_dependencies')->where('deal_step_instance_id', $rates->id)->count());
    }

    /** @return array{0:Deal,1:User} */
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
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        $deal = Deal::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'period' => '2026-03',
            'deal_date' => '2026-03-01', 'property_value' => 2_150_000, 'total_commission' => 107_500,
            'buyer_name' => 'Thandi Mkhize',
        ]);

        return [$deal, $agent];
    }
}
