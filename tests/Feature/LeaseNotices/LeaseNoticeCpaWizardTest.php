<?php

namespace Tests\Feature\LeaseNotices;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * lease-notices.md §7 / CLAUDE.md #10a - the CPA business-days setting reaches the Setup Wizard's Leases step: shown with its explanation,
 * saved by the same saver as the settings page, reopened with the saved value, and a post that never rendered it leaves it alone (§6.1).
 */
final class LeaseNoticeCpaWizardTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function adminFor(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        foreach (['admin' => 'Administrator', 'branch_manager' => 'Branch Manager', 'agent' => 'Agent'] as $name => $label) {
            Role::create(['name' => $name, 'label' => $label, 'agency_id' => $agency->id]);
        }
        Role::clearCache();

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_the_wizard_leases_step_shows_saves_and_reopens_the_cpa_days(): void
    {
        $agency = Agency::create(['name' => 'Wizard Lettings', 'slug' => 'wiz-' . uniqid()]);
        $other = Agency::create(['name' => 'Other Lettings', 'slug' => 'oth-' . uniqid()]);
        $admin = $this->adminFor($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()
            ->assertSee('name="cpa_early_cancel_business_days"', false)->assertSee('Business days&#039; notice a tenant may cancel a fixed term on', false)
            ->assertSee('name="breach_remedy_business_days"', false)->assertSee('Business days a tenant has to put a breach right', false);

        $fields = array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency), ['cpa_early_cancel_business_days' => '16', 'breach_remedy_business_days' => '12']);
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(16, LeaseSetting::cpaEarlyCancelBusinessDaysFor($agency->id));
        $this->assertSame(12, LeaseSetting::breachRemedyBusinessDaysFor($agency->id));
        $this->assertSame(20, LeaseSetting::cpaEarlyCancelBusinessDaysFor($other->id), 'another agency is untouched');
        $this->assertSame(20, LeaseSetting::breachRemedyBusinessDaysFor($other->id));

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()->assertSee('value="16"', false);

        $partial = array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency));
        unset($partial['cpa_early_cancel_business_days'], $partial['breach_remedy_business_days']);
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $partial)->assertRedirect();
        $this->assertSame(16, LeaseSetting::cpaEarlyCancelBusinessDaysFor($agency->id), 'a post that never rendered it leaves it alone');
        $this->assertSame(12, LeaseSetting::breachRemedyBusinessDaysFor($agency->id));
    }
}
