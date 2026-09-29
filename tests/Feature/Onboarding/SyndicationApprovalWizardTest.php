<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Syndication\SyndicationApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Syndication Approval in the Agency Setup Wizard.
 * Spec: .ai/specs/syndication-approval-gate.md §9 (CLAUDE.md #10a).
 *
 * This is the case the wizard's control vocabulary could not previously
 * express: "who approves" is a LIVE list of that agency's own people, not a
 * static option map. It is also a PAIR of controls with a hard rule between
 * them — the switch cannot be saved on with nobody behind it.
 *
 * The rule that makes the pair safe, and the reason this file exists:
 * AgencySetupWizardController::save() IGNORES a saver's return value. It only
 * reacts to a thrown ValidationException (and a 403). A saver that refused by
 * RETURNING a redirect would be swallowed whole — the step would be marked
 * complete and the setting silently never written, which is exactly the
 * failure agency-onboarding-setup.md §6.1 exists to prevent. So the refusal is
 * asserted here as a validation error, not as "it didn't save".
 */
class SyndicationApprovalWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    /** The minimum the capabilities step posts alongside our two controls. */
    private function stepPayload(array $overrides = []): array
    {
        return array_merge([
            'marketing_enabled'         => '1',
            'syndication_p24_enabled'   => '1',
            'syndication_pp_enabled'    => '1',
            'pp_exclusivity_enabled'    => '1',
            'pp_exclusive_days_max'     => 92,
            'matches_enabled'           => '1',
            'split_branches_enabled'    => '0',
        ], $overrides);
    }

    // ── The control renders, with this agency's real people ─────────────

    public function test_the_step_offers_the_switch_and_this_agencys_own_people(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $colleague = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $admin->branch_id,
            'role' => 'agent', 'is_active' => true, 'name' => 'Penny Principal',
        ]);

        // Somebody from ANOTHER agency must never be offered as an approver.
        $otherAgency = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $otherBranch = Branch::withoutAgencyStamping(fn () => Branch::create([
            'agency_id' => $otherAgency->id, 'name' => 'Main',
        ]));
        $outsider = User::withoutAgencyStamping(fn () => User::factory()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id,
            'role' => 'agent', 'is_active' => true, 'name' => 'Rival Ronnie',
        ]));
        $this->assertSame($otherAgency->id, (int) $outsider->agency_id, 'Fixture guard: the outsider must really be outside.');

        $response = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'capabilities']))
            ->assertOk();

        $response->assertSee('Require approval before a listing is syndicated');
        $response->assertSee('Who approves');
        $response->assertSee('Penny Principal');
        $response->assertSee('syndication_approver_user_ids[]', false);
        $response->assertDontSee('Rival Ronnie');
    }

    public function test_an_inactive_person_is_not_offered_as_an_approver(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $admin->branch_id,
            'role' => 'agent', 'is_active' => false, 'name' => 'Departed Derek',
        ]);

        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'capabilities']))
            ->assertOk()
            ->assertDontSee('Departed Derek');
    }

    // ── Saving the pair ─────────────────────────────────────────────────

    public function test_the_step_saves_the_switch_and_the_chosen_approvers(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $penny  = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $admin->branch_id,
            'role' => 'agent', 'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->stepPayload([
                'syndication_approval_required' => '1',
                'syndication_approver_user_ids' => ['', (string) $penny->id, (string) $admin->id],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(SyndicationApprovalService::isRequiredForAgency($agency->id));
        $this->assertEqualsCanonicalizing(
            [$penny->id, $admin->id],
            SyndicationApprovalService::approverIdsFor($agency->id),
            'The empty hidden companion must be filtered out, and both real picks kept.'
        );
    }

    public function test_switching_it_on_with_nobody_chosen_is_refused_with_a_field_error(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        // A redirect-with-flash here would be SWALLOWED by the wizard (it
        // ignores saver return values), the step marked complete, and the
        // setting silently not written. It must be a validation error.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->stepPayload([
                'syndication_approval_required' => '1',
                'syndication_approver_user_ids' => [''],
            ]))
            ->assertSessionHasErrors('syndication_approver_user_ids');

        $this->assertFalse(
            SyndicationApprovalService::isRequiredForAgency($agency->id),
            'A refused save must leave the gate OFF, never half-applied.'
        );
    }

    public function test_the_roster_can_be_emptied_when_the_switch_is_off(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        PerformanceSetting::set(SyndicationApprovalService::SETTING_APPROVERS, json_encode([$admin->id]), $agency->id);
        PerformanceSetting::set(SyndicationApprovalService::SETTING_REQUIRED, 1, $agency->id);

        // Un-ticking everyone AND switching off is a legitimate "we don't use
        // this" — the empty hidden companion is what makes the empty roster
        // actually arrive, instead of the field being absent and the old
        // roster silently surviving.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->stepPayload([
                'syndication_approval_required' => '0',
                'syndication_approver_user_ids' => [''],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse(SyndicationApprovalService::isRequiredForAgency($agency->id));
        $this->assertSame([], SyndicationApprovalService::approverIdsFor($agency->id));
    }

    // ── §6.1: this step must not wipe what it does not render ───────────

    public function test_saving_the_step_leaves_the_other_syndication_settings_alone(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->stepPayload([
                'syndication_approval_required' => '0',
                'syndication_approver_user_ids' => [''],
            ]))
            ->assertRedirect();

        // The portal switches this step DOES render are still what was posted.
        $this->assertSame('1', (string) PerformanceSetting::get('syndication_p24_enabled', null, $agency->id));
        $this->assertSame('1', (string) PerformanceSetting::get('syndication_pp_enabled', null, $agency->id));
    }

    public function test_the_settings_page_refuses_the_same_way(): void
    {
        // One saver, one rule — the settings page and the wizard cannot drift.
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $this->actingAs($admin)
            ->post(route('corex.settings.syndication-portals'), [
                'syndication_approval_required' => '1',
                'syndication_approver_user_ids' => [''],
            ])
            ->assertSessionHasErrors('syndication_approver_user_ids');

        $this->assertFalse(SyndicationApprovalService::isRequiredForAgency($agency->id));
    }
}
