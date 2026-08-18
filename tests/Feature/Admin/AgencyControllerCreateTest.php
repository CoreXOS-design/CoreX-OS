<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression coverage for the bug where creating a NEW agency (with its
 * admin) while the acting owner had an unrelated Agency Switcher session
 * active caused the new admin User and the AgencyOnboardingSetup record to
 * be silently stamped onto the SWITCHED-INTO agency instead of the
 * brand-new one — so the onboarding link resolved to the wrong agency.
 *
 * Root cause: BelongsToAgency::bootBelongsToAgency()'s creating() hook
 * treats an owner with session('active_agency_id') set as a SCOPED user
 * (isUnscopedOwner() === false) and force-overwrites any explicit
 * agency_id with the owner's effectiveAgencyId() — even when the caller
 * (AgencyController@store / CreateAgencySetupPortal listener) explicitly
 * and correctly set agency_id to the just-created agency's own id.
 *
 * Fix: BelongsToAgency::withoutAgencyStamping() lets trusted server code
 * bypass that auto-stamp for an explicitly-validated agency_id.
 */
class AgencyControllerCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function ownerUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();
        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    public function test_new_agency_admin_and_onboarding_setup_are_not_stamped_onto_switched_agency(): void
    {
        Mail::fake();

        $owner = $this->ownerUser();

        // The owner is currently "viewing as" an unrelated, pre-existing
        // agency via the Agency Switcher — a completely normal workflow
        // that must not leak into unrelated record creation.
        $switchedAgency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'home-finders-coastal']);

        $this->actingAs($owner);
        $this->withSession(['active_agency_id' => $switchedAgency->id]);

        $response = $this->post(route('agencies.store'), [
            'name'         => 'Demo Agency Test',
            'is_demo'      => false,
            'is_active'    => true,
            'admin_name'   => 'Demo Admin',
            'admin_email'  => 'demo-admin@example.test',
        ]);

        $response->assertRedirect();

        $newAgency = Agency::where('name', 'Demo Agency Test')->firstOrFail();
        $this->assertNotEquals($switchedAgency->id, $newAgency->id);

        $admin = User::where('email', 'demo-admin@example.test')->firstOrFail();
        $this->assertSame($newAgency->id, $admin->agency_id, 'New admin must belong to the new agency, not the switched-into agency.');

        $setup = AgencyOnboardingSetup::queryWithoutAgencyScope()->where('admin_user_id', $admin->id)->firstOrFail();
        $this->assertSame($newAgency->id, $setup->agency_id, 'Onboarding setup/link must be scoped to the new agency, not the switched-into agency.');
    }

    /**
     * A brand-new agency has no towns/suburbs/price bands of its own — none
     * of that can be auto-cloned the way the WhatsApp template default is,
     * since it's genuinely region-specific. Left alone, Market Intelligence
     * sits blank until someone eventually finds Prospecting Setup on their
     * own. The creator (an owner, per the owner_only route gate — rarely a
     * member of the agency they just made) is instead switched into the new
     * agency's context and sent straight into Prospecting Setup.
     */
    public function test_creating_a_live_agency_switches_into_it_and_redirects_to_prospecting_setup(): void
    {
        Mail::fake();
        $owner = $this->ownerUser();

        $response = $this->actingAs($owner)->post(route('agencies.store'), [
            'name'         => 'Fresh Agency',
            'is_demo'      => false,
            'is_active'    => true,
            'admin_name'   => 'Fresh Admin',
            'admin_email'  => 'fresh-admin@example.test',
            'branch_name'  => 'Head Office',
            'branch_code'  => 'HQ',
        ]);

        $newAgency = Agency::where('name', 'Fresh Agency')->firstOrFail();

        $response->assertRedirect(route('settings.prospecting.index'));
        $response->assertSessionHas('active_agency_id', $newAgency->id);

        // AT-378 created the first branch atomically but never actually
        // stamped agencies.default_branch_id — every branch-context
        // fallback that reads it (e.g. SellerOutreach\EntryPointController::
        // resolveBranchId()) had nothing to land on until now.
        $branch = \App\Models\Branch::where('agency_id', $newAgency->id)->firstOrFail();
        $this->assertSame($branch->id, $newAgency->fresh()->default_branch_id);
    }

    /** Demo agencies are sandboxes, not a real agent's first day — skip the redirect. */
    public function test_creating_a_demo_agency_still_redirects_to_agency_list(): void
    {
        $owner = $this->ownerUser();

        $response = $this->actingAs($owner)->post(route('agencies.store'), [
            'name'      => 'Demo Sandbox',
            'is_demo'   => true,
            'is_active' => true,
            'branch_name' => 'Head Office',
            'branch_code' => 'HQ',
        ]);

        $response->assertRedirect(route('agencies.index'));
    }
}
