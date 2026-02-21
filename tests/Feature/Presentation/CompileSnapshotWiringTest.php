<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\PresentationVersion;
use App\Models\User;
use App\Services\Presentations\PresentationCompilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI3: Compile pack uses latest snapshot outputs — wiring verification.
 */
class CompileSnapshotWiringTest extends TestCase
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
            'role'      => 'admin',
            'branch_id' => $this->branch->id,
        ]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Compile Wiring Test',
            'property_address'   => '5 Test Drive',
            'suburb'             => 'Newlands',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function createSnapshot(array $outputs = []): PresentationSnapshot
    {
        $defaultOutputs = [
            'p30'            => 0.20,
            'p60'            => 0.45,
            'p90'            => 0.72,
            'expected_days'  => 70,
            'skip_reason'    => null,
            'confidence'     => [
                'confidence_score'     => 65,
                'confidence_grade'     => 'B',
                'data_quality_flags'   => [],
                'volatility_indicator' => 'medium',
            ],
            'ppi'            => [
                'ppi_score' => 55,
                'ppi_label' => 'Balanced',
            ],
            'explainability' => [
                'key_drivers'         => ['Moderate demand'],
                'risk_factors'        => ['High inventory'],
                'position_summary'    => 'Priced near the median.',
                'price_leverage_note' => 'Moderate leverage.',
            ],
            'competitive_stock' => [
                'total_active_stock'  => 18,
                'below_subject_count' => 7,
                'above_subject_count' => 11,
            ],
            'holding_cost' => [
                'monthly_total' => 12000,
            ],
        ];

        return PresentationSnapshot::create([
            'presentation_id'      => $this->presentation->id,
            'generated_by_user_id' => $this->user->id,
            'created_by_user_id'   => $this->user->id,
            'inputs_json'          => json_encode(['suburb' => 'Newlands', 'type' => 'house', 'period_months' => 12]),
            'output_summary_json'  => json_encode(array_merge($defaultOutputs, $outputs)),
            'snapshot_json'        => json_encode(['source' => 'test']),
            'generated_at'         => now(),
        ]);
    }

    // ── Compiler pulls from latest snapshot ─────────────────────────────

    public function test_compiler_uses_latest_snapshot(): void
    {
        // Create an old snapshot with different values
        $this->createSnapshot(['p60' => 0.30, 'expected_days' => 100]);

        // Advance time so the second snapshot has a later created_at
        $this->travel(5)->minutes();

        // Create a newer snapshot — this should be used
        $this->createSnapshot(['p60' => 0.75, 'expected_days' => 40]);

        config()->set('features.presentation_blueprint', true);

        $version = (new PresentationCompilerService())->compile(
            $this->presentation->id,
            $this->user->id,
        );

        $snapshot = json_decode($version->data_snapshot_json, true);

        $this->assertEquals(0.75, $snapshot['analytics']['p60']);
        $this->assertEquals(40, $snapshot['analytics']['expected_days']);
    }

    // ── Compiled version includes confidence ────────────────────────────

    public function test_compiled_version_includes_confidence(): void
    {
        $this->createSnapshot();
        config()->set('features.presentation_blueprint', true);

        $version = (new PresentationCompilerService())->compile(
            $this->presentation->id,
            $this->user->id,
        );

        $snapshot = json_decode($version->data_snapshot_json, true);

        $this->assertNotNull($snapshot['confidence']);
        $this->assertEquals(65, $snapshot['confidence']['confidence_score']);
        $this->assertEquals('B', $snapshot['confidence']['confidence_grade']);
    }

    // ── Compiled version includes PPI ───────────────────────────────────

    public function test_compiled_version_includes_ppi(): void
    {
        $this->createSnapshot();
        config()->set('features.presentation_blueprint', true);

        $version = (new PresentationCompilerService())->compile(
            $this->presentation->id,
            $this->user->id,
        );

        $snapshot = json_decode($version->data_snapshot_json, true);

        $this->assertNotNull($snapshot['ppi']);
        $this->assertEquals(55, $snapshot['ppi']['ppi_score']);
        $this->assertEquals('Balanced', $snapshot['ppi']['ppi_label']);
    }

    // ── Compiled version includes competitive stock ─────────────────────

    public function test_compiled_version_includes_competitive_stock(): void
    {
        $this->createSnapshot();
        config()->set('features.presentation_blueprint', true);

        $version = (new PresentationCompilerService())->compile(
            $this->presentation->id,
            $this->user->id,
        );

        $snapshot = json_decode($version->data_snapshot_json, true);

        $this->assertNotNull($snapshot['competitive_stock']);
        $this->assertEquals(18, $snapshot['competitive_stock']['total_active_stock']);
        $this->assertEquals(7, $snapshot['competitive_stock']['below_subject_count']);
    }

    // ── Compiled version includes explainability ────────────────────────

    public function test_compiled_version_includes_explainability(): void
    {
        $this->createSnapshot();
        config()->set('features.presentation_blueprint', true);

        $version = (new PresentationCompilerService())->compile(
            $this->presentation->id,
            $this->user->id,
        );

        $snapshot = json_decode($version->data_snapshot_json, true);

        $this->assertNotNull($snapshot['explainability']);
        $this->assertContains('Moderate demand', $snapshot['explainability']['key_drivers']);
        $this->assertContains('High inventory', $snapshot['explainability']['risk_factors']);
    }

    // ── No snapshot → analytics is empty ────────────────────────────────

    public function test_no_snapshot_results_in_empty_analytics(): void
    {
        config()->set('features.presentation_blueprint', true);

        $version = (new PresentationCompilerService())->compile(
            $this->presentation->id,
            $this->user->id,
        );

        $snapshot = json_decode($version->data_snapshot_json, true);

        $this->assertEmpty($snapshot['analytics']);
        $this->assertNull($snapshot['confidence']);
        $this->assertNull($snapshot['ppi']);
        $this->assertNull($snapshot['competitive_stock']);
    }

    // ── Compile creates a PresentationVersion row ───────────────────────

    public function test_compile_creates_version_row(): void
    {
        $this->createSnapshot();
        config()->set('features.presentation_blueprint', true);

        $countBefore = PresentationVersion::count();

        (new PresentationCompilerService())->compile(
            $this->presentation->id,
            $this->user->id,
        );

        $this->assertSame($countBefore + 1, PresentationVersion::count());
    }

    // ── Compile endpoint gated by feature flag ──────────────────────────

    public function test_compile_endpoint_gated_by_feature_flag(): void
    {
        config()->set('features.presentation_blueprint', false);
        $this->actingAs($this->user);

        $response = $this->post(route('presentations.compile', $this->presentation));
        $response->assertNotFound();
    }

    // ── Compile endpoint works when flag on ─────────────────────────────

    public function test_compile_endpoint_returns_redirect_when_flag_on(): void
    {
        $this->createSnapshot();
        config()->set('features.presentation_blueprint', true);
        config()->set('features.presentation_readiness_check', false);
        $this->actingAs($this->user);

        $response = $this->post(route('presentations.compile', $this->presentation));
        $response->assertRedirect(route('presentations.show', $this->presentation));
    }
}
