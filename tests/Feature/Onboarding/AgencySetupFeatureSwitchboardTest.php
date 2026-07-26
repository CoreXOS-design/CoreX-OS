<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agency Onboarding — Feature Switchboard step.
 * Spec: .ai/specs/agency-onboarding-feature-switchboard.md §12/§13.
 *
 * The switchboard is step 2 of the wizard: a consolidated front door onto
 * feature toggles that already exist. Every toggle fans to its EXISTING
 * canonical saver (no parallel flag system), and a feature switched OFF here
 * skips its dedicated detail step (adaptive step-gating).
 *
 * Note: a fresh test DB has no role_permissions, so PermissionService falls back
 * to "allow all" — a plain admin passes every permission gate without seeding.
 */
class AgencySetupFeatureSwitchboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function agency(string $name = 'Coastal Realty'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name)]);
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

    private function setupFor(Agency $agency, array $attrs = []): AgencyOnboardingSetup
    {
        $s = new AgencyOnboardingSetup();
        $s->agency_id       = $agency->id;
        $s->token           = AgencyOnboardingSetup::generateToken();
        $s->slug            = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $s->current_step    = 1;
        $s->completed_steps = [];
        $s->expires_at      = now()->addDays(30);
        $s->forceFill($attrs);
        $s->save();
        return $s;
    }

    /** The switchboard form's full field set (hidden-companion contract: every field always posts). */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'marketing_enabled'        => '1',
            'matches_enabled'          => '1',
            'split_branches_enabled'   => '0',
            'website_enabled'          => '0',
            'syndication_p24_enabled'  => '0',
            'syndication_pp_enabled'   => '0',
        ], $overrides);
    }

    // ── Render ───────────────────────────────────────────────────────────────

    public function test_capabilities_step_renders_all_six_toggles_with_explainers(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'capabilities']))
            ->assertOk()
            // 'what' card (copy-guard requirement).
            ->assertSee('Your CoreX toolkit')
            ->assertSee('What this changes:')
            // Six capability labels.
            ->assertSee('Marketing')
            ->assertSee('Core Matches')
            ->assertSee('Multi-branch offices')
            ->assertSee('Public website')
            ->assertSee('Publish to Property24')
            ->assertSee('Publish to Private Property')
            // The portal sub-heading.
            ->assertSee('Property portals');
    }

    public function test_capabilities_step_is_position_two(): void
    {
        $this->assertSame('capabilities', AgencyOnboardingSetup::STEPS[1]);
        $this->assertSame(13, AgencyOnboardingSetup::totalSteps());
    }

    // ── Round-trip: writes through the SAME store the settings page writes ────

    public function test_saving_the_step_round_trips_each_toggle_into_the_real_store(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->payload([
                'marketing_enabled'       => '0',
                'matches_enabled'         => '0',
                'split_branches_enabled'  => '1',
                'website_enabled'         => '1',
                'syndication_p24_enabled' => '1',
                'syndication_pp_enabled'  => '0',
            ]))
            ->assertRedirect();

        // PerformanceSetting keys — identical to what the settings-page savers write.
        $this->assertSame(0, (int) PerformanceSetting::get('marketing_enabled'));
        $this->assertSame(0, (int) PerformanceSetting::get('matches_enabled'));
        $this->assertSame(1, (int) PerformanceSetting::get('syndication_p24_enabled'));
        $this->assertSame(0, (int) PerformanceSetting::get('syndication_pp_enabled'));

        // Agency columns.
        $agency->refresh();
        $this->assertTrue((bool) $agency->split_branches_enabled);
        $this->assertTrue((bool) $agency->website_enabled);
    }

    public function test_website_enabled_round_trips_through_the_wizard(): void
    {
        $agency = $this->agency();
        $agency->forceFill(['website_enabled' => false])->save();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // ON.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->payload(['website_enabled' => '1']))
            ->assertRedirect();
        $this->assertTrue((bool) $agency->fresh()->website_enabled, 'website_enabled is now settable inside onboarding');

        // OFF (hidden-companion "0").
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->payload(['website_enabled' => '0']))
            ->assertRedirect();
        $this->assertFalse((bool) $agency->fresh()->website_enabled);
    }

    public function test_saving_the_step_advances_to_branding(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // capabilities (2) → branding (3).
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'capabilities']), $this->payload())
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'branding']));
    }

    // ── Adaptive step-gating ─────────────────────────────────────────────────

    public function test_matches_off_removes_the_matches_step_from_the_flow(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        PerformanceSetting::updateOrCreate(['key' => 'matches_enabled'], ['value' => 0]);

        // Not in the active-step list, and the denominator drops from 13 to 12.
        $active = AgencyOnboardingSetup::activeSteps($agency);
        $this->assertNotContains('matches', $active);
        $this->assertCount(12, $active);

        // show('matches') redirects forward (never 404s a legitimately-gated step).
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'matches']))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'contacts']));

        // Save on the step BEFORE matches advances straight past it to contacts.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'presentations']), [
                'presentations_coverage_rich_threshold'     => 12,
                'presentations_coverage_moderate_threshold' => 6,
                'presentations_coverage_thin_threshold'     => 3,
                'presentations_default_period_months'       => 12,
            ])
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'contacts']));
    }

    public function test_matches_on_keeps_the_matches_step_reachable(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // Default is ON (no PerformanceSetting row → default 1).
        $active = AgencyOnboardingSetup::activeSteps($agency);
        $this->assertContains('matches', $active);
        $this->assertCount(13, $active);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'matches']))
            ->assertOk()
            ->assertSee('What Core Matches is');
    }

    public function test_progress_denominator_excludes_the_gated_step(): void
    {
        $agency = $this->agency();
        $this->admin($agency);
        // Everything except matches completed.
        $setup = $this->setupFor($agency, ['completed_steps' => [
            'identity', 'capabilities', 'branding', 'branches', 'commission', 'properties',
            'presentations', 'contacts', 'compliance', 'notifications', 'roles', 'access',
        ]]);

        PerformanceSetting::updateOrCreate(['key' => 'matches_enabled'], ['value' => 0]);

        // 12 of 12 active steps done → 100% reachable without ever doing matches.
        $this->assertSame(100, $setup->progressPercent($agency));
    }

    // ── One home per switch ──────────────────────────────────────────────────

    public function test_moved_toggles_are_present_in_the_switchboard(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        // The masters that moved OUT of properties/matches/branches now live here.
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'capabilities']))
            ->assertOk()
            ->assertSee('Marketing')
            ->assertSee('Core Matches')
            ->assertSee('Multi-branch offices');

        // And they are gone from the matches detail step (which itself only shows
        // when Core Matches is on).
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'matches']))
            ->assertOk()
            ->assertDontSee('Turn Core Matches on');
    }

    // ── Skip / defaults ──────────────────────────────────────────────────────

    public function test_skipping_the_step_leaves_defaults_and_does_not_break_later_steps(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->setupFor($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.skip', ['step' => 'capabilities']))
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'branding']));

        // No writes happened — no PerformanceSetting rows were created.
        $this->assertNull(PerformanceSetting::where('key', 'marketing_enabled')->first());

        // Later steps still load (gating resolves against live defaults; matches
        // default-on so the matches step is present).
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'properties']))->assertOk();
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'matches']))->assertOk();
    }
}
