<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\MarketAnalyticsRun;
use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\PresentationVersion;
use App\Models\SaleProbabilityRun;
use App\Models\User;
use App\Services\Presentations\PresentationCompilerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresentationVersionTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::factory()->create();
    }

    private function createBranch(): Branch
    {
        return Branch::create(['name' => 'Test Branch', 'code' => 'TB']);
    }

    private function createPresentation(Branch $branch, User $user): Presentation
    {
        return Presentation::create([
            'branch_id'          => $branch->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Test Presentation',
            'property_address'   => '123 Test Street',
            'suburb'             => 'Testville',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    private function createMaRun(User $user): MarketAnalyticsRun
    {
        return MarketAnalyticsRun::create([
            'model_version'  => 'v1.0.0',
            'inputs_hash'    => sha1('test-ma-inputs'),
            'inputs_json'    => json_encode(['suburb' => 'Testville', 'type' => 'house']),
            'outputs_json'   => json_encode(['months_of_inventory' => 2.5]),
            'breakdown_json' => json_encode([]),
            'created_by'     => $user->id,
        ]);
    }

    private function createSpRun(MarketAnalyticsRun $maRun, User $user): SaleProbabilityRun
    {
        return SaleProbabilityRun::create([
            'market_analytics_run_id'        => $maRun->id,
            'market_analytics_model_version' => 'v1.0.0',
            'market_analytics_inputs_hash'   => sha1('test-ma-inputs'),
            'model_version'                  => 'prob-v1.0.0',
            'inputs_hash'                    => sha1('test-sp-inputs'),
            'inputs_json'                    => json_encode(['market_analytics_run_id' => $maRun->id]),
            'outputs_json'                   => json_encode(['p30' => 0.4, 'p60' => 0.65, 'p90' => 0.85, 'expected_days' => 55]),
            'breakdown_json'                 => json_encode([]),
            'data_sources_json'              => json_encode([]),
            'created_by'                     => $user->id,
        ]);
    }

    private function createSnapshot(Presentation $presentation, MarketAnalyticsRun $maRun, SaleProbabilityRun $spRun, User $user): PresentationSnapshot
    {
        return PresentationSnapshot::create([
            'presentation_id'         => $presentation->id,
            'generated_by_user_id'    => $user->id,
            'snapshot_json'           => json_encode([]),
            'generated_at'            => now(),
            'market_analytics_run_id' => $maRun->id,
            'sale_probability_run_id' => $spRun->id,
            'inputs_json'             => json_encode(['suburb' => 'Testville', 'type' => 'house', 'period_months' => 12]),
            'output_summary_json'     => json_encode(['p30' => 0.4, 'p60' => 0.65, 'p90' => 0.85, 'expected_days' => 55]),
        ]);
    }

    // ── compile() creates a version row ──────────────────────────────────────

    public function test_compile_creates_presentation_version_row(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $this->assertSame(0, PresentationVersion::count());

        $compiler = new PresentationCompilerService();
        $version  = $compiler->compile($presentation->id, $user->id);

        $this->assertSame(1, PresentationVersion::count());
        $this->assertInstanceOf(PresentationVersion::class, $version);
    }

    public function test_version_stores_correct_presentation_id(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $version = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertSame($presentation->id, $version->presentation_id);
    }

    public function test_version_stores_blueprint_version_v1(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $version = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertSame('v1', $version->blueprint_version);
    }

    public function test_version_stores_compiled_by(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $version = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertSame($user->id, $version->compiled_by);
    }

    // ── data_snapshot_json is populated ──────────────────────────────────────

    public function test_data_snapshot_json_contains_blueprint_sections(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $version  = (new PresentationCompilerService())->compile($presentation->id, $user->id);
        $snapshot = $version->getSnapshotArray();

        $this->assertArrayHasKey('sections', $snapshot);
        $this->assertCount(10, $snapshot['sections']);
    }

    public function test_data_snapshot_json_contains_presentation_fields(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $version  = (new PresentationCompilerService())->compile($presentation->id, $user->id);
        $snapshot = $version->getSnapshotArray();

        $this->assertArrayHasKey('presentation', $snapshot);
        $this->assertSame('Test Presentation', $snapshot['presentation']['title']);
    }

    // ── Compiling twice increments version count ──────────────────────────────

    public function test_compiling_twice_creates_two_version_rows(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $compiler = new PresentationCompilerService();
        $v1       = $compiler->compile($presentation->id, $user->id);
        $v2       = $compiler->compile($presentation->id, $user->id);

        $this->assertSame(2, PresentationVersion::count());
        $this->assertNotSame($v1->id, $v2->id);
    }

    // ── Analytics run IDs captured from snapshot ──────────────────────────────

    public function test_analytics_run_ids_captured_from_latest_snapshot(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);
        $maRun        = $this->createMaRun($user);
        $spRun        = $this->createSpRun($maRun, $user);
        $this->createSnapshot($presentation, $maRun, $spRun, $user);

        $version = (new PresentationCompilerService())->compile($presentation->id, $user->id);

        $this->assertSame($maRun->id, $version->analytics_run_id);
        $this->assertSame($spRun->id, $version->probability_run_id);
    }

    // ── HTTP endpoint ─────────────────────────────────────────────────────────

    public function test_compile_endpoint_creates_version_and_redirects(): void
    {
        $user         = $this->createUser();
        $branch       = $this->createBranch();
        $presentation = $this->createPresentation($branch, $user);

        $response = $this->actingAs($user)
            ->post("/presentations/{$presentation->id}/compile");

        $response->assertRedirect("/presentations/{$presentation->id}");
        $this->assertSame(1, PresentationVersion::count());
    }

    public function test_compile_endpoint_requires_auth(): void
    {
        $branch       = $this->createBranch();
        $user         = $this->createUser();
        $presentation = $this->createPresentation($branch, $user);

        $this->post("/presentations/{$presentation->id}/compile")
             ->assertRedirect('/login');
    }
}
