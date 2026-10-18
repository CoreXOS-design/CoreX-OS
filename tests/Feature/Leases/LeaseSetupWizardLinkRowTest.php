<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Template;
use App\Models\LeaseSetting;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * leases.md §15.14 / rule #10a (Build L0) — the Rentals step of the Setup Wizard tells an agency about its
 * lease agreement: an information row with a link, explain + "What this changes:", and a done / not-done
 * chip read from rental_lease_templates. It posts no field and has no saver, so saving the step can never
 * wipe a setting it did not render (agency-onboarding-setup.md §6.1 is satisfied by absence).
 */
final class LeaseSetupWizardLinkRowTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    private function agencyWithAdmin(): array
    {
        $agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);

        return [$agency, $admin];
    }

    public function test_the_step_shows_the_row_with_explain_what_it_changes_and_a_not_set_up_chip(): void
    {
        [, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk()
            ->assertSee('Your lease agreement')
            ->assertSee('Not set up yet')
            ->assertSee('The lease agreement CoreX opens when you prepare a lease for signing.')
            ->assertSee('What this changes:')
            ->assertSee('Until you set one up the button is greyed out.')
            ->assertSee('Settings → Rental lease agreements')
            ->assertSee(route('corex.rental-lease-templates.index'), false);
    }

    public function test_the_chip_turns_to_set_up_once_the_agency_has_a_ready_lease_agreement(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $template = new Template(['name' => 'Own lease', 'render_type' => 'pdf', 'is_esign' => true, 'signing_parties' => ['owner_party', 'acquiring_party', 'agent']]);
        $template->agency_id = $agency->id;
        $template->save();
        RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $template->id, 'category' => 'residential', 'is_active' => true,
            'field_map' => [
                'rent' => 'monthly_rental', 'start_date' => 'lease_start', 'end_date' => 'lease_end',
                'tenant_name' => 'lessee_name', 'landlord_name' => 'lessor_name',
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk()
            ->assertSee('Set up')
            ->assertDontSee('Not set up yet');
    }

    public function test_another_agencys_ready_lease_agreement_does_not_make_this_agency_set_up(): void
    {
        [, $admin] = $this->agencyWithAdmin();
        $other = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $template = new Template(['name' => 'Their lease', 'render_type' => 'pdf', 'is_esign' => true, 'signing_parties' => ['owner_party', 'acquiring_party', 'agent']]);
        $template->agency_id = $other->id;
        $template->save();
        RentalLeaseTemplate::create([
            'agency_id' => $other->id, 'name' => 'Residential', 'docuperfect_template_id' => $template->id, 'category' => 'residential', 'is_active' => true,
            'field_map' => ['rent' => 'a', 'start_date' => 'b', 'end_date' => 'c', 'tenant_name' => 'd', 'landlord_name' => 'e'],
        ]);

        $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk()
            ->assertSee('Not set up yet');
    }

    public function test_the_row_posts_no_field_so_there_is_nothing_for_a_partial_post_to_wipe(): void
    {
        $html = view('agency-setup.steps.rentals-lease-agreement', ['wzLeaseAgreementLinked' => false])->render();

        $this->assertStringNotContainsString('<input', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('name=', $html);
    }

    public function test_saving_the_step_leaves_the_lease_agreement_rows_and_settings_alone(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $template = new Template(['name' => 'Own lease', 'render_type' => 'pdf', 'is_esign' => true]);
        $template->agency_id = $agency->id;
        $template->save();
        $row = RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $template->id, 'category' => 'residential',
            'is_active' => true, 'is_default' => true, 'field_map' => ['rent' => 'monthly_rental'],
        ]);
        LeaseSetting::updateOrCreate(['agency_id' => $agency->id], ['expiry_notice_window_days' => 45]);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency)))
            ->assertSessionHasNoErrors();

        $fresh = $row->fresh();
        $this->assertTrue($fresh->is_default);
        $this->assertSame(['rent' => 'monthly_rental'], $fresh->field_map);
        $this->assertTrue($fresh->is_active);
    }
}
