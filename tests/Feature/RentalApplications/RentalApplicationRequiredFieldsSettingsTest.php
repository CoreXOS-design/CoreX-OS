<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Submission hard floor, AT-392 round 5, 2026-09-13 — Johan, twice ruled:
 * every field on the applicant form gets its own compulsory tick, no
 * locked set. This settings screen is the one place an agency expresses
 * that choice; these tests prove it actually saves what's ticked
 * (including "nothing at all", a legitimate agency choice) and that the
 * screen never presents a branch-only field as if every applicant sees it.
 */
final class RentalApplicationRequiredFieldsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    private function owner(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    public function test_the_settings_screen_renders_the_full_field_checklist(): void
    {
        $response = $this->actingAs($this->owner())->get(route('corex.settings.rental-applications.edit'));

        $response->assertOk();
        $response->assertSee('Compulsory Fields');
        $response->assertSee('Full name');
        $response->assertSee('Only applies if the applicant says they are permanently employed');
        $response->assertSee('Only applies if the applicant says they are currently renting');
    }

    public function test_ticking_a_new_field_persists_it(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.required-fields'), [
            'required_fields_submitted' => 1,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['citizenship']),
        ])->assertRedirect(route('corex.settings.rental-applications.edit'));

        $this->assertContains('citizenship', RentalApplicationQualifyingSetting::requiredFieldKeysFor($this->agency->id));
    }

    public function test_an_agency_can_untick_everything_including_id_number_and_signatures_no_exception(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.required-fields'), [
            'required_fields_submitted' => 1,
            'required_field_keys' => [],
        ])->assertRedirect(route('corex.settings.rental-applications.edit'));

        $this->assertSame([], RentalApplicationQualifyingSetting::requiredFieldKeysFor($this->agency->id));
    }

    public function test_a_tampered_unknown_key_is_silently_dropped_not_fatal(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.required-fields'), [
            'required_fields_submitted' => 1,
            'required_field_keys' => ['full_name', 'not_a_real_field'],
        ])->assertRedirect(route('corex.settings.rental-applications.edit'));

        $saved = RentalApplicationQualifyingSetting::requiredFieldKeysFor($this->agency->id);
        $this->assertContains('full_name', $saved);
        $this->assertNotContains('not_a_real_field', $saved);
    }

    public function test_marital_status_options_save_with_implies_spouse_flag(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.marital-status-options'), [
            'marital_status_options' => [
                ['label' => 'Single', 'implies_spouse' => '0'],
                ['label' => 'Married', 'implies_spouse' => '1'],
            ],
        ])->assertRedirect(route('corex.settings.rental-applications.edit'));

        $options = RentalApplicationQualifyingSetting::maritalStatusOptionsFor($this->agency->id);
        $this->assertTrue(collect($options)->firstWhere('label', 'Married')['implies_spouse']);
        $this->assertFalse(collect($options)->firstWhere('label', 'Single')['implies_spouse']);
    }

    public function test_marital_status_options_cannot_be_saved_empty(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.marital-status-options'), [
            'marital_status_options' => [],
        ])->assertSessionHasErrors('marital_status_options');
    }
}
