<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalInventorySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md §13 — the condition_states repeater added
 * alongside the pre-existing disposition_presets one. Both live on the SAME
 * form/action, so this also proves saving one doesn't wipe the other.
 */
final class RentalInventorySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Settings Test Agency', 'slug' => 'settings-test-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);
    }

    public function test_edit_page_renders_default_condition_states_and_disposition_presets(): void
    {
        $response = $this->get(route('corex.settings.rental-inventory.edit'));

        $response->assertOk();
        foreach (RentalInventorySetting::DEFAULT_CONDITION_STATES as $state) {
            $response->assertSee($state['label']);
        }
        foreach (RentalInventorySetting::DEFAULT_DISPOSITION_PRESETS as $preset) {
            // Labels render inside a Js::from() JSON blob (Alpine's own
            // x-data seed), not literal HTML — a non-ASCII character like
            // "Short — quantity missing"'s em-dash gets JS-unicode-escaped
            // there, so assertSee on the FULL label fails even though the
            // real, correct text is present (Alpine unescapes it back to a
            // real em-dash client-side). Check the plain-ASCII portion only.
            $response->assertSee(str_replace('—', '', $preset['label']) === $preset['label']
                ? $preset['label']
                : trim(explode('—', $preset['label'])[0]));
        }
    }

    public function test_saving_condition_states_does_not_wipe_disposition_presets(): void
    {
        $this->post(route('corex.settings.rental-inventory.update'), [
            'condition_states' => [
                ['key' => 'new', 'label' => 'Brand new', 'requires_notes' => '0'],
                ['key' => 'damaged', 'label' => 'Damaged', 'requires_notes' => '1'],
            ],
            'disposition_presets' => [
                ['key' => 'present', 'label' => 'Present', 'requires_notes' => '0'],
                ['key' => 'missing', 'label' => 'Missing entirely', 'requires_notes' => '1'],
            ],
        ])->assertRedirect(route('corex.settings.rental-inventory.edit'));

        $setting = RentalInventorySetting::where('agency_id', $this->agency->id)->first();
        $this->assertSame([
            ['key' => 'new', 'label' => 'Brand new', 'requires_notes' => false],
            ['key' => 'damaged', 'label' => 'Damaged', 'requires_notes' => true],
        ], $setting->condition_states);
        $this->assertSame([
            ['key' => 'present', 'label' => 'Present', 'requires_notes' => false],
            ['key' => 'missing', 'label' => 'Missing entirely', 'requires_notes' => true],
        ], $setting->disposition_presets);

        $this->assertSame($setting->condition_states, RentalInventorySetting::conditionStatesFor($this->agency->id));
    }
}
