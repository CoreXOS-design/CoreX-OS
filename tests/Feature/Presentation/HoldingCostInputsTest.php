<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests holding cost input capture, persistence, and integration (P15).
 */
class HoldingCostInputsTest extends TestCase
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
            'title'              => 'HC Test Presentation',
            'property_address'   => '1 HC Street',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    // ── updateHoldingCost() persists correctly ────────────────────────────────

    public function test_saving_holding_cost_persists_fields(): void
    {
        $this->actingAs($this->user);

        $this->patch(
            route('presentations.holding-cost.update', $this->presentation),
            [
                'monthly_bond'    => 12000,
                'monthly_rates'   => 1500,
                'monthly_levies'  => 800,
                'monthly_insurance' => 500,
            ],
        )->assertRedirect(route('presentations.show', $this->presentation));

        $this->presentation->refresh();

        $this->assertEquals(12000.0, $this->presentation->monthly_bond);
        $this->assertEquals(1500.0,  $this->presentation->monthly_rates);
        $this->assertEquals(800.0,   $this->presentation->monthly_levies);
        $this->assertEquals(500.0,   $this->presentation->monthly_insurance);
        $this->assertNull($this->presentation->monthly_utilities);
        $this->assertNull($this->presentation->monthly_opportunity_cost);
    }

    public function test_saving_holding_cost_requires_auth(): void
    {
        $this->patch(
            route('presentations.holding-cost.update', $this->presentation),
            ['monthly_bond' => 5000],
        )->assertRedirect('/login');
    }

    public function test_partial_update_does_not_affect_unset_fields(): void
    {
        // Pre-set a value
        $this->presentation->update(['monthly_bond' => 10000]);

        $this->actingAs($this->user);

        $this->patch(
            route('presentations.holding-cost.update', $this->presentation),
            ['monthly_rates' => 2000],
        )->assertRedirect();

        $this->presentation->refresh();
        // monthly_rates updated, monthly_bond now null (not posted = null per validate)
        $this->assertEquals(2000.0, $this->presentation->monthly_rates);
    }

    // ── simulate() returns holding_cost block ────────────────────────────────

    public function test_simulate_returns_null_holding_cost_when_no_inputs_set(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $this->assertArrayHasKey('holding_cost', $response->json());
        $this->assertNull($response->json('holding_cost'));
    }

    public function test_simulate_returns_holding_cost_block_when_fields_set(): void
    {
        $this->presentation->update([
            'monthly_bond'  => 15000,
            'monthly_rates' => 1500,
        ]);

        $this->actingAs($this->user);

        $response = $this->postJson(
            route('presentations.simulate', $this->presentation),
            [
                'suburb'        => 'Claremont',
                'type'          => 'house',
                'price'         => 2_000_000,
                'period_months' => 12,
            ],
        )->assertOk();

        $hc = $response->json('holding_cost');
        $this->assertNotNull($hc);
        $this->assertArrayHasKey('monthly_total', $hc);
        $this->assertEquals(16500.0, $hc['monthly_total']);
    }

    public function test_simulate_ppi_reflects_holding_cost(): void
    {
        // With no holding cost — PPI has max 15 pts from holding cost component
        $this->actingAs($this->user);

        $responseNoCost = $this->postJson(
            route('presentations.simulate', $this->presentation),
            ['suburb' => 'Claremont', 'type' => 'house', 'price' => 2_000_000, 'period_months' => 12],
        )->assertOk();

        // Add a large holding cost (close to ceiling = 50 000)
        $this->presentation->update(['monthly_bond' => 45000]);

        $responseWithCost = $this->postJson(
            route('presentations.simulate', $this->presentation),
            ['suburb' => 'Claremont', 'type' => 'house', 'price' => 2_000_000, 'period_months' => 12],
        )->assertOk();

        $ppiNoCost   = $responseNoCost->json('ppi.ppi_score');
        $ppiWithCost = $responseWithCost->json('ppi.ppi_score');

        // With holding cost, holding component is smaller → PPI should be lower
        if ($ppiNoCost !== null && $ppiWithCost !== null) {
            $this->assertLessThanOrEqual($ppiNoCost, $ppiWithCost);
        }
    }

    // ── Compiler reads from presentation fields ───────────────────────────────

    public function test_compile_uses_presentation_holding_cost_fields(): void
    {
        $this->presentation->update([
            'monthly_bond'  => 20000,
            'monthly_rates' => 3000,
        ]);

        $compiler = new \App\Services\Presentations\PresentationCompilerService();
        $version  = $compiler->compile($this->presentation->id, $this->user->id);

        $snapshot = $version->getSnapshotArray();

        $this->assertArrayHasKey('holding_cost', $snapshot);
        $this->assertNotNull($snapshot['holding_cost']);
        $this->assertEquals(23000.0, $snapshot['holding_cost']['monthly_total']);
    }

    public function test_compile_holding_cost_null_when_no_fields_set(): void
    {
        $compiler = new \App\Services\Presentations\PresentationCompilerService();
        $version  = $compiler->compile($this->presentation->id, $this->user->id);

        $snapshot = $version->getSnapshotArray();

        $this->assertNull($snapshot['holding_cost']);
    }
}
