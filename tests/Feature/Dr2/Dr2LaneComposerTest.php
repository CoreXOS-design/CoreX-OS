<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\DealV2\DealLaneComposer;
use App\Services\DealV2\DealStructureAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-334 (concurrent-lanes rework) · Stage B — honest convergence + the lane composer.
 *
 * A bond+cash deal assembles to the target board: condition lanes converge on the Granted
 * gate; Stage 2 = Attorneys (sequence) → 5 concurrent lanes → Deeds Office Lodgement
 * (sequence, gated on ALL lane tails via deal_step_instance_dependencies) → Registration ∥
 * Payment. Convergence is a real fan-in graph, not just a visual grouping.
 */
final class Dr2LaneComposerTest extends TestCase
{
    use RefreshDatabase;

    public function test_bond_cash_deal_composes_to_the_target_lane_board(): void
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agent] = $this->makeDeal();
        $this->actingAs($agent);

        app(DealStructureAssembler::class)->assemble($deal, ['bond' => ['deposit' => false], 'cash' => ['payments' => 1]]);
        $deal->refresh();

        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')->get();
        $board = app(DealLaneComposer::class)->board($steps);

        $this->assertSame('Deal Signed', $board['anchor']->name);
        $this->assertSame('Granted', $board['gate']->name);

        // Honest fan-in written to the EXISTING dependency table.
        $lodgeId = $steps->firstWhere('name', 'Deeds Office Lodgement')->id;
        $this->assertSame(4, DB::table('deal_step_instance_dependencies')->where('deal_step_instance_id', $lodgeId)->count());

        $s2 = $board['stage2'];
        $this->assertSame('sequence', $s2[0]['type']);
        $this->assertSame('Attorneys Instructed', $s2[0]['step']->name);
        $this->assertSame('band', $s2[1]['type']);
        $this->assertCount(5, $s2[1]['lanes']);
        $fica = collect($s2[1]['lanes'])->first(fn ($l) => $l[0]->name === 'FICA Completed (Buyer)');
        $this->assertSame(['FICA Completed (Buyer)', 'FICA Completed (Seller)'], array_map(fn ($s) => $s->name, $fica));
        $this->assertSame('sequence', $s2[2]['type']);
        $this->assertSame('Deeds Office Lodgement', $s2[2]['step']->name);
        $this->assertSame('band', $s2[3]['type']);
        $this->assertCount(2, $s2[3]['lanes']);

        // Stage 1 conditions read as lanes (they converge on the gate bar).
        $s1lanes = collect($board['stage1'])->where('type', 'band')->flatMap(fn ($seg) => $seg['lanes']);
        $this->assertTrue($s1lanes->contains(fn ($l) => collect($l)->contains(fn ($s) => $s->name === 'Bond Approved')));
        $this->assertTrue($s1lanes->contains(fn ($l) => collect($l)->contains(fn ($s) => $s->name === 'Proof of Funds')));
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
