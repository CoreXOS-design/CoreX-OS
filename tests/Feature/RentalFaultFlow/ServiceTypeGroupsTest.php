<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceType;
use App\Models\DealV2\AgencyServiceTypeGroup;
use App\Services\Rentals\RentalFaultContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan, 13 Oct 2026: "if I add a service type in settings now there's no way to say if it's property sales or your trades". Service types now sit in the agency's own GROUPS
 * (seeded: "Your trades", "Property sales"); the property-sales roles are real, editable rows in "Property sales" that keep their stored `specialty` value; the supplier form's
 * Trade dropdown is grouped by them; the directory filters by group and sorts on the word it shows.
 */
final class ServiceTypeGroupsTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private int $aid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Groups');
        $this->aid = (int) $this->agency->id;
        AgencyServiceType::seedDefaultsFor($this->aid);   // what a new agency gets
        AgencyServiceType::withoutGlobalScopes()->firstOrCreate(['agency_id' => $this->aid, 'code' => 'security'], ['label' => 'Security', 'sort_order' => 20, 'is_active' => true]);
    }

    private function group(string $code): AgencyServiceTypeGroup
    {
        return AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->where('code', $code)->firstOrFail();
    }

    private function type(string $code): AgencyServiceType
    {
        return AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->where('code', $code)->firstOrFail();
    }

    public function test_every_agency_has_the_two_default_groups_the_existing_types_in_trades_and_the_roles_in_property_sales(): void
    {
        $this->assertSame(['Your trades', 'Property sales'], AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->orderBy('sort_order')->pluck('label')->all());
        $this->assertSame($this->group('trades')->id, (int) $this->type('Plumbing')->group_id);
        $this->assertSame($this->group('trades')->id, (int) $this->type('security')->group_id, 'a type added without a group falls into Your trades');

        foreach (['transfer_attorney', 'bond_attorney', 'conveyancer', 'bond_originator', 'external_agency'] as $role) {
            $row = $this->type($role);
            $this->assertSame($this->group('property_sales')->id, (int) $row->group_id, $role);
            $this->assertSame($role, $row->legacy_specialty, 'the stored specialty value each role row stands for is unchanged');
        }
    }

    public function test_the_migration_is_re_runnable_and_only_fills_in_what_is_missing(): void
    {
        // an agency as it was BEFORE groups: no group on its types, no role rows
        AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->whereNotNull('legacy_specialty')->delete();
        AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->update(['group_id' => null]);
        AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->forceDelete();
        $before = AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->whereNull('legacy_specialty')->pluck('label', 'code')->all();

        $run = fn () => (require database_path('migrations/2026_10_29_500000_service_type_groups.php'))->up();
        $run();
        $run();

        $this->assertSame(2, AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->count());
        $this->assertSame(0, AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->whereNull('group_id')->count());
        $this->assertSame($before, AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->whereNull('legacy_specialty')->pluck('label', 'code')->all(), 'no existing type is changed');
        $this->assertSame(5, AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->whereNotNull('legacy_specialty')->count());
        $this->assertSame(
            AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->where('group_id', $this->group('trades')->id)->whereNull('legacy_specialty')->count(),
            count($before),
            'every existing type is in Your trades',
        );
    }

    public function test_the_roles_are_never_work_types_for_the_pipelines_work_orders_ticks_or_the_fault_match(): void
    {
        $this->assertNotContains('transfer_attorney', AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->active()->pluck('code')->all());
        $this->assertContains('transfer_attorney', AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->offered()->pluck('code')->all());

        $html = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="service_types[]" value="transfer_attorney"', $html, 'a role is not a tick box');
        $this->assertStringContainsString('name="service_types[]" value="security"', $html);

        $c = app(RentalFaultContractorService::class)->classifyFor($this->aid, 'Anything', 'Conveyancer');
        $this->assertTrue($c['mapping_gap'], 'a role is not a trade a fault can need');
    }

    public function test_the_supplier_trade_dropdown_is_grouped_by_the_agencys_groups_and_a_new_type_lands_in_the_group_it_was_given(): void
    {
        $letting = $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.groups.store'), ['label' => 'Letting'])->assertSessionHasNoErrors();
        $lettingId = (int) AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->where('label', 'Letting')->value('id');
        $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.store'), ['label' => 'Tenant Vetting', 'group_id' => $lettingId, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.store'), ['label' => 'Valuer', 'group_id' => $this->group('property_sales')->id, 'is_active' => 1])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<optgroup label="Your trades">.*?<option value="type:Plumbing"[^>]*>Plumbing<\/option>.*?<\/optgroup>/s', $html);
        $this->assertMatchesRegularExpression('/<optgroup label="Property sales">.*?<option value="transfer_attorney"[^>]*>Transfer Attorney<\/option>.*?<option value="type:valuer"[^>]*>Valuer<\/option>.*?<\/optgroup>/s', $html, 'a type added to Property sales shows there, next to the roles');
        $this->assertMatchesRegularExpression('/<optgroup label="Letting">\s*<option value="type:tenant_vetting"[^>]*>Tenant Vetting<\/option>/s', $html, 'and a group the agency added shows as its own');
        $this->assertStringNotContainsString('<option value="plumber">', $html);
    }

    public function test_a_service_type_needs_a_group_and_can_be_moved(): void
    {
        $this->actingAs($this->admin)->from(route('deals-v2.settings.service-types.index'))->post(route('deals-v2.settings.service-types.store'), ['label' => 'No Group', 'is_active' => 1])
            ->assertSessionHasErrors('group_id');
        $this->assertSame(0, AgencyServiceType::withoutGlobalScopes()->where('agency_id', $this->aid)->where('label', 'No Group')->count());

        $security = $this->type('security');
        $this->actingAs($this->admin)->put(route('deals-v2.settings.service-types.update', $security), ['label' => 'Security', 'sort_order' => 20, 'is_active' => 1, 'group_id' => $this->group('property_sales')->id])->assertSessionHasNoErrors();
        $this->assertSame($this->group('property_sales')->id, (int) $security->fresh()->group_id);
        $this->assertSame('security', $security->fresh()->code, 'the stored value never changes');

        $this->actingAs($this->admin)->from(route('deals-v2.settings.service-types.index'))->put(route('deals-v2.settings.service-types.update', $security), ['label' => 'Security', 'sort_order' => 20, 'is_active' => 1])
            ->assertSessionHasErrors('group_id');
    }

    public function test_the_settings_list_shows_the_group_column_and_filters_and_sorts_by_group(): void
    {
        $url = route('deals-v2.settings.service-types.index');
        $all = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-sort="group"', $all);
        $this->assertStringContainsString('data-groups-card', $all);

        $sales = $this->visibleText($this->actingAs($this->admin)->get($url . '?group=' . $this->group('property_sales')->id)->getContent());
        $this->assertStringContainsString('Transfer Attorney', $sales);
        $this->assertStringNotContainsString('Beetle / Entomologist', $sales, 'the filter shows only that group');

        $trades = $this->actingAs($this->admin)->get($url . '?group=' . $this->group('trades')->id)->getContent();
        $this->assertStringNotContainsString('data-type-row="transfer_attorney"', $trades);
        $this->assertStringContainsString('data-type-row="security"', $trades);

        $sorted = $this->actingAs($this->admin)->get($url . '?sort=group&dir=desc')->getContent();   // "Your trades" before "Property sales" when descending by name
        $this->assertLessThan(strpos($sorted, 'data-type-row="transfer_attorney"'), strpos($sorted, 'data-type-row="security"'));
    }

    public function test_groups_can_be_added_renamed_reordered_archived_when_empty_and_restored(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.groups.store'), ['label' => 'Letting'])->assertSessionHasNoErrors();
        $g = AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->where('label', 'Letting')->firstOrFail();
        $this->actingAs($this->admin)->from('/x')->post(route('deals-v2.settings.service-types.groups.store'), ['label' => 'Letting'])->assertSessionHasErrors('label');

        $this->actingAs($this->admin)->put(route('deals-v2.settings.service-types.groups.update', $g), ['label' => 'Lettings desk'])->assertSessionHasNoErrors();
        $this->assertSame('Lettings desk', $g->fresh()->label);
        $this->assertSame('letting', $g->fresh()->code, 'the stored code never changes');

        $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.groups.move', $g), ['dir' => 'up']);
        $order = AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->orderBy('sort_order')->pluck('label')->all();
        $this->assertSame(['Your trades', 'Lettings desk', 'Property sales'], $order);

        // a group with service types cannot be archived: nothing is orphaned
        $res = $this->actingAs($this->admin)->from('/x')->delete(route('deals-v2.settings.service-types.groups.destroy', $this->group('property_sales')));
        $res->assertSessionHasErrors('label');
        $this->assertStringContainsString('still has 5 service types', session('errors')->first('label'));
        $this->assertNull($this->group('property_sales')->deleted_at);

        // an empty one can
        $this->actingAs($this->admin)->delete(route('deals-v2.settings.service-types.groups.destroy', $g))->assertSessionHasNoErrors();
        $this->assertNotNull(AgencyServiceTypeGroup::withoutGlobalScopes()->withTrashed()->find($g->id)->deleted_at);
        $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.groups.restore', $g->id))->assertSessionHasNoErrors();
        $this->assertNull(AgencyServiceTypeGroup::withoutGlobalScopes()->find($g->id)->deleted_at);
    }

    public function test_a_type_restored_into_an_archived_group_comes_back_into_your_trades(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.groups.store'), ['label' => 'Temp']);
        $temp = AgencyServiceTypeGroup::withoutGlobalScopes()->where('agency_id', $this->aid)->where('label', 'Temp')->firstOrFail();
        $type = AgencyServiceType::withoutGlobalScopes()->create(['agency_id' => $this->aid, 'code' => 'temp_type', 'label' => 'Temp type', 'sort_order' => 90, 'is_active' => true, 'group_id' => $temp->id]);
        $this->actingAs($this->admin)->delete(route('deals-v2.settings.service-types.destroy', $type));
        $this->actingAs($this->admin)->delete(route('deals-v2.settings.service-types.groups.destroy', $temp))->assertSessionHasNoErrors();   // empty now

        $this->actingAs($this->admin)->post(route('deals-v2.settings.service-types.restore', $type->id));

        $this->assertSame($this->group('trades')->id, (int) AgencyServiceType::withoutGlobalScopes()->find($type->id)->group_id);
    }

    public function test_a_property_sales_role_keeps_its_stored_value_and_attorney_logic(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Jafta Inc', 'kind' => 'attorney_conveyancer', 'specialty' => 'transfer_attorney', 'email' => 'j@example.invalid'])->assertSessionHasNoErrors();
        $p = AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Jafta Inc')->firstOrFail();
        $this->assertSame('transfer_attorney', $p->specialty);
        $this->assertSame('attorney_conveyancer', $p->kind);
        $this->assertSame(0, DB::table('agency_service_provider_service_types')->where('service_provider_id', $p->id)->count(), 'a role is never ticked as a trade');

        // even a hand-crafted "type:" post for a role row stores the role itself
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Ooba Group', 'kind' => 'contractor', 'specialty' => 'type:bond_originator', 'email' => 'o@example.invalid'])->assertSessionHasNoErrors();
        $o = AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Ooba Group')->firstOrFail();
        $this->assertSame('bond_originator', $o->specialty);
        $this->assertSame('bond_originator', $o->kind, 'the existing rule that derives the kind from the role still runs');
        $this->assertSame(0, DB::table('agency_service_provider_service_types')->where('service_provider_id', $o->id)->count());
    }

    public function test_the_directory_filters_by_group_and_sorts_on_the_word_it_shows(): void
    {
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Zed Gate Motors', 'kind' => 'contractor', 'specialty' => 'type:security', 'email' => 'z@example.invalid'])->assertSessionHasNoErrors();   // stored "other", shown "Security"
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Alpha Pipes', 'kind' => 'contractor', 'specialty' => 'type:Plumbing', 'email' => 'a@example.invalid'])->assertSessionHasNoErrors();   // stored "plumber"
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), ['name' => 'Jafta Inc', 'kind' => 'attorney_conveyancer', 'specialty' => 'transfer_attorney', 'email' => 'j@example.invalid'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put(route('deals-v2.settings.service-types.update', $this->type('security')), ['label' => 'Security', 'sort_order' => 20, 'is_active' => 1, 'group_id' => $this->group('trades')->id]);

        $url = route('deals-v2.suppliers.index');
        $trades = $this->visibleText($this->actingAs($this->admin)->get($url . '?group=' . $this->group('trades')->id)->getContent());
        $this->assertStringContainsString('Zed Gate Motors', $trades);
        $this->assertStringContainsString('Alpha Pipes', $trades);
        $this->assertStringNotContainsString('Jafta Inc', $trades);
        $sales = $this->visibleText($this->actingAs($this->admin)->get($url . '?group=' . $this->group('property_sales')->id)->getContent());
        $this->assertStringContainsString('Jafta Inc', $sales);
        $this->assertStringNotContainsString('Alpha Pipes', $sales);

        // the Specialty sort follows the displayed word: Plumbing (Alpha Pipes) < Security (Zed Gate Motors) < Transfer Attorney (Jafta); the old stored values would give other < plumber < transfer_attorney
        $html = $this->actingAs($this->admin)->get($url . '?sort=specialty&dir=asc')->getContent();
        $a = strpos($html, 'Alpha Pipes');
        $z = strpos($html, 'Zed Gate Motors');
        $j = strpos($html, 'Jafta Inc');
        $this->assertTrue($a < $z && $z < $j, 'sorted by Plumbing, Security, Transfer Attorney');
        $this->assertTrue(strpos($html, 'sd-group') !== false, 'the Group filter is on the page');
    }
}
