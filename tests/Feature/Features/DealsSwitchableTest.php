<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Services\Features\AgencyFeatureService;
use App\Services\Features\AgencyFeatureWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-478 — Deals is a switchable feature (spec agency-feature-rollout.md §3.3). On by default, so every
 * existing agency is unchanged; off hides the deal register, and what is SHARED with other areas stays.
 */
final class DealsSwitchableTest extends TestCase
{
    use RefreshDatabase;

    private function owned(string $routeName): bool
    {
        return Str::is((array) config('corex-features.deals.route_names'), $routeName);
    }

    public function test_deals_is_switchable_and_on_by_default(): void
    {
        $features = app(AgencyFeatureService::class);
        $agency = Agency::create(['name' => 'Deals ' . uniqid(), 'slug' => 'deals-' . uniqid()]);

        $this->assertFalse($features->isCore('deals'));
        $this->assertTrue($features->enabled('deals', $agency));
        $this->assertContains('deals', $features->moduleFeatureKeys(), 'shows on the Features page and in the wizard');
    }

    public function test_the_deal_register_is_owned_but_shared_suppliers_and_lookups_are_not(): void
    {
        foreach (['admin.deals', 'admin.deals.edit', 'agent.deals.index', 'deals-v2.show', 'deals-v2.index',
                  'deals-dr2.pipeline', 'deals-dr2.unfiled-emails.index', 'deals-v2.search.deals', 'api.v1.deals.index'] as $name) {
            $this->assertTrue($this->owned($name), "$name belongs to Deals");
        }
        foreach (['deals-v2.suppliers.index', 'deals-v2.settings.service-types.index', 'deals-v2.search.properties',
                  'deals-v2.search.contacts', 'deals-dr2.search.contacts', 'deals-dr2.suppliers.search', 'deals-dr2.attorney.search'] as $name) {
            $this->assertFalse($this->owned($name), "$name is shared (rentals / other screens) and must stay available");
        }

        $features = app(AgencyFeatureService::class);
        $this->assertSame('deals', $features->featureForPermission('deals_v2.view'));
        $this->assertSame('deals', $features->featureForPermission('view_deals'));
        $this->assertNull($features->featureForPermission('deals_v2.manage_suppliers'), 'suppliers stay available');
        $this->assertSame('proforma-invoices', $features->featureForPermission('proforma.generate'));
    }

    public function test_with_deals_off_the_menu_hides_the_deal_register_and_its_pages_refuse(): void
    {
        \App\Http\Middleware\EnforceFeatureRoutes::flush();
        $agency = Agency::create(['name' => 'Menu ' . uniqid(), 'slug' => 'menu-' . uniqid()]);
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = \App\Models\User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $register = route('deals-dr2.index');
        $myDeals = route('agent.deals.index');

        $html = $this->actingAs($admin)->get(route('agent.portal'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . $register . '"', $html, 'on by default: the menu shows the register');

        app(AgencyFeatureWriter::class)->write($agency, ['deals' => false]);
        app(AgencyFeatureService::class)->forget();

        $html = $this->actingAs($admin)->get(route('agent.portal'))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="' . $register . '"', $html);
        $this->assertStringNotContainsString('href="' . $myDeals . '"', $html);
        $this->actingAs($admin)->get(route('admin.deals'))->assertNotFound();
        $this->actingAs($admin)->get($register)->assertNotFound();
    }

    public function test_switching_deals_off_takes_its_dependants_with_it_and_nothing_else(): void
    {
        $features = app(AgencyFeatureService::class);
        $agency = Agency::create(['name' => 'Deals ' . uniqid(), 'slug' => 'deals-' . uniqid()]);
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);

        app(AgencyFeatureWriter::class)->write($agency, ['deals' => false]);
        $features->forget();

        $this->assertFalse($features->enabled('deals', $agency));
        $this->assertFalse($features->enabled('commission-management', $agency));
        $this->assertFalse($features->enabled('proforma-invoices', $agency));
        $this->assertTrue($features->enabled('agency-tracker', $agency), 'the tracker is more than the deal register');
        $this->assertTrue($features->enabled('rentals', $agency));
        $this->assertTrue($features->enabled('deals', $other), 'another agency is untouched');
    }
}
