<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\User;
use App\Services\DealV2\DealPipelineTemplateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS-R1 — default pipeline templates: idempotent, additive, agency-safe
 * provisioning (the de-landmined replacement for the seeder's agency-blind
 * forceDelete) + the "Load standard templates" affordance.
 */
final class DealPipelineDefaultTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private DealPipelineTemplateProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisioner = app(DealPipelineTemplateProvisioner::class);
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // A real agency always has an admin — the seeder-style null-creator path
        // resolves attribution to this user (mirrors reality, BUILD_STANDARD §5).
        User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'is_admin' => true,
        ]);

        return $agencyId;
    }

    private function tally(int $agencyId): array
    {
        $tpls = DealPipelineTemplate::withoutGlobalScopes()->where('agency_id', $agencyId);
        $ids = $tpls->pluck('id');
        $steps = DealPipelineStep::withoutGlobalScopes()->whereIn('pipeline_template_id', $ids)->count();

        return [$tpls->count(), $steps];
    }

    public function test_provisions_three_defaults_with_all_steps(): void
    {
        $agencyId = $this->makeAgency();
        $creator = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $result = $this->provisioner->provisionDefaultsForAgency($agencyId, $creator->id);

        $this->assertSame(3, $result['created']);
        $this->assertSame(40, $result['steps_created'], '15 (bond) + 9 (cash) + 16 (sale_of_2nd)');
        [$tpls, $steps] = $this->tally($agencyId);
        $this->assertSame(3, $tpls);
        $this->assertSame(40, $steps);

        // The bond template is the default; each type present exactly once.
        $bond = DealPipelineTemplate::withoutGlobalScopes()->where('agency_id', $agencyId)->where('deal_type', 'bond')->first();
        $this->assertTrue((bool) $bond->is_default);
        $this->assertSame(15, $bond->steps()->count());
        // Trigger links resolved (after_step points at a real sibling id).
        $this->assertNotNull($bond->steps()->where('name', 'Bond Approved')->first()->trigger_step_id);
    }

    public function test_is_idempotent_no_duplication_on_rerun(): void
    {
        $agencyId = $this->makeAgency();
        $this->provisioner->provisionDefaultsForAgency($agencyId, null);
        [$tpl1, $step1] = $this->tally($agencyId);

        $second = $this->provisioner->provisionDefaultsForAgency($agencyId, null);
        [$tpl2, $step2] = $this->tally($agencyId);

        $this->assertSame(0, $second['created']);
        $this->assertSame(3, $second['skipped']);
        $this->assertSame(0, $second['steps_created']);
        $this->assertSame($tpl1, $tpl2);
        $this->assertSame($step1, $step2);
    }

    public function test_does_not_clobber_a_customised_template(): void
    {
        $agencyId = $this->makeAgency();

        $creatorId = User::withoutGlobalScopes()->where('agency_id', $agencyId)->value('id');

        // Agency already has a customised bond template (its own steps).
        $custom = DealPipelineTemplate::create([
            'name' => 'Standard Bond Sale', 'deal_type' => 'bond', 'agency_id' => $agencyId,
            'branch_id' => null, 'is_default' => true, 'is_active' => true, 'created_by_id' => $creatorId,
        ]);
        $custom->steps()->create([
            'agency_id' => $agencyId, 'name' => 'My Custom Step', 'position' => 1,
            'is_locked' => false, 'is_milestone' => false, 'completion_type' => 'manual_tick',
            'trigger_type' => 'on_creation', 'days_offset' => 0, 'rag_amber_days' => 5, 'rag_red_days' => 2,
        ]);

        $this->provisioner->provisionDefaultsForAgency($agencyId, null);

        // Custom template untouched (still 1 step, still the same step), and NOT
        // duplicated (matched by name+type). Cash + Sale-of-2nd added alongside.
        $this->assertSame(1, $custom->fresh()->steps()->count(), 'custom steps preserved — not force-recreated');
        $this->assertSame('My Custom Step', $custom->steps()->first()->name);
        $this->assertSame(1, DealPipelineTemplate::withoutGlobalScopes()->where('agency_id', $agencyId)->where('deal_type', 'bond')->count(), 'no duplicate bond template');
        $this->assertSame(3, DealPipelineTemplate::withoutGlobalScopes()->where('agency_id', $agencyId)->count());
    }

    public function test_is_agency_scoped(): void
    {
        $agencyA = $this->makeAgency();
        $agencyB = $this->makeAgency();

        $this->provisioner->provisionDefaultsForAgency($agencyA, null);

        [$tplA] = $this->tally($agencyA);
        [$tplB] = $this->tally($agencyB);
        $this->assertSame(3, $tplA);
        $this->assertSame(0, $tplB, 'provisioning agency A must not create templates for agency B');
    }

    public function test_load_defaults_route_provisions_for_own_agency(): void
    {
        $agencyId = $this->makeAgency();
        $admin = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin', 'is_active' => true,
        ]);

        $resp = $this->actingAs($admin)->post(route('deals-v2.pipeline.load-defaults'));

        $resp->assertRedirect(route('deals-v2.pipeline.index'));
        [$tpls, $steps] = $this->tally($agencyId);
        $this->assertSame(3, $tpls);
        $this->assertSame(40, $steps);
    }
}
