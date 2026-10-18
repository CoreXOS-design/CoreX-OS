<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceType;
use App\Services\Rentals\RentalFaultContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan, 13 Oct 2026: "we have a way to set up contractor types (Settings -> Service types) which should be linked to the contractor specialty (Supplier Directory)".
 * ONE source of truth: a contractor's trade is its ticked service types. The Trade dropdown on the add form offers the agency's service types, the directory column shows
 * them, and the fault "Suits <fault type>" match reads the same ticks (plus the old fixed trade for suppliers added before the two were linked). The property-sales roles
 * (attorney, bond originator) are untouched.
 */
final class SupplierTradesFromServiceTypesTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Trades');
        foreach ([['COC', 'Electrical COC'], ['Plumbing', 'Plumbing'], ['security', 'Security'], ['general_contractor', 'General Contractor'], ['Other', 'Other']] as $i => [$code, $label]) {
            AgencyServiceType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'code' => $code, 'label' => $label, 'sort_order' => $i, 'is_active' => true]);
        }
    }

    private function ticks(AgencyServiceProvider $p): array
    {
        return DB::table('agency_service_provider_service_types')->where('service_provider_id', $p->id)->whereNull('deleted_at')->pluck('service_type')->sort()->values()->all();
    }

    public function test_the_old_fixed_trades_map_to_the_nearest_service_type_and_everything_else_is_other(): void
    {
        $m = fn ($c, $l = null) => AgencyServiceProvider::legacySpecialtyForType($c, $l);
        $this->assertSame('plumber', $m('Plumbing', 'Plumbing'));
        $this->assertSame('electrician', $m('COC', 'Electrical COC'));
        $this->assertSame('electric_fence', $m('Electric Fence', 'Electric Fence COC'));
        $this->assertSame('entomologist', $m('Beetle', 'Beetle / Entomologist'));
        $this->assertSame('gas', $m('Gas', 'Gas Certificate'));
        $this->assertSame('other', $m('security', 'Security'));
        $this->assertSame('other', $m('general_contractor', 'General Contractor'));
    }

    public function test_the_add_form_offers_the_agencys_service_types_and_keeps_the_sales_roles(): void
    {
        $html = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="type:security"', $html);
        $this->assertStringContainsString('<option value="type:Plumbing"', $html);
        $this->assertStringContainsString('<option value="type:general_contractor"', $html);
        $this->assertStringContainsString('<option value="transfer_attorney"', $html, 'the property-sales roles are still offered');
        $this->assertStringContainsString('<option value="bond_originator"', $html);
        $this->assertStringNotContainsString('<option value="plumber">', $html, 'the old fixed contractor list is no longer the source');
        $this->assertStringNotContainsString('<option value="electrician">', $html);
    }

    public function test_adding_a_supplier_with_a_trade_ticks_the_service_type_and_derives_the_old_specialty(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Atlantic Gate Motors', 'kind' => 'contractor', 'specialty' => 'type:security', 'email' => 'gate@example.invalid'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Sparky Pipes', 'kind' => 'contractor', 'specialty' => 'type:Plumbing', 'email' => 'pipes@example.invalid'])->assertSessionHasNoErrors();

        $gate = AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Atlantic Gate Motors')->firstOrFail();
        $this->assertSame('other', $gate->specialty);
        $this->assertSame(['security'], $this->ticks($gate));
        $pipes = AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Sparky Pipes')->firstOrFail();
        $this->assertSame('plumber', $pipes->specialty, 'the sales-side pickers keep working');
        $this->assertSame(['Plumbing'], $this->ticks($pipes));

        $list = $this->visibleText($this->actingAs($this->admin)->get(route('deals-v2.suppliers.index'))->getContent());
        $this->assertMatchesRegularExpression('/Atlantic Gate Motors.{0,80}Contractor Security/s', preg_replace('/\s+/', ' ', $list), 'the SPECIALTY column shows the service type, not "Other"');
    }

    public function test_an_attorney_is_saved_exactly_as_before(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Jafta Inc', 'kind' => 'attorney_conveyancer', 'specialty' => 'transfer_attorney', 'email' => 'j@example.invalid'])->assertSessionHasNoErrors();

        $p = AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Jafta Inc')->firstOrFail();
        $this->assertSame('transfer_attorney', $p->specialty);
        $this->assertSame('attorney_conveyancer', $p->kind);
        $this->assertSame([], $this->ticks($p));
    }

    public function test_the_fault_match_suits_the_security_contractor_and_a_legacy_plumber(): void
    {
        $gate = $this->supplier('Atlantic Gate', 'gate@example.invalid');
        DB::table('agency_service_provider_service_types')->insert(['agency_id' => $this->agency->id, 'service_provider_id' => $gate->id, 'service_type' => 'security', 'created_at' => now(), 'updated_at' => now()]);
        $legacy = $this->supplier('Old Plumber', 'old@example.invalid');
        $legacy->forceFill(['specialty' => 'plumber'])->save();
        $other = $this->supplier('Painter Pete', 'pete@example.invalid');

        $security = app(RentalFaultContractorService::class)->classifyFor($this->agency->id, 'Gate / garage motor', 'Security');
        $this->assertSame([$gate->id], $security['suits']->pluck('id')->all(), 'Atlantic Gate suits a Gate / garage motor fault');
        $this->assertFalse($security['mapping_gap']);
        $this->assertContains($legacy->id, $security['others']->pluck('id')->all());

        $plumbing = app(RentalFaultContractorService::class)->classifyFor($this->agency->id, 'Blocked drain', 'Plumbing');
        $this->assertSame([$legacy->id], $plumbing['suits']->pluck('id')->all(), 'a supplier added before the link, with only the old "plumber" trade, still suits');
        $this->assertContains($other->id, $plumbing['others']->pluck('id')->all());
    }

    public function test_the_backfill_ticks_the_matching_type_only_for_suppliers_with_no_ticks_and_changes_nothing_else(): void
    {
        $legacy = $this->supplier('Old Plumber', 'old@example.invalid');
        $legacy->forceFill(['specialty' => 'plumber'])->save();
        $ticked = $this->supplier('Has Ticks', 'ticks@example.invalid');
        $ticked->forceFill(['specialty' => 'plumber'])->save();
        DB::table('agency_service_provider_service_types')->insert(['agency_id' => $this->agency->id, 'service_provider_id' => $ticked->id, 'service_type' => 'security', 'created_at' => now(), 'updated_at' => now()]);
        $plain = $this->supplier('Painter Pete', 'pete@example.invalid');

        $run = fn () => (require database_path('migrations/2026_10_29_400000_backfill_supplier_service_types_from_old_specialty.php'))->up();
        $run();
        $run();   // idempotent

        $this->assertSame(['Plumbing'], $this->ticks($legacy));
        $this->assertSame(['security'], $this->ticks($ticked), 'a supplier that already has ticks is left exactly as it is');
        $this->assertSame([], $this->ticks($plain), 'nothing to map for "other"');
        $this->assertSame('plumber', $legacy->fresh()->specialty, 'the old value is untouched');
    }
}
