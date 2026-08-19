<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Exceptions\MissingAgencyContextException;
use App\Models\Deal;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Models\User;
use App\Services\Deal\Dr1PipelineService;
use App\Services\DealV2\DealStructureAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * QA1 incident (2026-08-02, deal 218) — an unscoped owner/super_admin building a CASH deal
 * hit SQLSTATE[23000] 1452 on deal_step_instances_agency_id_foreign: the deal's agency_id
 * was never stamped (BelongsToAgency's single-agency fallback is a no-op on any
 * multi-agency install), and DealStructureAssembler::assemble() cast that null to the
 * invalid sentinel 0 via `(int) $deal->agency_id`.
 *
 * Fix (STANDARDS Rule 17 / AT-253 pattern): block deal CREATION for an actor with no
 * resolvable agency before any deal or pipeline row is built, and add defense-in-depth
 * guards in every downstream builder so an invalid agency_id can never reach a
 * deal_step_instances insert.
 */
final class Dr2AgencyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unscoped_owner_creating_a_cash_deal_is_blocked_with_a_friendly_error_not_a_db_exception(): void
    {
        // Two agencies — matches the QA1 shape where the single-agency fallback is a no-op.
        $this->makeAgency();
        $branchId = $this->makeAgency();

        $owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);
        $this->assertNull($owner->effectiveAgencyId(), 'precondition: no agency context');

        $dealsBefore = DB::table('deals')->count();
        $stepsBefore = DB::table('deal_step_instances')->count();

        $resp = $this->actingAs($owner)->post(route('deals-dr2.store'), [
            'period' => '2026-08', 'deal_date' => '2026-08-02', 'deal_type' => 'cash',
            'property_value' => 1_200_000, 'total_commission' => 60_000,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'branch_id' => $branchId,
            'listing_agents' => [], 'selling_agents' => [],
        ]);

        // Friendly validation error — never a raw QueryException / 500.
        $resp->assertSessionHasErrors('agency_id');
        $this->assertSame($dealsBefore, DB::table('deals')->count(), 'no deal row was created');
        $this->assertSame($stepsBefore, DB::table('deal_step_instances')->count(), 'no pipeline row was created');
        $this->assertSame(0, DB::table('deal_step_instances')->where('agency_id', 0)->count());
    }

    public function test_a_properly_scoped_user_creates_a_cash_deal_end_to_end_with_the_correct_agency_id(): void
    {
        $agencyId = $this->makeAgency();
        // super_admin bypasses permission checks (owner role) but is fully agency-scoped here
        // (agency_id + branch_id set → effectiveAgencyId() resolves to a real value) — this is
        // the "properly scoped" shape, distinct from Johan's unscoped-owner QA1 case above.
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        $this->assertSame($agencyId, $agent->effectiveAgencyId());

        $resp = $this->actingAs($agent)->post(route('deals-dr2.store'), [
            'period' => '2026-08', 'deal_date' => '2026-08-02', 'deal_type' => 'cash',
            'property_value' => 1_200_000, 'total_commission' => 60_000,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'branch_id' => $agencyId,
            'listing_agents' => [$agent->id], 'selling_agents' => [$agent->id],
        ]);

        $resp->assertSessionDoesntHaveErrors();
        $deal = Deal::where('agency_id', $agencyId)->latest('id')->first();
        $this->assertNotNull($deal, 'the deal was created');
        $this->assertSame($agencyId, (int) $deal->agency_id);

        // End-to-end: build the pipeline (the exact action that 1452'd for deal 218).
        app(DealStructureAssembler::class)->assemble($deal, ['cash' => ['payments' => 1]]);

        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)->get();
        $this->assertGreaterThan(0, $steps->count(), 'pipeline steps were created');
        $this->assertTrue(
            $steps->every(fn ($s) => (int) $s->agency_id === $agencyId),
            'every step instance carries the deal\'s real agency_id'
        );
    }

    public function test_dr1_pipeline_service_refuses_a_null_agency_deal_instead_of_a_1048(): void
    {
        // Two agencies — the single-agency fallback must NOT rescue this deal.
        $this->makeAgency();
        $agencyId = $this->makeAgency();

        // No acting user (console-shaped) + no explicit agency_id + 2 agencies present
        // → BelongsToAgency leaves agency_id NULL, exactly as it did for QA1 deal 218.
        $deal = Deal::create([
            'branch_id' => $agencyId, 'period' => '2026-08', 'deal_date' => '2026-08-02',
            'property_value' => 1_200_000, 'total_commission' => 60_000, 'accepted_status' => 'P',
        ]);
        $this->assertNull($deal->agency_id, 'precondition: the deal has no agency stamped');

        $templateOwner = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        $template = DealPipelineTemplate::create([
            'name' => 'Cash', 'deal_type' => 'cash', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $templateOwner->id,
        ]);
        DealPipelineStep::create([
            'pipeline_template_id' => $template->id, 'agency_id' => $agencyId,
            'position' => 1, 'name' => 'Deal Signed', 'is_locked' => false, 'is_milestone' => true,
            'completion_type' => 'date_input', 'trigger_type' => 'on_creation', 'days_offset' => 0,
            'rag_amber_days' => 7, 'rag_red_days' => 3,
            'notify_agent' => false, 'notify_bm' => false, 'notify_admin' => false,
        ]);

        $stepsBefore = DB::table('deal_step_instances')->count();

        $this->expectException(MissingAgencyContextException::class);
        try {
            app(Dr1PipelineService::class)->createPipeline($deal, $template->id);
        } finally {
            $this->assertSame($stepsBefore, DB::table('deal_step_instances')->count(), 'no row was inserted — refused before the FK/NOT-NULL crash');
        }
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
