<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI2: Brain simulation screen acceptance tests.
 */
class BrainSimulationTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Branch       $branch;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'name'      => 'Test Branch',
            'code'      => 'TEST',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $this->branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Brain Test Property',
            'property_address'   => '10 Test Lane',
            'suburb'             => 'Constantia',
            'property_type'      => 'house',
            'bedrooms'           => 3,
            'floor_area_m2'      => 200,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    // ── Feature flag off → 404 ──────────────────────────────────────────

    public function test_flag_off_returns_404(): void
    {
        config()->set('features.presentation_brain_ui_v1', false);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertNotFound();
    }

    // ── Feature flag on → 200 ───────────────────────────────────────────

    public function test_flag_on_returns_ok(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();
    }

    // ── Shows page title ────────────────────────────────────────────────

    public function test_shows_brain_simulation_title(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();
        $response->assertSee('Brain Simulation');
    }

    // ── Shows presentation title ────────────────────────────────────────

    public function test_shows_presentation_title(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();
        $response->assertSee('Brain Test Property');
    }

    // ── Pre-populates suburb from presentation ──────────────────────────

    public function test_prepopulates_suburb_from_presentation(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();
        $response->assertSee('Constantia');
    }

    // ── Pre-populates from latest snapshot inputs ───────────────────────

    public function test_prepopulates_from_snapshot_inputs(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);

        PresentationSnapshot::create([
            'presentation_id'      => $this->presentation->id,
            'generated_by_user_id' => $this->user->id,
            'created_by_user_id'   => $this->user->id,
            'inputs_json'          => json_encode(['suburb' => 'Bishopscourt', 'type' => 'house', 'period_months' => 12, 'price' => 5000000]),
            'output_summary_json'  => json_encode(['p60' => 0.55]),
            'snapshot_json'        => json_encode(['source' => 'test']),
            'generated_at'         => now(),
        ]);

        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();
        $response->assertSee('Bishopscourt');
    }

    // ── Shows all 4 action buttons ──────────────────────────────────────

    public function test_shows_action_buttons(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();
        $response->assertSee('Simulate');
        $response->assertSee('Trajectory');
        $response->assertSee('Price Band');
        $response->assertSee('Competitive Threats');
    }

    // ── Shows input fields ──────────────────────────────────────────────

    public function test_shows_input_fields(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();
        $response->assertSee('Price Slider');
        $response->assertSee('Suburb');
        $response->assertSee('Bedrooms');
    }

    // ── No DB writes on GET ─────────────────────────────────────────────

    public function test_brain_does_not_write_to_db(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        $this->actingAs($this->user);

        $snapshotCount = PresentationSnapshot::count();

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertOk();

        $this->assertSame($snapshotCount, PresentationSnapshot::count());
    }

    // ── Show page has Brain button when flag on ─────────────────────────

    public function test_show_page_has_brain_button_when_flag_on(): void
    {
        config()->set('features.presentation_brain_ui_v1', true);
        config()->set('features.presentation_power_panel_v1', false);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('Brain Simulation');
    }

    // ── Show page hides Brain button when flag off ──────────────────────

    public function test_show_page_hides_brain_button_when_flag_off(): void
    {
        config()->set('features.presentation_brain_ui_v1', false);
        config()->set('features.presentation_power_panel_v1', false);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertDontSee('Brain Simulation');
    }
}
