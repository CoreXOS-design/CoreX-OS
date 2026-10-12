<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Compliance\PpraEmploymentLetter;
use App\Models\OtherAgencyStockConsent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * The PPRA employment-letter setting and the two Other Agency Stock settings in
 * the Agency Setup Wizard (CLAUDE.md #10a).
 *
 *   - PPRA letter addressee block      -> Compliance step
 *     (specs: ppra-ffc-employment-letter.md §11)
 *   - OAS "who can see it" + consent   -> Properties step
 *     (specs: other-agency-stock.md §6/§3a)
 *
 * Every test is the same shape the wizard really runs: GET the step, serialise
 * the form the way a browser does, change one thing, POST it, then read the real
 * column AND reopen the step to see the saved value rendered back (§6.2).
 */
final class AgencySetupWizardPpraOasTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ct-rentals-' . uniqid()]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        // A fresh test DB has no per-agency roles, and Role::allRoles() would then be empty — which
        // the saver reads as "every role ticked". Give the agency its real working roles.
        foreach (['admin' => 'Administrator', 'branch_manager' => 'Branch Manager', 'agent' => 'Agent'] as $name => $label) {
            Role::create(['name' => $name, 'label' => $label, 'agency_id' => $agency->id]);
        }
        Role::clearCache();

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function save(User $admin, string $step, array $overrides = [], array $unset = [])
    {
        $fields = array_replace($this->browserFormFields($admin, $step), $overrides);
        foreach ($unset as $key) {
            unset($fields[$key]);
        }

        return $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => $step]), $fields);
    }

    // ── PPRA employment letter — Compliance step ─────────────────────────

    public function test_ppra_letter_address_shows_the_ppra_default_as_a_placeholder_not_a_value(): void
    {
        $admin = $this->admin($this->agency());

        $html = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'compliance']))
            ->assertOk()
            ->assertSee('PPRA employment letter — who it is addressed to')
            ->assertSee('What this changes:', false)
            ->getContent();

        // The default is the regulator's own address, not any one agency's.
        $this->assertStringContainsString('63 Wierda Road East', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="ppra_employment_letter_address_block"[^>]*placeholder="[^"]*Wierda Road East/s', $html);
        // …and it is NOT pre-filled as the value, so saving the step never freezes
        // the default into the agency's own column.
        $this->assertDoesNotMatchRegularExpression('/<textarea[^>]*name="ppra_employment_letter_address_block"[^>]*>[^<]*Wierda/s', $html);
    }

    public function test_ppra_letter_address_round_trips_and_matches_the_settings_page_save(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $block  = "The Property Practitioners Regulatory Authority\nCape Town Regional Office\n12 Strand Street\nCape Town\n8001";

        $this->save($admin, 'compliance', ['ppra_employment_letter_address_block' => $block])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($block, $agency->fresh()->ppra_employment_letter_address_block);

        // Reopening the step renders the SAVED value back.
        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'compliance']))
            ->assertOk()
            ->assertSee('12 Strand Street');

        // One source of truth: clear it, then the settings page's own route stores the identical value.
        $viaWizard = $agency->fresh()->ppra_employment_letter_address_block;
        $agency->forceFill(['ppra_employment_letter_address_block' => null])->save();
        $this->actingAs($admin)
            ->post(route('corex.settings.ppra-employment-letter.save'), ['ppra_employment_letter_address_block' => $block])
            ->assertRedirect();
        $this->assertSame($viaWizard, $agency->fresh()->ppra_employment_letter_address_block);
    }

    public function test_blank_ppra_letter_address_goes_back_to_the_default(): void
    {
        $agency = $this->agency();
        $agency->forceFill(['ppra_employment_letter_address_block' => '1 Old Road'])->save();
        $admin = $this->admin($agency);

        $this->save($admin, 'compliance', ['ppra_employment_letter_address_block' => ''])->assertRedirect();

        $this->assertNull($agency->fresh()->ppra_employment_letter_address_block);
    }

    public function test_a_post_that_never_rendered_the_ppra_address_leaves_it_alone(): void
    {
        $agency = $this->agency();
        $agency->forceFill(['ppra_employment_letter_address_block' => '1 Old Road'])->save();
        $admin = $this->admin($agency);

        // §6.1 — absent means "leave it alone", never "clear it".
        $this->save($admin, 'compliance', [], ['ppra_employment_letter_address_block'])->assertRedirect();

        $this->assertSame('1 Old Road', $agency->fresh()->ppra_employment_letter_address_block);
    }

    // ── Other Agency Stock — Properties step ─────────────────────────────

    public function test_oas_defaults_to_every_role_ticked_and_the_standard_wording_as_placeholder(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $this->assertNull($agency->other_agency_stock_visible_roles);

        $html = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'properties']))
            ->assertOk()
            ->assertSee('Who can see Other Agency Stock')
            ->assertSee('Import consent wording')
            ->getContent();

        $roles = Role::allRoles($agency->id)->pluck('name')->all();
        $this->assertNotEmpty($roles);
        foreach ($roles as $role) {
            $this->assertMatchesRegularExpression(
                '/<input type="checkbox" name="other_agency_stock_visible_roles\[\]" value="' . preg_quote($role, '/') . '"\s+checked/s',
                $html,
                "role '{$role}' should be ticked by default (NULL = visible to everyone)"
            );
        }
        $this->assertStringContainsString('name="other_agency_stock_visible_roles_submitted"', $html);
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="other_agency_stock_consent_wording"[^>]*placeholder="[^"]*permission from this agency/s',
            $html
        );
    }

    public function test_oas_visible_roles_round_trip_and_match_the_settings_page_save(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $this->save($admin, 'properties', ['other_agency_stock_visible_roles' => ['admin', 'branch_manager']])
            ->assertRedirect();

        $this->assertSame(['admin', 'branch_manager'], $agency->fresh()->other_agency_stock_visible_roles);

        // Reopen: exactly those two are ticked, the rest are not.
        $html = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'properties']))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression('/value="admin"\s+checked/s', $html);
        $this->assertMatchesRegularExpression('/value="branch_manager"\s+checked/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="other_agency_stock_visible_roles\[\]" value="agent"\s+checked/s', $html);

        // One source of truth: clear it, then the settings page's route stores the identical list.
        $viaWizard = $agency->fresh()->other_agency_stock_visible_roles;
        $agency->forceFill(['other_agency_stock_visible_roles' => null])->save();
        $this->actingAs($admin)->put(route('corex.settings.other-agency-stock'), [
            'other_agency_stock_visible_roles_submitted' => '1',
            'other_agency_stock_visible_roles'           => ['admin', 'branch_manager'],
        ])->assertRedirect();
        $this->assertSame($viaWizard, $agency->fresh()->other_agency_stock_visible_roles);
    }

    public function test_oas_ticking_every_role_or_none_stores_the_visible_to_everyone_default(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $agency->forceFill(['other_agency_stock_visible_roles' => ['admin']])->save();

        // Every role ticked (what a straight "Save & continue" on a default agency posts).
        $this->save($admin, 'properties', ['other_agency_stock_visible_roles' => Role::allRoles($agency->id)->pluck('name')->all()])
            ->assertRedirect();
        $this->assertNull($agency->fresh()->other_agency_stock_visible_roles);

        // Un-ticking the whole group posts only the marker — still a real choice.
        $agency->forceFill(['other_agency_stock_visible_roles' => ['admin']])->save();
        $this->save($admin, 'properties', [], ['other_agency_stock_visible_roles'])->assertRedirect();
        $this->assertNull($agency->fresh()->other_agency_stock_visible_roles);
    }

    public function test_oas_consent_wording_round_trips_and_blank_returns_to_the_standard_wording(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $words  = 'I confirm the listing agency has agreed in writing that I may show this property to my own buyers only.';

        $this->save($admin, 'properties', ['other_agency_stock_consent_wording' => $words])->assertRedirect();
        $this->assertSame($words, $agency->fresh()->other_agency_stock_consent_wording);

        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'properties']))
            ->assertOk()
            ->assertSee('agreed in writing');

        // Blank -> NULL -> the import popup falls back to the standard wording.
        $this->save($admin, 'properties', ['other_agency_stock_consent_wording' => ''])->assertRedirect();
        $this->assertNull($agency->fresh()->other_agency_stock_consent_wording);
        $this->assertNotEmpty(OtherAgencyStockConsent::DEFAULT_WORDING);
    }

    public function test_a_post_that_never_rendered_the_oas_fields_leaves_them_alone(): void
    {
        $agency = $this->agency();
        $agency->forceFill([
            'other_agency_stock_visible_roles'   => ['admin'],
            'other_agency_stock_consent_wording' => 'Our own wording.',
        ])->save();
        $admin = $this->admin($agency);

        // §6.1 — drop the roles marker + list and the wording field: nothing is written.
        $this->save($admin, 'properties', [], [
            'other_agency_stock_visible_roles',
            'other_agency_stock_visible_roles_submitted',
            'other_agency_stock_consent_wording',
        ])->assertRedirect();

        $fresh = $agency->fresh();
        $this->assertSame(['admin'], $fresh->other_agency_stock_visible_roles);
        $this->assertSame('Our own wording.', $fresh->other_agency_stock_consent_wording);
    }

    public function test_oas_roles_and_wording_are_scoped_to_the_acting_agency(): void
    {
        $mine  = $this->agency();
        $admin = $this->admin($mine);
        $rival = $this->agency();

        $this->save($admin, 'properties', [
            'other_agency_stock_visible_roles'   => ['admin'],
            'other_agency_stock_consent_wording' => 'Mine only.',
        ])->assertRedirect();

        $this->assertNull($rival->fresh()->other_agency_stock_visible_roles);
        $this->assertNull($rival->fresh()->other_agency_stock_consent_wording);
    }

    public function test_default_ppra_address_is_the_regulators_not_any_agencys(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('home finders', PpraEmploymentLetter::DEFAULT_PPRA_ADDRESS_BLOCK);
        $this->assertStringNotContainsStringIgnoringCase('home finders', OtherAgencyStockConsent::DEFAULT_WORDING);
    }
}
