<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\Features\AgencyTypeFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New-agency walk, 14 Oct 2026: a brand-new agency started with syndication, payroll and every sales tool switched
 * on, whatever it does — and the syndication/marketing switches were not even its own: with no row of its own it
 * read the platform-wide row, which carries Home Finders Coastal's choices.
 *
 * The agency now says what it does (sales / rentals / both, default both) and its switches are seeded from that
 * (config/agency-types.php). Existing agencies are left exactly as they are. The admin can still switch any on.
 */
final class AgencyTypeFeatureSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = \App\Models\Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        \App\Models\Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);
    }

    /** The owner creating an agency through the real form. */
    private function createAgencyThroughTheForm(array $extra = []): Agency
    {
        // The owner is "viewing as" an unrelated agency (Agency Switcher) — the new agency's rows must still land on
        // the NEW agency, not on the one the owner happens to be looking at.
        $other = Agency::firstOrCreate(['slug' => 'switched-into'], ['name' => 'Switched Into']);
        $this->withSession(['active_agency_id' => $other->id]);
        $this->actingAs($this->owner())->post(route('agencies.store'), array_merge([
            'name' => 'Cape Town Lettings ' . uniqid(),
            'is_demo' => '1',
            'branch_name' => 'Head Office',
            'branch_code' => 'HQ',
        ], $extra))->assertRedirect()->assertSessionHasNoErrors();

        $this->flushSession();   // the owner's switcher session must not follow the agency admins used below
        $created = Agency::withoutGlobalScopes()->where('slug', '!=', 'switched-into')->latest('id')->firstOrFail();
        $this->assertSame(0, AgencyFeature::withoutGlobalScopes()->where('agency_id', $other->id)->count(), 'nothing may land on the agency the owner was viewing');

        return $created;
    }

    private function state(Agency $agency): array
    {
        app(AgencyFeatureService::class)->forget($agency->id);

        return app(AgencyFeatureService::class)->all($agency);
    }

    public function test_a_rentals_agency_starts_without_the_sales_tools_syndication_and_payroll(): void
    {
        // The platform-wide rows carry another agency's choices — they must not leak into the new agency.
        PerformanceSetting::set('syndication_p24_enabled', 1, null);
        PerformanceSetting::set('syndication_pp_enabled', 1, null);
        PerformanceSetting::set('marketing_enabled', 0, null);

        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'rentals']);
        $on = $this->state($agency);

        $this->assertSame('rentals', $agency->fresh()->agency_type);
        foreach (config('agency-types.sales_features') as $key) {
            $this->assertFalse($on[$key], "$key is a sales tool and must start off for a rentals-only agency");
        }
        foreach (['syndication-p24', 'syndication-pp', 'payroll'] as $key) {
            $this->assertFalse($on[$key], "$key must start off");
        }
        $this->assertTrue($on['rentals']);
        $this->assertTrue($on['rental-leases']);
        $this->assertTrue($on['marketing'], "marketing starts at the neutral default, not at another agency's choice");
        // Core stays core.
        $this->assertTrue($on['properties']);
        $this->assertTrue($on['contacts']);
        // children cascade with their parent
        $this->assertFalse($on['road-to-sold']);
        $this->assertFalse($on['commission-management']);
        $this->assertFalse($on['leave']);
    }

    public function test_a_sales_agency_starts_without_the_rentals_area(): void
    {
        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'sales']);
        $on = $this->state($agency);

        $this->assertFalse($on['rentals']);
        $this->assertFalse($on['rental-leases']);
        $this->assertTrue($on['prospecting']);
        $this->assertTrue($on['core-matches']);
        $this->assertFalse($on['syndication-p24']);
        $this->assertFalse($on['payroll']);
    }

    public function test_an_agency_that_does_both_gets_both_and_both_is_the_default(): void
    {
        $agency = $this->createAgencyThroughTheForm();   // no type posted
        $on = $this->state($agency);

        $this->assertSame('both', $agency->fresh()->agency_type);
        $this->assertTrue($on['rentals']);
        $this->assertTrue($on['prospecting']);
        $this->assertTrue($on['core-matches']);
        $this->assertFalse($on['syndication-p24'], 'syndication needs portal credentials — the agency opts in');
        $this->assertFalse($on['payroll']);
    }

    public function test_the_new_agency_has_its_own_rows_for_the_switches_that_used_to_fall_back_to_the_platform_row(): void
    {
        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'both']);

        foreach (['marketing_enabled', 'syndication_p24_enabled', 'syndication_pp_enabled', 'matches_enabled'] as $key) {
            $this->assertTrue(
                PerformanceSetting::where('agency_id', $agency->id)->where('key', $key)->exists(),
                "$key must be pinned to the new agency's own row"
            );
        }
    }

    public function test_existing_agencies_are_left_exactly_as_they_are(): void
    {
        $existing = Agency::create(['name' => 'Old Agency', 'slug' => 'old-agency']);
        $before = $this->state($existing);

        $this->createAgencyThroughTheForm(['agency_type' => 'rentals']);

        $this->assertSame('both', $existing->fresh()->agency_type, 'existing agencies default to both');
        $this->assertSame(0, AgencyFeature::withoutGlobalScopes()->where('agency_id', $existing->id)->count());
        $this->assertSame($before, $this->state($existing));
    }

    public function test_the_admin_can_still_switch_any_feature_on(): void
    {
        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'rentals']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->post(route('corex.settings.features.update'), ['payroll' => '1', 'prospecting' => '1'])->assertRedirect();

        $on = $this->state($agency);
        $this->assertTrue($on['payroll']);
        $this->assertTrue($on['prospecting']);
        $this->assertTrue($on['road-to-sold']);
    }

    public function test_changing_the_type_later_re_applies_only_the_types_own_switches(): void
    {
        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'rentals']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        // The admin has chosen payroll on, and training off — neither belongs to the type.
        $this->actingAs($admin)->post(route('corex.settings.features.update'), ['payroll' => '1', 'training' => '0']);

        $this->actingAs($admin)->post(route('corex.settings.agency-type.update'), ['agency_type' => 'both'])
            ->assertRedirect(route('corex.features.index'));

        $on = $this->state($agency);
        $this->assertSame('both', $agency->fresh()->agency_type);
        $this->assertTrue($on['core-matches'], 'sales tools come back with the type');
        $this->assertTrue($on['prospecting']);
        $this->assertTrue($on['payroll'], "the admin's own choice survives");
        $this->assertFalse($on['training'], "the admin's own choice survives");
    }

    public function test_saving_the_same_type_changes_nothing_and_a_post_without_the_field_is_ignored(): void
    {
        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'rentals']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        // The admin switches a sales tool on by hand.
        $this->actingAs($admin)->post(route('corex.settings.features.update'), ['presentations' => '1']);

        $this->actingAs($admin)->post(route('corex.settings.agency-type.update'), ['agency_type' => 'rentals']);
        $this->actingAs($admin)->post(route('corex.settings.agency-type.update'), []);

        $this->assertTrue($this->state($agency)['presentations'], 'saving the same type must not undo a deliberate switch');
    }

    public function test_the_wizard_identity_step_changes_the_type_and_the_next_step_shows_the_result(): void
    {
        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'both']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);

        $page = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'identity']))->assertOk();
        $page->assertSee('What does your agency do?');

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'identity']), [
            'agency_type' => 'rentals',
        ])->assertRedirect();

        $this->assertSame('rentals', $agency->fresh()->agency_type);
        $this->assertFalse($this->state($agency)['core-matches']);
    }

    public function test_the_features_page_offers_the_type(): void
    {
        $agency = $this->createAgencyThroughTheForm(['agency_type' => 'rentals']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->get(route('corex.features.index'))
            ->assertOk()
            ->assertSee('What does your agency do?')
            ->assertSee('Rentals only');
    }

    public function test_the_type_lists_only_name_real_features_and_the_wizard_options_match_the_config(): void
    {
        $registry = config('corex-features');
        foreach (['sales_features', 'rentals_features', 'opt_in_features'] as $list) {
            foreach (config("agency-types.$list") as $key) {
                $this->assertArrayHasKey($key, $registry, "agency-types.$list names unknown feature '$key'");
                $this->assertFalse($registry[$key]['core'], "$key is core and cannot be switched");
            }
        }
        $wizard = collect(config('agency-onboarding-copy.identity.controls'))->firstWhere('key', 'agency_type');
        $this->assertSame(AgencyTypeFeatureSeeder::options(), $wizard['options']);
        $this->assertSame(config('agency-types.default'), $wizard['default']);
    }
}
