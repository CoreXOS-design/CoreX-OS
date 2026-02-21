<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\PresentationSoldComp;
use App\Models\User;
use App\Services\Presentations\PresentationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests PresentationReadinessService and compile gating (P16).
 */
class PresentationReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private User         $user;
    private Branch       $branch;
    private Presentation $presentation;
    private PresentationReadinessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create(['name' => 'Test', 'code' => 'T', 'is_active' => true]);
        $this->user   = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);

        $this->presentation = Presentation::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'title'              => 'Readiness Test',
            'property_address'   => '1 Test Rd',
            'suburb'             => 'Claremont',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        $this->service = new PresentationReadinessService();
    }

    // ── Identity fields ───────────────────────────────────────────────────────

    public function test_missing_suburb_makes_identity_incomplete(): void
    {
        $this->presentation->update(['suburb' => null]);

        $result = $this->service->evaluate($this->presentation);

        $identity = collect($result['required_items'])->firstWhere('key', 'identity_complete');
        $this->assertFalse($identity['satisfied']);
        $this->assertFalse($result['can_compile']);
    }

    public function test_missing_property_type_makes_identity_incomplete(): void
    {
        $this->presentation->update(['property_type' => null]);

        $result = $this->service->evaluate($this->presentation);

        $identity = collect($result['required_items'])->firstWhere('key', 'identity_complete');
        $this->assertFalse($identity['satisfied']);
    }

    public function test_suburb_and_type_set_satisfies_identity(): void
    {
        $result = $this->service->evaluate($this->presentation);

        $identity = collect($result['required_items'])->firstWhere('key', 'identity_complete');
        $this->assertTrue($identity['satisfied']);
    }

    // ── can_compile false when required items missing ─────────────────────────

    public function test_fresh_presentation_cannot_compile(): void
    {
        $result = $this->service->evaluate($this->presentation);

        $this->assertFalse($result['can_compile']);
        $this->assertNotEmpty($result['missing_required']);
    }

    public function test_can_compile_true_when_all_required_satisfied(): void
    {
        // Satisfy all required items:
        // 1. Identity — already set (suburb + property_type)
        // 2. Suburb evidence — add a sold comp as evidence via upload
        // 3. Vicinity sales — add a sold comp record
        // 4. Competitive stock — add an active listing

        // Add a sold comp to satisfy vicinity_sales
        PresentationSoldComp::create([
            'presentation_id' => $this->presentation->id,
            'suburb'          => 'Claremont',
            'sold_date'       => now()->subMonths(3)->toDateString(),
            'sold_price_inc'  => 2_000_000,
            'property_type'   => 'house',
            'raw_row_json'    => '{}',
            'parser_version'  => 'v1',
        ]);

        // Add an active listing to satisfy competitive_stock
        PresentationActiveListing::create([
            'presentation_id'  => $this->presentation->id,
            'suburb'           => 'Claremont',
            'list_price_inc'   => 2_200_000,
            'property_type'    => 'house',
            'raw_row_json'     => '{}',
            'parser_version'   => 'v1',
            'extraction_method'=> 'upload',
        ]);

        // Add a mock upload to satisfy suburb_evidence
        \App\Models\PresentationUpload::create([
            'presentation_id'    => $this->presentation->id,
            'uploaded_by_user_id'=> $this->user->id,
            'type'               => 'suburb_stats',
            'original_filename'  => 'report.pdf',
            'storage_path'       => 'presentations/1/report.pdf',
            'extraction_status'  => 'ok',
        ]);

        $result = $this->service->evaluate($this->presentation->fresh());

        $this->assertTrue($result['can_compile']);
        $this->assertEmpty($result['missing_required']);
    }

    // ── Percent calculation deterministic ─────────────────────────────────────

    public function test_completed_percent_is_deterministic(): void
    {
        $r1 = $this->service->evaluate($this->presentation);
        $r2 = $this->service->evaluate($this->presentation);

        $this->assertSame($r1['completed_percent'], $r2['completed_percent']);
    }

    public function test_completed_percent_increases_as_items_satisfied(): void
    {
        $before = $this->service->evaluate($this->presentation)['completed_percent'];

        PresentationSoldComp::create([
            'presentation_id' => $this->presentation->id,
            'suburb'          => 'Claremont',
            'sold_date'       => now()->subMonths(3)->toDateString(),
            'sold_price_inc'  => 2_000_000,
            'property_type'   => 'house',
            'raw_row_json'    => '{}',
            'parser_version'  => 'v1',
        ]);

        $after = $this->service->evaluate($this->presentation->fresh())['completed_percent'];

        $this->assertGreaterThan($before, $after);
    }

    public function test_result_has_all_required_keys(): void
    {
        $result = $this->service->evaluate($this->presentation);

        $this->assertArrayHasKey('required_items', $result);
        $this->assertArrayHasKey('missing_required', $result);
        $this->assertArrayHasKey('optional_items', $result);
        $this->assertArrayHasKey('completed_percent', $result);
        $this->assertArrayHasKey('can_compile', $result);
    }

    public function test_required_items_count_is_four(): void
    {
        $result = $this->service->evaluate($this->presentation);

        $this->assertCount(4, $result['required_items']);
    }

    public function test_optional_items_count_is_three(): void
    {
        $result = $this->service->evaluate($this->presentation);

        $this->assertCount(3, $result['optional_items']);
    }

    // ── Compile endpoint gating ───────────────────────────────────────────────

    public function test_compile_blocked_when_readiness_flag_on_and_missing_required(): void
    {
        config()->set('features.presentation_blueprint', true);
        config()->set('features.presentation_readiness_check', true);

        $this->actingAs($this->user);

        $response = $this->post(route('presentations.compile', $this->presentation));

        // Should redirect back with error (not compile)
        $response->assertRedirect(route('presentations.show', $this->presentation));
        $response->assertSessionHas('error');
        $this->assertSame(0, \App\Models\PresentationVersion::count());
    }

    public function test_compile_allowed_when_readiness_flag_off(): void
    {
        config()->set('features.presentation_blueprint', true);
        config()->set('features.presentation_readiness_check', false);

        $this->actingAs($this->user);

        $response = $this->post(route('presentations.compile', $this->presentation));

        $response->assertRedirect(route('presentations.show', $this->presentation));
        $response->assertSessionHas('success');
        $this->assertSame(1, \App\Models\PresentationVersion::count());
    }

    public function test_admin_can_force_compile_despite_missing_required(): void
    {
        config()->set('features.presentation_blueprint', true);
        config()->set('features.presentation_readiness_check', true);

        $admin = User::factory()->create(['role' => 'admin', 'branch_id' => $this->branch->id]);
        $this->actingAs($admin);

        $response = $this->post(
            route('presentations.compile', $this->presentation),
            ['force' => '1'],
        );

        $response->assertRedirect(route('presentations.show', $this->presentation));
        $response->assertSessionHas('success');
        $this->assertSame(1, \App\Models\PresentationVersion::count());
    }
}
