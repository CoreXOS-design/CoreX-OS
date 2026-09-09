<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApplicationMarkColorSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Highlighter freehand redesign, 2026-09-09 — Johan: "admin can pick 6
 * colours - agent 3 and auth 3." Mirrors the RentalApplicationQualifyingSetting
 * test pattern: colorsFor() never creates a row on read, an explicit save
 * persists exactly six colours, and an invalid value never reaches storage.
 */
final class RentalApplicationMarkColorSettingTest extends TestCase
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

    public function test_colors_for_returns_shipped_defaults_when_never_configured(): void
    {
        $colors = RentalApplicationMarkColorSetting::colorsFor($this->agency->id);

        $this->assertSame(RentalApplicationMarkColorSetting::DEFAULTS, $colors);
        $this->assertDatabaseCount('rental_application_mark_color_settings', 0);
    }

    public function test_colors_for_role_returns_only_the_requested_roles_three(): void
    {
        $agent = RentalApplicationMarkColorSetting::colorsForRole($this->agency->id, 'agent');
        $authoriser = RentalApplicationMarkColorSetting::colorsForRole($this->agency->id, 'authoriser');

        $this->assertSame(['income', 'expense', 'unpaid'], array_keys($agent));
        $this->assertSame(['income', 'expense', 'unpaid'], array_keys($authoriser));
        $this->assertNotSame($agent, $authoriser);
    }

    public function test_agency_can_save_all_six_colours(): void
    {
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $payload = [
            'agent_income_color' => '#111111',
            'agent_expense_color' => '#222222',
            'agent_unpaid_color' => '#333333',
            'authoriser_income_color' => '#444444',
            'authoriser_expense_color' => '#555555',
            'authoriser_unpaid_color' => '#666666',
        ];

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.mark-colors'), $payload)
            ->assertSessionDoesntHaveErrors();

        $colors = RentalApplicationMarkColorSetting::colorsFor($this->agency->id);
        $this->assertSame('#111111', $colors['agent']['income']);
        $this->assertSame('#222222', $colors['agent']['expense']);
        $this->assertSame('#333333', $colors['agent']['unpaid']);
        $this->assertSame('#444444', $colors['authoriser']['income']);
        $this->assertSame('#555555', $colors['authoriser']['expense']);
        $this->assertSame('#666666', $colors['authoriser']['unpaid']);
    }

    public function test_an_invalid_colour_is_rejected_and_never_persisted(): void
    {
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $payload = [
            'agent_income_color' => 'not-a-colour',
            'agent_expense_color' => '#222222',
            'agent_unpaid_color' => '#333333',
            'authoriser_income_color' => '#444444',
            'authoriser_expense_color' => '#555555',
            'authoriser_unpaid_color' => '#666666',
        ];

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.mark-colors'), $payload)
            ->assertSessionHasErrors('agent_income_color');

        $this->assertDatabaseCount('rental_application_mark_color_settings', 0);
        $this->assertSame(
            RentalApplicationMarkColorSetting::DEFAULTS,
            RentalApplicationMarkColorSetting::colorsFor($this->agency->id)
        );
    }

    public function test_a_second_save_updates_the_same_row_rather_than_creating_another(): void
    {
        $owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        $base = [
            'agent_income_color' => '#111111', 'agent_expense_color' => '#222222', 'agent_unpaid_color' => '#333333',
            'authoriser_income_color' => '#444444', 'authoriser_expense_color' => '#555555', 'authoriser_unpaid_color' => '#666666',
        ];
        $this->actingAs($owner)->post(route('corex.settings.rental-applications.mark-colors'), $base);
        $this->actingAs($owner)->post(route('corex.settings.rental-applications.mark-colors'), array_merge($base, ['agent_income_color' => '#abcdef']));

        $this->assertDatabaseCount('rental_application_mark_color_settings', 1);
        $this->assertSame('#abcdef', RentalApplicationMarkColorSetting::colorsFor($this->agency->id)['agent']['income']);
    }
}
