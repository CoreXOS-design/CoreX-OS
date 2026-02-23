<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pricing Simulator tests (formerly Brain Simulation).
 * Brain route now redirects to Pricing Simulator.
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

    // ── Legacy brain route redirects to pricing simulator ─────────────

    public function test_brain_route_redirects_to_pricing_simulator(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.brain', $this->presentation));
        $response->assertRedirect(route('presentations.pricing-simulator', $this->presentation));
    }

    // ── Pricing Simulator flag off → 404 ──────────────────────────────

    public function test_pricing_simulator_flag_off_returns_404(): void
    {
        config()->set('features.pricing_simulator_v1', false);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.pricing-simulator', $this->presentation));
        $response->assertNotFound();
    }

    // ── Pricing Simulator flag on → 200 ───────────────────────────────

    public function test_pricing_simulator_flag_on_returns_ok(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.pricing-simulator', $this->presentation));
        $response->assertOk();
    }

    // ── Shows page title ──────────────────────────────────────────────

    public function test_shows_pricing_simulator_title(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.pricing-simulator', $this->presentation));
        $response->assertOk();
        $response->assertSee('Pricing Simulator');
    }

    // ── Shows presentation title ──────────────────────────────────────

    public function test_shows_presentation_title(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.pricing-simulator', $this->presentation));
        $response->assertOk();
        $response->assertSee('Brain Test Property');
    }

    // ── Shows configuration inputs ────────────────────────────────────

    public function test_shows_configuration_inputs(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.pricing-simulator', $this->presentation));
        $response->assertOk();
        $response->assertSee('Commission');
        $response->assertSee('Transfer Cost');
        $response->assertSee('Monthly Holding Cost');
    }

    // ── Shows action buttons ──────────────────────────────────────────

    public function test_shows_action_buttons(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.pricing-simulator', $this->presentation));
        $response->assertOk();
        $response->assertSee('Compute Scenarios');
        $response->assertSee('Save Configuration');
    }

    // ── No DB writes on GET ───────────────────────────────────────────

    public function test_pricing_simulator_does_not_write_to_db(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        $this->actingAs($this->user);

        $snapshotCount = PresentationSnapshot::count();

        $response = $this->get(route('presentations.pricing-simulator', $this->presentation));
        $response->assertOk();

        $this->assertSame($snapshotCount, PresentationSnapshot::count());
    }

    // ── Show page has Pricing Simulator button when flag on ───────────

    public function test_show_page_has_pricing_simulator_button_when_flag_on(): void
    {
        config()->set('features.pricing_simulator_v1', true);
        config()->set('features.presentation_power_panel_v1', false);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('Pricing Simulator');
    }

    // ── Show page hides Pricing Simulator button when flag off ────────

    public function test_show_page_hides_pricing_simulator_button_when_flag_off(): void
    {
        config()->set('features.pricing_simulator_v1', false);
        config()->set('features.presentation_power_panel_v1', false);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertDontSee('Pricing Simulator');
    }
}
