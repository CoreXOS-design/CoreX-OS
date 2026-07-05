<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealV2;
use App\Models\User;
use App\Services\DealV2\DealPipelineTemplateProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-158 WS-V5 — the shipped default templates, corrected against the canonical
 * SA conveyancing process, and the no-hard-delete refresh/upgrade path.
 */
final class DealTemplateCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private DealPipelineTemplateProvisioner $provisioner;
    private int $agencyId;
    private int $creatorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisioner = app(DealPipelineTemplateProvisioner::class);

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->creatorId = User::factory()->create([
            'agency_id' => $this->agencyId, 'is_admin' => true, 'is_active' => true,
        ])->id;
    }

    public function test_bond_template_matches_the_corrected_sa_process(): void
    {
        $this->provisioner->provisionDefaultsForAgency($this->agencyId, $this->creatorId);
        $bond = DealPipelineTemplate::where('agency_id', $this->agencyId)->where('deal_type', 'bond')->first();
        $steps = $bond->steps()->orderBy('position')->get()->keyBy('name');

        // Deposit now sequences from OTP (not from bond grant).
        $this->assertSame('OTP Signed', $this->triggerName($steps['Deposit Paid'], $steps));

        // The five previously-missing conveyancing steps are present.
        foreach (['Bond Cancellation Figures', 'Guarantees Issued', 'Documents Signed',
                  'Transfer Duty / SARS Receipt', 'Levy / HOA Consent', 'Beetle Certificate'] as $name) {
            $this->assertArrayHasKey($name, $steps->toArray(), "$name present");
        }

        // Water Installation COC dropped from the KZN default.
        $this->assertArrayNotHasKey('Water Installation COC', $steps->toArray());

        // Bond Approved + Deposit are suspensive conditions.
        $this->assertTrue((bool) $steps['Bond Approved']->is_suspensive);
        $this->assertTrue((bool) $steps['Deposit Paid']->is_suspensive);

        // Deeds Office Lodgement AND-gates on the full preparation cluster.
        $lodgement = $steps['Deeds Office Lodgement'];
        $depNames = $this->dependencyNames($lodgement);
        foreach (['Documents Signed', 'Electrical COC', 'Beetle Certificate', 'Guarantees Issued', 'Transfer Duty / SARS Receipt'] as $need) {
            $this->assertContains($need, $depNames, "Lodgement waits on $need");
        }

        // Documents Signed AND-gates on both FICA steps.
        $depNamesSign = $this->dependencyNames($steps['Documents Signed']);
        $this->assertContains('FICA Completed (Buyer)', $depNamesSign);
        $this->assertContains('FICA Completed (Seller)', $depNamesSign);
    }

    public function test_cash_template_carries_the_coastal_beetle_certificate(): void
    {
        $this->provisioner->provisionDefaultsForAgency($this->agencyId, $this->creatorId);
        $cash = DealPipelineTemplate::where('agency_id', $this->agencyId)->where('deal_type', 'cash')->first();
        $names = $cash->steps()->pluck('name')->all();

        $this->assertContains('Beetle Certificate', $names, 'coastal cash sale still needs a beetle cert');
        $this->assertContains('Transfer Duty / SARS Receipt', $names);
        $this->assertNotContains('Water Installation COC', $names);
    }

    public function test_refresh_replaces_deal_free_templates_but_preserves_in_use(): void
    {
        $this->provisioner->provisionDefaultsForAgency($this->agencyId, $this->creatorId);
        $bondBefore = DealPipelineTemplate::where('agency_id', $this->agencyId)->where('deal_type', 'bond')->first();
        $cashBefore = DealPipelineTemplate::where('agency_id', $this->agencyId)->where('deal_type', 'cash')->first();

        // Put a deal on the bond template so it is "in use".
        $property = \App\Models\Property::withoutEvents(fn () => \App\Models\Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8), 'title' => '5 Marine Drive, Margate',
            'address' => '5 Marine Drive, Margate', 'agent_id' => $this->creatorId,
            'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
        ]));
        DealV2::withoutGlobalScopes()->create([
            'agency_id' => $this->agencyId, 'reference' => 'DL-2026-90001', 'deal_type' => 'bond',
            'status' => 'active', 'property_id' => $property->id, 'pipeline_template_id' => $bondBefore->id,
            'listing_agent_id' => $this->creatorId, 'purchase_price' => 1_000_000,
            'commission_amount' => 50_000, 'commission_vat' => 7_500,
            'offer_date' => '2026-03-01', 'branch_id' => $this->agencyId, 'created_by_id' => $this->creatorId,
        ]);

        $r = $this->provisioner->refreshDefaultsForAgency($this->agencyId, $this->creatorId);

        // Bond kept (a live deal depends on it); cash refreshed (deal-free).
        $this->assertSame(1, $r['skipped_in_use']);
        $this->assertSame($bondBefore->id, DealPipelineTemplate::where('agency_id', $this->agencyId)->where('deal_type', 'bond')->first()->id, 'in-use template preserved');
        $cashAfter = DealPipelineTemplate::where('agency_id', $this->agencyId)->where('deal_type', 'cash')->first();
        $this->assertNotSame($cashBefore->id, $cashAfter->id, 'deal-free template re-provisioned fresh');
        $this->assertSoftDeleted('deal_pipeline_templates', ['id' => $cashBefore->id]); // no hard delete
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function triggerName(DealPipelineStep $step, $steps): string
    {
        $parent = $steps->first(fn ($s) => $s->id === $step->trigger_step_id);

        return $parent?->name ?? '';
    }

    private function dependencyNames(DealPipelineStep $step): array
    {
        return DealPipelineStep::whereIn('id',
            DB::table('deal_pipeline_step_dependencies')->where('pipeline_step_id', $step->id)->pluck('depends_on_step_id')
        )->pluck('name')->all();
    }
}
