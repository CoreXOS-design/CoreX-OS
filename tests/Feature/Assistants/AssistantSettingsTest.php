<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 — Prompt K: the Assistants switch, and the Setup Wizard.
 *
 * WHY THIS FILE EXISTS AT ALL. The Assistants admin page tells an agency, when the feature is
 * off: "they will not be able to do anything until Assistants is enabled in Company Settings."
 * For a while that sentence was a LIE — the toggle did not exist. STANDARDS.md forbids exactly
 * that ("never link to a screen promising an edit that the destination then refuses"), and it is
 * the sort of thing that survives review because everyone reads the code and nobody reads the
 * sentence. So the promise is now pinned by a test.
 *
 * And the §6.1 hazard, which is the reason Non-negotiable #10a exists in the first place: a
 * wizard step posts a SUBSET of its saver's fields. A saver that reads an absent checkbox as
 * FALSE silently wipes a setting the step never rendered — which is how an agency would find
 * Assistants mysteriously switched off after saving an unrelated step.
 *
 * Paths proven: the toggle exists and saves · it defaults OFF for every agency · it can be
 * flipped back and forth with no data loss · the FICA default saves · A SAVER CALL THAT OMITS
 * ONE FIELD DOES NOT WIPE IT · the settings page is permission-gated · the wizard declares both
 * controls with explain + affects.
 */
final class AssistantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch       = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);

        $this->admin = User::factory()->create([
            'name' => 'Johan Reichel', 'agency_id' => $this->agency->id,
            'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        RolePermission::create([
            'role' => 'admin', 'permission_key' => 'manage_performance_settings',
            'agency_id' => $this->agency->id,
        ]);

        PermissionService::clearCache();
        Role::clearCache();
        PermissionService::forceProductionPosture();
    }

    public function test_assistants_ships_off_for_every_agency(): void
    {
        // The safe default. The code is live; the enforcement is dormant. No principal wakes up
        // to find assistants suddenly able to act.
        $this->assertFalse((bool) $this->agency->assistants_enabled);

        $fresh = Agency::create(['name' => 'Coastal Realty', 'slug' => 'cr-' . uniqid()]);
        $this->assertFalse((bool) $fresh->assistants_enabled);
    }

    public function test_an_admin_can_turn_assistants_on_and_off(): void
    {
        // THE PROMISE. The Assistants admin page sends people here when the feature is off. If
        // this route does not exist, that instruction is a dead end.
        $this->actingAs($this->admin)
            ->put(route('corex.settings.assistants'), [
                'assistants_enabled'              => '1',
                'assistant_fica_required_default' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $this->agency->fresh()->assistants_enabled);

        // ...and off again. Flip freely, no data loss.
        $this->actingAs($this->admin)
            ->put(route('corex.settings.assistants'), [
                'assistants_enabled'              => '0',
                'assistant_fica_required_default' => '1',
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $this->agency->fresh()->assistants_enabled);
    }

    public function test_the_fica_default_saves_independently(): void
    {
        $this->actingAs($this->admin)->put(route('corex.settings.assistants'), [
            'assistants_enabled'              => '1',
            'assistant_fica_required_default' => '0',
        ]);

        $agency = $this->agency->fresh();

        $this->assertTrue((bool) $agency->assistants_enabled);
        $this->assertFalse((bool) $agency->assistant_fica_required_default);
    }

    /**
     * THE §6.1 HAZARD, and the reason Non-negotiable #10a is written the way it is.
     *
     * A wizard step posts a SUBSET of its saver's fields. If the saver coerces an absent checkbox
     * to false, saving a step that never rendered `assistants_enabled` would switch Assistants
     * OFF — and the agency's assistants would silently stop working, with nobody having touched
     * the setting.
     */
    public function test_a_partial_save_does_not_wipe_the_field_it_omitted(): void
    {
        $this->agency->update([
            'assistants_enabled'              => true,
            'assistant_fica_required_default' => true,
        ]);

        // A caller that renders ONLY the FICA control — exactly what a wizard step can do.
        $this->actingAs($this->admin)->put(route('corex.settings.assistants'), [
            'assistant_fica_required_default' => '0',
        ]);

        $agency = $this->agency->fresh();

        $this->assertTrue(
            (bool) $agency->assistants_enabled,
            'A save that never rendered assistants_enabled must not switch it off.'
        );
        $this->assertFalse((bool) $agency->assistant_fica_required_default);
    }

    public function test_the_settings_route_is_permission_gated(): void
    {
        $agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->actingAs($agent)
            ->put(route('corex.settings.assistants'), ['assistants_enabled' => '1'])
            ->assertForbidden();

        $this->assertFalse((bool) $this->agency->fresh()->assistants_enabled);
    }

    /**
     * Non-negotiable #10a: a setting that exists only on the settings page is NOT done. The
     * wizard is the only place an agency is ever walked through what CoreX can do — a feature
     * whose switch never appears there ships inert and stays inert.
     */
    public function test_both_settings_are_surfaced_in_the_setup_wizard(): void
    {
        $step = config('agency-onboarding-copy.branches');

        $controls = collect($step['controls'] ?? [])->keyBy('key');

        foreach (['assistants_enabled', 'assistant_fica_required_default'] as $key) {
            $this->assertTrue($controls->has($key), "[{$key}] must be surfaced in the Setup Wizard (NN #10a).");

            $control = $controls->get($key);

            $this->assertNotEmpty($control['explain'] ?? '', "[{$key}] needs an `explain` — what the setting IS, in a full sentence.");
            $this->assertNotEmpty($control['affects'] ?? '', "[{$key}] needs an `affects` — a concrete, observable consequence.");
            $this->assertStringContainsString('What this changes', $control['affects'], "[{$key}]'s `affects` must state an observable consequence, not a tautology.");
        }

        // And the saver has to be wired, or the wizard would render controls that save nothing.
        $savers = collect($step['savers'] ?? [])->pluck('method');
        $this->assertTrue($savers->contains('updateAssistants'), 'The wizard step must wire the canonical saver.');
    }
}
