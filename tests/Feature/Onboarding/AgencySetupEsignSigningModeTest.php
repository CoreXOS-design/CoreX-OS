<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\EsignSettings;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * esign-signing-modes.md ruling 5 (Johan, 23 Oct 2026) + non-negotiable #10a: the "How my signers sign" setting reaches the
 * Setup Wizard (Compliance step), saved by the same write as E-Sign -> How my signers sign. Default stays Mode 1.
 */
final class AgencySetupEsignSigningModeTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function agencyWithAdmin(string $name): array
    {
        $agency = Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
        $branch = Branch::firstOrCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        foreach (['admin' => 'Administrator', 'branch_manager' => 'Branch Manager', 'agent' => 'Agent'] as $role => $label) {
            Role::create(['name' => $role, 'label' => $label, 'agency_id' => $agency->id]);
        }
        Role::clearCache();

        return [$agency, User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true])];
    }

    private function save(User $admin, array $fields)
    {
        return $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'compliance']), $fields);
    }

    public function test_the_step_asks_the_question_and_a_new_agency_sees_the_default_chosen(): void
    {
        [, $admin] = $this->agencyWithAdmin('Karoo Lettings');

        $html = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'compliance']))->assertOk()->getContent();

        $this->assertStringContainsString('name="signing_mode"', $html);
        $this->assertStringContainsString('How my signers sign', $html);
        $this->assertMatchesRegularExpression('/<option value="sign_each"[^>]*selected/', $html, 'an agency that never chose signs every place');
    }

    public function test_choosing_a_mode_in_the_wizard_saves_it_for_that_agency_only_and_reopens_showing_it(): void
    {
        [$karoo, $admin] = $this->agencyWithAdmin('Karoo Lettings');
        [$cape] = $this->agencyWithAdmin('Cape Rentals');

        $fields = array_replace($this->browserFormFields($admin, 'compliance'), ['signing_mode' => 'adopt_once']);
        $this->save($admin, $fields)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('adopt_once', EsignSettings::signingModeFor($karoo->id));
        $this->assertSame('sign_each', EsignSettings::signingModeFor($cape->id), 'another agency is untouched');
        $html = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'compliance']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="adopt_once"[^>]*selected/', $html, 'reopening shows the saved choice');
    }

    public function test_a_step_post_that_never_carried_the_field_leaves_the_setting_alone(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin('Karoo Lettings');
        EsignSettings::updateOrCreate(['agency_id' => $agency->id], ['signing_mode' => 'adopt_once']);

        $fields = $this->browserFormFields($admin, 'compliance');
        unset($fields['signing_mode']);
        $this->save($admin, $fields)->assertRedirect();

        $this->assertSame('adopt_once', EsignSettings::signingModeFor($agency->id));
    }

    public function test_an_unknown_mode_is_refused_and_nothing_is_saved(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin('Karoo Lettings');

        $fields = array_replace($this->browserFormFields($admin, 'compliance'), ['signing_mode' => 'sign_nothing']);
        $this->save($admin, $fields)->assertSessionHasErrors('signing_mode');

        $this->assertSame('sign_each', EsignSettings::signingModeFor($agency->id));
    }
}
