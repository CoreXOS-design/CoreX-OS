<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\DealV2\DealDateCascade;
use App\Services\DealV2\DealDependencyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-334 (concurrent-lanes rework) · Stage A — the generalized, multi-predecessor
 * Due-date cascade.
 *
 * The predecessor SET is now resolved by DealDependencyResolver = the single primary
 * "follows" pointer UNION the AND-gate fan-in rows in the EXISTING
 * deal_step_instance_dependencies table (no new table). The cascade bases a step's Due on
 * the LATEST of its predecessors' (actual-if-set-else-due) + offset. These tests prove:
 *   1. resolver falls back to the one-element {trigger} set when no dep rows exist;
 *   2. that one-element case reproduces the prior single-follows cascade exactly;
 *   3. a fan-in step (2 predecessors) bases off the LATEST, not the primary;
 *   4. an OLD-model deal (no grant marker / condition_key) is left completely untouched.
 */
final class Dr2CascadeConvergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_falls_back_to_single_follows_when_no_dep_rows(): void
    {
        [$deal, $ids] = $this->newModelDeal();
        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')->get();

        $map = app(DealDependencyResolver::class)->predecessorMap($steps);

        foreach ($steps as $s) {
            $expected = $s->trigger_step_instance_id ? [(int) $s->trigger_step_instance_id] : [];
            $got = $map[(int) $s->id];
            sort($expected);
            sort($got);
            $this->assertSame($expected, $got, "step {$s->name}: set == single {trigger}");
        }
    }

    public function test_single_follows_cascade_matches_legacy_behaviour(): void
    {
        [$deal, $ids] = $this->newModelDeal();
        app(DealDateCascade::class)->recompute($deal);

        // A follows OTP(actual 03-01) +10 → 03-11; C follows A(due 03-11, no actual) +5 → 03-16.
        $this->assertSame('2026-03-11', $this->due($ids['A']), 'A = OTP actual (03-01) + 10');
        $this->assertSame('2026-03-16', $this->due($ids['C']), 'C = A due (03-11) + 5 — single follows');
        $this->assertSame('2026-04-10', $this->due($ids['B']), 'B = OTP actual (03-01) + 40');
    }

    public function test_fan_in_step_bases_off_the_latest_predecessor(): void
    {
        [$deal, $ids] = $this->newModelDeal();
        app(DealDateCascade::class)->recompute($deal);
        $this->assertSame('2026-03-16', $this->due($ids['C']), 'baseline single-follows first');

        // Make C an AND-gate: C now ALSO depends on B (actual captured LATE, 05-01).
        DB::table('deal_step_instance_dependencies')->insert([
            'agency_id'                   => $deal->agency_id,
            'deal_step_instance_id'       => $ids['C'],
            'depends_on_step_instance_id' => $ids['B'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Resolver now reports the 2-element set {A, B}.
        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')->get();
        $set = app(DealDependencyResolver::class)->predecessorMap($steps)[$ids['C']];
        sort($set);
        $this->assertSame([(int) $ids['A'], (int) $ids['B']], $set);

        app(DealDateCascade::class)->recompute($deal);

        // LATEST(A due 03-11, B actual 05-01) = 05-01; + C offset 5 → 05-06 (NOT the 03-16
        // the primary trigger alone would give).
        $this->assertSame('2026-05-06', $this->due($ids['C']), 'C = LATEST predecessor (B actual 05-01) + 5');
    }

    public function test_old_model_deal_is_untouched(): void
    {
        [$deal, $agencyId, $agent] = $this->makeDeal();
        $this->actingAs($agent);

        // Steps with NO grant marker and NO condition_key → isNewModel() is false.
        $root = $this->makeStep($deal, $agencyId, 'Root', null, 0, ['status' => 'completed', 'actual_date' => '2026-03-01']);
        $child = $this->makeStep($deal, $agencyId, 'Child', $root->id, 10, ['due_date' => '2020-01-01']);

        $this->assertFalse(app(DealDateCascade::class)->isNewModel($deal->fresh()));

        app(DealDateCascade::class)->recompute($deal->fresh());

        $this->assertSame('2020-01-01', $this->due($child->id), 'old-model Due is never rewritten');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** A DR1 deal with a small NEW-model pipeline (grant marker + condition_key present). */
    private function newModelDeal(): array
    {
        Carbon::setTestNow('2026-03-01 09:00:00');
        [$deal, $agencyId, $agent] = $this->makeDeal();
        $this->actingAs($agent);

        $otp = $this->makeStep($deal, $agencyId, 'Deal Signed', null, 0, [
            'status' => 'completed', 'actual_date' => '2026-03-01',
        ]);
        $a = $this->makeStep($deal, $agencyId, 'Bond Application', $otp->id, 10, ['condition_key' => 'bond']);
        $b = $this->makeStep($deal, $agencyId, 'Bond Approved', $otp->id, 40, [
            'condition_key' => 'bond', 'actual_date' => '2026-05-01',
        ]);
        $grant = $this->makeStep($deal, $agencyId, 'Granted', $a->id, 0, ['is_grant_marker' => true]);
        $c = $this->makeStep($deal, $agencyId, 'Attorneys', $a->id, 5, []);

        return [$deal->fresh(), ['OTP' => $otp->id, 'A' => $a->id, 'B' => $b->id, 'GRANT' => $grant->id, 'C' => $c->id]];
    }

    private function makeStep(Deal $deal, int $agencyId, string $name, ?int $follows, int $offset, array $extra): DealStepInstance
    {
        static $pos = 0;
        $pos += 10;

        return DealStepInstance::create(array_merge([
            'deal_id'                  => null,
            'dr1_deal_id'              => $deal->id,
            'agency_id'                => $agencyId,
            'pipeline_step_id'         => null,
            'name'                     => $name,
            'position'                 => $pos,
            'is_locked'                => false,
            'is_milestone'             => false,
            'is_custom'                => false,
            'is_suspensive'            => false,
            'is_grant_marker'          => false,
            'condition_key'            => null,
            'completion_type'          => 'manual_tick',
            'status'                   => 'not_started',
            'trigger_type'             => $follows ? 'after_step' : 'on_creation',
            'trigger_step_instance_id' => $follows,
            'days_offset'              => $offset,
            'rag_green_days'           => 14,
            'rag_amber_days'           => 7,
            'rag_red_days'             => 3,
            'current_rag'              => 'grey',
            'notify_agent'             => true,
            'notify_bm'                => true,
            'notify_admin'             => false,
            'approval_status'          => 'not_required',
        ], $extra));
    }

    private function due($stepId): ?string
    {
        $d = DealStepInstance::find($stepId)->due_date;
        return $d ? Carbon::parse($d)->format('Y-m-d') : null;
    }

    /** @return array{0:Deal,1:int,2:User} */
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

        return [$deal, $agencyId, $agent];
    }
}
