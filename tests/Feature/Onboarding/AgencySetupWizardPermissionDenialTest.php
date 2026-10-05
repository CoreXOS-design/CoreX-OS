<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Syndication\SyndicationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner's ruling 2026-09-30: the Setup Wizard used to swallow the 403 a saver
 * throws when the user lacks that saver's permission (e.g. updateSyndicationPortals
 * -> properties.syndication.manage_approvers) and STILL marked the step complete
 * and said "Saved.". A denied save must not mark the step complete and must tell
 * the user plainly that they lack permission.
 *
 * NOTE: written without being able to run the suite (no test DB available to the
 * authoring agent) — run this file before merging.
 */
final class AgencySetupWizardPermissionDenialTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithoutApproverPermission(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Seed SOME grants so the unseeded "allow all" test fallback does not
        // apply — this role may run the wizard but does NOT hold
        // properties.syndication.manage_approvers.
        RolePermission::create(['role' => 'admin', 'permission_key' => 'agency_setup.run', 'agency_id' => $agency->id]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_settings', 'agency_id' => $agency->id]);

        return $user;
    }

    private function capabilitiesPayload(User $user): array
    {
        return [
            'marketing_enabled' => '1',
            'syndication_p24_enabled' => '1',
            'syndication_pp_enabled' => '1',
            'pp_exclusivity_enabled' => '1',
            'pp_exclusive_days_max' => 92,
            'matches_enabled' => '1',
            'split_branches_enabled' => '0',
            'syndication_approval_required' => '1',
            'syndication_approver_user_ids' => [(string) $user->id],
        ];
    }

    public function test_a_denied_syndication_approval_save_is_not_marked_complete_and_says_so(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $user = $this->adminWithoutApproverPermission($agency);

        $this->actingAs($user)->get(route('corex.agency-setup.step', ['step' => 'capabilities']));

        $response = $this->actingAs($user)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->capabilitiesPayload($user));

        // Stays on the SAME step with a visible permission error, never advances.
        $response->assertRedirect(route('corex.agency-setup.step', ['step' => 'capabilities']));
        $response->assertSessionHasErrors('permission');
        $this->assertNotEquals('Saved.', session('success'));

        // The step is NOT marked complete.
        $setup = AgencyOnboardingSetup::where('agency_id', $agency->id)->first();
        $this->assertNotNull($setup);
        $this->assertNotContains('capabilities', (array) $setup->completed_steps);

        // The gate itself was not switched on.
        $this->assertFalse(SyndicationApprovalService::isRequiredForAgency($agency->id));
    }

    public function test_the_permission_message_is_shown_on_the_step_the_user_lands_on(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $user = $this->adminWithoutApproverPermission($agency);

        $this->actingAs($user)->get(route('corex.agency-setup.step', ['step' => 'capabilities']));
        $this->actingAs($user)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->capabilitiesPayload($user));

        $this->actingAs($user)
            ->get(route('corex.agency-setup.step', ['step' => 'capabilities']))
            ->assertOk()
            ->assertSee('do not have permission', false);
    }
}
