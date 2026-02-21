<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI1: Power Panel acceptance tests.
 */
class PowerPanelTest extends TestCase
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
            'title'              => 'UI1 Feature Test',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function createSnapshot(array $outputs = []): PresentationSnapshot
    {
        $defaultOutputs = [
            'p30'            => 0.25,
            'p60'            => 0.55,
            'p90'            => 0.78,
            'expected_days'  => 62,
            'skip_reason'    => null,
            'confidence'     => [
                'confidence_score'     => 75,
                'confidence_grade'     => 'B',
                'data_quality_flags'   => [],
                'volatility_indicator' => 'low',
            ],
            'ppi'            => [
                'ppi_score' => 68,
                'ppi_label' => 'Balanced',
            ],
            'explainability' => [
                'key_drivers'         => ['Strong buyer demand relative to available stock'],
                'risk_factors'        => ['Slow absorption'],
                'position_summary'    => 'Priced below the median of active comparable listings.',
                'price_leverage_note' => 'A 1% price reduction is estimated to reduce time on market by ~3 days.',
            ],
            'competitive_stock' => [
                'total_active_stock'   => 12,
                'below_subject_count'  => 4,
                'above_subject_count'  => 8,
            ],
            'holding_cost' => [
                'monthly_total' => 15000,
            ],
        ];

        return PresentationSnapshot::create([
            'presentation_id'         => $this->presentation->id,
            'generated_by_user_id'    => $this->user->id,
            'created_by_user_id'      => $this->user->id,
            'inputs_json'             => json_encode(['suburb' => 'Claremont', 'type' => 'house', 'period_months' => 12]),
            'output_summary_json'     => json_encode(array_merge($defaultOutputs, $outputs)),
            'snapshot_json'           => json_encode(['source' => 'test']),
            'generated_at'            => now(),
        ]);
    }

    // ── Feature flag off → no panel ──────────────────────────────────────

    public function test_flag_off_no_power_panel(): void
    {
        config()->set('features.presentation_power_panel_v1', false);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertDontSee('Power Panel');
    }

    // ── Feature flag on, no snapshot → no panel ──────────────────────────

    public function test_flag_on_no_snapshot_no_panel(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertDontSee('Power Panel');
    }

    // ── Feature flag on, snapshot exists → panel shows ───────────────────

    public function test_flag_on_with_snapshot_shows_panel(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('Power Panel');
    }

    // ── Panel shows probability values ───────────────────────────────────

    public function test_panel_shows_probability_values(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot(['p60' => 0.55]);
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('55%'); // p60 = 0.55 → 55%
    }

    // ── Panel shows confidence ───────────────────────────────────────────

    public function test_panel_shows_confidence_score(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('75'); // confidence_score
        $response->assertSee('(B)'); // confidence_grade
    }

    // ── Panel shows PPI ──────────────────────────────────────────────────

    public function test_panel_shows_ppi(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('68'); // ppi_score
        $response->assertSee('(Balanced)'); // ppi_label
    }

    // ── Panel shows explainability drivers ────────────────────────────────

    public function test_panel_shows_drivers(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('Strong buyer demand');
    }

    // ── Panel shows risk factors ─────────────────────────────────────────

    public function test_panel_shows_risks(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('Slow absorption');
    }

    // ── Panel shows competitive stock ────────────────────────────────────

    public function test_panel_shows_competitive_stock(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();
        $response->assertSee('Active Stock');
    }

    // ── No DB writes on show ─────────────────────────────────────────────

    public function test_show_does_not_write_to_db(): void
    {
        config()->set('features.presentation_power_panel_v1', true);
        $this->createSnapshot();
        $this->actingAs($this->user);

        $snapshotCountBefore = PresentationSnapshot::count();

        $response = $this->get(route('presentations.show', $this->presentation));
        $response->assertOk();

        $this->assertSame($snapshotCountBefore, PresentationSnapshot::count());
    }
}
